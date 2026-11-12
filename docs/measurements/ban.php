<?php
declare(strict_types=1);

/*
 * Forgetting a million pages: the same ban for every design, on one machine.
 * The shape of php-library's README ("Bans"): 1 000 tags with 1 000 pages
 * each (64-byte values, one tag per page), forgotten 50 tags per call.
 *
 *   classic     the classic design as Cm_Cache_Backend_Redis runs it with
 *               use_lua off: a hash per page (zc:k:<id>), a set per tag
 *               (zc:ti:<tag>); a ban is SUNION of the tag sets, then UNLINK
 *               of the pages in chunks of 10 000, every chunk its own
 *               MULTI/EXEC, then UNLINK of the sets
 *   classic-lua the same, as it runs by default (use_lua on): one EVAL of the
 *               backend's own clean script per call, SUNION and an UNLINK
 *               per page inside Redis
 *   classic-cms the CMS's classic backend, an older fork of the same design,
 *               as the talk's port runs it (Talk\Store\RedisClassic): SUNION
 *               of the tag sets, then one MULTI/EXEC with a single DEL of
 *               every page, the DEL of the sets and the SREM of the tags (its
 *               "update the other tags" loop reads SMEMBERS inside the MULTI,
 *               gets no list back from phpredis, and does nothing)
 *   Redis       php-library's Store\Redis: tag hashes kept by Lua functions,
 *               forgotten 7 500 per call
 *   Redisearch  php-library's Store\Redisearch: the tags indexed by the query
 *               engine, the matches read a page at a time
 *   RedisVersioned  php-library's versioned store: one rule per call
 *
 * A second client PINGs all along the ban: how long everyone else waits.
 * After the ban a sample of pages is read back: every one must be gone (or,
 * versioned, outdated). Run in the talk's app container:
 *
 *   php ban.php <classic|classic-lua|classic-cms|Redis|Redisearch|RedisVersioned> [tags] [pages per tag] [tags per call] [expired names per tag]
 *
 * The last one plants the names of pages that expired long ago in the classic
 * lists (they have no expiry: after days in production they hold many more
 * names than live pages); the stores keep no such names.
 */

namespace Talk\Bench;

use Ovos\Application;
use Ovos\Store;
use Ovos\Store\Mysql\Traits\CacheService;
use Redis;
use RuntimeException;
use Talk\Config;
use Talk\Store\RedisClassic;

use function array_chunk;
use function array_map;
use function count;
use function define;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function hrtime;
use function in_array;
use function intdiv;
use function json_decode;
use function json_encode;
use function min;
use function number_format;
use function pcntl_fork;
use function pcntl_waitpid;
use function printf;
use function random_int;
use function range;
use function sort;
use function sprintf;
use function str_repeat;
use function touch;
use function unlink;
use function usleep;

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
$perCall = (int)($argv[4] ?? 50);
$garbage = (int)($argv[5] ?? 0);
$value = str_repeat('x', 64);
$tags = array_map(static fn(int $t): string => 'tag-' . $t, range(0, $tagCount - 1));

// Cm_Cache_Backend_Redis::LUA_CLEAN_SCRIPT, verbatim
const CM_CLEAN = "--!df flags=allow-undeclared-keys
"
	. "for i = 1, #KEYS, ARGV[6] do "
	. "local prefixedTags = {} "
	. "for x, tag in ipairs(KEYS) do "
	. "prefixedTags[x] = ARGV[1]..tag "
	. "end "
	. "local keysToDel = redis.call('SUNION', unpack(prefixedTags, i, math.min(#prefixedTags, i + ARGV[6] - 1))) "
	. "for _, keyname in ipairs(keysToDel) do "
	. "redis.call('UNLINK', ARGV[2]..keyname) "
	. "if (ARGV[5] == '1') then "
	. "redis.call('SREM', ARGV[4], keyname) "
	. "end "
	. "end "
	. "redis.call('UNLINK', unpack(prefixedTags, i, math.min(#prefixedTags, i + ARGV[6] - 1))) "
	. "redis.call('SREM', ARGV[3], unpack(KEYS, i, math.min(#KEYS, i + ARGV[6] - 1))) "
	. "end "
	. "return true";

// Cm_Cache_Backend_Redis, emulated below; the CMS's backend is the port, a store like the others
$classic = in_array($design, ['classic', 'classic-lua'], true);
$redis = Config::redis(600.0);
$redis->flushDB();

$store = null;
if($classic === false)
{
	Config::patch(['store' => $design === 'classic-cms' ? RedisClassic::class : $design]);
	$store = (new Probe())->store();
	if($store instanceof RedisClassic === false)
	{
		$store->setMany(['warm-up' => $value], 60, ['warm-up']);
		$store->invalidateTags(['warm-up']);
	}
}

// 1. the pages, every design in its own layout
$started = hrtime(true);
foreach($tags as $t => $tag)
{
	if($store === null)
	{
		$pipe = $redis->multi(Redis::PIPELINE);
		foreach(range(0, $perTag - 1) as $i)
		{
			$id = 'page-' . $t . '-' . $i;
			$pipe->hMSet('zc:k:' . $id, ['d' => $value, 't' => $tag, 'm' => 1]);
			$pipe->expire('zc:k:' . $id, 3600);
			$pipe->sAdd('zc:ti:' . $tag, $id);
		}
		$pipe->sAdd('zc:tags', $tag);
		$pipe->exec();
		if($garbage > 0)
		{
			for($from = 0; $from < $garbage; $from+= 10_000)
			{
				$redis->sAdd('zc:ti:' . $tag, ...array_map(static fn(int $i): string => 'expired-' . $t . '-' . $i, range($from, min($garbage, $from + 10_000) - 1)));
			}
		}
		
		continue;
	}
	
	// the port's own writes, through a pipeline (save() takes two round trips a page)
	if($store instanceof RedisClassic)
	{
		foreach(array_chunk(range(0, $perTag - 1), 10_000) as $chunk)
		{
			$pipe = $redis->multi(Redis::PIPELINE);
			$store->queueNew($pipe, array_map(static fn(int $i): string => 'page-' . $t . '-' . $i, $chunk), $value, 3600, $tag);
			$pipe->exec();
		}
		for($from = 0; $from < $garbage; $from+= 10_000)
		{
			$redis->sAdd($store->tagSet($tag), ...array_map(static fn(int $i): string => $store->itemId('expired-' . $t . '-' . $i), range($from, min($garbage, $from + 10_000) - 1)));
		}
		
		continue;
	}
	
	$batch = [];
	foreach(range(0, $perTag - 1) as $i)
	{
		$batch['page-' . $t . '-' . $i] = $value;
	}
	$store->setMany($batch, 3600, [$tag]);
}
$filledS = (hrtime(true) - $started) / 1e9;
$memory = (int)$redis->info('memory')['used_memory'];

// 2. the ban: every tag, $perCall per call, while a second client PINGs all along: how long everyone else waits
$stopFile = '/tmp/ban-probe.stop';
$resultFile = '/tmp/ban-probe.json';
@unlink($stopFile);
@unlink($resultFile);
$probe = pcntl_fork();
if($probe === 0)
{
	$client = Config::redis(60.0);
	$waits = [];
	while(file_exists($stopFile) === false)
	{
		$sent = hrtime(true);
		$client->ping();
		$waits[] = (hrtime(true) - $sent) / 1e6;
	}
	sort($waits);
	file_put_contents($resultFile, json_encode([
		'p50' => $waits[intdiv(count($waits), 2)],
		'p99' => $waits[(int)(count($waits) * 0.99)],
		'max' => $waits[count($waits) - 1],
	]));
	exit(0);
}
usleep(200_000);
$started = hrtime(true);
foreach(array_chunk($tags, $perCall) as $chunk)
{
	if($store !== null)
	{
		$store->invalidateTags($chunk);
		
		continue;
	}
	
	if($design === 'classic-lua')
	{
		$redis->eval(CM_CLEAN, [...$chunk, 'zc:ti:', 'zc:k:', 'zc:tags', 'zc:ids', '0', '5000'], count($chunk));
		
		continue;
	}
	
	$sets = array_map(static fn(string $tag): string => 'zc:ti:' . $tag, $chunk);
	$ids = $redis->sUnion(...$sets);
	foreach(array_chunk($ids, 10_000) as $part)
	{
		$redis->multi();
		$redis->unlink(...array_map(static fn(string $id): string => 'zc:k:' . $id, $part));
		$redis->exec();
	}
	$redis->multi();
	$redis->unlink(...$sets);
	$redis->sRem('zc:tags', ...$chunk);
	$redis->exec();
}
$banS = (hrtime(true) - $started) / 1e9;
touch($stopFile);
pcntl_waitpid($probe, $status);
$ping = json_decode((string)file_get_contents($resultFile), true);

// 3. every page gone (a sample of 200)
$left = 0;
for($n = 0; $n < 200; $n++)
{
	$id = 'page-' . random_int(0, $tagCount - 1) . '-' . random_int(0, $perTag - 1);
	$there = $store === null
		? $redis->exists('zc:k:' . $id) > 0
		: $store->get($id, static fn(): string => 'rebuilt') !== 'rebuilt';
	$left+= $there ? 1 : 0;
}
if($left > 0)
{
	throw new RuntimeException(sprintf('%s: %d of 200 sampled pages survived the ban.', $design, $left));
}

printf(
	"| %s (%s) | %s pages, %s tags, %d per call, %s expired names in the lists | filled in %.1f s, %s MB | **ban %.2f s** | PING p50 / p99 / max %.1f / %.1f / %.1f ms | 200 of 200 sampled pages gone |
",
	$design,
	$store === null ? ['classic' => 'Cm_Cache_Backend_Redis, no Lua', 'classic-lua' => 'Cm_Cache_Backend_Redis, Lua'][$design] : $store::class,
	number_format($tagCount * $perTag),
	number_format($tagCount),
	$perCall,
	number_format($garbage * $tagCount),
	$filledS,
	number_format($memory / 1048576, 0),
	$banS,
	$ping['p50'],
	$ping['p99'],
	$ping['max'],
);
