<?php
declare(strict_types=1);

namespace Ovos;

use function define;

use const DIRECTORY_SEPARATOR;

/**
 * Entry point of the CLI application:
 *
 *   php cli.php demo run scenario stampede workers 24
 *   php cli.php demo record
 *
 * and of every worker a run starts (`php cli.php demo worker job …`): each
 * one boots the application the way an FPM request would.
 */

// the project root, this file's directory, with a trailing separator
define('BASE_DIR', __DIR__ . DIRECTORY_SEPARATOR);

require_once BASE_DIR . 'init.php';

$app = new Application(Application::INT_CLI);
$app->run();
