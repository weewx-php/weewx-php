<?php

declare(strict_types=1);

namespace WeewxPhp\Frontend;

use DateTimeZone;

/** Refresh slots are independent of source invalidation. No TTL for closed results. */
final class Refresh
{
    public function __construct(public readonly string $rule = 'archive')
    {
        if (!in_array($rule, ['archive', 'daily', 'weekly', 'monthly', 'yearly', 'once'], true)
            && preg_match('/^daily@(?:[01]\d|2[0-3]):[0-5]\d$/D', $rule) !== 1) {
            Span::seconds($rule);
        }
    }

    public static function nightly(string $time = '03:00'): self
    {
        return new self('daily@' . $time);
    }

    public function next(int $now, DateTimeZone $zone, int $archiveInterval = 300): int
    {
        $day = Span::date($now, $zone)->setTime(0, 0);
        if (str_starts_with($this->rule, 'daily@')) {
            $parts = explode(':', substr($this->rule, 6));
            $next = $day->setTime((int) $parts[0], (int) $parts[1]);
            return ($next->getTimestamp() <= $now ? $next->modify('+1 day') : $next)->getTimestamp();
        }
        return match ($this->rule) {
            'once' => PHP_INT_MAX,
            'daily' => $day->modify('+1 day')->getTimestamp(),
            'weekly' => Span::calendar('week', $now + 1, $zone, weekStart: 0)->end,
            'monthly' => $day->modify('first day of next month')->getTimestamp(),
            'yearly' => $day->setDate((int) $day->format('Y') + 1, 1, 1)->getTimestamp(),
            default => (intdiv($now, $this->rule === 'archive' ? $archiveInterval : Span::seconds($this->rule)) + 1)
                * ($this->rule === 'archive' ? $archiveInterval : Span::seconds($this->rule)),
        };
    }
}
