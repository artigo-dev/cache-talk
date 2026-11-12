<?php
declare(strict_types=1);

namespace Talk\Sink;

use Talk\Sink;

/**
 * Keeps every event: what bin/record.php writes into the deck, and what a
 * scenario summarises its segments from
 */
class Buffer implements Sink
{
	/**
	 * @var list<array<string, mixed>>
	 */
	protected array $events = [];
	
	public function __construct(
		protected ?Sink $forward = null,
	)
	{
	}
	
	public function emit(
		array $event,
	): void
	{
		$this->events[] = $event;
		$this->forward?->emit($event);
	}
	
	public function isListening(): bool
	{
		return $this->forward?->isListening() ?? true;
	}
	
	/**
	 * @return list<array<string, mixed>>
	 */
	public function events(): array
	{
		return $this->events;
	}
	
	public function reset(): void
	{
		$this->events = [];
	}
}
