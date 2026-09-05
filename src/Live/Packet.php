<?php

declare(strict_types=1);

namespace WeewxPhp\Live;

use WeewxPhp\Weewx\UnitSystem;

/**
 * One reading as it arrived, before anything was done to it.
 *
 * `data` holds the readings under the names the sender used, in the unit
 * system `unitSystem` names. With no dialect the names are WeeWX's own,
 * which is what this application's archiver reads; a dialect names a
 * vocabulary an ingest catalog would have to translate, and such packets
 * wait in the journal until something can.
 */
final class Packet
{
    /**
     * @param string $sender The id an archive selects on: the key of a `[[station]]`.
     * @param array<string, mixed> $data Readings by name.
     * @param string $driver Which ingest read the packet; the journal keeps it beside the identity.
     * @param string $identity What the hardware calls itself: a PASSKEY, a serial, '' for none.
     * @param string|null $dialect The vocabulary of the names, or null for WeeWX's.
     * @param array<string, mixed>|null $mapping A stored description of that vocabulary, JSON values only.
     * @param float|null $interval Minutes an archive-kind packet stands for.
     * @param int|null $received When the packet reached the server; null for "now, when stored".
     * @param string|null $raw The upload as it came off the wire, while it is kept.
     * @param list<string> $volatile Names in `data` that say something about the console rather
     *     than the weather -- uptime, free heap -- and are left out of the digest, so a console
     *     retransmitting the same readings a second later sends the same packet.
     */
    public function __construct(
        public readonly int $dateTime,
        public readonly UnitSystem $unitSystem,
        public readonly array $data,
        public readonly string $sender,
        public readonly string $driver = 'unknown',
        public readonly string $identity = '',
        public readonly ?string $dialect = null,
        public readonly ?array $mapping = null,
        public readonly PacketKind $kind = PacketKind::Loop,
        public readonly ?float $interval = null,
        public readonly ?int $received = null,
        public readonly ?string $raw = null,
        public readonly array $volatile = [],
    ) {}

    /**
     * A short hash of the measurements, so a retransmission is not a new
     * packet. The same canonical JSON as weewx-evo hashes: sorted keys, no
     * spaces, so a journal the two share agrees about what is a duplicate.
     */
    public function digest(): string
    {
        $payload = $this->volatile === []
            ? $this->data
            : array_diff_key($this->data, array_flip($this->volatile));
        if (isset($this->mapping['weewx_php_fields'])) {
            $payload['__weewx_php_fields'] = $this->mapping['weewx_php_fields'];
        }
        return substr(hash('sha256', self::canonical($payload)), 0, 16);
    }

    /**
     * The packet as an observation record, ready for the accumulator.
     *
     * @return array<string, mixed>
     */
    public function record(): array
    {
        $record = $this->data;
        $record['dateTime'] = $this->dateTime;
        $record['usUnits'] = $this->unitSystem->value;
        if ($this->interval !== null) {
            $record['interval'] = $this->interval;
        }
        return $record;
    }

    /**
     * JSON with the keys sorted and nothing between the tokens: what
     * Python's `json.dumps(sort_keys=True, separators=(",", ":"))` writes.
     *
     * @param array<string, mixed> $data
     */
    public static function canonical(array $data): string
    {
        ksort($data, SORT_STRING);
        foreach ($data as $key => $value) {
            if (is_array($value) && !array_is_list($value)) {
                ksort($value, SORT_STRING);
                $data[$key] = $value;
            }
        }
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
