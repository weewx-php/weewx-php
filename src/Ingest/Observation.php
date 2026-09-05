<?php

declare(strict_types=1);

namespace WeewxPhp\Ingest;

use WeewxPhp\Live\Packet;
use WeewxPhp\Weewx\UnitSystem;

final class Observation
{
    /**
     * @param array<string, float|null> $data Normalized WeeWX fields.
     * @param array<string, array{observation: ?string, value: ?float, unit: ?string}> $fields Native inventory.
     */
    public function __construct(
        public readonly Protocol $protocol,
        public readonly string $identity,
        public readonly string $model,
        public readonly int $timestamp,
        public readonly UnitSystem $units,
        public readonly array $data,
        public readonly string $sample,
        public readonly bool $deviceTime,
        public readonly array $fields = [],
        public readonly ?int $reportedTimestamp = null,
        public readonly string $timeReason = 'server',
    ) {}

    /** @param array<string, \WeewxPhp\Measurement\Source> $sources */
    public function packet(Sender $sender, int $received, array $sources = []): Packet
    {
        $data = $this->data;
        $definitions = [];
        foreach ($sources as $native => $source) {
            $field = $this->fields[$native] ?? null;
            if ($field === null || $field['observation'] !== null || $field['value'] === null) {
                continue;
            }
            if (array_key_exists($source->observation, $data)) {
                throw new Rejected('conflicting source definition');
            }
            $group = \WeewxPhp\Measurement\Catalog::KINDS[$source->kind];
            $unit = \WeewxPhp\Weewx\Units::standardUnit($this->units, $group);
            $data[$source->observation] = \WeewxPhp\Weewx\Units::convert($field['value'], $source->unit, $unit);
            $definitions[$source->observation] = ['kind' => $source->kind, 'unit' => $unit];
        }
        // WU identity is the persistent sender id, never the secret or the
        // device's non-unique ID. Two devices may use the same WU ID.
        return new Packet(
            $this->timestamp,
            $this->units,
            $data,
            $sender->id,
            $this->protocol->value,
            $this->protocol === Protocol::Ecowitt ? $this->identity : $sender->id,
            mapping: $definitions === [] ? null : ['weewx_php_fields' => $definitions, 'version' => 1],
            received: $received,
            raw: $this->sample,
        );
    }
}
