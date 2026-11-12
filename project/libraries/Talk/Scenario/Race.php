<?php
declare(strict_types=1);

namespace Talk\Scenario;

use Ovos\Cache\Store\KeyValue;
use Stores\Pages;
use Talk\Barrier;
use Talk\Config;
use Talk\Env;
use Talk\Events;
use Talk\Fleet;
use Talk\Parameter;
use Talk\Scenario;
use Talk\Sink;
use Talk\Sink\Buffer;
use Talk\Store\RedisClassic;

use function array_filter;
use function count;
use function explode;
use function sprintf;

/**
 * A slow page, a fast edit. Each round forces the race: a visitor's page is
 * missing and the server starts building it from the old text; while it is
 * being built the editor saves, and the article's tag is forgotten; the build
 * then returns the old text and the store saves it. Does the old page stay?
 *
 * Without the guard: our classic backend (Talk\Store\RedisClassic), as our
 * sites ran it. With it: php-library's Store\Redis. Each mode in a worker of
 * its own, so each one builds its store from its own configuration.
 */
class Race extends Scenario
{
	public const array LABELS = [
		'off' => 'A classic Redis backend (no guard)',
		'on' => 'php-library Redis (the guard)',
	];
	
	/**
	 * The guard's window of php-library's store (the classic backend has none)
	 */
	protected const int WINDOW_MS = 60_000;
	
	protected const string OLD = 'the old text';
	
	public function name(): string
	{
		return 'race';
	}
	
	public function parameters(): array
	{
		return [
			'rounds' => new Parameter(live: 1_000, min: 10, max: 5_000, label: 'rounds'),
			'modes' => new Parameter(live: 'off,on', options: ['off', 'on'], label: 'guard'),
		];
	}
	
	public function run(
		Sink $sink,
		array $params,
	): void
	{
		$redis = Config::redis();
		$rounds = (int)$params['rounds'];
		
		foreach(explode(',', (string)$params['modes']) as $mode)
		{
			Env::flush($redis);
			$sink->emit([
				'type' => 'segment',
				't' => 0,
				'mode' => $mode,
				'label' => static::LABELS[$mode],
				'rounds' => $rounds,
			]);
			
			$events = new Buffer($sink);
			(new Fleet($events, $redis))->run($this->name(), $this->runId(), 1, [
				'mode' => $mode,
				'rounds' => $rounds,
			], [
				'mode' => $mode,
			]);
			
			$stayed = count(array_filter($events->events(), static fn(array $event): bool => $event['type'] === 'round' && $event['stayed']));
			$sink->emit([
				'type' => 'summary',
				't' => Events::now(),
				'mode' => $mode,
				'rounds' => $rounds,
				'stayed' => $stayed,
			]);
			$this->check($sink, $mode === 'off' ? $stayed === $rounds : $stayed === 0, $mode === 'off'
				? sprintf('without the guard, the old page stays in every round (%d of %d)', $stayed, $rounds)
				: sprintf('with the guard, it stays in none (%d of %d)', $stayed, $rounds));
		}
	}
	
	public function work(
		array $job,
	): void
	{
		$mode = (string)$job['mode'];
		$store = static::store($mode);
		
		Barrier::arrive(Config::redis(), (string)$job['run']);
		for($round = 0; $round < (int)$job['rounds']; $round++)
		{
			$key = 'article-' . $round;
			$tag = 'article-' . $round;
			
			// a visitor's page is missing: built from the old text, and the editor saves meanwhile
			$store->get($key, static function() use ($store, $tag): string
			{
				$store->invalidateTags([$tag]);
				
				return static::OLD;
			}, ttl: 3600, tags: [$tag]);
			
			// what the cache holds now: the old page, or nothing
			Events::emit('round', [
				'round' => $round,
				'stayed' => $store->get($key) === static::OLD,
			]);
		}
	}
	
	/**
	 * Without the guard: our classic backend, as our sites ran it. With it: php-library's Store\Redis
	 */
	protected static function store(
		string $mode,
	): KeyValue
	{
		Config::patch($mode === 'off'
			? [
				'store' => RedisClassic::class,
			]
			: [
				'store' => 'Redis',
				'store_options' => [
					'invalidation_window_ms' => static::WINDOW_MS,
				],
			]);
		
		return (new Pages())->store();
	}
}
