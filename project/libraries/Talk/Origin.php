<?php
declare(strict_types=1);

namespace Talk;

use Redis;
use RuntimeException;

use function usleep;

/**
 * The expensive thing a cache is for - a report, a query over a large table,
 * a render. It takes a fixed time and counts its calls in Redis, so that the
 * calls of different processes add up. A worker sets it up once; the demo
 * site's stores call work() where a real one would query its database.
 */
class Origin
{
	protected static ?Redis $redis = null;
	
	protected static string $run = '';
	
	protected static int $worker = 0;
	
	protected static int $ms = 0;
	
	public static function configure(
		Redis $redis,
		string $run,
		int $worker,
		int $ms,
	): void
	{
		static::$redis = $redis;
		static::$run = $run;
		static::$worker = $worker;
		static::$ms = $ms;
	}
	
	public static function key(
		string $run,
	): string
	{
		return 'talk:origin:' . $run;
	}
	
	/**
	 * @return array{what: string, by: int}
	 */
	public static function work(
		string $what,
	): array
	{
		if(static::$redis === null)
		{
			throw new RuntimeException('Origin::configure() was not called.');
		}
		
		static::$redis->incr(static::key(static::$run));
		Events::emit('origin.start', [
			'w' => static::$worker,
			'what' => $what,
		]);
		
		usleep(static::$ms * 1000);
		
		Events::emit('origin.end', [
			'w' => static::$worker,
			'what' => $what,
		]);
		
		return [
			'what' => $what,
			'by' => static::$worker,
		];
	}
	
	public static function calls(
		Redis $redis,
		string $run,
	): int
	{
		return (int)$redis->get(static::key($run));
	}
}
