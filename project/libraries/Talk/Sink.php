<?php
declare(strict_types=1);

namespace Talk;

/**
 * Where a run's events go: a terminal, a browser, a recording
 */
interface Sink
{
	/**
	 * @param array<string, mixed> $event
	 */
	public function emit(
		array $event,
	): void;
	
	/**
	 * Whether whoever reads the events is still there: a run stops, and
	 * takes its workers down with it, when nobody is
	 */
	public function isListening(): bool;
}
