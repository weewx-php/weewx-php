<?php

declare(strict_types=1);

use WeewxPhp\Frontend\ThemeSite;

require_once dirname(__DIR__) . '/src/autoload.php';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$info = $_SERVER['PATH_INFO'] ?? '';
ThemeSite::asset(ThemeSite::configPath(), is_string($method) ? $method : '', is_string($info) ? $info : '')->send();
