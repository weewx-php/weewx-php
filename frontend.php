<?php

declare(strict_types=1);

use WeewxPhp\Frontend\Weather;

require_once __DIR__ . '/src/autoload.php';

$weatherConfig = getenv('WEEWX_PHP_CONF');
return Weather::open($weatherConfig === false || $weatherConfig === '' ? __DIR__ . '/weewx-php.conf' : $weatherConfig);
