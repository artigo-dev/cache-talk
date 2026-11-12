<?php
declare(strict_types=1);

namespace Talk\Scenario;

use Ovos\Cache\Store\KeyValue;
use Redis;
use RuntimeException;
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
use Talk\Store\RedisClassic;

use function array_chunk;
use function array_map;
use function count;
use function explode;
use function ceil;
use function hrtime;
use function intdiv;
use function max;
use function random_int;
use function range;
use function round;
use function sort;
use function sprintf;
use function str_repeat;
use function usleep;

/**
 * One tag on many cached pages is forgotten. How long does it take, and how
 * long does everyone else wait for Redis meanwhile?
 *
 * One store at a time, in a fleet: the first worker fills the store in its
 * own layout (not drawn), then all are released together - the first forgets
 * the tag in one invalidateTags() call, and VISITORS visitors, a process each,
 * arrive at even intervals across the ban and each reads a cached page. When
 * did each one ask, when did it get its answer? A worker is a fresh process,
 * so every store is built from its own configuration.
 *
 * - classic: our classic backend (Talk\Store\RedisClassic, the CMS's, an
 *   older fork of Colin Mollenhour's design) - a hash per page, a set per
 *   tag; forgetting is SUNION of the set, then one MULTI with a single DEL
 *   of every page
 * - redis: php-library's Store\Redis - Lua functions, 7 500 per call
 * - redisearch: php-library's Store\Redisearch - the tags indexed
 * - versioned: php-library's RedisVersioned - one invalidation rule
 */
class Ban extends Scenario
{
	public const array LABELS = [
		'classic' => 'A classic Redis backend (in one go)',
		'redis' => 'php-library Redis (in batches · Lua)',
		'redisearch' => 'php-library RediSearch (the tags indexed)',
		'versioned' => 'php-library RedisVersioned (one rule)',
	];
	
	/**
	 * The store each one is, for `cache.persistent.store`
	 */
	public const array STORES = [
		'classic' => RedisClassic::class,
		'redis' => 'Redis',
		'redisearch' => 'Redisearch',
		'versioned' => 'RedisVersioned',
	];
	
	/**
	 * The visitors of every store: a row each in the chart
	 */
	protected const int VISITORS = 24;
	
	/**
	 * How long forgetting takes a store, per cached page (measured on a laptop):
	 * the visitors' arrivals are spread over that, so they cover the whole ban
	 */
	protected const array MS_PER_PAGE = [
		'classic' => 0.0019,
		'redis' => 0.0054,
		'redisearch' => 0.0052,
		'versioned' => 0.0,
	];
	
	/**
	 * The visitors' arrivals span this much at least (the versioned store's ban
	 * is over in a millisecond: the visitors after it show that nobody waited)
	 */
	protected const float MIN_SPAN_MS = 400.0;
	
	/**
	 * What a visitor reads: a cached page of the site, not one of those forgotten
	 */
	protected const string VISITOR_KEY = 'talk:ban:front-page';
	
	protected const string TAG = 'article-42';
	
	/**
	 * How long the workers wait for each other: a million pages take the
	 * slowest store about 25 s to fill
	 */
	protected const float BARRIER_S = 300.0;
	
	public function name(): string
	{
		return 'ban';
	}
	
	public function parameters(): array
	{
		return [
			'pages' => new Parameter(live: 200_000, record: 1_000_000, min: 1_000, max: 1_000_000, grid: [100_000, 1_000_000], label: 'cached pages'),
			'stores' => new Parameter(live: 'classic,redis,versioned', record: 'classic,redis,redisearch,versioned', options: ['classic', 'redis', 'redisearch', 'versioned'], label: 'stores'),
		];
	}
	
	public function run(
		Sink $sink,
		array $params,
	): void
	{
		$redis = Config::redis(600.0);
		$pages = (int)$params['pages'];
		
		foreach(explode(',', (string)$params['stores']) as $kind)
		{
			Env::flush($redis);
			$run = $this->runId();
			$sink->emit([
				'type' => 'segment',
				't' => 0,
				'store' => $kind,
				'label' => static::LABELS[$kind],
				'pages' => $pages,
			]);
			
			$redis->set(static::VISITOR_KEY, str_repeat('x', 64));
			$span = max(static::MIN_SPAN_MS, $pages * static::MS_PER_PAGE[$kind] * 1.15);
			
			$events = new Buffer($sink);
			(new Fleet($events, $redis, static::BARRIER_S + 60))->run($this->name(), $run, 1 + static::VISITORS, [
				'store' => $kind,
				'pages' => $pages,
				'stepMs' => ceil($span / static::VISITORS),
			], [
				'store' => $kind,
			]);
			
			$this->summarise($sink, $events, $kind, $pages);
		}
	}
	
	public function work(
		array $job,
	): void
	{
		$kind = (string)$job['store'];
		$run = (string)$job['run'];
		
		if((int)$job['index'] > 0)
		{
			$this->visit($run, (int)$job['index'], (float)$job['stepMs']);
			
			return;
		}
		
		$redis = Config::redis(600.0);
		$pages = (int)$job['pages'];
		$store = static::store($kind);
		// the classic backend's two steps, drawn above its panel
		if($store instanceof RedisClassic)
		{
			$store->observe(static fn(string $step, bool $start) => Events::emit($start ? 'phase.start' : 'phase.end', [
				'name' => $step,
			]));
		}
		$memory = $this->fill($redis, $store, $pages);
		
		Barrier::arrive($redis, $run, static::BARRIER_S);
		Events::emit('filled', [
			'memoryMb' => round($memory / 1048576, 1),
		]);
		Events::emit('ban.start');
		$started = hrtime(true);
		$store->invalidateTags([static::TAG]);
		Events::emit('ban.end', [
			'ms' => round((hrtime(true) - $started) / 1e6, 3),
		]);
		Events::emit('sample', [
			'gone' => $this->sampleGone($redis, $store, $pages),
		]);
	}
	
	/**
	 * A visitor: arrives at its moment and reads a cached page; when did it
	 * ask, when did it get its answer?
	 */
	protected function visit(
		string $run,
		int $index,
		float $stepMs,
	): void
	{
		$redis = Config::redis(600.0);
		Barrier::arrive($redis, $run, static::BARRIER_S);
		
		$left = ($index - 1) * $stepMs - Events::now();
		if($left > 0)
		{
			usleep((int)($left * 1000));
		}
		Events::emit('request', [
			'w' => $index,
		]);
		$redis->get(static::VISITOR_KEY);
		Events::emit('respond', [
			'w' => $index,
		]);
	}
	
	/**
	 * Every page with the tag, in the store's own layout; the memory Redis
	 * then holds
	 */
	protected function fill(
		Redis $redis,
		KeyValue $store,
		int $pages,
	): int
	{
		$value = str_repeat('x', 64);
		
		// the classic backend's own writes, through a pipeline (save() would take two round trips a page)
		if($store instanceof RedisClassic)
		{
			foreach(array_chunk(range(0, $pages - 1), 10_000) as $chunk)
			{
				$pipe = $redis->multi(Redis::PIPELINE);
				$store->queueNew($pipe, array_map(static fn(int $i): string => 'page-' . $i, $chunk), $value, 3600, static::TAG);
				$pipe->exec();
			}
			
			return (int)$redis->info('memory')['used_memory'];
		}
		
		// the store ready before the first page: RediSearch's index is created on first use, and an
		// index created over pages already there fills in the background, behind the ban
		$store->setMany(['warm-up' => $value], 60, ['warm-up']);
		$store->invalidateTags(['warm-up']);
		foreach(array_chunk(range(0, $pages - 1), 1_000) as $chunk)
		{
			$batch = [];
			foreach($chunk as $i)
			{
				$batch['page-' . $i] = $value;
			}
			$store->setMany($batch, 3600, [static::TAG]);
		}
		
		return (int)$redis->info('memory')['used_memory'];
	}
	
	/**
	 * What the segment came to, and the claims it makes
	 */
	protected function summarise(
		Sink $sink,
		Buffer $events,
		string $kind,
		int $pages,
	): void
	{
		$banMs = 0.0;
		$memoryMb = 0.0;
		$gone = false;
		$asked = [];
		$waits = [];
		foreach($events->events() as $event)
		{
			match($event['type'])
			{
				'ban.end' => $banMs = (float)$event['ms'],
				'filled' => $memoryMb = (float)$event['memoryMb'],
				'sample' => $gone = (bool)$event['gone'],
				'request' => $asked[$event['w']] = (float)$event['t'],
				'respond' => $waits[] = (float)$event['t'] - ($asked[$event['w']] ?? (float)$event['t']),
				default => null,
			};
		}
		sort($waits);
		$count = count($waits);
		$longest = $count > 0 ? $waits[$count - 1] : 0.0;
		
		$sink->emit([
			'type' => 'summary',
			't' => Events::now(),
			'store' => $kind,
			'pages' => $pages,
			'banMs' => $banMs,
			'waitMax' => round($longest, 3),
			'waitP99' => $count > 0 ? round($waits[(int)($count * 0.99)], 3) : 0.0,
			'waitP50' => $count > 0 ? round($waits[intdiv($count, 2)], 3) : 0.0,
			'memoryMb' => $memoryMb,
		]);
		
		$this->check($sink, $banMs > 0, 'the tag was forgotten');
		$this->check($sink, $gone, 'every one of 200 sampled cached pages is gone, or outdated');
		if($kind === 'classic')
		{
			$this->check($sink, $longest >= $banMs / 3, sprintf('the classic backend freezes Redis: the longest wait (%d ms) is at least a third of the ban (%d ms)', $longest, $banMs));
		}
		else
		{
			$this->check($sink, $longest < 100, sprintf('nobody waits more than 100 ms (the longest: %.1f ms)', $longest));
		}
	}
	
	protected function sampleGone(
		Redis $redis,
		KeyValue $store,
		int $pages,
	): bool
	{
		for($n = 0; $n < 200; $n++)
		{
			$id = 'page-' . random_int(0, $pages - 1);
			$there = $store->get($id, static fn(): string => 'rebuilt') !== 'rebuilt';
			if($there)
			{
				return false;
			}
		}
		
		return true;
	}
	
	protected static function store(
		string $kind,
	): KeyValue
	{
		Config::patch([
			'store' => static::STORES[$kind] ?? throw new RuntimeException(sprintf('No store "%s".', $kind)),
		]);
		
		return (new Pages())->store();
	}
}
