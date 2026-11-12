<?php
declare(strict_types=1);

namespace Talk;

use Throwable;

/**
 * Runs one scenario into a sink: "begin" with the parameters and the
 * machine, the scenario's own events, then "done" - always, so that a deck
 * waiting on the stream is never left waiting, and closes it instead of
 * letting the browser reconnect and start the run again.
 */
class Runner
{
	/**
	 * @param array<string, mixed> $input
	 */
	public static function run(
		string $name,
		array $input,
		string $profile,
		Sink $sink,
	): bool
	{
		Events::setSink($sink);
		Events::setEpoch(0.0);
		
		$ok = false;
		try
		{
			$scenario = Scenarios::get($name);
			$params = $scenario->resolve($input, $profile);
			
			$sink->emit([
				'type' => 'begin',
				't' => 0,
				'scenario' => $name,
				'profile' => $profile,
				'params' => $params,
				'env' => Env::describe(Config::redis(5.0)),
			]);
			
			$scenario->run($sink, $params);
			$ok = true;
		}
		catch(Throwable $exception)
		{
			$sink->emit([
				'type' => 'error',
				't' => 0,
				'message' => $exception->getMessage(),
			]);
		}
		finally
		{
			$sink->emit([
				'type' => 'done',
				't' => 0,
				'ok' => $ok,
			]);
		}
		
		return $ok;
	}
	
	/**
	 * A worker's side: its job, decoded, handed to its scenario. What goes
	 * wrong is said as an event; the fleet reports anything else the worker
	 * writes as an error too.
	 *
	 * @param array<string, mixed> $job
	 */
	public static function work(
		array $job,
	): bool
	{
		try
		{
			Scenarios::get((string)$job['scenario'])->work($job);
			
			return true;
		}
		catch(Throwable $exception)
		{
			Events::emit('log', [
				'level' => 'error',
				'w' => $job['index'] ?? null,
				'message' => $exception::class . ': ' . $exception->getMessage(),
			]);
			
			return false;
		}
	}
}
