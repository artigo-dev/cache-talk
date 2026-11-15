<?php
declare(strict_types=1);

namespace Talk\Scenario;

use Ovos\Cache\Store\KeyValue;
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

use function array_keys;
use function array_map;
use function count;
use function explode;
use function glob;
use function hrtime;
use function is_dir;
use function max;
use function min;
use function rmdir;
use function round;
use function sort;
use function sprintf;
use function str_repeat;
use function unlink;

/**
 * Forgetting one tag in a file cache, as the cached pages grow: the tag's
 * list is read, and every file on it is deleted - one at a time.
 *
 * Per number of pages and per medium a worker of its own: it fills
 * Talk\Store\File with the pages, all carrying one tag (not drawn), forgets
 * the tag in one invalidateTags() call and times it, then checks the pages
 * are gone. Two media, the same pages on both, in turn: tmpfs, in memory
 * (the best case), and a disk - compose.yaml's talk-disk volume, the Docker
 * VM's ext4, whose virtual disk is on the laptop's SSD.
 */
class FileBan extends Scenario
{
	protected const string TAG = 'article-42';
	
	/**
	 * Where each medium keeps its files
	 */
	protected const array MEDIA = [
		'memory' => '/tmp/talk/file-ban',
		'disk' => '/var/talk-disk/file-ban',
	];
	
	/**
	 * What the deck calls each medium: its name, then (after " · ") the lines under it
	 */
	protected const array LABELS = [
		'memory' => 'in memory (tmpfs) · best case scenario',
		'disk' => 'on disk (ext4) · the laptop\'s SSD',
	];
	
	public function name(): string
	{
		return 'file-ban';
	}
	
	public function parameters(): array
	{
		return [
			'pages' => new Parameter(live: '1000,10000,25000,50000', record: '10000,100000,200000,300000,400000,500000', label: 'cached pages'),
		];
	}
	
	public function run(
		Sink $sink,
		array $params,
	): void
	{
		$redis = Config::redis();
		$counts = array_map('intval', explode(',', (string)$params['pages']));
		sort($counts);
		foreach(static::MEDIA as $medium => $dir)
		{
			$sink->emit([
				'type' => 'segment',
				't' => 0,
				'mode' => $medium,
				'label' => static::LABELS[$medium],
				'pages' => $counts,
			]);
		}
		
		// every number of pages on both media in turn, so whatever drifts during the run drifts for both
		$events = new Buffer($sink);
		foreach($counts as $pages)
		{
			foreach(static::MEDIA as $medium => $dir)
			{
				Env::flush($redis);
				$run = $this->runId();
				try
				{
					(new Fleet($events, $redis, 600))->run($this->name(), $run, 1, [
						'pages' => $pages,
						'dir' => $dir,
					], [
						'mode' => $medium,
					]);
				}
				finally
				{
					static::removeDir($dir . '/' . $run);
				}
			}
		}
		
		$this->summarise($sink, $events);
	}
	
	public function work(
		array $job,
	): void
	{
		$pages = (int)$job['pages'];
		$dir = (string)$job['dir'] . '/' . (string)$job['run'];
		$store = static::store($dir);
		Barrier::arrive(Config::redis(), (string)$job['run']);
		
		$value = str_repeat('x', 64);
		$started = hrtime(true);
		for($i = 0; $i < $pages; $i++)
		{
			$store->set('page-' . $i, $value, 3600, [static::TAG]);
		}
		Events::emit('filled', [
			'pages' => $pages,
			'ms' => round((hrtime(true) - $started) / 1e6, 3),
		]);
		
		Events::emit('ban.start', [
			'pages' => $pages,
		]);
		$started = hrtime(true);
		$store->invalidateTags([static::TAG]);
		$ms = (hrtime(true) - $started) / 1e6;
		Events::emit('forgotten', [
			'pages' => $pages,
			'ms' => round($ms, 3),
			'left' => count(glob($dir . '/items/*') ?: []),
		]);
	}
	
	/**
	 * What the run came to, and the claims it makes
	 */
	protected function summarise(
		Sink $sink,
		Buffer $events,
	): void
	{
		$times = [];
		$left = [];
		foreach($events->events() as $event)
		{
			if($event['type'] === 'forgotten')
			{
				$medium = (string)$event['mode'];
				$times[$medium][(int)$event['pages']] = (float)$event['ms'];
				$left[$medium] = ($left[$medium] ?? 0) + (int)$event['left'];
			}
		}
		
		foreach(array_keys(static::MEDIA) as $medium)
		{
			$this->summariseMedium($sink, $medium, $times[$medium] ?? [], $left[$medium] ?? 0);
		}
	}
	
	/**
	 * One medium's times: the median time per page, and the claims
	 *
	 * @param array<int, float> $times the time to forget the tag, by the number of pages
	 */
	protected function summariseMedium(
		Sink $sink,
		string $medium,
		array $times,
		int $left,
	): void
	{
		$perPage = [];
		foreach($times as $pages => $ms)
		{
			$perPage[] = $ms * 1000 / $pages;
		}
		sort($perPage);
		$median = $perPage === [] ? 0.0 : $perPage[(int)(count($perPage) / 2)];
		
		$sink->emit([
			'type' => 'summary',
			't' => Events::now(),
			'mode' => $medium,
			'times' => $times,
			'usPerPage' => round($median, 3),
		]);
		
		$this->check($sink, $times !== [] && $left === 0, sprintf('%s: every cached page is gone after every ban (%d files left)', $medium, $left));
		$smallest = $times === [] ? 0 : min(array_keys($times));
		$largest = $times === [] ? 0 : max(array_keys($times));
		$this->check($sink, $largest > $smallest && $times[$largest] > $times[$smallest] * ($largest / $smallest) / 3, sprintf('%s: the time grows with the pages: %s for %d, %s for %d', $medium, static::duration($times[$smallest] ?? 0.0), $smallest, static::duration($times[$largest] ?? 0.0), $largest));
	}
	
	protected static function duration(
		float $ms,
	): string
	{
		return $ms >= 1000 ? sprintf('%.2f s', $ms / 1000) : sprintf('%.1f ms', $ms);
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
