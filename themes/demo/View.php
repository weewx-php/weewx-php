<?php

declare(strict_types=1);

namespace WeewxPhp\DemoTheme;

use DateTimeZone;
use Throwable;
use WeewxPhp\Frontend\Query;
use WeewxPhp\Frontend\Report;
use WeewxPhp\Frontend\Series;
use WeewxPhp\Frontend\Span;
use WeewxPhp\Frontend\Value;
use WeewxPhp\Frontend\Weather;

/** Presentation helpers for this theme. All weather data comes through PHP tags. */
final class View
{
    /** @var array<string, Value|Series|Report> */
    public readonly array $data;
    public readonly string $name;
    public readonly DateTimeZone $zone;
    public readonly string $range;
    public readonly ?int $updated;
    public readonly string $status;
    public readonly Value $liveTemperature;

    public function __construct(Weather $wx, string $range = '24h')
    {
        $this->range = $range === '7d' ? '7d' : '24h';
        $this->name = $wx->configuration()->name;
        $this->zone = $wx->configuration()->timezone;
        /** @var array<string, Query> $queries */
        $queries = require __DIR__ . '/data.php';
        $wx->syncTheme('demo', $queries);
        $data = [];
        $pending = false;
        $stale = false;
        foreach ($queries as $key => $query) {
            // The CLI can register both ranges; the page loads only its selection.
            if (in_array($key, ['temperature24h', 'temperature7d'], true) && $key !== 'temperature' . $this->range) {
                continue;
            }
            try {
                $result = $query->get();
            } catch (Throwable $error) {
                error_log('Demo theme ' . $key . ': ' . $error->getMessage());
                $result = new Value(null, status: 'unavailable');
            }
            $data[$key] = $result;
            $pending = $pending || $result->status === 'pending';
            $stale = $stale || $result->status === 'stale';
        }
        $this->data = $data;
        $updated = $this->value('updated')->raw;
        $this->updated = is_int($updated) || is_float($updated) ? (int) $updated : null;
        $this->status = $pending ? 'Daten werden berechnet' : ($stale ? 'Aktualisierung ausstehend' : '');
        $this->liveTemperature = $wx->live('outTemp')->to('degree_C');
    }

    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function value(string $key): Value
    {
        $value = $this->data[$key] ?? null;
        return $value instanceof Value ? $value : new Value(null);
    }

    public function number(string $key): string
    {
        return $this->value($key)->html(label: false);
    }

    public function date(?int $timestamp, string $format = 'd.m.Y · H:i'): string
    {
        return $timestamp === null ? '—' : Span::date($timestamp, $this->zone)->format($format);
    }

    public function solarTime(string $key): string
    {
        $value = $this->value($key)->raw;
        return $this->date(is_int($value) || is_float($value) ? (int) $value : null, 'H:i');
    }

    /** @return list<array{start: int, end: int, value: float|null}> */
    public function points(string $key, string $unit): array
    {
        $series = $this->data[$key] ?? null;
        if (!$series instanceof Series || $series->unit === null) {
            return [];
        }
        $points = [];
        foreach ($series->to($unit)->points as $point) {
            $raw = $point['value'];
            $points[] = ['start' => $point['start'], 'end' => $point['end'], 'value' => is_int($raw) || is_float($raw) ? (float) $raw : null];
        }
        return $points;
    }

    public function report(string $key): Report
    {
        $value = $this->data[$key] ?? null;
        return $value instanceof Report ? $value : new Report(new Series([]), [], [], 'pending');
    }

    /** The same prepared values used by the HTML view.
     * @return array<string, mixed> */
    public function snapshot(): array
    {
        $formatted = [];
        foreach ($this->data as $name => $value) {
            if ($value instanceof Value) {
                $formatted[$name] = $value->format(label: false);
            }
        }
        return ['data' => $this->data, 'formatted' => $formatted, 'updated' => $this->updated,
            'updatedLabel' => $this->date($this->updated), 'live' => $this->liveTemperature,
            'liveLabel' => $this->liveTemperature->format(), 'status' => $this->status];
    }

    /**
     * Separate paths preserve gaps. SVG coordinates are generated only from numeric values.
     * @return array{paths: list<string>, low: float, high: float, start: int|null, end: int|null, points: list<array{start: int, end: int, value: float|null}>}
     */
    public function chart(): array
    {
        $points = $this->points('temperature' . $this->range, 'degree_C');
        $values = [];
        foreach ($points as $point) {
            if ($point['value'] !== null) {
                $values[] = $point['value'];
            }
        }
        $low = $values === [] ? 0.0 : floor(min($values) / 5) * 5;
        $high = $values === [] ? 10.0 : max($low + 5, ceil(max($values) / 5) * 5);
        $start = $points[0]['end'] ?? null;
        $end = $points === [] ? null : $points[count($points) - 1]['end'];
        $paths = [];
        $path = '';
        foreach ($points as $point) {
            if ($point['value'] === null) {
                if ($path !== '') {
                    $paths[] = $path;
                    $path = '';
                }
                continue;
            }
            $x = 48 + ($point['end'] - ($start ?? 0)) / max(1, ($end ?? 0) - ($start ?? 0)) * 704;
            $y = 210 - ($point['value'] - $low) / ($high - $low) * 180;
            $path .= ($path === '' ? 'M' : ' L') . sprintf('%.2F %.2F', $x, $y);
        }
        if ($path !== '') {
            $paths[] = $path;
        }
        return ['paths' => $paths, 'low' => $low, 'high' => $high, 'start' => $start, 'end' => $end, 'points' => $points];
    }
}
