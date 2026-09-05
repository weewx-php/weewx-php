<?php

declare(strict_types=1);

namespace WeewxPhp\Config;

use WeewxPhp\Upload\Kind;
use WeewxPhp\Upload\Trigger;

/**
 * One upload as `[Uploads]` announces it: which service, whose readings,
 * when it runs, and the service's own settings, read against the kind's
 * spec so that every key is there and of its type.
 */
final class UploadConfig
{
    /**
     * @param string $archive The id of the archive whose readings go.
     * @param int $every Seconds between runs on the `interval` trigger.
     * @param int $catchUp How many missed records one run may send; 0 means only the newest.
     * @param int $timeout Seconds one request may take.
     * @param int $stale How old the newest record may be and still be posted as current.
     * @param array<string, string|int|float|bool|list<string>|null> $options The kind's own settings,
     *     every key of {@see Kind::spec()} present, defaults filled in.
     */
    public function __construct(
        public readonly string $id,
        public readonly Kind $kind,
        public readonly string $archive,
        public readonly Trigger $trigger,
        public readonly int $every,
        public readonly int $catchUp,
        public readonly int $timeout,
        public readonly int $stale,
        public readonly array $options,
    ) {}

    public function text(string $key): string
    {
        $value = $this->options[$key] ?? null;
        if (is_string($value)) {
            return $value;
        }
        return is_int($value) || is_float($value) ? (string) $value : '';
    }

    public function flag(string $key): bool
    {
        return ($this->options[$key] ?? false) === true;
    }

    public function int(string $key): ?int
    {
        $value = $this->options[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        return is_string($value) && is_numeric($value) ? (int) $value : null;
    }

    public function float(string $key): ?float
    {
        $value = $this->options[$key] ?? null;
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        return is_string($value) && is_numeric($value) ? (float) $value : null;
    }

    /** @return list<string> */
    public function list(string $key): array
    {
        $value = $this->options[$key] ?? null;
        if (is_array($value)) {
            return array_values(array_map(static fn(mixed $one): string => is_scalar($one) ? (string) $one : '', $value));
        }
        return is_string($value) && $value !== '' ? [$value] : [];
    }
}
