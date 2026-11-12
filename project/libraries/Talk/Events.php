<?php
declare(strict_types=1);

namespace Talk;

use Talk\Sink\Ndjson;

use function hrtime;
use function round;

/**
 * The clock and the voice of a process. Every event carries its time in
 * milliseconds since the fleet was released (the epoch every process of a
 * run shares), so the lanes of different processes line up.
 *
 * The clock is the monotonic one (hrtime()), not the wall clock: every
 * process of a run is on one machine (one container), where it is shared,
 * and it never jumps. Docker Desktop's VM corrects its wall clock in steps:
 * a step of 1.6 s made every request in flight look 1.6 s longer, and ended
 * the visitors' loops early.
 */
class Events
{
	protected static float $epoch = 0.0;
	
	protected static ?Sink $sink = null;
	
	public static function setEpoch(
		float $epoch,
	): void
	{
		static::$epoch = $epoch;
	}
	
	public static function getEpoch(): float
	{
		return static::$epoch;
	}
	
	public static function setSink(
		Sink $sink,
	): void
	{
		static::$sink = $sink;
	}
	
	/**
	 * Seconds on the monotonic clock: what an epoch is counted in
	 */
	public static function clock(): float
	{
		return hrtime(true) / 1e9;
	}
	
	/**
	 * Milliseconds since the epoch, to the microsecond
	 */
	public static function now(): float
	{
		return round((static::clock() - static::$epoch) * 1000, 3);
	}
	
	/**
	 * @param array<string, mixed> $data
	 */
	public static function emit(
		string $type,
		array $data = [],
	): void
	{
		static::$sink ??= new Ndjson();
		static::$sink->emit(['type' => $type, 't' => static::now()] + $data);
	}
}
