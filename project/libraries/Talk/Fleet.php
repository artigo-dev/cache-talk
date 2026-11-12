<?php
declare(strict_types=1);

namespace Talk;

use JsonException;
use Redis;
use RuntimeException;

use function base64_encode;
use function count;
use function explode;
use function fclose;
use function fread;
use function is_array;
use function is_resource;
use function json_decode;
use function json_encode;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function rtrim;
use function sprintf;
use function stream_select;
use function stream_set_blocking;
use function strrpos;
use function strtr;
use function substr;
use function trim;

use const BASE_DIR;
use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

/**
 * N worker processes of one scenario: each one a request, or a server, with
 * nothing in common but Redis. The fleet starts them, releases them together
 * once every one is ready (see Barrier), and passes their events on, marked
 * with what the caller wants them marked with. A fleet whose listener went
 * away, or that outlives its timeout, takes its workers down.
 */
class Fleet
{
	/**
	 * @var array<int, array{process: resource, pipes: array<int, resource>, buffer: array<int, string>}>
	 */
	protected array $workers = [];
	
	public function __construct(
		protected Sink $sink,
		protected Redis $redis,
		protected float $timeout = 120.0,
	)
	{
	}
	
	/**
	 * @param array<string, mixed> $job what every worker gets, plus its own "index"
	 * @param array<string, mixed> $mark what every event of this fleet is marked with
	 */
	public function run(
		string $scenario,
		string $run,
		int $count,
		array $job,
		array $mark = [],
	): void
	{
		$this->workers = [];
		for($index = 0; $index < $count; $index++)
		{
			$this->start($scenario, $run, $index, $job);
		}
		
		$released = false;
		$deadline = Events::clock() + $this->timeout;
		try
		{
			while($this->hasRunning())
			{
				$this->pump($mark);
				
				if($released === false && (int)$this->redis->get(Barrier::key($run, 'ready')) >= $count)
				{
					Barrier::release($this->redis, $run, $count);
					$released = true;
				}
				
				if($this->sink->isListening() === false)
				{
					throw new RuntimeException('The listener went away.');
				}
				
				if(Events::clock() > $deadline)
				{
					throw new RuntimeException(sprintf('The fleet of %d did not finish within %d s.', $count, $this->timeout));
				}
			}
			
			// what the last ones wrote before they exited
			$this->pump($mark, 0);
		}
		finally
		{
			$this->stop();
		}
		
		if($released === false)
		{
			throw new RuntimeException(sprintf('The workers of run %s exited before every one was ready.', $run));
		}
	}
	
	/**
	 * @param array<string, mixed> $job
	 */
	protected function start(
		string $scenario,
		string $run,
		int $index,
		array $job,
	): void
	{
		// base64url: nothing in it that a command line's parameter parser reads
		$payload = rtrim(strtr(base64_encode(json_encode(['scenario' => $scenario, 'run' => $run, 'index' => $index] + $job, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
		// a worker boots the application, as an FPM request would
		$process = proc_open(
			[PHP_BINARY, BASE_DIR . 'cli.php', 'demo', 'worker', 'job', $payload],
			[
				0 => ['pipe', 'r'],
				1 => ['pipe', 'w'],
				2 => ['pipe', 'w'],
			],
			$pipes,
			BASE_DIR,
		);
		
		if(is_resource($process) === false)
		{
			throw new RuntimeException(sprintf('Worker %d could not be started.', $index));
		}
		
		fclose($pipes[0]);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);
		
		$this->workers[$index] = [
			'process' => $process,
			'pipes' => [1 => $pipes[1], 2 => $pipes[2]],
			'buffer' => [1 => '', 2 => ''],
		];
	}
	
	protected function hasRunning(): bool
	{
		foreach($this->workers as $worker)
		{
			if(proc_get_status($worker['process'])['running'])
			{
				return true;
			}
		}
		
		return false;
	}
	
	/**
	 * Reads what the workers wrote: events from their stdout, anything on
	 * their stderr as an error
	 *
	 * @param array<string, mixed> $mark
	 */
	protected function pump(
		array $mark,
		int $waitUs = 20_000,
	): void
	{
		$read = [];
		foreach($this->workers as $worker)
		{
			foreach($worker['pipes'] as $pipe)
			{
				$read[] = $pipe;
			}
		}
		
		$write = null;
		$except = null;
		if($read === [] || stream_select($read, $write, $except, 0, $waitUs) === 0)
		{
			return;
		}
		
		foreach($this->workers as $index => $worker)
		{
			foreach($worker['pipes'] as $channel => $pipe)
			{
				$chunk = fread($pipe, 65536);
				if($chunk === false || $chunk === '')
				{
					continue;
				}
				
				$this->workers[$index]['buffer'][$channel].= $chunk;
				$buffer = $this->workers[$index]['buffer'][$channel];
				$end = strrpos($buffer, "\n");
				if($end === false)
				{
					continue;
				}
				
				$this->workers[$index]['buffer'][$channel] = substr($buffer, $end + 1);
				foreach(explode("\n", substr($buffer, 0, $end)) as $line)
				{
					$this->forward($index, $channel, $line, $mark);
				}
			}
		}
	}
	
	/**
	 * @param array<string, mixed> $mark
	 */
	protected function forward(
		int $index,
		int $channel,
		string $line,
		array $mark,
	): void
	{
		$line = trim($line);
		if($line === '')
		{
			return;
		}
		
		if($channel === 1)
		{
			try
			{
				$event = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
				if(is_array($event))
				{
					$this->sink->emit($event + $mark);
					
					return;
				}
			}
			catch(JsonException)
			{
				// not an event: said as what it is, below
			}
		}
		
		$this->sink->emit([
			'type' => 'log',
			'level' => 'error',
			'w' => $index,
			'message' => $line,
		] + $mark);
	}
	
	protected function stop(): void
	{
		foreach($this->workers as $worker)
		{
			if(proc_get_status($worker['process'])['running'])
			{
				proc_terminate($worker['process'], 9);
			}
			
			foreach($worker['pipes'] as $pipe)
			{
				fclose($pipe);
			}
			
			proc_close($worker['process']);
		}
		
		$this->workers = [];
	}
	
	public function count(): int
	{
		return count($this->workers);
	}
}
