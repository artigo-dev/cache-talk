<?php
declare(strict_types=1);

namespace Talk;

use Ovos\ArrayObject;
use Redis;

use function filter_var;
use function is_array;
use function Ovos\container;

use const FILTER_VALIDATE_BOOLEAN;

/**
 * The application's configuration, as the talk uses it. A station of the
 * road is a patch to `cache.persistent`: applied before the Cache service
 * builds its store, it changes the store the way an edit of
 * environments.yml would.
 */
class Config
{
	public static function app(): ArrayObject
	{
		/** @var ArrayObject $config */
		$config = container()->get('config');
		
		return $config;
	}
	
	/**
	 * @param array<string, mixed> $patch merged into cache.persistent
	 */
	public static function patch(
		array $patch,
	): void
	{
		static::merge(static::app()->cache->persistent, $patch);
	}
	
	/**
	 * @param array<string, mixed> $patch
	 */
	protected static function merge(
		ArrayObject $target,
		array $patch,
	): void
	{
		foreach($patch as $key => $value)
		{
			$current = $target->offsetGet($key);
			if(is_array($value) && $current instanceof ArrayObject)
			{
				static::merge($current, $value);
				
				continue;
			}
			
			$target->offsetSet($key, $value);
		}
	}
	
	public static function redisHost(): string
	{
		return (string)static::app()->connections->redis->host;
	}
	
	public static function redisPort(): int
	{
		return (int)(static::app()->connections->redis->port ?? 6379);
	}
	
	/**
	 * A plain client for the fleet's own bookkeeping (the barrier, counters,
	 * the probe), never the client of a store being measured. Its read
	 * timeout outlasts any blocking command a run sends.
	 */
	public static function redis(
		float $readTimeout = 60.0,
	): Redis
	{
		$redis = new Redis();
		$redis->connect(static::redisHost(), static::redisPort(), 2.0);
		$redis->setOption(Redis::OPT_READ_TIMEOUT, $readTimeout);
		
		return $redis;
	}
	
	/**
	 * Whether the scenarios may empty the Redis they run on
	 */
	public static function isDedicated(): bool
	{
		return filter_var(static::app()->talk?->dedicated, FILTER_VALIDATE_BOOLEAN);
	}
	
	/**
	 * The hosted live mode: grid-only parameters, one run at a time
	 */
	public static function isPublic(): bool
	{
		return filter_var(static::app()->talk?->public, FILTER_VALIDATE_BOOLEAN);
	}
}
