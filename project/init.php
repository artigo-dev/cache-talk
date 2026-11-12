<?php
declare(strict_types=1);

namespace Ovos;

use function date_default_timezone_get;
use function date_default_timezone_set;

use const BASE_DIR;
use const DIRECTORY_SEPARATOR;

/**
 * Bootstrap shared by every entry point: the time zone and the Composer
 * autoloader
 */

if(date_default_timezone_get() === '')
{
	date_default_timezone_set('Europe/Vienna');
}

require_once BASE_DIR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
