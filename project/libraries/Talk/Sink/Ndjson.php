<?php
declare(strict_types=1);

namespace Talk\Sink;

use Talk\Sink;

use function fflush;
use function fwrite;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const STDOUT;

/**
 * One JSON object per line: what a worker tells its fleet, and what
 * bin/run.php prints
 */
class Ndjson implements Sink
{
	/**
	 * @param resource $stream
	 */
	public function __construct(
		protected mixed $stream = STDOUT,
	)
	{
	}
	
	public function emit(
		array $event,
	): void
	{
		fwrite($this->stream, json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
		fflush($this->stream);
	}
	
	public function isListening(): bool
	{
		return true;
	}
}
