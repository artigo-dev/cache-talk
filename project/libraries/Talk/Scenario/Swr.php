<?php
declare(strict_types=1);

namespace Talk\Scenario;

use Closure;
use Ovos\Cache\Store\KeyValue;
use Redis;
use Stores\Pages;
use Talk\Barrier;
use Talk\Config;
use Talk\Env;
use Talk\Events;
use Talk\Fleet;
use Talk\Origin;
use Talk\Parameter;
use Talk\Scenario;
use Talk\Sink;
use Talk\Sink\Buffer;

use function explode;
use function in_array;
use function max;
use function round;
use function sprintf;
use function usleep;

/**
 * Visitors around the moment a cached page ends: it expires (expire, stale)
 * or the editor saves (edit, soft). Who waits, who rebuilds, and does anyone
 * get the previous page?
 *
 * - expire: ttl 1 s - the first visitor after it rebuilds, the others wait
 * - stale: ttl 1 s, stale 60 s - the previous page at once, one refresh after
 * - edit: the editor saves, the tag is forgotten - the page is gone
 * - soft: the same with stale 60 s, soft - the previous page, one refresh
 *
 * A fleet per mode: worker 0 builds the page at t = 0 and, in the edit modes,
 * saves the article at EDIT_MS; the visitors arrive STEP_MS apart, the first
 * FIRST_BEFORE_MS before the moment. Every worker has a deferrer, so a
 * refresh runs after its own answer, as under PHP-FPM
 * (fastcgi_finish_request()).
 */
class Swr extends Scenario
{
	public const array LABELS = [
		'expire' => 'Plain (ttl: 1)',
		'stale' => 'Stale-while-revalidate (ttl: 1 · stale: 60)',
		'edit' => 'A plain edit (invalidateTags())',
		'soft' => 'Soft invalidation (stale: 60 · soft: true)',
	];
	
	protected const string KEY = 'front-page';
	
	protected const string TAG = 'article-42';
	
	/**
	 * When the editor saves, in the edit modes
	 */
	protected const int EDIT_MS = 1_000;
	
	/**
	 * How far apart the visitors arrive
	 */
	protected const int STEP_MS = 50;
	
	/**
	 * When the page expires, in the expiry modes: it is built by about 300 ms, ttl 1 s
	 */
	protected const int EXPIRES_MS = 1_300;
	
	/**
	 * How long before the moment the first visitor arrives: three come before
	 * it, none at it (a visitor in the same millisecond races the moment)
	 */
	protected const int FIRST_BEFORE_MS = 125;
	
	public function name(): string
	{
		return 'swr';
	}
	
	public function parameters(): array
	{
		return [
			'visitors' => new Parameter(live: 16, min: 2, max: 40, label: 'visitors'),
			'originMs' => new Parameter(live: 300, min: 50, max: 2000, label: 'origin (ms)'),
			'modes' => new Parameter(live: 'expire,stale,edit,soft', options: ['expire', 'stale', 'edit', 'soft'], label: 'modes'),
		];
	}
	
	public function run(
		Sink $sink,
		array $params,
	): void
	{
		$redis = Config::redis();
		$visitors = (int)$params['visitors'];
		$originMs = (int)$params['originMs'];
		
		foreach(explode(',', (string)$params['modes']) as $mode)
		{
			Env::flush($redis);
			$run = $this->runId();
			$sink->emit([
				'type' => 'segment',
				't' => 0,
				'mode' => $mode,
				'label' => static::LABELS[$mode],
				'visitors' => $visitors,
				'originMs' => $originMs,
			]);
			
			$events = new Buffer($sink);
			(new Fleet($events, $redis))->run($this->name(), $run, $visitors + 1, [
				'mode' => $mode,
				'originMs' => $originMs,
			], [
				'mode' => $mode,
			]);
			
			$this->summarise($sink, $events, $mode, Origin::calls($redis, $run), $originMs);
		}
	}
	
	public function work(
		array $job,
	): void
	{
		$mode = (string)$job['mode'];
		$index = (int)$job['index'];
		$run = (string)$job['run'];
		$redis = Config::redis();
		Origin::configure($redis, $run, $index, (int)$job['originMs']);
		
		$store = (new Pages())->store();
		$deferred = [];
		$store->setDeferrer(static function(Closure $refresh) use (&$deferred): void
		{
			$deferred[] = $refresh;
		});
		$version = 'talk:swr:version:' . $run;
		$read = fn() => $store->get(static::KEY, static function() use ($redis, $version): array
		{
			// the article as it is when the build starts: an edit during the build is not in it
			$article = (int)$redis->get($version);
			$built = Origin::work('the front page');
			
			return [
				'version' => $article,
				'by' => $built['by'],
				'builtAt' => Events::now(),
			];
		}, ...static::options($mode));
		
		Barrier::arrive($redis, $run);
		
		if($index === 0)
		{
			$page = $read();
			if(in_array($mode, ['expire', 'stale'], true))
			{
				Events::emit('expires', [
					'at' => round((float)$page['builtAt'] + 1000, 3),
				]);
			}
			else
			{
				static::sleepUntil(static::EDIT_MS);
				$redis->incr($version);
				$store->invalidateTags([static::TAG]);
				Events::emit('edit');
			}
			
			return;
		}
		
		$first = (in_array($mode, ['expire', 'stale'], true) ? static::EXPIRES_MS : static::EDIT_MS) - static::FIRST_BEFORE_MS;
		static::sleepUntil($first + ($index - 1) * static::STEP_MS);
		Events::emit('request', [
			'w' => $index,
		]);
		$page = $read();
		Events::emit('respond', [
			'w' => $index,
			'by' => (int)$page['by'],
			'previous' => in_array($mode, ['expire', 'stale'], true)
				? (float)$page['builtAt'] + 1000 < Events::now()
				: (int)$page['version'] < (int)$redis->get($version),
		]);
		
		// after the answer, as fastcgi_finish_request() would have it
		foreach($deferred as $refresh)
		{
			$refresh();
		}
	}
	
	/**
	 * What the segment came to, and the claims it makes
	 */
	protected function summarise(
		Sink $sink,
		Buffer $events,
		string $mode,
		int $calls,
		int $originMs,
	): void
	{
		$requests = [];
		$waits = [];
		$last = null;
		foreach($events->events() as $event)
		{
			if($event['type'] === 'request')
			{
				$requests[$event['w']] = (float)$event['t'];
			}
			if($event['type'] === 'respond')
			{
				$waits[] = (float)$event['t'] - ($requests[$event['w']] ?? (float)$event['t']);
				$last = $event;
			}
		}
		$longest = $waits === [] ? 0.0 : max($waits);
		$rebuilds = max(0, $calls - 1);
		
		$sink->emit([
			'type' => 'summary',
			't' => Events::now(),
			'mode' => $mode,
			'waitMax' => round($longest, 3),
			'rebuilds' => $rebuilds,
			'lastPrevious' => (bool)($last['previous'] ?? true),
		]);
		
		if(in_array($mode, ['expire', 'edit'], true))
		{
			$this->check($sink, $longest >= $originMs * 0.8, sprintf('somebody waits for the rebuild (the longest wait: %d ms)', $longest));
		}
		else
		{
			$this->check($sink, $longest < 50, sprintf('nobody waits more than 50 ms (the longest: %.1f ms)', $longest));
			$this->check($sink, $rebuilds === 1, sprintf('one rebuild after the moment (%d)', $rebuilds));
			$this->check($sink, ($last['previous'] ?? true) === false, 'the last visitor gets the new page');
		}
	}
	
	/**
	 * @return array<string, mixed> get()'s ttl, tags and options for the mode
	 */
	protected static function options(
		string $mode,
	): array
	{
		return match($mode)
		{
			'expire' => ['ttl' => 1],
			'stale' => ['ttl' => 1, 'stale' => 60],
			'edit' => ['ttl' => 300, 'tags' => [static::TAG]],
			'soft' => ['ttl' => 300, 'tags' => [static::TAG], 'stale' => 60, 'soft' => true],
		};
	}
	
	protected static function sleepUntil(
		float $ms,
	): void
	{
		$left = $ms - Events::now();
		if($left > 0)
		{
			usleep((int)($left * 1000));
		}
	}
}
