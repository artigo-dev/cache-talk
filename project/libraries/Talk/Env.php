<?php
declare(strict_types=1);

namespace Talk;

use Redis;
use RuntimeException;

use function date;
use function getenv;
use function gethostname;
use function php_uname;
use function sprintf;

use const PHP_VERSION;

/**
 * The Redis the scenarios may empty, and what a run was measured on
 */
class Env
{
	/**
	 * The scenarios empty the database they run on: only one the
	 * configuration says is theirs (talk.dedicated), or one that is empty
	 */
	public static function flush(
		Redis $redis,
	): void
	{
		if(Config::isDedicated() === false && $redis->dbSize() > 0)
		{
			throw new RuntimeException(sprintf(
				'Redis at %s:%d holds data and is not the talk\'s own; set TALK[DEDICATED] = yes in .env to let the scenarios empty it.',
				Config::redisHost(),
				Config::redisPort(),
			));
		}
		
		$redis->flushDB();
	}
	
	/**
	 * What a recording says about where it was made
	 *
	 * @return array<string, string>
	 */
	public static function describe(
		Redis $redis,
	): array
	{
		$server = $redis->info('server');
		
		return [
			'machine' => getenv('GITHUB_ACTIONS') === 'true'
				? 'GitHub Actions (' . (getenv('RUNNER_OS') ?: 'runner') . ')'
				: (string)(getenv('TALK_MACHINE') ?: gethostname()),
			'os' => php_uname('s') . ' ' . php_uname('r'),
			'php' => PHP_VERSION,
			'redis' => (string)($server['redis_version'] ?? '?'),
			'date' => date('Y-m-d'),
		];
	}
}
