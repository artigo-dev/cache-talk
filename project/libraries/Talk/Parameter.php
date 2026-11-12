<?php
declare(strict_types=1);

namespace Talk;

use InvalidArgumentException;

use function array_filter;
use function array_map;
use function explode;
use function implode;
use function in_array;
use function is_int;
use function max;
use function min;
use function sprintf;
use function trim;

/**
 * One knob of a scenario: its value on stage ("live"), when recorded, and
 * what a caller may set it to. A live run in the browser takes its values
 * from the request, so every one is clamped or checked against a list here.
 */
readonly class Parameter
{
	/**
	 * @param list<string> $options the values a list may hold (empty for a number)
	 * @param list<int|string> $grid the values the deck's recorded grid holds
	 */
	public function __construct(
		public int|string $live,
		public int|string|null $record = null,
		public int $min = 0,
		public int $max = 0,
		public array $options = [],
		public array $grid = [],
		public string $label = '',
	)
	{
	}
	
	public function default(
		string $profile,
	): int|string
	{
		return $profile === 'record' && $this->record !== null ? $this->record : $this->live;
	}
	
	/**
	 * A number clamped to [min, max]; a comma-separated list of options,
	 * each one checked
	 */
	public function resolve(
		mixed $value,
		string $profile,
	): int|string
	{
		if($value === null || $value === '')
		{
			return $this->default($profile);
		}
		
		if(is_int($this->live))
		{
			return max($this->min, min($this->max, (int)$value));
		}
		
		$picked = array_filter(array_map(trim(...), explode(',', (string)$value)), fn(string $item) => $item !== '');
		foreach($picked as $item)
		{
			if(in_array($item, $this->options, true) === false)
			{
				throw new InvalidArgumentException(sprintf('"%s" is not one of %s.', $item, implode(', ', $this->options)));
			}
		}
		
		return implode(',', $picked);
	}
}
