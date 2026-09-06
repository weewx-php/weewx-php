<?php

declare(strict_types=1);

namespace WeewxPhp\Admin;

use WeewxPhp\Config\ArchiveConfig;
use WeewxPhp\Db\Sqlite;
use WeewxPhp\Measurement\Catalog;
use WeewxPhp\Weewx\Units;
use WeewxPhp\Weewx\UnitSystem;

/** Exact observation names only, before the first station has any assignments. */
final class MappingSuggestions
{
    /** @return array<string, array{native: string, value: int|float, unit: ?string, last_seen: ?int}> */
    public static function candidates(ReadModel $read, ArchiveConfig $archive): array
    {
        $senders = $archive->senders ?? [];
        if (!$archive->explicitMapping || count($senders) !== 1 || array_filter($archive->fields) !== []) {
            return [];
        }
        $info = ReadModel::archive($archive);
        if ($info['first'] === null) {
            return [];
        }
        $counts = [];
        $candidates = [];
        foreach ($read->fields($senders[0]) as $field) {
            $source = $field['observation'];
            if (!is_string($source)) {
                continue;
            }
            $counts[$source] = ($counts[$source] ?? 0) + 1;
            $value = $field['value'];
            if (!$info['schema']->hasColumn($source) || !Catalog::compatible($source, $source, $archive->measurementKinds)
                || (!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
                continue;
            }
            $candidates[$source] = ['native' => Sqlite::text($field['native']), 'value' => $value,
                'unit' => is_string($field['unit']) ? $field['unit'] : null,
                'last_seen' => is_int($field['last_seen']) ? $field['last_seen'] : null];
        }
        foreach ($counts as $source => $count) {
            if ($count !== 1) {
                unset($candidates[$source]);
            }
        }
        ksort($candidates);
        return $candidates;
    }

    /** Bind confirmation to the reviewed assignments, independently of fresh sensor readings.
     * @param array<string, array{native: string, value: int|float, unit: ?string, last_seen: ?int}> $candidates
     */
    public static function fingerprint(ArchiveConfig $archive, array $candidates): string
    {
        $names = [];
        foreach ($candidates as $source => $field) {
            $names[$source] = $field['native'];
        }
        return hash('sha256', json_encode([$archive->id, $archive->senders, $names], JSON_THROW_ON_ERROR));
    }

    /** One aggregate scan, then indexed timestamp lookups, including sparse columns.
     * @param list<string> $columns
     * @return array<string, array{value: int|float|null, unit: ?string, last_seen: ?int}>
     */
    public static function lastValues(ArchiveConfig $archive, array $columns): array
    {
        $inventory = Inventory::read($archive, $columns);
        $db = Sqlite::readOnly($archive->database);
        $values = [];
        try {
            foreach ($columns as $column) {
                Catalog::identifier($column);
                $time = $inventory['columns'][$column]['last'];
                $row = $time === null ? null : $db->one('SELECT "' . $column . '" AS value, usUnits FROM archive WHERE dateTime = ?', [$time]);
                $system = is_int($row['usUnits'] ?? null) ? UnitSystem::tryFrom($row['usUnits']) : null;
                $value = $row['value'] ?? null;
                $values[$column] = ['value' => is_int($value) || is_float($value) ? $value : null,
                    'unit' => $system === null ? null : Units::unitOf($system, $column, Catalog::groups($archive->measurementKinds))[0],
                    'last_seen' => $time];
            }
        } finally {
            $db->close();
        }
        return $values;
    }
}
