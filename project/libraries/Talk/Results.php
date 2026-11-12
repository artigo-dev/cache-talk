<?php
declare(strict_types=1);

namespace Talk;

use RuntimeException;

use function array_filter;
use function array_values;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_file;
use function json_decode;
use function json_encode;
use function preg_match;
use function rename;
use function sprintf;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * The recordings the deck replays: site/data/results.js, one entry per
 * scenario. A script rather than JSON, so that the deck opened from file://
 * reads it without fetch(), which browsers refuse there.
 */
class Results
{
	protected const string PREFIX = 'window.RESULTS = ';
	
	/**
	 * @var array<string, array<string, mixed>>
	 */
	protected array $results = [];
	
	public function __construct(
		protected string $path,
	)
	{
		if(is_file($path) === false)
		{
			return;
		}
		
		$text = (string)file_get_contents($path);
		if(preg_match('/^window\.RESULTS = (.*);\s*$/s', $text, $match) === 1)
		{
			$decoded = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
			$this->results = is_array($decoded) ? $decoded : [];
		}
	}
	
	/**
	 * @param list<array<string, mixed>> $events
	 */
	public function put(
		string $scenario,
		array $events,
	): void
	{
		$begin = array_values(array_filter($events, fn(array $event) => $event['type'] === 'begin'))[0] ?? [];
		
		$this->results[$scenario] = [
			'env' => $begin['env'] ?? null,
			'params' => $begin['params'] ?? null,
			// a worker's log line is for the terminal, not the replay
			'events' => array_values(array_filter($events, fn(array $event) => $event['type'] !== 'log')),
		];
	}
	
	public function save(): void
	{
		$json = json_encode($this->results, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$temporary = $this->path . '.tmp';
		if(file_put_contents($temporary, static::PREFIX . $json . ";\n") === false || rename($temporary, $this->path) === false)
		{
			throw new RuntimeException(sprintf('Could not write the recordings to "%s".', $this->path));
		}
	}
}
