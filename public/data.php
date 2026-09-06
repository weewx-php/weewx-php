<?php

declare(strict_types=1);

use WeewxPhp\Frontend\ThemeSite;

require_once dirname(__DIR__) . '/src/autoload.php';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
ThemeSite::respond(ThemeSite::configPath(), is_string($method) ? $method : '', $_GET, snapshot: true, cookies: $_COOKIE)->send();
