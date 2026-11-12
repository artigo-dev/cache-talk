<?php

declare(strict_types=1);

/*
 * Compression of ~50 KB HTML, re-measured: deflate (Symfony's DeflateMarshaller),
 * gzip (php-library's Compression\Gzip) and zstd 3, on the generated fragment of
 * benchmarks/marshaller.php and on real pages. Also the fixed cost of one call.
 *
 *   php zbench.php <generator.php> <page.html>...
 */

namespace Talk\Bench;

use Closure;
use LogicException;

use function array_slice;
use function basename;
use function count;
use function file_get_contents;
use function gzdecode;
use function gzdeflate;
use function gzencode;
use function gzinflate;
use function hrtime;
use function intdiv;
use function mt_srand;
use function phpversion;
use function printf;
use function sort;
use function strlen;
use function zstd_compress;
use function zstd_uncompress;

use const PHP_VERSION;
use const ZLIB_VERSION;
use const ZSTD_VERSION_TEXT;

require $argv[1];

mt_srand(2026);
for($i = 0; $i < 100; ++$i)
{
	\productModel($i);
}
\smallValues(100, 50);
\smallValues(300, 50);
\smallValues(800, 50);

$inputs = [
	'generated fragment' => \htmlFragment(50 * 1024),
];
foreach(array_slice($argv, 2) as $page)
{
	$inputs[basename($page)] = file_get_contents($page);
}
$inputs['16 bytes (fixed cost)'] = 'cache-key-12345!';

$codecs = [
	'deflate -1 (gzinflate)' => [
		static fn(string $value): string => gzdeflate($value, -1),
		static fn(string $frame): string => gzinflate($frame),
	],
	'gzip -1 (gzdecode)' => [
		static fn(string $value): string => gzencode($value, -1),
		static fn(string $frame): string => gzdecode($frame),
	],
	'zstd 3 (zstd_uncompress)' => [
		static fn(string $value): string => zstd_compress($value, 3),
		static fn(string $frame): string => zstd_uncompress($frame),
	],
];

/**
 * ns per call: the median of 9 runs, each repeating $call for at least 50 ms
 */
$medianNs = static function(
	Closure $call,
): float
{
	$call();
	$samples = [];
	
	for($run = 0; $run < 9; ++$run)
	{
		$calls = 0;
		$started = hrtime(true);
		do
		{
			for($i = 0; $i < 20; ++$i)
			{
				$call();
			}
			$calls += 20;
			$elapsed = hrtime(true) - $started;
		}
		while($elapsed < 50_000_000);
		
		$samples[] = $elapsed / $calls;
	}
	sort($samples);
	
	return $samples[intdiv(count($samples), 2)];
};

printf("PHP %s, ext-zstd %s (libzstd %s), zlib %s\n\n", PHP_VERSION, phpversion('zstd'), ZSTD_VERSION_TEXT, ZLIB_VERSION);
printf("| input | bytes | codec | ratio | compress µs | decompress µs | decompress MB/s |\n|---|---:|---|---:|---:|---:|---:|\n");

foreach($inputs as $name => $value)
{
	foreach($codecs as $codec => [$compress, $decompress])
	{
		$frame = $compress($value);
		if($decompress($frame) !== $value)
		{
			throw new LogicException($codec.' does not round-trip '.$name);
		}
		$compressNs = $medianNs(static fn() => $compress($value));
		$decompressNs = $medianNs(static fn() => $decompress($frame));
		printf(
			"| %s | %d | %s | %.3f | %.2f | %.2f | %.0f |\n",
			$name,
			strlen($value),
			$codec,
			strlen($frame) / strlen($value),
			$compressNs / 1000,
			$decompressNs / 1000,
			strlen($value) / $decompressNs * 1000,
		);
	}
}
