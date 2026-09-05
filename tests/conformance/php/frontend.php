<?php

declare(strict_types=1);

use WeewxPhp\Frontend\ArchiveReader;
use WeewxPhp\Frontend\Catalog;
use WeewxPhp\Frontend\Computation;
use WeewxPhp\Frontend\ReadBudget;
use WeewxPhp\Frontend\Span;
use WeewxPhp\Frontend\Spec;
use WeewxPhp\Tests\Support\Archives;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../../Unit/Support/Archives.php';

$input = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
$budget = new ReadBudget(maxRows: 1000000, maxStatements: 100000, milliseconds: 30000);
$reader = new ArchiveReader(Archives::config(database: $input['path']), $budget);
$answer = ['aggregates' => [], 'periods' => [], 'catalog' => Catalog::CORE];
try {
    foreach ($input['queries'] as $query) {
        $spec = Spec::fromJson(json_encode($query, JSON_THROW_ON_ERROR));
        $work = new Computation($spec, $reader, $reader->last ?? 0);
        while (!$work->step($budget)) {}
        $answer['aggregates'][] = $work->result($reader->last ?? 0);
    }
    foreach ($input['periods'] ?? [] as $period) {
        $span = Span::calendar($period['name'], $period['at'], new DateTimeZone($period['zone']), $period['ago'] ?? 0);
        $answer['periods'][] = [$span->start, $span->end];
    }
} finally {
    $reader->close();
}
echo json_encode($answer, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
