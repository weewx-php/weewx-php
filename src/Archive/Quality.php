<?php

declare(strict_types=1);

namespace WeewxPhp\Archive;

use WeewxPhp\Config\ArchiveConfig;
use WeewxPhp\Config\Calibration;
use WeewxPhp\Config\QcRule;
use WeewxPhp\Weewx\Units;
use WeewxPhp\Weewx\UnitSystem;

/**
 * Calibration and quality control: what a reading has to survive before
 * anything is derived from it or accumulated. WeeWX's `StdCalibrate` and
 * `StdQC`, in that order, on every packet.
 *
 * Corrections first, then limits. The other way round tests an uncorrected
 * reading against corrected limits, and a thermometer with an offset fails
 * at its own ceiling. Both run on a packet that is already in the
 * archive's units, so a correction is written in those, as WeeWX's are.
 *
 * A reading outside its limits becomes null, which is what WeeWX leaves:
 * the record still carries the column, and the accumulator sees the type
 * with no value, as WeeWX's would. Nothing is refused in silence; the
 * counts are for the log.
 */
final class Quality
{
    /** @var array<string, int> */
    private array $dropped = [];

    private int $adjusted = 0;

    /**
     * @param array<string, QcRule> $rules Limits by observation type.
     * @param UnitSystem|null $ruleSystem The system limits without a unit are written in; null for
     *     the record's own, which is WeeWX's risky supposition.
     * @param array<string, array<string, Calibration>> $calibrations Per sender, corrections by
     *     observation type.
     */
    public function __construct(
        private readonly array $rules,
        private readonly ?UnitSystem $ruleSystem,
        private readonly array $calibrations,
    ) {}

    public static function fromConfig(ArchiveConfig $config): self
    {
        return new self($config->qc, $config->qcUnitSystem, $config->calibrate);
    }

    /**
     * One sender's corrections applied: `value * scale + offset`. Never
     * drops anything, and touches only numbers.
     *
     * @param array<string, mixed> $record A placed packet in the archive's unit system.
     *
     * @return array<string, mixed>
     */
    public function calibrate(array $record, string $sender): array
    {
        foreach ($this->calibrations[$sender] ?? [] as $obsType => $calibration) {
            $value = $record[$obsType] ?? null;
            if (!is_int($value) && !is_float($value)) {
                continue;
            }
            $record[$obsType] = $value * $calibration->scale + $calibration->offset;
            ++$this->adjusted;
        }
        return $record;
    }

    /**
     * The limits applied: WeeWX's `QC.apply_qc`, with a limit's unit
     * converted into the record's on each check.
     *
     * @param array<string, mixed> $record A record with `usUnits`.
     *
     * @return array<string, mixed>
     */
    public function check(array $record): array
    {
        $usUnits = $record['usUnits'] ?? null;
        $system = is_int($usUnits) ? UnitSystem::tryFrom($usUnits) : null;
        if ($system === null) {
            return $record;
        }
        foreach ($record as $name => $value) {
            if ((is_int($value) || is_float($value)) && $value < 0
                && in_array(Units::groupOf($name), ['group_rain', 'group_rainrate'], true)) {
                $record[$name] = null;
                $this->dropped[$name] = ($this->dropped[$name] ?? 0) + 1;
            }
        }
        foreach ($this->rules as $obsType => $rule) {
            $value = $record[$obsType] ?? null;
            if (!is_int($value) && !is_float($value)) {
                continue;
            }
            [$minimum, $maximum] = $this->limits($rule, $system);
            if (!($minimum <= $value && $value <= $maximum)) {
                $record[$obsType] = null;
                $this->dropped[$obsType] = ($this->dropped[$obsType] ?? 0) + 1;
            }
        }
        return $record;
    }

    /** @return array<string, int> Readings refused, by name and count. */
    public function dropped(): array
    {
        return $this->dropped;
    }

    public function adjusted(): int
    {
        return $this->adjusted;
    }

    /** One line for a log: the most refused readings first, at most six. */
    public function summary(): string
    {
        $worst = $this->dropped;
        arsort($worst);
        $parts = [];
        foreach (array_slice($worst, 0, 6, true) as $obsType => $count) {
            $parts[] = sprintf('%s x%d', $obsType, $count);
        }
        return implode(', ', $parts);
    }

    /**
     * A rule's limits in the record's unit. A limit without a unit is in
     * the rule system's standard unit for the type when there is one, and
     * otherwise taken as written.
     *
     * @return array{0: float, 1: float}
     */
    private function limits(QcRule $rule, UnitSystem $system): array
    {
        $group = Units::groupOf($rule->obsType);
        if ($group === null) {
            return [$rule->minimum, $rule->maximum];
        }
        $unit = $rule->unit ?? ($this->ruleSystem === null ? null : Units::standardUnit($this->ruleSystem, $group));
        if ($unit === null) {
            return [$rule->minimum, $rule->maximum];
        }
        $target = Units::standardUnit($system, $group);
        return [
            (float) Units::convert($rule->minimum, $unit, $target),
            (float) Units::convert($rule->maximum, $unit, $target),
        ];
    }
}
