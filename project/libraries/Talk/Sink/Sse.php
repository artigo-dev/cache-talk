<?php
declare(strict_types=1);

namespace Talk\Sink;

use Talk\Sink;

use function connection_aborted;
use function flush;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Server-Sent Events to the deck. The browser's EventSource reconnects on
 * its own when a stream ends, which would start the run again: the deck
 * closes it at the "done" event, which every run ends with.
 */
class Sse implements Sink
{
	public function emit(
		array $event,
	): void
	{
		echo 'data: ' . json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n\n";
		flush();
	}
	
	/**
	 * Known only after something was written: the deck that went away is
	 * noticed at the next event
	 */
	public function isListening(): bool
	{
		return connection_aborted() === 0;
	}
}
