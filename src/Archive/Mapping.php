<?php

declare(strict_types=1);

namespace WeewxPhp\Archive;

use WeewxPhp\Config\ArchiveConfig;
use WeewxPhp\Live\Packet;
use WeewxPhp\Live\SenderIdentity;
use WeewxPhp\Weewx\Schema;
use WeewxPhp\Weewx\Wview;

/**
 * Which of a packet's readings go into which archive columns.
 *
 * One sender needs none of this: its readings go to the columns of the
 * same name. With two, both send `outTemp` and there is one `outTemp`, so
 * a place names one primary sender, whose readings are placed by name, and
 * every other sender writes only what `[[[fields]]]` has placed by hand,
 * plus its own housekeeping -- battery levels and signal strengths already
 * carry their sensor in the name. That is weewx-evo's arrangement, and its
 * reason: a second sender may be a whole second station or a single soil
 * probe, and which it is cannot be read off the protocol.
 *
 * `[[[fields]]]` moves a reading (`outTemp = extraTemp1`) or drops it
 * (`inTemp = -`), for the primary as much as for anyone. `indoor = false`
 * drops a sender's room readings unless one of them was placed by hand:
 * somebody who named a column has settled that question themselves.
 *
 * A database this application did not create is not written by name
 * unless the configuration says so, in `[[[fields]]]` or with
 * `auto_mapping`: its columns may hold years of some other sensor's
 * history, and a wrong value in a WeeWX archive is a value nothing can
 * tell from a right one afterwards.
 */
final class Mapping
{
    /** What an additional sender writes unplaced: names that already carry their sensor. */
    public const KEEPS = ['Batt', 'batt', '_rssi', '_sig', 'BatteryStatus'];

    private const INDOOR = ['inTemp', 'inHumidity', 'inDewpoint'];

    /** @var array<string, int> Readings of additional senders that had nowhere to go, by name and count. */
    private array $dropped = [];

    /**
     * @param string|null $primary The sender placed by name, as {@see resolvePrimary()} found it;
     *     null while nobody has been heard from.
     */
    public function __construct(
        private readonly ArchiveConfig $config,
        private readonly ?string $primary,
    ) {}

    /**
     * The primary sender: the configured one, else the selected sender
     * heard first, else none.
     *
     * @param iterable<SenderIdentity> $heard Every sender the journal knows, earliest first.
     */
    public static function resolvePrimary(ArchiveConfig $config, iterable $heard): ?string
    {
        if ($config->primary !== null) {
            return $config->primary;
        }
        foreach ($heard as $identity) {
            if ($config->selects($identity->sender)) {
                return $identity->sender;
            }
        }
        return null;
    }

    public function primary(): ?string
    {
        return $this->primary;
    }

    /**
     * Whether this mapping may write the database as it is.
     *
     * @param bool $foreign Whether the database was created by something other than this
     *     application, or holds records older than its creation here.
     *
     * @throws MappingError With what to change.
     */
    public function verify(Schema $schema, bool $foreign): void
    {
        $policy = $this->config->policy();
        foreach ($schema->columns as $column) {
            $kind = $this->config->measurementKinds[$column] ?? \WeewxPhp\Measurement\Catalog::extensionKind($column);
            if (\WeewxPhp\Measurement\Catalog::lastKind($kind) && $policy->of($column)->extractor !== \WeewxPhp\Weewx\Extractor::Last) {
                throw new MappingError('Snapshot measurement requires last aggregation: ' . $column);
            }
        }
        if (!$this->config->explicitMapping && $foreign && $this->primary !== null && !$this->config->autoMapping
            && !isset($this->config->fields[$this->primary])) {
            throw new MappingError(sprintf(
                'archive %s was not created by this application, so %s is not written by name: place its readings in [[[fields]]], or set auto_mapping = true to take the columns of the same name',
                $this->config->id,
                $this->primary,
            ));
        }
        $missing = [];
        foreach ($this->config->fields as $sender => $placements) {
            foreach ($placements as $raw => $target) {
                if ($target !== '-' && !$schema->hasColumn($target)) {
                    $missing[] = sprintf('%s.%s = %s', $sender, $raw, $target);
                }
                if ($target !== '-' && \WeewxPhp\Measurement\Catalog::extensionKind($raw) !== null) {
                    $kind = \WeewxPhp\Measurement\Catalog::kind($raw, $this->config->measurementKinds);
                    $targetKind = \WeewxPhp\Measurement\Catalog::kind($target, $this->config->measurementKinds);
                    if ($targetKind !== null && !\WeewxPhp\Measurement\Catalog::compatible($raw, $target, $this->config->measurementKinds)) {
                        throw new MappingError('Incompatible measurement types: ' . $raw . ' / ' . $target);
                    }
                    if (\WeewxPhp\Measurement\Catalog::lastKind($kind)
                        && $policy->of($target)->extractor !== \WeewxPhp\Weewx\Extractor::Last) {
                        throw new MappingError('Snapshot measurement requires last aggregation: ' . $target);
                    }
                }
            }
        }
        if ($missing !== []) {
            throw new MappingError(sprintf(
                'archive %s has no column for %s; add it under [[[columns]]] or place the reading elsewhere',
                $this->config->id,
                implode(', ', $missing),
            ));
        }
        if ($this->config->explicitMapping) {
            $writers = [];
            foreach ($this->config->fields as $sender => $fields) {
                if (!$this->config->selects($sender)) {
                    throw new MappingError('Source station is not selected by the archive');
                }
                foreach ($fields as $source => $target) {
                    if ($target === '-') {
                        continue;
                    }
                    $key = strtolower($target);
                    if (isset($writers[$key])) {
                        throw new MappingError('Archive column has more than one source: ' . $target);
                    }
                    $writers[$key] = $sender . '.' . $source;
                    if (in_array($target, Wview::NOT_OBSERVATIONS, true)) {
                        throw new MappingError('Reserved archive column');
                    }
                    if (!\WeewxPhp\Measurement\Catalog::compatible($source, $target, $this->config->measurementKinds)) {
                        throw new MappingError('Incompatible measurement types: ' . $source . ' / ' . $target);
                    }
                    if (!in_array(strtoupper($schema->columnTypes[$target] ?? ''), ['REAL', 'INTEGER'], true)) {
                        throw new MappingError('Measurement requires a numeric archive column');
                    }
                }
            }
        }
    }

    /** The effective destination, also used by the admin inventory. */
    public function target(string $sender, string $source): ?string
    {
        if (!$this->config->selects($sender) || in_array($source, Wview::NOT_OBSERVATIONS, true)) {
            return null;
        }
        $decision = $this->config->fields[$sender][$source] ?? null;
        if ($decision !== null) {
            return $decision === '-' ? null : $decision;
        }
        if ($this->config->explicitMapping || ($sender !== $this->primary && !self::keeps($source))) {
            return null;
        }
        return !$this->config->takesIndoor($sender) && in_array($source, self::INDOOR, true) ? null : $source;
    }

    /**
     * One packet in this archive's column names, with `dateTime`,
     * `usUnits` and, for an archive-kind packet, `interval`; or null when
     * the packet said nothing this archive can hold.
     *
     * Null for a sender the archive does not select, for a packet in a
     * dialect nothing here translates, and for one whose every reading is
     * placed nowhere. A reading the console explicitly did not take is
     * dropped rather than kept as null: that is a measurement absent, not
     * a measurement of nothing.
     *
     * @return array<string, mixed>|null
     */
    public function place(Packet $packet): ?array
    {
        if ($packet->dialect !== null || !$this->config->selects($packet->sender)) {
            return null;
        }
        if ($this->config->explicitMapping) {
            $data = [];
            foreach ($packet->data as $source => $value) {
                $target = $this->target($packet->sender, $source);
                if ($target !== null && $value !== null) {
                    $this->verifyPacketField($packet, $source);
                    // A selected cumulative rain source feeds the existing per-sender
                    // delta calculation. The counter itself is never stored as rain.
                    if ($target === 'rain' && \WeewxPhp\Measurement\Catalog::kind($source, $this->config->measurementKinds) === 'rain_counter') {
                        $target = 'totalRain';
                    }
                    $data[$target] = $value;
                }
            }
            if ($data === []) {
                return null;
            }
            $data['dateTime'] = $packet->dateTime;
            $data['usUnits'] = $packet->unitSystem->value;
            if ($packet->interval !== null) {
                $data['interval'] = $packet->interval;
            }
            return $data;
        }
        $decisions = $this->config->fields[$packet->sender] ?? [];
        $placed = [];
        foreach ($decisions as $target) {
            if ($target !== '-') {
                $placed[$target] = true;
            }
        }

        $data = [];
        foreach ($packet->data as $raw => $value) {
            if ($value === null || in_array($raw, Wview::NOT_OBSERVATIONS, true)) {
                continue;
            }
            $target = $decisions[$raw] ?? $raw;
            if ($target === '-') {
                continue;
            }
            $this->verifyPacketField($packet, $raw);
            $data[$target] = $value;
        }

        if ($packet->sender !== $this->primary) {
            foreach (array_keys($data) as $column) {
                if (!isset($placed[$column]) && !self::keeps($column)) {
                    unset($data[$column]);
                    $this->dropped[$column] = ($this->dropped[$column] ?? 0) + 1;
                }
            }
        }
        if (!$this->config->takesIndoor($packet->sender)) {
            foreach (self::INDOOR as $column) {
                if (!isset($placed[$column])) {
                    unset($data[$column]);
                }
            }
        }
        if ($data === []) {
            return null;
        }

        $data['dateTime'] = $packet->dateTime;
        $data['usUnits'] = $packet->unitSystem->value;
        if ($packet->interval !== null) {
            $data['interval'] = $packet->interval;
        }
        return $data;
    }

    private function verifyPacketField(Packet $packet, string $source): void
    {
        $definitions = $packet->mapping['weewx_php_fields'] ?? null;
        $definition = is_array($definitions) ? ($definitions[$source] ?? null) : null;
        if (!is_array($definition)) {
            return;
        }
        $kind = \WeewxPhp\Measurement\Catalog::kind($source, $this->config->measurementKinds);
        $group = $kind === null ? null : (\WeewxPhp\Measurement\Catalog::KINDS[$kind] ?? null);
        if ($group === null || ($definition['kind'] ?? null) !== $kind
            || ($definition['unit'] ?? null) !== \WeewxPhp\Weewx\Units::standardUnit($packet->unitSystem, $group)) {
            throw new MappingError('Stored source definition does not match the archive revision: ' . $source);
        }
    }

    /** @return array<string, int> Readings of additional senders that had nowhere to go, by name and count. */
    public function dropped(): array
    {
        return $this->dropped;
    }

    /** Whether an additional sender may write this column unplaced. */
    public static function keeps(string $column): bool
    {
        foreach (self::KEEPS as $suffix) {
            if (str_ends_with($column, $suffix)) {
                return true;
            }
        }
        return false;
    }
}
