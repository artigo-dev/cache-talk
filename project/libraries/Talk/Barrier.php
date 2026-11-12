<?php
declare(strict_types=1);

namespace Talk;

use Redis;
use RuntimeException;

use function sprintf;
use function usleep;

/**
 * Releases a fleet together. Every worker says it is ready, and the fleet
 * releases them all at one moment shortly after the last one did - gated on
 * the count, not on a fixed lead, so a slow start cannot make a worker miss
 * the moment. That moment is the run's epoch: time 0 of every lane.
 */
class Barrier
{
	/**
	 * How far ahead of "now" the release is set, so that every worker is
	 * blocked waiting for it when it comes
	 * Unit: seconds
	 */
	public const float LEAD = 0.05;
	
	public static function key(
		string $run,
		string $what,
	): string
	{
		return 'talk:barrier:' . $run . ':' . $what;
	}
	
	/**
	 * The worker's side: ready, then blocked until the release
	 */
	public static function arrive(
		Redis $redis,
		string $run,
		float $timeout = 30.0,
	): float
	{
		$redis->incr(static::key($run, 'ready'));
		
		$reply = $redis->blPop([static::key($run, 'go')], $timeout);
		if($reply === false || $reply === [] || isset($reply[1]) === false)
		{
			throw new RuntimeException(sprintf('Run %s was never released.', $run));
		}
		
		$epoch = (float)$reply[1];
		Events::setEpoch($epoch);
		
		$wait = $epoch - Events::clock();
		if($wait > 0)
		{
			usleep((int)($wait * 1_000_000));
		}
		
		return $epoch;
	}
	
	/**
	 * The fleet's side: once $count workers are ready, one token each,
	 * carrying the moment they all start
	 */
	public static function release(
		Redis $redis,
		string $run,
		int $count,
		float $timeout = 30.0,
	): float
	{
		$deadline = Events::clock() + $timeout;
		while((int)$redis->get(static::key($run, 'ready')) < $count)
		{
			if(Events::clock() > $deadline)
			{
				throw new RuntimeException(sprintf('Run %s: not every one of %d workers became ready.', $run, $count));
			}
			
			usleep(2_000);
		}
		
		$epoch = Events::clock() + static::LEAD;
		$tokens = [];
		for($i = 0; $i < $count; $i++)
		{
			$tokens[] = sprintf('%.6F', $epoch);
		}
		
		$redis->rPush(static::key($run, 'go'), ...$tokens);
		$redis->expire(static::key($run, 'go'), 60);
		$redis->expire(static::key($run, 'ready'), 60);
		Events::setEpoch($epoch);
		
		return $epoch;
	}
}
