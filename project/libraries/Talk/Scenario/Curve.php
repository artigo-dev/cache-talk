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
use Talk\Store\File;
use Talk\Store\RedisClassic;

use function array_chunk;
use function array_keys;
use function array_map;
use function array_sum;
use function explode;
use function glob;
use function hrtime;
use function is_dir;
use function max;
use function min;
use function random_int;
use function range;
use function rmdir;
use function round;
use function sprintf;
use function str_repeat;
use function unlink;

/**
 * Forgetting one tag, by the number of cached pages that carry it: 10, 100,
 * ... a million, in every store of the road.
 *
 * Per store a worker of its own, Redis emptied first: one fill with a tag
 * per size on pages of its own (tag-10 on 10 pages, tag-100 on 100, ...),
 * then each tag forgotten in one call, smallest first, timed, and 20 of its
 * pages sampled: gone, or outdated.
 *
 * - file: Talk\Store\File, capped at FILE_MAX
 * - classic: our classic backend, Talk\Store\RedisClassic (SUNION, then one
 *   MULTI with a DEL of every page)
 * - redis, redisearch, versioned: php-library's stores
 */
class Curve extends Scenario
{
	public const array LABELS = [
		'file' => 'File cache',
		'classic' => 'Classic Redis',
		'redis' => 'Store\Redis',
		'redisearch' => 'RediSearch',
		'versioned' => 'RedisVersioned',
	];
	
	/**
	 * The store each php-library one is, for `cache.persistent.store`
	 */
	public const array STORES = [
		'classic' => RedisClassic::class,
		'redis' => 'Redis',
		'redisearch' => 'Redisearch',
		'versioned' => 'RedisVersioned',
	];
	
	protected const int FILE_MAX = 100_000;
	
	protected const string DIR = '/tmp/talk/curve';
	
	public function name(): string
	{
		return 'curve';
	}
	
	public function parameters(): array
	{
		return [
			'max' => new Parameter(live: 100_000, record: 1_000_000, min: 1_000, max: 1_000_000, label: 'the largest tag'),
			'stores' => new Parameter(live: 'file,classic,redis,versioned', record: 'file,classic,redis,redisearch,versioned', options: ['file', 'classic', 'redis', 'redisearch', 'versioned'], label: 'stores'),
		];
	}
	
	public function run(
		Sink $sink,
		array $params,
	): void
	{
		$redis = Config::redis(600.0);
		$max = (int)$params['max'];
		
		foreach(explode(',', (string)$params['stores']) as $kind)
		{
			Env::flush($redis);
			$run = $this->runId();
			$sizes = static::sizes($kind === 'file' ? min($max, static::FILE_MAX) : $max);
			$sink->emit([
				'type' => 'segment',
				't' => 0,
				'store' => $kind,
				'label' => static::LABELS[$kind],
				'sizes' => $sizes,
			]);
			
			$events = new Buffer($sink);
			try
			{
				(new Fleet($events, $redis, 900))->run($this->name(), $run, 1, [
					'store' => $kind,
					'sizes' => $sizes,
				], [
					'store' => $kind,
				]);
			}
			finally
			{
				static::removeDir(static::DIR . '/' . $run);
			}
			
			$this->summarise($sink, $events, $kind);
		}
	}
	
	public function work(
		array $job,
	): void
	{
		$kind = (string)$job['store'];
		$sizes = array_map('intval', (array)$job['sizes']);
		$redis = Config::redis(600.0);
		$store = static::store($kind, static::DIR . '/' . (string)$job['run']);
		Barrier::arrive($redis, (string)$job['run'], 900);
		
		$started = hrtime(true);
		$this->fill($redis, $store, $sizes);
		Events::emit('filled', [
			'pages' => array_sum($sizes),
			'ms' => round((hrtime(true) - $started) / 1e6, 3),
		]);
		
		// a first call of its own: the timed ones start warm (a connection, a script loaded)
		$store->invalidateTags(['none']);
		
		foreach($sizes as $size)
		{
			$started = hrtime(true);
			$store->invalidateTags([static::tag($size)]);
			$ms = (hrtime(true) - $started) / 1e6;
			Events::emit('forgotten', [
				'pages' => $size,
				'ms' => round($ms, 4),
				'gone' => static::sampleGone($redis, $store, $size),
			]);
		}
	}
	
	/**
	 * Every tag's pages, in the store's own layout
	 *
	 * @param list<int> $sizes
	 */
	protected function fill(
		Redis $redis,
		KeyValue $store,
		array $sizes,
	): void
	{
		$value = str_repeat('x', 64);
		
		// RediSearch's index is created on first use: before the first page, or it fills in the background
		if($store instanceof File === false && $store instanceof RedisClassic === false)
		{
			$store->setMany(['warm-up' => $value], 60, ['warm-up']);
			$store->invalidateTags(['warm-up']);
		}
		
		foreach($sizes as $size)
		{
			$tag = static::tag($size);
			foreach(array_chunk(range(0, $size - 1), $store instanceof RedisClassic ? 10_000 : 1_000) as $chunk)
			{
				// the classic backend's own writes, through a pipeline (save() would take two round trips a page)
				if($store instanceof RedisClassic)
				{
					$pipe = $redis->multi(Redis::PIPELINE);
					$store->queueNew($pipe, array_map(static fn(int $i): string => static::page($size, $i), $chunk), $value, 3600, $tag);
					$pipe->exec();
					
					continue;
				}
				
				if($store instanceof File)
				{
					foreach($chunk as $i)
					{
						$store->set(static::page($size, $i), $value, 3600, [$tag]);
					}
					
					continue;
				}
				
				$batch = [];
				foreach($chunk as $i)
				{
					$batch[static::page($size, $i)] = $value;
				}
				$store->setMany($batch, 3600, [$tag]);
			}
		}
	}
	
	/**
	 * What the store came to, and the claims it makes
	 */
	protected function summarise(
		Sink $sink,
		Buffer $events,
		string $kind,
	): void
	{
		$times = [];
		$gone = true;
		foreach($events->events() as $event)
		{
			if($event['type'] === 'forgotten')
			{
				$times[(int)$event['pages']] = (float)$event['ms'];
				$gone = $gone && (bool)$event['gone'];
			}
		}
		
		$sink->emit([
			'type' => 'summary',
			't' => Events::now(),
			'store' => $kind,
			'times' => $times,
		]);
		
		$smallest = $times === [] ? 0 : min(array_keys($times));
		$largest = $times === [] ? 0 : max(array_keys($times));
		$this->check($sink, $times !== [] && $gone, sprintf('%s: every sampled page is gone, or outdated, at every size', static::LABELS[$kind]));
		if($times === [])
		{
			return;
		}
		
		$ratio = $times[$largest] / max(0.001, $times[$smallest]);
		$this->check($sink, $kind === 'versioned' ? $ratio < 10 : $ratio > 30, $kind === 'versioned'
			? sprintf('%s: a flat line (%s for %d pages, %s for %d)', static::LABELS[$kind], static::duration($times[$smallest]), $smallest, static::duration($times[$largest]), $largest)
			: sprintf('%s: the time grows with the pages (%s for %d, %s for %d)', static::LABELS[$kind], static::duration($times[$smallest]), $smallest, static::duration($times[$largest]), $largest));
	}
	
	/**
	 * Whether 20 of the tag's pages are gone, or outdated
	 */
	protected static function sampleGone(
		Redis $redis,
		KeyValue $store,
		int $size,
	): bool
	{
		for($n = 0; $n < 20; $n++)
		{
			$page = static::page($size, random_int(0, $size - 1));
			$there = $store->get($page, static fn(): string => 'rebuilt') !== 'rebuilt';
			if($there)
			{
				return false;
			}
		}
		
		return true;
	}
	
	/**
	 * @return list<int> 10, 100, ... up to $max
	 */
	protected static function sizes(
		int $max,
	): array
	{
		$sizes = [];
		for($size = 10; $size <= $max; $size*= 10)
		{
			$sizes[] = $size;
		}
		
		return $sizes;
	}
	
	protected static function tag(
		int $size,
	): string
	{
		return 'tag-' . $size;
	}
	
	protected static function page(
		int $size,
		int $i,
	): string
	{
		return 'p' . $size . '-' . $i;
	}
	
	protected static function duration(
		float $ms,
	): string
	{
		return $ms >= 1000 ? sprintf('%.2f s', $ms / 1000) : sprintf('%.2f ms', $ms);
	}
	
	protected static function store(
		string $kind,
		string $dir,
	): KeyValue
	{
		Config::patch($kind === 'file'
			? [
				'store' => File::class,
				'store_options' => [
					'dir' => $dir,
				],
			]
			: [
				'store' => static::STORES[$kind] ?? throw new RuntimeException(sprintf('No store "%s".', $kind)),
			]);
		
		return (new Pages())->store();
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
