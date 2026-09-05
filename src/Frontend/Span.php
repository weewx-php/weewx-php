<?php

declare(strict_types=1);

namespace WeewxPhp\Frontend;

use DateTimeImmutable;
use DateTimeZone;
use WeewxPhp\Weewx\Intervals;

/** Archive intervals are (start, end]. Calendar operations use the archive's timezone. */
final class Span
{
    public function __construct(public readonly int $start, public readonly int $end)
    {
        if ($end < $start) {
            throw new QueryError('A period must end at or after its start');
        }
    }

    public function length(): int
    {
        return $this->end - $this->start;
    }

    public static function date(int $timestamp, DateTimeZone $zone): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone($zone);
    }

    public static function timestamp(int|string $value, DateTimeZone $zone): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})?)?$/D', $value) !== 1) {
            throw new QueryError('Use a Unix timestamp or an ISO date/time');
        }
        try {
            $date = new DateTimeImmutable($value, $zone);
        } catch (\Exception $error) {
            throw new QueryError('Invalid date: ' . $value, 0, $error);
        }
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw new QueryError('Invalid date: ' . $value);
        }
        return $date->getTimestamp();
    }

    /** Fixed elapsed durations. Calendar months and years are explicit period operations. */
    public static function seconds(int|string $duration): int
    {
        if (is_int($duration)) {
            if ($duration < 1) {
                throw new QueryError('Duration must be positive');
            }
            return $duration;
        }
        if (preg_match('/^([1-9]\d{0,8})(s|m|h|d|w)$/D', $duration, $match) !== 1) {
            throw new QueryError('Use seconds or a duration such as 15m, 6h, 7d');
        }
        return (int) $match[1] * ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800][$match[2]];
    }

    public static function calendar(string $period, int $at, DateTimeZone $zone, int $ago = 0, int $weekStart = 6, int $rainStart = 1, float $latitude = 0): self
    {
        if ($ago < 0 || $ago > 10000 || $weekStart < 0 || $weekStart > 6 || $rainStart < 1 || $rainStart > 12) {
            throw new QueryError('Invalid calendar setting');
        }
        // WeeWX assigns an archive record at a boundary to the interval it closes.
        $date = self::date($at - 1, $zone);
        $day = $date->setTime(0, 0);
        $year = (int) $date->format('Y');
        $month = (int) $date->format('n');
        switch ($period) {
            case 'hour':
                $start = $at - (int) self::date($at, $zone)->format('i') * 60 - (int) self::date($at, $zone)->format('s');
                if ($start === $at) {
                    $start -= 3600;
                }
                return new self($start - $ago * 3600, $start + 3600 - $ago * 3600);
            case 'day':
                $start = $day->modify('-' . $ago . ' days');
                $end = $start->modify('+1 day');
                break;
            case 'week':
                $offset = ((int) $date->format('N') - 1 - $weekStart + 7) % 7;
                $start = $day->modify('-' . ($offset + $ago * 7) . ' days');
                $end = $start->modify('+1 week');
                break;
            case 'month':
                $start = $day->setDate($year, $month, 1)->modify('-' . $ago . ' months');
                $end = $start->modify('+1 month');
                break;
            case 'season':
                $startMonth = intdiv($month, 3) * 3;
                $start = $day->setDate($startMonth === 0 ? $year - 1 : $year, $startMonth === 0 ? 12 : $startMonth, 1)->modify('-' . ($ago * 3) . ' months');
                $end = $start->modify('+3 months');
                break;
            case 'year':
            case 'rainyear':
            case 'seasonsyear':
                $firstMonth = match ($period) {
                    'rainyear' => $rainStart, 'seasonsyear' => $latitude < 0 ? 9 : 3, default => 1
                };
                $start = $day->setDate($year - ($month < $firstMonth ? 1 : 0) - $ago, $firstMonth, 1);
                $end = $start->modify('+1 year');
                break;
            default:
                throw new QueryError('Unknown period: ' . $period);
        }
        return new self($start->getTimestamp(), $end->getTimestamp());
    }

    /** @return list<self> */
    public function buckets(string|int $every, DateTimeZone $zone, int $limit = 2048, int $weekStart = 6): array
    {
        $calendar = is_string($every) && in_array($every, ['hour', 'day', 'week', 'month', 'season', 'year'], true);
        $result = [];
        $start = $this->start;
        while ($start < $this->end) {
            if (count($result) >= $limit) {
                throw new QueryError('Too many series points; choose a coarser interval');
            }
            if ($calendar) {
                $end = self::calendar((string) $every, $start + 1, $zone, weekStart: $weekStart)->end;
            } else {
                $end = $start + self::seconds($every);
            }
            $end = min($end, $this->end);
            $result[] = new self($start, $end);
            $start = $end;
        }
        return $result;
    }

    public function wholeDays(DateTimeZone $zone): bool
    {
        return Intervals::startOfDay($this->start, $zone) === $this->start
            && Intervals::startOfDay($this->end, $zone) === $this->end;
    }
}
