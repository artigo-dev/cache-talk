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
use Talk\Fleet as Workers;
use Talk\Origin;
use Talk\Parameter;
use Talk\Scenario;
use Talk\Sink;
use Talk\Sink\Buffer;
use Talk\Store\File;
use Talk\Store\RedisClassic;

use function explode;
use function glob;
use function is_dir;
use function rmdir;
use function sprintf;
use function unlink;
use function usleep;

/**
 * Two servers behind a load balancer that takes turns, and an editor who
 * saves on one of them. Does the other one hear of it?
 *
 * - file: every server keeps its cached pages in files of its own
 *   (Talk\Store\File, a directory per server)
 * - redis: both keep them in one Redis, our classic backend
 *   (Talk\Store\RedisClassic): the design of station 5
 *
 * A fleet per mode: worker 0 is the editor, on server A; the visitors, a
 * process each, arrive STEP_MS apart from FIRST_MS and go to A and B in
 * turn (odd to A, even to B), each built as its server. The article is a
 * version number in Redis, the database both servers share: a build reads
 * it, takes BUILD_MS, and the page carries the version it read.
 */
class Fleet extends Scenario
{
	public const array LABELS = [
		'file' => 'Own files (a directory per server)',
		'redis' => 'One Redis (a classic backend)',
	];
	
	protected const string KEY = 'article-42';
	
	protected const string TAG = 'article-42';
	
	protected const int FIRST_MS = 50;
	
	protected const int STEP_MS = 100;
	
	/**
	 * When the editor saves, on server A
	 */
	protected const int EDIT_MS = 600;
	
	protected const int BUILD_MS = 50;
	
	protected const string DIR = '/tmp/talk/fleet';
	
	public function name(): string
	{
		return 'fleet';
	}
	
	public function parameters(): array
	{
		return [
			'visitors' => new Parameter(live: 14, min: 8, max: 40, label: 'visitors'),
			'modes' => new Parameter(live: 'file,redis', options: ['file', 'redis'], label: 'modes'),
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
			$redis->set(static::versionKey($run), '1');
			$sink->emit([
				'type' => 'segment',
				't' => 0,
				'mode' => $mode,
				'label' => static::LABELS[$mode],
				'visitors' => $visitors,
				'editAt' => static::EDIT_MS,
				'buildMs' => static::BUILD_MS,
			]);
			
			$events = new Buffer($sink);
			try
			{
				(new Workers($events, $redis))->run($this->name(), $run, 1 + $visitors, [
					'mode' => $mode,
				], [
					'mode' => $mode,
				]);
			}
			finally
			{
				static::removeDir(static::DIR . '/' . $run);
			}
			
			$this->summarise($sink, $events, $mode);
		}
	}
	
	public function work(
		array $job,
	): void
	{
		$mode = (string)$job['mode'];
		$index = (int)$job['index'];
		$run = (string)$job['run'];
		$server = $index === 0 || $index % 2 === 1 ? 'A' : 'B';
		$redis = Config::redis();
		Origin::configure($redis, $run, $index, static::BUILD_MS);
		$store = static::store($mode, $run, $server);
		
		Barrier::arrive($redis, $run);
		
		if($index === 0)
		{
			static::sleepUntil(static::EDIT_MS);
			$redis->incr(static::versionKey($run));
			$store->invalidateTags([static::TAG]);
			Events::emit('edit', [
				'server' => $server,
			]);
			
			return;
		}
		
		static::sleepUntil(static::FIRST_MS + ($index - 1) * static::STEP_MS);
		Events::emit('request', [
			'w' => $index,
			'server' => $server,
		]);
		$version = static::versionKey($run);
		$page = $store->get(static::KEY, static function() use ($redis, $version): array
		{
			// the article as it is when the build starts
			$article = (int)$redis->get($version);
			Origin::work('article 42');
			
			return [
				'version' => $article,
			];
		}, ttl: 3600, tags: [static::TAG]);
		Events::emit('respond', [
			'w' => $index,
			'server' => $server,
			'version' => (int)$page['version'],
			'latest' => (int)$redis->get($version),
		]);
	}
	
	/**
	 * What the segment came to, and the claims it makes
	 */
	protected function summarise(
		Sink $sink,
		Buffer $events,
		string $mode,
	): void
	{
		$editAt = null;
		$requests = [];
		$after = ['A' => 0, 'B' => 0];
		$old = ['A' => 0, 'B' => 0];
		foreach($events->events() as $event)
		{
			match($event['type'])
			{
				'edit' => $editAt = (float)$event['t'],
				'request' => $requests[$event['w']] = (float)$event['t'],
				default => null,
			};
			if($event['type'] === 'respond' && $editAt !== null && ($requests[$event['w']] ?? 0.0) > $editAt)
			{
				$after[$event['server']]++;
				$old[$event['server']]+= $event['version'] < $event['latest'] ? 1 : 0;
			}
		}
		
		$sink->emit([
			'type' => 'summary',
			't' => Events::now(),
			'mode' => $mode,
			'after' => $after['A'] + $after['B'],
			'old' => $old['A'] + $old['B'],
		]);
		
		if($mode === 'file')
		{
			$this->check($sink, $after['B'] > 0 && $old['B'] === $after['B'], sprintf('after the edit, every visitor on server B gets the old article (%d of %d)', $old['B'], $after['B']));
			$this->check($sink, $after['A'] > 0 && $old['A'] === 0, sprintf('after the edit, every visitor on server A gets the new one (%d old of %d)', $old['A'], $after['A']));
		}
		else
		{
			$this->check($sink, $after['A'] + $after['B'] > 0 && $old['A'] + $old['B'] === 0, sprintf('after the edit, nobody gets the old article (%d of %d)', $old['A'] + $old['B'], $after['A'] + $after['B']));
		}
	}
	
	/**
	 * The server's store: files of its own, or the one Redis
	 */
	protected static function store(
		string $mode,
		string $run,
		string $server,
	): KeyValue
	{
		Config::patch($mode === 'file'
			? [
				'store' => File::class,
				'store_options' => [
					'dir' => static::DIR . '/' . $run . '/' . $server,
				],
			]
			: [
				'store' => RedisClassic::class,
			]);
		
		return (new Pages())->store();
	}
	
	protected static function versionKey(
		string $run,
	): string
	{
		return 'talk:fleet:version:' . $run;
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
	
	/**
	 * The run's directories: every server's files, then the directories
	 */
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
