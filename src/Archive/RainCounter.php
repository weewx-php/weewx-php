<?php

declare(strict_types=1);

namespace WeewxPhp\Archive;

use DateTimeImmutable;
use DateTimeZone;

/** Counter baselines belong to one sender and one physical rain gauge. */
final class RainCounter
{
    public const FIELDS = ['totalRain', 'yearRain', 'monthRain', 'weekRain', 'dayRain', 'eventRain'];

    /** @var array<string, array<string, array{value: float, time: int, resolution: float}>> */
    private array $previous = [];
    /** @var array<string, int> Last accounted observation, including direct interval rain. */
    private array $through = [];

    public function __construct(private readonly DateTimeZone $timezone) {}

    /** @param array<string, mixed> $counters Values already converted and quality checked.
     * @param array<string, float> $resolutions Encoded counter steps in the same units as the values.
     * @return array{start: int, stop: int, amount: float|null, status: string, counter: string}
     */
    public function read(string $sender, int $time, array $counters, bool $hardware, array $resolutions = []): array
    {
        $through = $this->through[$sender] ?? null;
        $result = ['start' => $through ?? $time, 'stop' => $time, 'amount' => null, 'status' => 'baseline', 'counter' => ''];
        $candidates = [];
        $tolerances = [];
        $old = $this->previous[$sender] ?? [];
        $valid = false;
        foreach ($counters as $field => $value) {
            if ((!is_int($value) && !is_float($value)) || !is_finite($value) || $value < 0) {
                continue;
            }
            $before = $this->previous[$sender][$field] ?? null;
            $resolution = $resolutions[$field] ?? 0.0;
            $valid = true;
            $this->previous[$sender][$field] = ['value' => (float) $value, 'time' => $time, 'resolution' => $resolution];
            // A returning counter cannot include an amount already booked through another source.
            if ($before === null || ($through !== null && $before['time'] !== $through)) {
                continue;
            }
            $delta = (float) $value - $before['value'];
            $tolerances[$field] = max($resolution, $before['resolution']);
            $period = match ($field) {
                'yearRain' => 'Y', 'monthRain' => 'Y-m', 'weekRain' => 'o-W', 'dayRain' => 'Y-m-d',
                'totalRain' => isset($counters['yearRain'], $old['yearRain'])
                    && $counters['yearRain'] === $value && $old['yearRain']['value'] === $before['value'] ? 'Y' : null,
                default => null,
            };
            $boundary = $period !== null && (new DateTimeImmutable('@' . $before['time']))->setTimezone($this->timezone)->format($period)
                !== (new DateTimeImmutable('@' . $time))->setTimezone($this->timezone)->format($period);
            $uncertainEvent = $field === 'eventRain' && $time - $before['time'] >= 86400;
            $candidates[$field] = ['start' => $before['time'], 'stop' => $time, 'amount' => $delta < -1e-9 ? null : max(0.0, $delta),
                'status' => $delta < -1e-9 ? 'reset' : 'complete', 'counter' => $field];
            if ($boundary || $uncertainEvent) {
                $candidates[$field]['amount'] = null;
                $candidates[$field]['status'] = 'boundary';
            }
        }
        // Always seed every counter, even when the station supplies interval rain.
        if ($valid || $hardware) {
            $this->through[$sender] = $time;
        }
        if (!$valid) {
            $result['status'] = 'absent';
        }
        if ($hardware) {
            return $result;
        }
        foreach ($candidates as $field => $candidate) {
            if ($candidate['amount'] !== null) {
                foreach ($candidates as $otherField => $other) {
                    // Equal rain increments can round differently in counters with different totals.
                    // Only the source's known encoded step can excuse that disagreement.
                    $tolerance = max($tolerances[$field], $tolerances[$otherField]) + 1e-9;
                    if ($other['amount'] !== null && abs($candidate['amount'] - $other['amount']) > $tolerance) {
                        $candidate['amount'] = null;
                        $candidate['status'] = 'conflict';
                        return $candidate;
                    }
                }
                return $candidate;
            }
            $result = $candidate;
        }
        // All usable counters fell: establish a new baseline. A new counter value
        // may be a manual correction; it is not automatically new rainfall.
        return $result;
    }
}
