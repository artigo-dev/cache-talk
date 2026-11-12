<?php
declare(strict_types=1);

namespace Controllers;

use InvalidArgumentException;
use Ovos\Controller;
use Talk\Results;
use Talk\Runner;
use Talk\Scenarios;
use Talk\Sink\Buffer;
use Talk\Sink\Ndjson;

use function array_shift;
use function base64_decode;
use function count;
use function json_decode;
use function strtr;

use const BASE_DIR;
use const JSON_THROW_ON_ERROR;

/**
 * The talk's runs from the command line, one event per line:
 *
 *   php cli.php demo run stampede
 *   php cli.php demo run stampede workers 50 hosts 12 modes memolock
 *   php cli.php demo run stampede profile record
 *
 * and the workers a run starts: php cli.php demo worker job <base64url JSON>
 */
class Demo extends Controller\Cli
{
	use Controller\Traits\Cli;
	
	/**
	 * How often a scenario is run before it is given up on unrecorded
	 */
	protected const int RECORD_ATTEMPTS = 3;
	
	/**
	 * The scenario's name, then its parameters as name/value pairs. Taken as
	 * one list: the dispatcher names only the arguments an action declares,
	 * and a scenario's parameters are its own.
	 */
	public function run(
		string ...$arguments,
	): void
	{
		$scenario = (string)array_shift($arguments);
		if($scenario === '' || count($arguments) % 2 !== 0)
		{
			throw new InvalidArgumentException('Usage: php cli.php demo run <scenario> [<parameter> <value>]...');
		}
		
		$input = [];
		while($arguments !== [])
		{
			$name = (string)array_shift($arguments);
			$input[$name] = array_shift($arguments);
		}
		
		$profile = (string)($input['profile'] ?? 'live');
		$ok = Runner::run($scenario, $input, $profile, new Ndjson());
		
		exit($ok ? 0 : 1);
	}
	
	/**
	 * Records scenarios into the deck (../site/data/results.js), at the
	 * "record" profile: every one, or those named. A scenario recorded
	 * earlier and not named now keeps its recording.
	 */
	public function record(
		string ...$scenarios,
	): void
	{
		$results = new Results(BASE_DIR . '../site/data/results.js');
		$ok = true;
		foreach($scenarios === [] ? Scenarios::names() : $scenarios as $scenario)
		{
			// a warm-up nobody keeps: the first run after a start loads the
			// Lua libraries and compiles what the workers have not run yet
			Runner::run($scenario, [], 'record', new Buffer());
			
			// a run whose claims fail (a disturbed measurement among them) is
			// tried again; only a run whose claims all hold is recorded
			$recorded = false;
			for($attempt = 1; $attempt <= static::RECORD_ATTEMPTS && $recorded === false; $attempt++)
			{
				$buffer = new Buffer(new Ndjson());
				$recorded = Runner::run($scenario, [], 'record', $buffer);
			}
			
			if($recorded)
			{
				$results->put($scenario, $buffer->events());
			}
			
			$ok = $ok && $recorded;
		}
		
		$results->save();
		
		exit($ok ? 0 : 1);
	}
	
	public function worker(
		string $job,
	): void
	{
		$decoded = json_decode((string)base64_decode(strtr($job, '-_', '+/'), true), true, flags: JSON_THROW_ON_ERROR);
		$ok = Runner::work($decoded);
		
		exit($ok ? 0 : 1);
	}
}
