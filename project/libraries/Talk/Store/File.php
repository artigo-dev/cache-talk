<?php
declare(strict_types=1);

namespace Talk\Store;

use Ovos\ArrayObject;
use Ovos\Cache\MemoLock;
use Ovos\Cache\Store\KeyValue\Tags;
use Override;

use function array_filter;
use function array_intersect;
use function array_merge;
use function array_unique;
use function array_values;
use function bin2hex;
use function count;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function is_array;
use function is_dir;
use function is_file;
use function mkdir;
use function random_bytes;
use function rename;
use function rmdir;
use function rtrim;
use function serialize;
use function sha1;
use function time;
use function unlink;
use function unserialize;

use const FILE_APPEND;
use const LOCK_EX;

/**
 * A cache in files, as a site on one server keeps it (stations 2 to 4): a
 * file per cached page, a file per tag listing its pages. Forgetting a tag
 * reads its list and deletes every file on it - the more pages carry the
 * tag, the longer it takes.
 *
 * Every server has its own directory (store_options.dir): an edit forgotten
 * on one server is still cached on the other. No lock (NoLock: every request
 * that misses computes) and no invalidation guard, as a plain file cache.
 *
 * Built by the cache service as any configured store: `cache.persistent.store:
 * \Talk\Store\File` (the connections it is handed are not used).
 */
class File extends Tags
{
	protected const string DIR = '/tmp/talk/files';
	
	protected string $dir;
	
	public function __construct(
		mixed $connection = null,
		mixed $queueConnection = null,
		?string $prefix = null,
		?ArrayObject $config = null,
	)
	{
		parent::__construct($prefix, $config);
		
		$this->dir = rtrim((string)($config?->getPath(['store_options', 'dir']) ?? static::DIR), '/');
		// no guard: a file cache remembers no edit
		$this->setInvalidationWindowMs(0);
	}
	
	#[Override]
	public function getMemoLock(): MemoLock
	{
		if($this->memoLock === null)
		{
			$this->memoLock = new NoLock($this->prefixer->getPrefix());
		}
		
		return $this->memoLock;
	}
	
	#[Override]
	public function itemId(
		string $key,
	): string
	{
		return $this->prefixer
			->prefix($key, $this->getGroup());
	}
	
	#[Override]
	protected function fetch(
		string $id,
	): mixed
	{
		$record = $this->read($id);
		if($record === null)
		{
			$this->rememberMiss($id, '');
			
			return null;
		}
		
		$value = $this->serializer->unserialize($this->compressor->decompress($record['data']));
		
		return $this->found($id, $value, '');
	}
	
	#[Override]
	public function set(
		string $key,
		mixed $value,
		int $ttl = 0,
		array $tags = [],
	): bool
	{
		$id = $this->itemId($key);
		if($value === null)
		{
			return $this->delete($key);
		}
		
		$data = $this->compressor
			->compress($this->serializer->serialize($value));
		$written = $this->write($this->itemPath($id), serialize([
			'expires' => $ttl > 0 ? time() + $ttl : 0,
			'tags' => array_values(array_unique($tags)),
			'data' => $data,
		]));
		
		// the tag lists: every page that carries the tag, one id a line
		foreach(array_unique($tags) as $tag)
		{
			$this->ensureDir('tags');
			file_put_contents($this->tagPath($tag), $id . "\n", FILE_APPEND | LOCK_EX);
		}
		
		return $written;
	}
	
	/**
	 * Every page on the tags' lists goes - all of them, or (MATCHING_ALL)
	 * those on every list - and then the lists
	 */
	#[Override]
	public function invalidateTags(
		array $tags,
		string $matching = self::MATCHING_ANY,
		bool $hard = false,
	): bool
	{
		if($tags === [])
		{
			return true;
		}
		
		$lists = [];
		foreach(array_unique($tags) as $tag)
		{
			$lists[$tag] = $this->tagList($tag);
		}
		
		$ids = $matching === self::MATCHING_ALL
			? array_intersect(...array_values($lists))
			: array_merge(...array_values($lists));
		foreach(array_unique($ids) as $id)
		{
			$this->unlink($this->itemPath($id));
		}
		
		foreach($lists as $tag => $list)
		{
			if($matching === self::MATCHING_ANY || count($list) === count(array_intersect($list, $ids)))
			{
				$this->unlink($this->tagPath($tag));
			}
		}
		
		return true;
	}
	
	public function delete(
		string $key,
	): bool
	{
		$id = $this->itemId($key);
		unset($this->misses[$id]);
		$this->unlink($this->itemPath($id));
		
		return true;
	}
	
	public function clear(): bool
	{
		foreach(['items', 'tags'] as $kind)
		{
			foreach(glob($this->dir . '/' . $kind . '/*') ?: [] as $path)
			{
				$this->unlink($path);
			}
		}
		
		return true;
	}
	
	/**
	 * The file the page $key is kept in (the demos that show the disk)
	 */
	public function itemFile(
		string $key,
	): string
	{
		return $this->itemPath($this->itemId($key));
	}
	
	/**
	 * The file listing the pages that carry $tag
	 */
	public function tagFile(
		string $tag,
	): string
	{
		return $this->tagPath($tag);
	}
	
	/**
	 * The record of $id, or null: none, unreadable, or past its expiry
	 *
	 * @return array{expires: int, tags: list<string>, data: string}|null
	 */
	protected function read(
		string $id,
	): ?array
	{
		$path = $this->itemPath($id);
		$contents = is_file($path) ? @file_get_contents($path) : false;
		if($contents === false)
		{
			return null;
		}
		
		$record = @unserialize($contents, ['allowed_classes' => false]);
		if(is_array($record) === false)
		{
			return null;
		}
		
		if($record['expires'] > 0 && $record['expires'] <= time())
		{
			$this->unlink($path);
			
			return null;
		}
		
		return $record;
	}
	
	/**
	 * Whole or not at all: a reader never sees half a file
	 */
	protected function write(
		string $path,
		string $contents,
	): bool
	{
		$this->ensureDir('items');
		$temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
		if(file_put_contents($temporary, $contents) === false)
		{
			return false;
		}
		
		return rename($temporary, $path);
	}
	
	/**
	 * @return list<string> the ids on $tag's list
	 */
	protected function tagList(
		string $tag,
	): array
	{
		$path = $this->tagPath($tag);
		$contents = is_file($path) ? @file_get_contents($path) : false;
		
		return $contents === false || $contents === ''
			? []
			: array_values(array_filter(explode("\n", $contents), static fn(string $id): bool => $id !== ''));
	}
	
	protected function itemPath(
		string $id,
	): string
	{
		return $this->dir . '/items/' . sha1($id);
	}
	
	protected function tagPath(
		string $tag,
	): string
	{
		return $this->dir . '/tags/' . sha1($this->prefixer->getPrefix() . $tag);
	}
	
	protected function ensureDir(
		string $kind,
	): void
	{
		$path = $this->dir . '/' . $kind;
		if(is_dir($path) === false)
		{
			@mkdir($path, 0777, true);
		}
	}
	
	protected function unlink(
		string $path,
	): void
	{
		if(is_file($path))
		{
			@unlink($path);
		}
	}
	
	/**
	 * Removes the store's directory, the files in it first (a scenario's run
	 * cleans up after itself)
	 */
	public function remove(): void
	{
		$this->clear();
		foreach(['items', 'tags', ''] as $kind)
		{
			$path = rtrim($this->dir . '/' . $kind, '/');
			if(is_dir($path))
			{
				@rmdir($path);
			}
		}
	}
}
