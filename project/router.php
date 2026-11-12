<?php
declare(strict_types=1);

/**
 * Router script for PHP's built-in server: the deck from ../site, the live
 * API from the application
 *
 *   php -S 0.0.0.0:8080 -t ../site router.php
 */

$path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if(str_starts_with($path, '/api/') === false)
{
	// a file of the deck. Served here, not by the built-in server (which drops our headers): the
	// browser asks again every time, so an edit shows at the next reload, never an older script
	// beside a newer recording; unchanged files cost a 304
	$root = (string)realpath($_SERVER['DOCUMENT_ROOT']);
	$file = realpath($root . (str_ends_with($path, '/') ? $path . 'index.html' : $path));
	if($file === false || is_file($file) === false || str_starts_with($file, $root . DIRECTORY_SEPARATOR) === false)
	{
		http_response_code(404);
		
		return true;
	}
	
	$types = [
		'html' => 'text/html; charset=utf-8',
		'js' => 'text/javascript; charset=utf-8',
		'css' => 'text/css; charset=utf-8',
		'json' => 'application/json',
		'woff2' => 'font/woff2',
		'svg' => 'image/svg+xml',
		'png' => 'image/png',
		'ico' => 'image/x-icon',
		'md' => 'text/markdown; charset=utf-8',
		'txt' => 'text/plain; charset=utf-8',
	];
	$modified = gmdate('D, d M Y H:i:s', (int)filemtime($file)) . ' GMT';
	header('Content-Type: ' . ($types[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
	header('Cache-Control: no-cache');
	header('Last-Modified: ' . $modified);
	if(($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '') === $modified)
	{
		http_response_code(304);
		
		return true;
	}
	
	readfile($file);
	
	return true;
}

$_SERVER['REQUEST_SCHEME'] ??= 'http';
$_SERVER['QUERY_STRING'] ??= (string)(parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY) ?? '');
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/public/index.php';

require __DIR__ . '/public/index.php';
