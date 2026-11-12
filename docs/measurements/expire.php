<?php
declare(strict_types=1);

/*
 * When the pages expire on their own: what is left of the tag lists.
 * A million pages under 1 000 tags (64-byte values), every page with a short
 * TTL; then the pages are left to expire, and what remains is measured:
 *
 *   classic     our classic backend, as the talk's port runs it
 *               (Talk\Store\RedisClassic): a set per tag with every page's
 *               name, no expiry on the sets - the names stay until a garbage
 *               collector removes them
 *   Redis       php-library's Store\Redis: every entry of a tag's list
 *               expires with its page (hash fields with an expiry)
 *   Redisearch  php-library's Store\Redisearch: no lists, the index drops a
 *               page when it expires
 *
 *   php expire.php <classic|Redis|Redisearch> [tags] [pages per tag] [ttl s]
 */

namespace Talk\Bench;

use Ovos\Application;
use Ovos\Store;
use Ovos\Store\Mysql\Traits\CacheService;
use Redis;
use Talk\Config;
use Talk\Store\RedisClassic;

use function array_map;
use function array_sum;
use function define;
use function number_format;
use function printf;
use function range;
use function sleep;
use function str_repeat;

define('BASE_DIR', '/app/project/');
require BASE_DIR . 'init.php';
new Application(Application::INT_CLI);

final class Probe extends Store
{
	use CacheService;
	
	public const string TABLE = 'probe';
	
	public function __construct()
	{
		parent::__construct();
		
		$this->initCacheService();
	}
	
	public function store(): object
	{
		return $this->cacheService->getStore();
	}
}

$design = $argv[1] ?? 'classic';
$tagCount = (int)($argv[2] ?? 1000);
$perTag = (int)($argv[3] ?? 1000);
$ttl = (int)($argv[4] ?? 5);
$value = str_repeat('x', 64);

$redis = Config::redis(600.0);
$redis->flushDB();
$base = (int)$redis->info('memory')['used_memory'];

Config::patch(['store' => $design === 'classic' ? RedisClassic::class : $design]);
$store = (new Probe())->store();
if($store instanceof RedisClassic === false)
{
	$store->setMany(['warm-up' => $value], 1, ['warm-up']);
}

// 1. the pages, every one with a short life
foreach(range(0, $tagCount - 1) as $t)
{
	$tag = 'tag-' . $t;
	// the port's own writes, through a pipeline (save() takes two round trips a page)
	if($store instanceof RedisClassic)
	{
		$pipe = $redis->multi(Redis::PIPELINE);
		$store->queueNew($pipe, array_map(static fn(int $i): string => 'page-' . $t . '-' . $i, range(0, $perTag - 1)), $value, $ttl, $tag);
		$pipe->exec();
		
		continue;
	}
	
	$batch = [];
	foreach(range(0, $perTag - 1) as $i)
	{
		$batch['page-' . $t . '-' . $i] = $value;
	}
	$store->setMany($batch, $ttl, [$tag]);
}
$filled = (int)$redis->info('memory')['used_memory'] - $base;

// 2. every page expires, and php-library's guard lets go of its mark (the epoch a page keeps for
// invalidation_window_ms, 60 s, after its data expired); a full SCAN makes Redis drop the expired keys it
// has not reached yet
sleep($ttl + 65);
$cursor = null;
do
{
	$redis->scan($cursor, '*', 10_000);
}
while($cursor > 0);
sleep(5);

$left = (int)$redis->info('memory')['used_memory'] - $base;
$listed = $store instanceof RedisClassic
	? array_sum(array_map(static fn(int $t): int => (int)$redis->sCard($store->tagSet('tag-' . $t)), range(0, $tagCount - 1)))
	: null;

printf(
	"| %s | %s pages, %s tags, ttl %d s | after filling: %s MB | after every page expired: **%s MB** left, %s keys%s |\n",
	$design,
	number_format($tagCount * $perTag),
	number_format($tagCount),
	$ttl,
	number_format($filled / 1048576, 0),
	number_format($left / 1048576, 1),
	number_format((int)$redis->dbSize()),
	$listed === null ? '' : ', ' . number_format($listed) . ' names still listed',
);
