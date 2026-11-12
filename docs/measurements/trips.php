<?php
declare(strict_types=1);

/*
 * What travels between PHP and Redis for one job: the steps sent from PHP
 * (pipelined, the classic way) against php-library's functions. Redis counts
 * it itself: INFO stats (total_net_input_bytes: what it received,
 * total_net_output_bytes: what it sent) and INFO commandstats. A function's
 * own commands run inside Redis: only its FCALL comes over the network.
 *
 *   php trips.php [copies]
 */

namespace Talk\Bench;

use Ovos\Application;
use Ovos\Store;
use Ovos\Store\Mysql\Traits\CacheService;
use Redis;
use Talk\Config;

use function array_map;
use function array_slice;
use function array_sum;
use function count;
use function define;
use function fwrite;
use function implode;
use function intdiv;
use function json_encode;
use function number_format;
use function preg_match_all;
use function printf;
use function range;
use function str_repeat;

use const STDERR;

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

$copies = (int)($argv[1] ?? 100_000);
$redis = Config::redis();
$store = (new Probe())->store();
$value = str_repeat('x', 200);
$tags = ['article-42', 'category-7', 'author-3', 'menu', 'front-page'];

/**
 * What Redis has received and sent so far, and how many commands of each kind it ran
 *
 * @return array{in: int, out: int, by: array<string, int>}
 */
$snapshot = static function() use ($redis): array
{
	$stats = $redis->info('stats');
	preg_match_all('/cmdstat_([a-z|_]+):calls=(\d+)/', (string)$redis->rawCommand('INFO', 'commandstats'), $calls);
	$by = [];
	foreach($calls[1] as $at => $name)
	{
		$by[$name] = (int)$calls[2][$at];
	}
	
	return [
		'in' => (int)$stats['total_net_input_bytes'],
		'out' => (int)$stats['total_net_output_bytes'],
		'by' => $by,
	];
};

/**
 * The difference a job made, the cost of taking the snapshots taken out
 *
 * @return array{in: int, out: int, by: array<string, int>}
 */
$measure = static function(callable $job) use ($snapshot): array
{
	$a = $snapshot();
	$b = $snapshot();
	$before = $snapshot();
	$job();
	$after = $snapshot();
	
	$by = [];
	foreach($after['by'] as $name => $count)
	{
		$delta = $count - ($before['by'][$name] ?? 0) - (($b['by'][$name] ?? 0) - ($a['by'][$name] ?? 0));
		if($delta !== 0)
		{
			$by[$name] = $delta;
		}
	}
	
	return [
		'in' => $after['in'] - $before['in'] - ($b['in'] - $a['in']),
		'out' => $after['out'] - $before['out'] - ($b['out'] - $a['out']),
		'by' => $by,
	];
};

/**
 * The classic save: the copy, its tag names, and the copy's name added to every tag's list
 */
$classicSave = static function(
	Redis $redis,
	string $key,
	array $tags,
) use ($value): void
{
	$redis->hSet('classic:' . $key, 'd', $value);
	$redis->hSet('classic:' . $key, 't', implode(',', $tags));
	$redis->expire('classic:' . $key, 3600);
	foreach($tags as $tag)
	{
		$redis->sAdd('classic:tag:' . $tag, $key);
	}
	$redis->sAdd('classic:tags', ...$tags);
};

/**
 * One row; the commands that came over the network: a function's FCALL, not what it ran inside Redis
 */
$print = static function(
	string $job,
	string $how,
	array $m,
	bool $functions,
	int $trips,
): void
{
	$sent = $functions ? ($m['by']['fcall'] ?? 0) + ($m['by']['fcall_ro'] ?? 0) : array_sum($m['by']);
	printf("| %s | %s | %s | %s | %s | %s |\n", $job, $how, number_format($trips), number_format($sent), number_format($m['in']), number_format($m['out']));
	fwrite(STDERR, $how . ': ' . json_encode($m['by']) . "\n");
};

$redis->flushDB();
// the store built and its function library loaded before anything is measured
$store->setMany(['warm-up' => $value], 60, ['warm-up']);
$store->invalidateTags(['warm-up']);
printf("| job | how | round trips | commands sent | bytes to Redis | bytes from Redis |\n|---|---|---:|---:|---:|---:|\n");

// 1. saving one page with 5 tags
$m = $measure(static function() use ($redis, $classicSave, $tags): void
{
	$pipe = $redis->multi(Redis::PIPELINE);
	$classicSave($pipe, 'front-page', $tags);
	$pipe->exec();
});
$print('save one page, 5 tags', 'steps from PHP (pipelined)', $m, false, 1);
$m = $measure(static fn() => $store->setMany(['front-page' => $value], 3600, $tags));
$print('save one page, 5 tags', 'php-library functions', $m, true, ($m['by']['fcall'] ?? 0) + ($m['by']['fcall_ro'] ?? 0));

// 2. forgetting a tag on $copies copies
$redis->flushDB();
for($from = 0; $from < $copies; $from+= 1000)
{
	$pipe = $redis->multi(Redis::PIPELINE);
	$batch = [];
	foreach(range($from, $from + 999) as $i)
	{
		$classicSave($pipe, 'page-' . $i, ['article-42']);
		$batch['page-' . $i] = $value;
	}
	$pipe->exec();
	$store->setMany($batch, 3600, ['article-42']);
}
$chunk = 10_000;
$m = $measure(static function() use ($redis, $chunk): void
{
	$names = $redis->sMembers('classic:tag:article-42');
	for($at = 0; $at < count($names); $at+= $chunk)
	{
		$redis->del(...array_map(static fn(string $name): string => 'classic:' . $name, array_slice($names, $at, $chunk)));
	}
	$redis->del('classic:tag:article-42');
});
$print('forget a tag on ' . number_format($copies) . ' copies', 'steps from PHP', $m, false, 1 + intdiv($copies + $chunk - 1, $chunk) + 1);
$m = $measure(static fn() => $store->invalidateTags(['article-42']));
$print('forget a tag on ' . number_format($copies) . ' copies', 'php-library functions', $m, true, ($m['by']['fcall'] ?? 0) + ($m['by']['fcall_ro'] ?? 0));
