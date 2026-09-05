<?php

declare(strict_types=1);

use WeewxPhp\Ingest\Http;

require dirname(__DIR__) . '/src/autoload.php';

Http::serve(dirname(__DIR__), '/receive.php');
