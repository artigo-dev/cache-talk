<?php
declare(strict_types=1);

namespace Talk\Scenario;

use Ovos\Cache\Store\KeyValue;
use Random\Engine\Mt19937;
use Random\Randomizer;
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
use function array_flip;
use function count;
use function explode;
use function intdiv;
use function max;
use function range;
use function sprintf;

/**
 * A slow page, a fast edit. In every round a visitor's page is missing and
 * the server builds it. In one round of $every, picked at random (a fixed
 * seed, so every run picks the same ones), the race happens: the build starts
 * from the old text, the editor saves meanwhile and the article's tag is
 * forgotten, and the build then returns the old text and the store saves it.
 * Does the old page stay? The other rounds have no edit: the page is built
 * and saved, and it has to be in the cache afterwards, guard or not.
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
	
	protected const string CURRENT = 'the current text';
	
	/**
	 * Picks the rounds with the race, the same ones in every run and mode
	 */
	protected const int SEED = 2026;
	
	public function name(): string
	{
		return 'race';
	}
	
	public function parameters(): array
	{
		return [
			'rounds' => new Parameter(live: 1_000, min: 10, max: 5_000, label: 'rounds'),
			'every' => new Parameter(live: 10, min: 1, max: 100, label: 'an edit mid-build in one round of'),
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
		$every = (int)$params['every'];
		
		foreach(explode(',', (string)$params['modes']) as $mode)
		{
			Env::flush($redis);
			$sink->emit([
				'type' => 'segment',
				't' => 0,
				'mode' => $mode,
				'label' => static::LABELS[$mode],
				'rounds' => $rounds,
				'every' => $every,
			]);
			
			$events = new Buffer($sink);
			(new Fleet($events, $redis))->run($this->name(), $this->runId(), 1, [
				'mode' => $mode,
				'rounds' => $rounds,
				'every' => $every,
			], [
				'mode' => $mode,
			]);
			
			$played = array_filter($events->events(), static fn(array $event): bool => $event['type'] === 'round');
			$races = count(array_filter($played, static fn(array $event): bool => $event['race']));
			$stayed = count(array_filter($played, static fn(array $event): bool => $event['race'] && $event['stayed']));
			$cached = count(array_filter($played, static fn(array $event): bool => $event['race'] === false && $event['cached']));
			$sink->emit([
				'type' => 'summary',
				't' => Events::now(),
				'mode' => $mode,
				'rounds' => $rounds,
				'races' => $races,
				'stayed' => $stayed,
				'cached' => $cached,
			]);
			$this->check($sink, $mode === 'off' ? $stayed === $races : $stayed === 0, $mode === 'off'
				? sprintf('without the guard, the old page stays after every edit mid-build (%d of %d)', $stayed, $races)
				: sprintf('with the guard, it stays after none (%d of %d)', $stayed, $races));
			$this->check($sink, $cached === $rounds - $races, sprintf('every round without an edit is cached as built (%d of %d)', $cached, $rounds - $races));
		}
	}
	
	public function work(
		array $job,
	): void
	{
		$mode = (string)$job['mode'];
		$rounds = (int)$job['rounds'];
		$store = static::store($mode);
		$races = static::races($rounds, (int)$job['every']);
		
		Barrier::arrive(Config::redis(), (string)$job['run']);
		for($round = 0; $round < $rounds; $round++)
		{
			$key = 'article-' . $round;
			$tag = 'article-' . $round;
			$race = isset($races[$round]);
			
			// a visitor's page is missing and is built; in a race round from
			// the old text, and the editor saves meanwhile
			$store->get($key, static function() use ($store, $tag, $race): string
			{
				if($race === false)
				{
					return static::CURRENT;
				}
				
				$store->invalidateTags([$tag]);
				
				return static::OLD;
			}, ttl: 3600, tags: [$tag]);
			
			// what the cache holds now: after a race the old page or nothing,
			// otherwise the page as built
			$held = $store->get($key);
			Events::emit('round', [
				'round' => $round,
				'race' => $race,
				'stayed' => $race && $held === static::OLD,
				'cached' => $race === false && $held === static::CURRENT,
			]);
		}
	}
	
	/**
	 * The rounds with the race: rounds / every of them, at random, the same
	 * ones in every run. With every = 1, all of them.
	 *
	 * @return array<int, int> the round numbers as keys
	 */
	protected static function races(
		int $rounds,
		int $every,
	): array
	{
		$count = intdiv($rounds, max(1, $every));
		if($count >= $rounds)
		{
			return array_flip(range(0, $rounds - 1));
		}
		
		return array_flip((new Randomizer(new Mt19937(static::SEED)))->pickArrayKeys(range(0, $rounds - 1), $count));
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
