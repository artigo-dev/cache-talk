<?php
declare(strict_types=1);

namespace Talk\Scenario;

use Ovos\Cache\Store\KeyValue;
use Redis;
use Stores\Pages;
use Talk\Barrier;
use Talk\Config;
use Talk\Env;
use Talk\Events;
use Talk\Fleet;
use Talk\Parameter;
use Talk\Scenario;
use Talk\Sink;
use Talk\Sink\Buffer;
use Talk\Store\File;

use function ceil;
use function count;
use function explode;
use function glob;
use function intdiv;
use function random_int;
use function is_dir;
use function rmdir;
use function sort;
use function sprintf;
use function str_repeat;
use function unlink;
use function usleep;

/**
 * More visitors than the database has room for: without a cache every visit
 * waits for one of its few slots; with a file cache, only the first moment
 * does.
 *
 * The database serves SLOTS queries at a time (a Redis list of tokens: BLPOP
 * takes one, RPUSH gives it back), each between QUERY_MIN_MS and
 * QUERY_MAX_MS long. The visitors are worker processes: BURST join within
 * BURST_SPREAD_MS of 0, then one about every RAMP_MS (give or take
 * RAMP_JITTER_MS); each opens the front page, reads it for THINK_MIN_MS to
 * THINK_MAX_MS and opens it again, until END_MS. The times vary as real
 * traffic does: fixed ones kept the visitors in lockstep, and a wave's
 * answers on one point of the chart.
 *
 * - none: every visit queries the database
 * - file: Talk\Store\File in front of it; its lock is per process, so the
 *   burst's misses all go to the database (station 9's stampede, on purpose)
 */
class Load extends Scenario
{
	public const array LABELS = [
		'none' => 'No cache (every visit asks the database)',
		'file' => 'A file cache (Talk\Store\File)',
	];
	
	protected const int SLOTS = 4;
	
	protected const int QUERY_MIN_MS = 35;
	
	protected const int QUERY_MAX_MS = 45;
	
	protected const int BURST = 8;
	
	/**
	 * How far apart the burst's visitors arrive at most: still at once, against a query of 35 ms
	 */
	protected const int BURST_SPREAD_MS = 5;
	
	protected const int RAMP_MS = 75;
	
	protected const int RAMP_JITTER_MS = 25;
	
	protected const int THINK_MIN_MS = 100;
	
	protected const int THINK_MAX_MS = 200;
	
	protected const int END_MS = 2_600;
	
	protected const string DIR = '/tmp/talk/load';
	
	public function name(): string
	{
		return 'load';
	}
	
	public function parameters(): array
	{
		return [
			'visitors' => new Parameter(live: 32, min: 8, max: 64, label: 'visitors'),
			'modes' => new Parameter(live: 'none,file', options: ['none', 'file'], label: 'modes'),
		];
	}
	
	public function run(
		Sink $sink,
		array $params,
	): void
	{
		$redis = Config::redis();
		$visitors = (int)$params['visitors'];
		
		foreach(explode(',', (string)$params['modes']) as $mode)
		{
			Env::flush($redis);
			$run = $this->runId();
			for($slot = 0; $slot < static::SLOTS; $slot++)
			{
				$redis->rPush(static::slotsKey($run), (string)$slot);
			}
			$sink->emit([
				'type' => 'segment',
				't' => 0,
				'mode' => $mode,
				'label' => static::LABELS[$mode],
				'visitors' => $visitors,
				'slots' => static::SLOTS,
				'queryMinMs' => static::QUERY_MIN_MS,
				'queryMaxMs' => static::QUERY_MAX_MS,
				'endMs' => static::END_MS,
			]);
			
			$events = new Buffer($sink);
			try
			{
				(new Fleet($events, $redis))->run($this->name(), $run, $visitors, [
					'mode' => $mode,
				], [
					'mode' => $mode,
				]);
			}
			finally
			{
				static::removeDir(static::DIR . '/' . $run);
			}
			
			$this->summarise($sink, $events, $mode, $visitors);
		}
	}
	
	public function work(
		array $job,
	): void
	{
		$mode = (string)$job['mode'];
		$index = (int)$job['index'];
		$run = (string)$job['run'];
		$redis = Config::redis();
		$store = $mode === 'file' ? static::store(static::DIR . '/' . $run) : null;
		
		Barrier::arrive($redis, $run);
		
		static::sleepUntil($index < static::BURST
			? random_int(0, static::BURST_SPREAD_MS * 1000) / 1000
			: ($index - static::BURST + 1) * static::RAMP_MS + random_int(-static::RAMP_JITTER_MS * 1000, static::RAMP_JITTER_MS * 1000) / 1000);
		Events::emit('joined', [
			'w' => $index,
		]);
		
		$visit = 0;
		while(Events::now() < static::END_MS)
		{
			$visit++;
			Events::emit('request', [
				'w' => $index,
				'visit' => $visit,
			]);
			$asked = false;
			$query = static function() use ($redis, $run, $index, &$asked): string
			{
				$asked = true;
				
				return static::query($redis, $run, $index);
			};
			$store === null
				? $query()
				: $store->get('front-page', $query, ttl: 60, tags: ['front-page']);
			Events::emit('respond', [
				'w' => $index,
				'visit' => $visit,
				'db' => $asked,
			]);
			
			usleep(random_int(static::THINK_MIN_MS * 1000, static::THINK_MAX_MS * 1000));
		}
	}
	
	/**
	 * The database: a slot, the query, the slot given back
	 */
	protected static function query(
		Redis $redis,
		string $run,
		int $index,
	): string
	{
		$token = $redis->blPop([static::slotsKey($run)], 30);
		Events::emit('query.start', [
			'w' => $index,
		]);
		usleep(random_int(static::QUERY_MIN_MS * 1000, static::QUERY_MAX_MS * 1000));
		Events::emit('query.end', [
			'w' => $index,
		]);
		$redis->rPush(static::slotsKey($run), (string)($token[1] ?? '0'));
		
		return str_repeat('x', 64);
	}
	
	/**
	 * What the segment came to, and the claims it makes
	 */
	protected function summarise(
		Sink $sink,
		Buffer $events,
		string $mode,
		int $visitors,
	): void
	{
		$asked = [];
		$early = [];
		$late = [];
		$all = [];
		$queries = 0;
		$earlyQueries = 0;
		$lateQueries = 0;
		foreach($events->events() as $event)
		{
			$key = $event['type'] === 'request' || $event['type'] === 'respond' ? $event['w'] . ':' . $event['visit'] : null;
			if($event['type'] === 'request')
			{
				$asked[$key] = (float)$event['t'];
			}
			if($event['type'] === 'respond' && isset($asked[$key]))
			{
				$wait = (float)$event['t'] - $asked[$key];
				$all[] = $wait;
				if($asked[$key] < 300)
				{
					$early[] = $wait;
				}
				if($asked[$key] >= static::END_MS / 2)
				{
					$late[] = $wait;
				}
				if($event['db'])
				{
					$queries++;
					$asked[$key] < 300 ? $earlyQueries++ : $lateQueries++;
				}
			}
		}
		
		sort($all);
		$sink->emit([
			'type' => 'summary',
			't' => Events::now(),
			'mode' => $mode,
			'visits' => count($all),
			'queries' => $queries,
			'waitMax' => $all === [] ? 0.0 : $all[count($all) - 1],
			'waitMedian' => static::median($all),
		]);
		
		// an undisturbed run: nobody waits longer than if every visitor queued at once (a stalled machine,
		// as Docker Desktop can stall for a second or two, fails this, and the recording is tried again)
		$bound = (int)ceil(($visitors / static::SLOTS + 1) * static::QUERY_MAX_MS * 1.2);
		$longest = $all === [] ? 0.0 : $all[count($all) - 1];
		$this->check($sink, $longest <= $bound, sprintf('nobody waits longer than every visitor queued at once would (%d ms; the longest: %d ms)', $bound, $longest));
		
		if($mode === 'none')
		{
			$this->check($sink, static::median($late) >= 2 * static::median($early), sprintf('the waits grow with the visitors: a median of %d ms at the start, %d ms in the second half', static::median($early), static::median($late)));
		}
		else
		{
			$this->check($sink, $earlyQueries > 1, sprintf('the first moment sends %d visits to the database at once (the stampede)', $earlyQueries));
			$this->check($sink, $lateQueries === 0, sprintf('after it, no visit asks the database (%d of %d)', $lateQueries, count($all) - $earlyQueries));
		}
	}
	
	/**
	 * @param list<float> $values
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
		
		return $values[intdiv(count($values), 2)];
	}
	
	protected static function store(
		string $dir,
	): KeyValue
	{
		Config::patch([
			'store' => File::class,
			'store_options' => [
				'dir' => $dir,
			],
		]);
		
		return (new Pages())->store();
	}
	
	protected static function slotsKey(
		string $run,
	): string
	{
		return 'talk:load:slots:' . $run;
	}
	
	protected static function sleepUntil(
		float $ms,
	): void
	{
		$left = $ms - Events::now();
		if($left > 0)
		{
			usleep((int)($left * 1000));
		}
	}
	
	protected static function removeDir(
		string $dir,
	): void
	{
		if(is_dir($dir) === false)
		{
			return;
		}
		
		foreach(glob($dir . '/*') ?: [] as $path)
		{
			is_dir($path) ? static::removeDir($path) : @unlink($path);
		}
		@rmdir($dir);
	}
}
