<?php
declare(strict_types=1);

namespace Talk;

use LogicException;
use RuntimeException;

use function bin2hex;
use function random_bytes;
use function sprintf;

/**
 * One demo of the talk. run() orchestrates it in the parent process and
 * emits what the deck draws; work() is one worker's part of a fleet, in a
 * process of its own. A scenario checks what it claims (check()) - a run that
 * would draw the right picture for the wrong reason fails instead.
 */
abstract class Scenario
{
	abstract public function name(): string;
	
	/**
	 * @return array<string, Parameter>
	 */
	abstract public function parameters(): array;
	
	/**
	 * @param array<string, int|string> $params resolved (see resolve())
	 */
	abstract public function run(
		Sink $sink,
		array $params,
	): void;
	
	/**
	 * @param array<string, mixed> $job what the fleet gave this worker, with its "index" and "run"
	 */
	public function work(
		array $job,
	): void
	{
		throw new LogicException(sprintf('Scenario %s runs no workers.', $this->name()));
	}
	
	/**
	 * Every parameter of the scenario, from $input where given (clamped or
	 * checked) and from the profile's defaults where not
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, int|string>
	 */
	public function resolve(
		array $input,
		string $profile,
	): array
	{
		$params = [];
		foreach($this->parameters() as $name => $parameter)
		{
			$params[$name] = $parameter->resolve($input[$name] ?? null, $profile);
		}
		
		return $params;
	}
	
	/**
	 * A claim the run makes, said in the stream; a false one ends the run
	 */
	protected function check(
		Sink $sink,
		bool $holds,
		string $claim,
	): void
	{
		$sink->emit([
			'type' => 'check',
			't' => Events::now(),
			'ok' => $holds,
			'claim' => $claim,
		]);
		
		if($holds === false)
		{
			throw new RuntimeException(sprintf('%s: the claim "%s" does not hold.', $this->name(), $claim));
		}
	}
	
	protected function runId(): string
	{
		return bin2hex(random_bytes(4));
	}
}
