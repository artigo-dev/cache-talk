<?php
declare(strict_types=1);

namespace Talk\Scenario;

use Closure;
use RuntimeException;
use Stores\Reports;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\LockRegistry;
use Symfony\Contracts\Cache\ItemInterface;
use Talk\Barrier;
use Talk\Config;
use Talk\Env;
use Talk\Events;
use Talk\Fleet;
use Talk\Origin;
use Talk\Parameter;
use Talk\Scenario;
use Talk\Sink;
use Talk\Sink\Buffer;
use Talk\Store\RedisClassic;

use function array_column;
use function array_filter;
use function array_map;
use function count;
use function explode;
use function in_array;
use function intdiv;
use function is_dir;
use function max;
use function min;
use function mkdir;
use function sort;
use function sprintf;
use function sys_get_temp_dir;
use function touch;

/**
 * A hot key goes cold and N requests arrive together. How many of them reach
 * the origin, and how long do the others wait behind the one that does?
 *
 * Three locks, one fleet each:
 * - none: our classic backend (Talk\Store\RedisClassic), which has no lock -
 *   every request computes
 * - lockregistry: Symfony's LockRegistry, flock() on local files - one
 *   computation per host, and its waiters poll
 * - memolock: php-library's Store\Redis with its MemoLock - one computation for the
 *   whole fleet, its waiters woken by a message
 *
 * A host is, to flock(), nothing more than its own set of lock files, which
 * is what lets one machine stand for a fleet of twelve.
 */
class Stampede extends Scenario
{
	public const array LABELS = [
		'none' => 'No lock (a classic Redis backend)',
		'lockregistry' => 'Symfony LockRegistry (flock, per server · checks every 100 ms)',
		'memolock' => 'MemoLock (Redis, all servers · woken by a message)',
	];
	
	/**
	 * Symfony's LockRegistry has 24 or 25 lock files a host
	 */
	protected const int LOCK_FILES = 25;
	
	public function name(): string
	{
		return 'stampede';
	}
	
	public function parameters(): array
	{
		return [
			'workers' => new Parameter(live: 24, min: 1, max: 64, grid: [8, 24, 50], label: 'requests'),
			'hosts' => new Parameter(live: 12, min: 1, max: 24, grid: [1, 4, 12], label: 'servers'),
			'originMs' => new Parameter(live: 300, min: 50, max: 2000, label: 'origin (ms)'),
			'modes' => new Parameter(live: 'none,lockregistry,memolock', options: ['none', 'lockregistry', 'memolock'], label: 'locks'),
		];
	}
	
	public function run(
		Sink $sink,
		array $params,
	): void
	{
		$redis = Config::redis();
		Env::flush($redis);
		
		$workers = (int)$params['workers'];
		$hosts = min((int)$params['hosts'], $workers);
		
		foreach(explode(',', (string)$params['modes']) as $mode)
		{
			$run = $this->runId();
			$sink->emit([
				'type' => 'segment',
				't' => 0,
				'mode' => $mode,
				'label' => static::LABELS[$mode],
				'workers' => $workers,
				'hosts' => $mode === 'lockregistry' ? $hosts : 1,
				'originMs' => (int)$params['originMs'],
			]);
			
			if($mode === 'lockregistry')
			{
				static::createLockFiles($run, $hosts);
			}
			
			$events = new Buffer($sink);
			(new Fleet($events, $redis))->run($this->name(), $run, $workers, [
				'mode' => $mode,
				// a month nobody asked for yet: a cold key
				'month' => '2026-' . $run,
				'hosts' => $hosts,
				'originMs' => (int)$params['originMs'],
			], [
				'mode' => $mode,
			]);
			
			$this->summarise($sink, $events, $mode, Origin::calls($redis, $run), $workers, $hosts, (int)$params['originMs']);
		}
	}
	
	/**
	 * What the segment came to, and the claims it makes
	 */
	protected function summarise(
		Sink $sink,
		Buffer $events,
		string $mode,
		int $calls,
		int $workers,
		int $hosts,
		int $originMs,
	): void
	{
		$of = fn(string $type) => array_filter($events->events(), fn(array $event) => $event['type'] === $type);
		$responses = $of('respond');
		$ends = array_column($of('origin.end'), 't');
		$computed = array_column($of('origin.start'), 'w');
		
		// how long after the first computation ended the others were answered
		$firstEnd = $ends === [] ? 0.0 : min($ends);
		$behind = array_map(
			fn(array $event) => $event['t'] - $firstEnd,
			array_filter($responses, fn(array $event) => in_array($event['w'], $computed, true) === false),
		);
		$latency = array_column($responses, 't');
		
		$sink->emit([
			'type' => 'summary',
			't' => Events::now(),
			'mode' => $mode,
			'originCalls' => $calls,
			'answered' => count($responses),
			'latencyP50' => static::median($latency),
			'latencyMax' => $latency === [] ? 0 : max($latency),
			'behindP50' => static::median($behind),
		]);
		
		// a run the machine disturbed (a cold start, a busy neighbour) fails
		// here rather than ending up in the deck as a fact about the lock
		$starts = array_column($of('origin.start'), 't', 'w');
		$slowest = 0.0;
		foreach($of('origin.end') as $event)
		{
			$slowest = max($slowest, $event['t'] - ($starts[$event['w']] ?? $event['t']));
		}
		
		$this->check($sink, $slowest <= $originMs * 1.5, sprintf('every computation took about the origin\'s %d ms (the slowest: %d ms)', $originMs, $slowest));
		$this->check($sink, count($responses) === $workers, sprintf('every one of the %d requests was answered', $workers));
		match($mode)
		{
			'none' => $this->check($sink, $calls > 1, 'without a lock, the origin computes the key more than once'),
			'lockregistry' => $this->check($sink, $calls >= 1 && $calls <= $hosts, sprintf('LockRegistry: at most one computation per host (%d hosts)', $hosts)),
			'memolock' => $this->check($sink, $calls === 1, 'MemoLock: one computation for the whole fleet'),
		};
	}
	
	public function work(
		array $job,
	): void
	{
		$index = (int)$job['index'];
		$host = $index % max(1, (int)$job['hosts']);
		$redis = Config::redis();
		Origin::configure($redis, (string)$job['run'], $index, (int)$job['originMs']);
		
		$get = match($job['mode'])
		{
			'none' => $this->throughStore(false),
			'memolock' => $this->throughStore(true),
			'lockregistry' => $this->throughLockRegistry((string)$job['run'], $host),
			default => throw new RuntimeException(sprintf('No mode "%s".', $job['mode'])),
		};
		
		Barrier::arrive($redis, (string)$job['run']);
		
		Events::emit('request', [
			'w' => $index,
			'host' => $host,
		]);
		$report = $get((string)$job['month']);
		Events::emit('respond', [
			'w' => $index,
			'host' => $host,
			'by' => $report['by'],
		]);
	}
	
	/**
	 * The demo site's store: our classic backend (no lock), or php-library's with its MemoLock
	 */
	protected function throughStore(
		bool $memoLock,
	): Closure
	{
		Config::patch($memoLock
			? [
				'queue' => [
					'enabled' => true,
				],
			]
			: [
				'store' => RedisClassic::class,
			]);
		
		$reports = new Reports();
		$reports->connect();
		
		return fn(string $month) => $reports->monthly($month);
	}
	
	/**
	 * Symfony's own answer: a RedisAdapter whose get() takes a flock() on one
	 * of this host's lock files. Symfony switches LockRegistry off on the
	 * CLI, so it is switched back on here.
	 */
	protected function throughLockRegistry(
		string $run,
		int $host,
	): Closure
	{
		$pool = new RedisAdapter(Config::redis(5.0), 'stampede');
		LockRegistry::setFiles(static::lockFiles($run, $host));
		$pool->setCallbackWrapper(LockRegistry::compute(...));
		
		return fn(string $month) => $pool->get('report-' . $month, function(ItemInterface $item) use ($month): array
		{
			$item->expiresAfter(3600);
			
			return Origin::work('report ' . $month);
		});
	}
	
	/**
	 * @return list<string>
	 */
	protected static function lockFiles(
		string $run,
		int $host,
	): array
	{
		$files = [];
		for($slot = 0; $slot < static::LOCK_FILES; $slot++)
		{
			$files[] = sprintf('%s/talk/locks/%s/host-%d/lock-%02d', sys_get_temp_dir(), $run, $host, $slot);
		}
		
		return $files;
	}
	
	protected static function createLockFiles(
		string $run,
		int $hosts,
	): void
	{
		for($host = 0; $host < $hosts; $host++)
		{
			foreach(static::lockFiles($run, $host) as $index => $file)
			{
				if($index === 0)
				{
					$directory = sprintf('%s/talk/locks/%s/host-%d', sys_get_temp_dir(), $run, $host);
					if(is_dir($directory) === false && mkdir($directory, 0o777, true) === false)
					{
						throw new RuntimeException(sprintf('Could not create "%s".', $directory));
					}
				}
				
				touch($file);
			}
		}
	}
	
	/**
	 * @param array<int|float> $values
	 */
	protected static function median(
		array $values,
	): float
	{
		if($values === [])
		{
			return 0.0;
		}
		
		sort($values);
		
		return (float)$values[intdiv(count($values), 2)];
	}
}
