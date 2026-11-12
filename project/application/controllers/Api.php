<?php
declare(strict_types=1);

namespace Controllers;

use Ovos\Controller;
use Ovos\Response;
use Talk\Config;
use Talk\Runner;
use Talk\Scenarios;
use Talk\Sink\Sse;

use function fclose;
use function flock;
use function fopen;
use function ignore_user_abort;
use function is_array;
use function set_time_limit;
use function sys_get_temp_dir;

use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;

/**
 * The live API the deck talks to when the talk's own server serves it:
 *
 *   GET /api/ping                         can a demo run live here?
 *   GET /api/run?scenario=stampede&…      one run, as Server-Sent Events
 *
 * A run starts real processes and loads Redis on purpose, so a name is looked
 * up in Talk\Scenarios (the whitelist), every parameter is clamped by its
 * scenario, and one run at a time is allowed on the server.
 */
class Api extends Controller
{
	public function ping(): Response
	{
		$response = new Response\Json;
		$response->live = true;
		$response->public = Config::isPublic();
		$response->scenarios = Scenarios::names();
		
		return $response;
	}
	
	public function run(): void
	{
		$query = $this->request->get();
		$input = is_array($query) ? $query : [];
		$scenario = (string)($input['scenario'] ?? '');
		
		$this->app->getResponse()
			->flushBuffers()
			->setHeader('Content-Type', 'text/event-stream', true)
			->setHeader('Cache-Control', 'no-cache, no-store', true)
			->setHeader('Content-Encoding', 'none', true) // no gzip buffering the stream
			->setHeader('X-Accel-Buffering', 'no', true) // no nginx proxy buffering
			->sendHeaders()
			->setIsSent(true);
		
		// a viewer that leaves is noticed by the fleet (connection_aborted()),
		// which then takes its workers down; aborting the script instead would
		// skip that and leave them running
		ignore_user_abort(true);
		set_time_limit(0);
		
		$sink = new Sse();
		$lock = fopen(sys_get_temp_dir() . '/talk-run.lock', 'c');
		if($lock === false || flock($lock, LOCK_EX | LOCK_NB) === false)
		{
			$sink->emit(['type' => 'error', 't' => 0, 'message' => 'Another run is in progress on this server; try again in a moment.']);
			$sink->emit(['type' => 'done', 't' => 0, 'ok' => false]);
			
			return;
		}
		
		try
		{
			Runner::run($scenario, $input, 'live', $sink);
		}
		finally
		{
			flock($lock, LOCK_UN);
			fclose($lock);
		}
	}
}
