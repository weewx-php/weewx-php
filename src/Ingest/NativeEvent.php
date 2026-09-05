<?php

declare(strict_types=1);

namespace WeewxPhp\Ingest;

use WeewxPhp\Live\Packet;
use WeewxPhp\Live\PacketKind;
use WeewxPhp\Weewx\UnitSystem;

/** An immutable observation; delivery identity is separate from measurement data. */
final class NativeEvent
{
    /** @param array<string, int|float|null> $data
     * @param array<string, string>|null $source
     */
    public function __construct(
        public readonly string $station,
        public readonly string $id,
        public readonly string $module,
        public readonly int $timestamp,
        public readonly UnitSystem $units,
        public readonly array $data,
        public readonly PacketKind $kind = PacketKind::Loop,
        public readonly ?float $interval = null,
        public readonly ?array $source = null,
    ) {}

    public function digest(): string
    {
        $record = [
            'station_id' => $this->station, 'driver_module' => $this->module,
            'dateTime' => $this->timestamp, 'usUnits' => $this->units->value,
            'kind' => $this->kind->value, 'data' => $this->data,
        ];
        // Preserve v1 LOOP receipts across upgrades.
        if ($this->interval !== null) {
            $record['interval'] = $this->interval;
        }
        if ($this->source !== null) {
            $record['source'] = $this->source;
        }
        return hash('sha256', Packet::canonical($record));
    }
}
