<?php

declare(strict_types=1);

namespace WeewxPhp\Ingest;

use WeewxPhp\Live\Packet;
use WeewxPhp\Weewx\UnitSystem;

/** An immutable observation; delivery identity is separate from measurement data. */
final class NativeEvent
{
    /** @param array<string, int|float|null> $data */
    public function __construct(
        public readonly string $station,
        public readonly string $id,
        public readonly string $module,
        public readonly int $timestamp,
        public readonly UnitSystem $units,
        public readonly array $data,
    ) {}

    public function digest(): string
    {
        return hash('sha256', Packet::canonical([
            'station_id' => $this->station, 'driver_module' => $this->module,
            'dateTime' => $this->timestamp, 'usUnits' => $this->units->value,
            'kind' => 'loop', 'data' => $this->data,
        ]));
    }
}
