<?php
declare(strict_types=1);

namespace Talk\Scenario;

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

use function array_diff;
use function array_filter;
use function array_keys;
use function basename;
use function count;
use function glob;
use function in_array;
use function is_dir;
use function rmdir;
use function sprintf;
use function unlink;

/**
 * Tags in a file cache, on disk: a file per cached page, a file per tag
 * listing its pages. A visitor opens every page of a small site (each one
 * built and saved, with the tags of what it is built from), the editor saves
 * article 42, and a visitor opens every page again.
 *
 * One worker; the events name the real files, and what the disk lost after
 * the edit (listed before and after).
 */
class Tags extends Scenario
{
	/**
	 * The site: every page, with the tags of what it is built from
	 */
	public const array PAGES = [
		'front-page' => ['article-42', 'category-7'],
		'article-42' => ['article-42'],
		'article-7' => ['article-7', 'category-7'],
		'news' => ['category-7', 'article-42', 'article-7'],
		'contact' => ['contact'],
	];
	
	protected const string EDITED = 'article-42';
	
	protected const string DIR = '/tmp/talk/tags';
	
	public function name(): string
	{
		return 'tags';
	}
	
	public function parameters(): array
	{
		return [];
	}
	
	public function run(
		Sink $sink,
		array $params,
	): void
	{
		$redis = Config::redis();
		Env::flush($redis);
		$run = $this->runId();
		$sink->emit([
			'type' => 'segment',
			't' => 0,
			'mode' => 'file',
			'label' => 'File cache (Talk\Store\File)',
			'pages' => static::PAGES,
			'edited' => static::EDITED,
		]);
		
		$events = new Buffer($sink);
		try
		{
			(new Fleet($events, $redis))->run($this->name(), $run, 1, [], [
				'mode' => 'file',
			]);
		}
		finally
		{
			static::removeDir(static::DIR . '/' . $run);
		}
		
		$this->summarise($sink, $events);
	}
	
	public function work(
		array $job,
	): void
	{
		$dir = static::DIR . '/' . (string)$job['run'];
		Config::patch([
			'store' => File::class,
			'store_options' => [
				'dir' => $dir,
			],
		]);
		/** @var File $store */
		$store = (new Pages())->store();
		Barrier::arrive(Config::redis(), (string)$job['run']);
		
		$open = static function(string $key, int $round) use ($store): void
		{
			$built = false;
			$store->get($key, static function() use ($key, &$built): string
			{
				$built = true;
				
				return 'the page ' . $key;
			}, ttl: 3600, tags: static::PAGES[$key]);
			
			Events::emit('read', [
				'round' => $round,
				'key' => $key,
				'hit' => $built === false,
				'file' => basename($store->itemFile($key)),
			]);
			if($built)
			{
				$lists = [];
				foreach(static::PAGES[$key] as $tag)
				{
					$lists[$tag] = basename($store->tagFile($tag));
				}
				Events::emit('saved', [
					'round' => $round,
					'key' => $key,
					'tags' => static::PAGES[$key],
					'lists' => $lists,
				]);
			}
		};
		
		foreach(array_keys(static::PAGES) as $key)
		{
			$open($key, 1);
		}
		
		// the editor saves article 42: what the disk holds before, and after
		$items = static::files($dir . '/items');
		$tags = static::files($dir . '/tags');
		Events::emit('edit', [
			'tag' => static::EDITED,
			'list' => basename($store->tagFile(static::EDITED)),
		]);
		$store->invalidateTags([static::EDITED]);
		$itemsGone = array_diff($items, static::files($dir . '/items'));
		$tagsGone = array_diff($tags, static::files($dir . '/tags'));
		foreach(array_keys(static::PAGES) as $key)
		{
			if(in_array(basename($store->itemFile($key)), $itemsGone, true))
			{
				Events::emit('deleted', [
					'key' => $key,
					'file' => basename($store->itemFile($key)),
				]);
			}
		}
		foreach(static::tagsOf() as $tag)
		{
			if(in_array(basename($store->tagFile($tag)), $tagsGone, true))
			{
				Events::emit('list.deleted', [
					'tag' => $tag,
					'file' => basename($store->tagFile($tag)),
				]);
			}
		}
		
		foreach(array_keys(static::PAGES) as $key)
		{
			$open($key, 2);
		}
	}
	
	/**
	 * What the run came to, and the claims it makes
	 */
	protected function summarise(
		Sink $sink,
		Buffer $events,
	): void
	{
		$tagged = array_keys(array_filter(static::PAGES, static fn(array $tags): bool => in_array(static::EDITED, $tags, true)));
		$deleted = [];
		$rebuilt = [];
		$cached = [];
		foreach($events->events() as $event)
		{
			if($event['type'] === 'deleted')
			{
				$deleted[] = $event['key'];
			}
			if($event['type'] === 'read' && $event['round'] === 2 && $event['hit'])
			{
				$cached[] = $event['key'];
			}
			if($event['type'] === 'read' && $event['round'] === 2 && $event['hit'] === false)
			{
				$rebuilt[] = $event['key'];
			}
		}
		
		$sink->emit([
			'type' => 'summary',
			't' => Events::now(),
			'mode' => 'file',
			'deleted' => $deleted,
			'rebuilt' => $rebuilt,
			'cached' => $cached,
		]);
		
		$this->check($sink, $deleted === $tagged, sprintf('the edit deletes exactly the pages tagged %s (%d of %d)', static::EDITED, count($deleted), count(static::PAGES)));
		$this->check($sink, $rebuilt === $tagged && count($cached) === count(static::PAGES) - count($tagged), sprintf('the next visitors rebuild those %d and find the other %d cached', count($rebuilt), count($cached)));
	}
	
	/**
	 * @return list<string> every tag of the site, once
	 */
	protected static function tagsOf(): array
	{
		$tags = [];
		foreach(static::PAGES as $pageTags)
		{
			foreach($pageTags as $tag)
			{
				$tags[$tag] = $tag;
			}
		}
		
		return array_keys($tags);
	}
	
	/**
	 * @return list<string> the names of the files in $dir
	 */
	protected static function files(
		string $dir,
	): array
	{
		$names = [];
		foreach(glob($dir . '/*') ?: [] as $path)
		{
			$names[] = basename($path);
		}
		
		return $names;
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
