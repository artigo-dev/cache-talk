<?php
declare(strict_types=1);

namespace Talk\Store;

use Closure;
use Ovos\ArrayObject;
use Ovos\Cache\MemoLock;
use Ovos\Cache\Prefixer;
use Ovos\Invoker;
use Override;

use function array_values;

/**
 * A lock that never locks, for the stores of the road before MemoLock (the
 * file cache, our classic Redis backend): every computation runs at once,
 * however many requests miss the same cached page at the same moment.
 *
 * php-library's stores read and compute through a MemoLock; this one hands
 * every request straight to the computation, as those caches did.
 */
class NoLock extends MemoLock
{
	public function __construct(
		?string $prefix = null,
	)
	{
		parent::__construct();
		
		$this->invoker = new Invoker($this);
		$this->prefixer = new Prefixer($prefix);
		$this->queueEnabled = false;
	}
	
	#[Override]
	public function setQueue(
		ArrayObject $config,
	): static
	{
		return $this;
	}
	
	/**
	 * No queue, no lock: the computation, at once
	 */
	#[Override]
	public function lockAndQueue(
		string $id,
		?Closure $fetcher = null,
		?Closure $resolver = null,
		?bool $queue = null,
		?int $queueLockTtlMs = null,
		?Closure $lockAndLook = null,
	): mixed
	{
		return $resolver === null
			? null
			: $resolver();
	}
	
	#[Override]
	public function releaseActiveLock(
		string $id,
	): bool
	{
		return true;
	}
	
	#[Override]
	public function renewLock(
		string $id,
	): bool
	{
		return true;
	}
	
	#[Override]
	public function getQueueLockTtlMs(): int
	{
		return 0;
	}
	
	/**
	 * Every id is "locked" by whoever asks: each request computes its own
	 *
	 * @return array{0: list<string>, 1: array<string, mixed>}
	 */
	#[Override]
	public function lockMany(
		array $ids,
		?int $queueLockTtlMs = null,
		?Closure $lockAndLookMany = null,
	): array
	{
		return [array_values($ids), []];
	}
	
	#[Override]
	public function waitForAny(
		array $ids,
		float|int $waitMs,
	): bool
	{
		return false;
	}
	
	#[Override]
	public function releaseMany(
		array $ids,
	): void
	{
	}
	
	#[Override]
	public function tryLock(
		string $id,
		?int $queueLockTtlMs = null,
	): bool
	{
		return true;
	}
}
