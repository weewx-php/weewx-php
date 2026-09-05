<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../../Unit/Support/Archives.php';

use WeewxPhp\Archive\Budget;
use WeewxPhp\Config\Config;
use WeewxPhp\Frontend\Cache;
use WeewxPhp\Frontend\ReadBudget;
use WeewxPhp\Frontend\Weather;
use WeewxPhp\Frontend\Worker;
use WeewxPhp\Log\MemoryLogger;
use WeewxPhp\Tests\Support\Archives;
use WeewxPhp\Tick\Runtime;
use WeewxPhp\Time\FixedClock;

$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$archive = Archives::config(database: $input['path'], timezone: 'UTC');
$config = new Config(Archives::settings(dirname($input['path'])), [], [$archive->id => $archive], []);
$clock = new FixedClock($input['end']);
$wx = new Weather($config, clock: $clock);
$before = hrtime(true);
$total = $wx->alltime()->sum('rain')->raw();
$coldMs = (hrtime(true) - $before) / 1e6;
$wx->close();
$wx = new Weather($config, clock: $clock);
$before = hrtime(true);
$raw = $wx->between($input['start'] + 1, $input['end'] - 1)->sum('rain')->get();
$rawMs = (hrtime(true) - $before) / 1e6;
$wx->close();
$wx = new Weather($config, clock: $clock, budget: new ReadBudget(maxRows: 0, maxStatements: 0));
$before = hrtime(true);
$warm = $wx->alltime()->sum('rain')->raw();
$warmMs = (hrtime(true) - $before) / 1e6;
$wx->close();
$wx = new Weather($config, clock: $clock);
$recipe = $wx->alltime()->series('rain', 'day')->completed(0.95)->rank(1)->nightly();
$id = $recipe->register();
$runtime = Runtime::of($config, $clock, new MemoryLogger());
$cache = new Cache($config->settings);
$before = hrtime(true);
$passes = 0;
try {
    do {
        $answer = (new Worker($runtime))->run(Budget::of($clock, 5, 10000));
        if ($answer['failed'] !== 0 || ++$passes > 100) {
            throw new RuntimeException('Analysis failed to resume');
        }
    } while (!is_string($cache->request($id)['payload'] ?? null));
    $analysisMs = (hrtime(true) - $before) / 1e6;
    $prepared = $wx->cacheOnly();
    $rank = $prepared->alltime()->series('rain', 'day')->completed(0.95)->rank(1)->nightly()->report();
    $prepared->close();
} finally {
    $wx->close();
    $cache->close();
    $runtime->close();
}
echo json_encode(['total' => $total, 'warm' => $warm, 'rawStatus' => $raw->status, 'coldMs' => $coldMs, 'warmMs' => $warmMs, 'rawMs' => $rawMs,
    'rankCount' => $rank->meta['populationCount'], 'rankStart' => $rank->periods->points[0]['start'],
    'rankValue' => $rank->periods->points[0]['value'], 'analysisMs' => $analysisMs, 'passes' => $passes], JSON_THROW_ON_ERROR);
