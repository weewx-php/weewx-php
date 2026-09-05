<?php

declare(strict_types=1);

namespace WeewxPhp\Archive;

/**
 * What the derived readings ask the archive for: WeeWX's pressure cooker
 * wants the temperature of twelve hours ago, and its evapotranspiration
 * the aggregates of the last hour. An interface so that a test can answer
 * from a few arrays.
 */
interface History
{
    /**
     * The record closest to a moment, within a tolerance, without its NULL
     * columns; null when there is none.
     *
     * @return array<string, mixed>|null
     */
    public function recordNear(int $timestamp, int $maxDelta): ?array;

    /**
     * The aggregates evapotranspiration needs over (start, stop): keys
     * `t_max`, `t_min`, `rad_avg`, `wind_avg`, `rh_max`, `rh_min`,
     * `units_max`, `units_min`, each null where nothing was recorded; or
     * null when the archive lacks the columns.
     *
     * @return array<string, mixed>|null
     */
    public function etWindow(int $start, int $stop): ?array;
}
