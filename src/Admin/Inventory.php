<?php

declare(strict_types=1);

namespace WeewxPhp\Admin;

use WeewxPhp\Config\ArchiveConfig;
use WeewxPhp\Db\Sqlite;
use WeewxPhp\Measurement\Catalog;

/** Exact non-NULL counts; one read-only aggregate for the visible destinations. */
final class Inventory
{
    /** @param list<string> $columns
     * @return array{count: int, first: ?int, last: ?int, columns: array<string, array{count: int, first: ?int, last: ?int}>}
     */
    public static function read(ArchiveConfig $archive, array $columns): array
    {
        $columns = array_values(array_unique($columns));
        $schema = ReadModel::archive($archive)['schema'];
        $select = ['COUNT(*) AS records', 'MIN(dateTime) AS first', 'MAX(dateTime) AS last'];
        foreach ($columns as $index => $column) {
            Catalog::identifier($column);
            if (!$schema->hasColumn($column)) {
                throw new Problem('error.source');
            }
            $select[] = 'COUNT("' . $column . '") AS count' . $index;
            $select[] = 'MIN(CASE WHEN "' . $column . '" IS NOT NULL THEN dateTime END) AS first' . $index;
            $select[] = 'MAX(CASE WHEN "' . $column . '" IS NOT NULL THEN dateTime END) AS last' . $index;
        }
        $db = Sqlite::readOnly($archive->database);
        try {
            $row = $db->one('SELECT ' . implode(', ', $select) . ' FROM archive') ?? [];
        } finally {
            $db->close();
        }
        $fields = [];
        foreach ($columns as $index => $column) {
            $fields[$column] = ['count' => (int) Sqlite::text($row['count' . $index] ?? 0),
                'first' => is_int($row['first' . $index] ?? null) ? $row['first' . $index] : null,
                'last' => is_int($row['last' . $index] ?? null) ? $row['last' . $index] : null];
        }
        return ['count' => (int) Sqlite::text($row['records'] ?? 0),
            'first' => is_int($row['first'] ?? null) ? $row['first'] : null,
            'last' => is_int($row['last'] ?? null) ? $row['last'] : null, 'columns' => $fields];
    }

    /** Configuration history is evidence of assignments, not per-record provenance.
     * @return list<array{from: int, to: int, sources: list<string>, calculated: bool}>
     */
    public static function sources(ReadModel $read, ArchiveConfig $archive, string $column, int $first, int $last): array
    {
        $revisions = $read->mappingRevisions($archive->id);
        $result = [];
        foreach ($revisions as $index => $revision) {
            $boundary = $revision['boundary'];
            $from = max($first, $boundary + 1);
            $to = min($last, $revisions[$index + 1]['boundary'] ?? $last);
            if ($from > $to) {
                continue;
            }
            $sources = [];
            $calculated = false;
            // The initial baseline must not attribute an imported database's past.
            if ($boundary > 0) {
                $file = $revision['file'];
                $one = $file->root()->optionalSection('Archives')?->optionalSection($archive->id);
                if ($one?->optional('explicit_mapping')?->bool() === true) {
                    foreach ($one->optionalSection('fields')?->sections() ?? [] as $station => $fields) {
                        foreach ($fields->keys() as $source) {
                            if ($fields->value($source)->string() === $column) {
                                $name = $file->root()->optionalSection('Stations')?->optionalSection($station)?->optional('name')?->string() ?? $station;
                                $sources[] = $name . ' · ' . $source;
                            }
                        }
                    }
                    $policy = $one->optionalSection('calculate')?->optional($column)?->string();
                    $calculated = ($sources === [] || $policy === 'software') && isset(\WeewxPhp\Archive\Derivable::DEFAULTS[$column]) && $policy !== 'hardware';
                    if ($policy === 'software') {
                        $sources = [];
                    }
                }
            }
            $previous = count($result) - 1;
            if (isset($result[$previous]) && $result[$previous]['sources'] === $sources && $result[$previous]['calculated'] === $calculated) {
                $result[$previous]['to'] = $to;
            } else {
                $result[] = ['from' => $from, 'to' => $to, 'sources' => $sources, 'calculated' => $calculated];
            }
        }
        return $result === [] ? [['from' => $first, 'to' => $last, 'sources' => [], 'calculated' => false]] : $result;
    }
}
