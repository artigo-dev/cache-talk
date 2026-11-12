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
 * Per number of pages a worker of its own: it fills Talk\Store\File with the
 * pages, all carrying one tag (not drawn), forgets the tag in one
 * invalidateTags() call and times it, then checks the pages are gone. The
 * files are on tmpfs, in memory: a real disk is slower.
 */
class FileBan extends Scenario
{
	protected const string TAG = 'article-42';
	
	protected const string DIR = '/tmp/talk/file-ban';
	
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
		$sink->emit([
			'type' => 'segment',
			't' => 0,
			'mode' => 'file',
			'label' => 'File cache (Talk\Store\File · tmpfs)',
			'pages' => $counts,
		]);
		
		$events = new Buffer($sink);
		foreach($counts as $pages)
		{
			Env::flush($redis);
			$run = $this->runId();
			try
			{
				(new Fleet($events, $redis, 600))->run($this->name(), $run, 1, [
					'pages' => $pages,
				], [
					'mode' => 'file',
				]);
			}
			finally
			{
				static::removeDir(static::DIR . '/' . $run);
			}
		}
		
		$this->summarise($sink, $events);
	}
	
	public function work(
		array $job,
	): void
	{
		$pages = (int)$job['pages'];
		$dir = static::DIR . '/' . (string)$job['run'];
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
		$left = 0;
		foreach($events->events() as $event)
		{
			if($event['type'] === 'forgotten')
			{
				$times[(int)$event['pages']] = (float)$event['ms'];
				$left+= (int)$event['left'];
			}
		}
		
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
			'mode' => 'file',
			'times' => $times,
			'usPerPage' => round($median, 3),
		]);
		
		$this->check($sink, $times !== [] && $left === 0, sprintf('every cached page is gone after every ban (%d files left)', $left));
		$smallest = $times === [] ? 0 : min(array_keys($times));
		$largest = $times === [] ? 0 : max(array_keys($times));
		$this->check($sink, $largest > $smallest && $times[$largest] > $times[$smallest] * ($largest / $smallest) / 3, sprintf('the time grows with the pages: %s for %d, %s for %d', static::duration($times[$smallest] ?? 0.0), $smallest, static::duration($times[$largest] ?? 0.0), $largest));
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
