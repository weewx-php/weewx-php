<?php

declare(strict_types=1);

use WeewxPhp\Ingest\Http;

require dirname(__DIR__, 2) . '/src/autoload.php';

Http::serve(dirname(__DIR__, 2), '/ingest/weewx.php', native: true);
