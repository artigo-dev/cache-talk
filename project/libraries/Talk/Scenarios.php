<?php
declare(strict_types=1);

namespace Talk;

use InvalidArgumentException;
use Talk\Scenario\Ban;
use Talk\Scenario\Curve;
use Talk\Scenario\FileBan;
use Talk\Scenario\Fleet;
use Talk\Scenario\Load;
use Talk\Scenario\Race;
use Talk\Scenario\Stampede;
use Talk\Scenario\Swr;
use Talk\Scenario\Tags;

use function array_keys;
use function implode;
use function sprintf;

/**
 * Every scenario there is. A name from outside (a browser, a command line)
 * is looked up here and nowhere else: this list is the whitelist.
 */
class Scenarios
{
	/**
	 * @var array<string, class-string<Scenario>>
	 */
	public const array ALL = [
		'stampede' => Stampede::class,
		'ban' => Ban::class,
		'race' => Race::class,
		'swr' => Swr::class,
		'fleet' => Fleet::class,
		'file-ban' => FileBan::class,
		'tags' => Tags::class,
		'load' => Load::class,
		'curve' => Curve::class,
	];
	
	public static function get(
		string $name,
	): Scenario
	{
		$class = static::ALL[$name] ?? throw new InvalidArgumentException(sprintf(
			'No scenario "%s"; there are: %s.',
			$name,
			implode(', ', array_keys(static::ALL)),
		));
		
		return new $class();
	}
	
	/**
	 * @return list<string>
	 */
	public static function names(): array
	{
		return array_keys(static::ALL);
	}
}
