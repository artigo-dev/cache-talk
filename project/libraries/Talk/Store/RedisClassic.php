<?php
declare(strict_types=1);

namespace Talk\Store;

use Closure;
use Ovos\ArrayObject;
use Ovos\Cache\MemoLock;
use Ovos\Cache\Store\KeyValue\Tags;
use Override;
use Redis;
use Talk\Config;

use function array_diff;
use function array_map;
use function array_pop;
use function count;
use function explode;
use function gzcompress;
use function gzuncompress;
use function implode;
use function is_array;
use function is_string;
use function number_format;
use function sprintf;
use function strlen;
use function substr;
use function time;

/**
 * Our classic Redis backend, as our sites ran it for years: the CMS's
 * CMS\Cache\Backend\Redis (an older fork of Colin Mollenhour's design),
 * ported command for command onto php-library's KeyValue\Tags so the demos
 * compare it fairly with php-library's stores.
 *
 * - a hash per cached page, <prefix>:items:<id>: data, tags (comma
 *   separated), mtime, infinite; a set per tag, <prefix>:tags_items:<tag>,
 *   of the pages that carry it; and <prefix>:tags, the set of every tag
 * - save: HGET of the page's old tags, then one MULTI: HMSET, EXPIRE, SADD
 *   of the new tags and of the id to each new tag's set, SREM of the id from
 *   each dropped tag's set
 * - forgetting tags: SUNION of their sets (any of them; SINTER for all),
 *   then one MULTI with a single DEL of every page, the DEL of the tag sets
 *   and their SREM from the set of tags (any only)
 * - clear: FLUSHDB; compression: gzip level 1 from 2 KB, behind a prefix
 *
 * No lock (NoLock: every request that misses computes) and no invalidation
 * guard: the backend had neither. The quirks are kept, each marked where it
 * is.
 */
class RedisClassic extends Tags
{
	/**
	 * What a compressed value starts with after its method's two letters
	 */
	public const string COMPRESS_PREFIX = ":\x1f\x8b";
	
	protected Redis $redis;
	
	protected string $keyPrefix;
	
	protected string $tagIdsPrefix;
	
	protected string $tagsSet;
	
	protected bool $compression;
	
	protected int $compressionThreshold;
	
	/**
	 * Told of each step a forget takes (see observe()): the backend had no such hook
	 */
	protected ?Closure $observer = null;
	
	/**
	 * Built by the cache service as any configured store (`cache.persistent.
	 * store: \Talk\Store\RedisClassic`); it opens its own connection, as the
	 * backend was handed one of its own
	 */
	public function __construct(
		mixed $connection = null,
		mixed $queueConnection = null,
		?string $prefix = null,
		?ArrayObject $config = null,
	)
	{
		parent::__construct($prefix, $config);
		
		$option = static fn(string $name, mixed $default): mixed => $config?->getPath(['store_options', $name]) ?? $default;
		$redisPrefix = (string)$option('prefix', 'cms');
		$this->keyPrefix = $redisPrefix . ':items:';
		$this->tagIdsPrefix = $redisPrefix . ':tags_items:';
		$this->tagsSet = $redisPrefix . ':tags';
		$this->compression = (bool)$option('compression', true);
		$this->compressionThreshold = (int)$option('compression_threshold', 2048);
		
		// a long read timeout: one DEL of a million pages holds the reply for seconds
		$this->redis = Config::redis((float)$option('read_timeout', 600.0));
		// no guard: the backend remembered no edit
		$this->setInvalidationWindowMs(0);
	}
	
	#[Override]
	public function getMemoLock(): MemoLock
	{
		return $this->memoLock ??= new NoLock($this->prefixer->getPrefix());
	}
	
	#[Override]
	public function itemId(
		string $key,
	): string
	{
		return $this->prefixer
			->prefix($key, $this->getGroup());
	}
	
	/**
	 * load(): HGET of the data
	 */
	#[Override]
	protected function fetch(
		string $id,
	): mixed
	{
		$data = $this->redis->hGet($this->keyPrefix . $id, 'data');
		if($data === false)
		{
			return null;
		}
		
		return $this->found($id, $this->serializer->unserialize($this->decompress($data)), '');
	}
	
	/**
	 * save(): the old tags, then one transaction
	 */
	#[Override]
	public function set(
		string $key,
		mixed $value,
		int $ttl = 0,
		array $tags = [],
	): bool
	{
		if($value === null)
		{
			return $this->delete($key);
		}
		
		$id = $this->itemId($key);
		$itemKey = $this->keyPrefix . $id;
		
		$oldTags = $this->redis->hGet($itemKey, 'tags');
		$oldTags = $oldTags ? explode(',', $oldTags) : [];
		
		$this->redis->multi();
		$this->redis->hMSet($itemKey, [
			'data' => $this->compress($this->serializer->serialize($value)),
			'tags' => implode(',', $tags),
			'mtime' => time(),
			'infinite' => $ttl > 0 ? 0 : 1,
		]);
		if($ttl > 0)
		{
			$this->redis->expire($itemKey, $ttl);
		}
		
		$addTags = $oldTags !== [] ? array_diff($tags, $oldTags) : $tags;
		if($addTags !== [])
		{
			$this->redis->sAdd($this->tagsSet, ...$addTags);
			foreach($addTags as $tag)
			{
				$this->redis->sAdd($this->tagIdsPrefix . $tag, $id);
			}
		}
		
		$removedTags = $oldTags !== [] ? array_diff($oldTags, $tags) : [];
		foreach($removedTags as $tag)
		{
			$this->redis->sRem($this->tagIdsPrefix . $tag, $id);
		}
		
		$result = $this->redis->exec();
		
		return is_array($result) && (bool)$result[0];
	}
	
	/**
	 * remove(): the page's tags, then its DEL and an SREM from each tag's set
	 */
	public function delete(
		string $key,
	): bool
	{
		$id = $this->itemId($key);
		$itemKey = $this->keyPrefix . $id;
		// kept: a page that is not there reads as one empty tag, and its set gets an SREM
		$tags = explode(',', (string)$this->redis->hGet($itemKey, 'tags'));
		
		$this->redis->multi();
		$this->redis->del($itemKey);
		foreach($tags as $tag)
		{
			$this->redis->sRem($this->tagIdsPrefix . $tag, $id);
		}
		$result = $this->redis->exec();
		
		return is_array($result) && (bool)$result[0];
	}
	
	/**
	 * clean(matchingAnyTag) or clean(matchingTag): the matching ids, then removeMultiple()
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
		
		$sets = array_map(fn(string $tag): string => $this->tagIdsPrefix . $tag, $tags);
		$this->step("reading the tag's list of pages", true);
		$ids = $matching === self::MATCHING_ALL
			? $this->redis->sInter(...$sets)
			: $this->redis->sUnion(...$sets);
		$this->step("reading the tag's list of pages", false);
		
		return $this->removeMultiple(is_array($ids) ? $ids : [], $matching, $tags);
	}
	
	/**
	 * removeMultiple(): one transaction, a single DEL of every page in it
	 *
	 * @param list<string> $ids
	 * @param list<string> $tags
	 */
	protected function removeMultiple(
		array $ids,
		string $matching,
		array $tags,
	): bool
	{
		// kept: no pages, nothing done - the tag sets stay too
		if($ids === [])
		{
			return true;
		}
		
		$this->redis->multi();
		$this->redis->del(...array_map(fn(string $id): string => $this->keyPrefix . $id, $ids));
		if($tags !== [])
		{
			if($matching === self::MATCHING_ANY)
			{
				$this->redis->del(...array_map(fn(string $tag): string => $this->tagIdsPrefix . $tag, $tags));
				$this->redis->sRem($this->tagsSet, ...$tags);
			}
			
			// kept: "update the id list for each tag" reads SMEMBERS inside the transaction, where
			// phpredis queues it and answers with the client itself: the loop runs over nothing
			$remaining = (array)$this->redis->sMembers($this->tagsSet);
			foreach($remaining as $tag)
			{
				if(is_string($tag))
				{
					$this->redis->sRem($this->tagIdsPrefix . $tag, ...$ids);
				}
			}
		}
		// the step the demos draw: the transaction running, while Redis serves nobody else
		$step = sprintf('deleting %s pages, in one go', number_format(count($ids), 0, '.', ' '));
		$this->step($step, true);
		$result = $this->redis->exec();
		$this->step($step, false);
		
		return is_array($result) && (bool)$result[0];
	}
	
	/**
	 * clean(all): FLUSHDB, the whole database
	 */
	public function clear(): bool
	{
		return (bool)$this->redis->flushDb();
	}
	
	/**
	 * clean(old), _collectGarbage(): the names of expired pages out of the tag sets, a tag at a time
	 */
	#[Override]
	public function collectGarbage(): bool|int
	{
		$exists = [];
		foreach((array)$this->redis->sMembers($this->tagsSet) as $tag)
		{
			$members = (array)$this->redis->sMembers($this->tagIdsPrefix . $tag);
			$total = count($members);
			$expired = [];
			$gone = 0;
			$alive = 0;
			while(($id = array_pop($members)) !== null)
			{
				$exists[$id] ??= $this->redis->exists($this->keyPrefix . $id) > 0;
				if($exists[$id])
				{
					$alive++;
					
					continue;
				}
				
				$gone++;
				$expired[] = $id;
				// removed incrementally, to keep the memory down
				if(count($expired) % 100 === 0 && $alive > 0)
				{
					$this->redis->sRem($this->tagIdsPrefix . $tag, ...$expired);
					$expired = [];
				}
			}
			
			if($gone === $total)
			{
				$this->redis->del($this->tagIdsPrefix . $tag);
				$this->redis->sRem($this->tagsSet, $tag);
			}
			elseif($expired !== [])
			{
				$this->redis->sRem($this->tagIdsPrefix . $tag, ...$expired);
			}
		}
		
		return true;
	}
	
	/**
	 * gzip level 1 from the threshold, behind "gz" and the prefix
	 */
	protected function compress(
		string $data,
	): string
	{
		if($this->compression === false || strlen($data) < $this->compressionThreshold)
		{
			return $data;
		}
		
		$compressed = gzcompress($data, 1);
		
		return $compressed === false
			? $data
			: 'gz' . static::COMPRESS_PREFIX . $compressed;
	}
	
	protected function decompress(
		string $data,
	): string
	{
		if(substr($data, 2, 3) !== static::COMPRESS_PREFIX)
		{
			return $data;
		}
		
		return (string)gzuncompress(substr($data, 5));
	}
	
	/**
	 * $observer(string $step, bool $start) is told when a forget starts and ends each step: reading
	 * the list, deleting the pages (the demos draw them; the backend had no such hook)
	 */
	public function observe(
		?Closure $observer,
	): static
	{
		$this->observer = $observer;
		
		return $this;
	}
	
	protected function step(
		string $name,
		bool $start,
	): void
	{
		if($this->observer !== null)
		{
			($this->observer)($name, $start);
		}
	}
	
	/**
	 * A page's data as save() writes it: serialized, compressed from the threshold (a scenario that
	 * fills a million pages through a pipeline writes exactly this)
	 */
	public function encode(
		mixed $value,
	): string
	{
		return $this->compress($this->serializer->serialize($value));
	}
	
	/**
	 * save() for pages that are not there yet, queued on a pipeline: the same writes without the read
	 * of the old tags (a new page has none). A scenario fills a million pages with it; save() takes
	 * two round trips a page
	 *
	 * @param list<string> $keys
	 */
	public function queueNew(
		Redis $pipeline,
		array $keys,
		mixed $value,
		int $ttl,
		string $tag,
	): void
	{
		$data = $this->encode($value);
		$ids = [];
		foreach($keys as $key)
		{
			$id = $this->itemId($key);
			$ids[] = $id;
			$pipeline->hMSet($this->keyPrefix . $id, [
				'data' => $data,
				'tags' => $tag,
				'mtime' => time(),
				'infinite' => $ttl > 0 ? 0 : 1,
			]);
			if($ttl > 0)
			{
				$pipeline->expire($this->keyPrefix . $id, $ttl);
			}
		}
		$pipeline->sAdd($this->tagsSet, $tag);
		$pipeline->sAdd($this->tagIdsPrefix . $tag, ...$ids);
	}
	
	/**
	 * The set of every tag
	 */
	public function tagsSet(): string
	{
		return $this->tagsSet;
	}
	
	/**
	 * The connection the store keeps, for a scenario that reads its keys
	 */
	public function getRedis(): Redis
	{
		return $this->redis;
	}
	
	/**
	 * The key a cached page is kept under, and the set of a tag
	 */
	public function itemKey(
		string $key,
	): string
	{
		return $this->keyPrefix . $this->itemId($key);
	}
	
	public function tagSet(
		string $tag,
	): string
	{
		return $this->tagIdsPrefix . $tag;
	}
}
