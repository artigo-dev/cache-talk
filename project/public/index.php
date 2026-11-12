<?php
declare(strict_types=1);

namespace Ovos;

use function define;
use function dirname;

use const DIRECTORY_SEPARATOR;

/**
 * Entry point of the HTTP application: the live API the deck talks to
 * (/api/ping, /api/run). The deck itself is static and lives in ../../site.
 */

// the project root, one above public/, with a trailing separator
define('BASE_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR);

require_once BASE_DIR . 'init.php';

$app = new Application();
$app->run();
