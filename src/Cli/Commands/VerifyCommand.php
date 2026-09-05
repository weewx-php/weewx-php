<?php

declare(strict_types=1);

namespace WeewxPhp\Cli\Commands;

use WeewxPhp\Archive\ArchiveDb;
use WeewxPhp\Cli\Application;
use WeewxPhp\Cli\Command;
use WeewxPhp\Cli\Console;
use WeewxPhp\Weewx\ScalarStats;
use WeewxPhp\Weewx\Schema;
use WeewxPhp\Weewx\Stats;
use WeewxPhp\Weewx\VecStats;

/**
 * The archive checked against itself: SQLite's integrity check, then
 * every day's stored summaries against the ones its records give. A sum
 * that differs is a fault. An extreme that is sharper than the records
 * is right -- it came from a LOOP packet the records averaged away --
 * and one that is duller is a fault.
 */
final class VerifyCommand implements Command
{
    private const SUMS = ['sum', 'count', 'wsum', 'sumtime', 'xsum', 'ysum', 'dirsumtime', 'squaresum', 'wsquaresum'];

    /** Relative difference two sums may show and still be the same arithmetic. */
    private const TOLERANCE = 1e-9;

    public function name(): string
    {
        return 'verify';
    }

    public function usage(): string
    {
        return 'verify <archive>';
    }

    public function summary(): string
    {
        return 'check an archive\'s integrity and its daily summaries against its records';
    }

    public function run(Application $app, array $args): int
    {
        $runtime = $app->runtime();
        $console = $app->console();
        $archive = isset($args[0]) ? $runtime->config->archive($args[0]) : null;
        if ($archive === null) {
            $console->error('usage: ' . $this->usage());
            return Application::USAGE_ERROR;
        }
        $db = ArchiveDb::open($archive->database, $runtime->config->settings->journalMode, $archive->policy(), $archive->timezone);
        try {
            $integrity = $db->integrityCheck();
            if ($integrity !== ['ok']) {
                foreach ($integrity as $line) {
                    $console->line('integrity: ' . $line);
                }
                return 1;
            }
            $console->line(sprintf('%s: integrity ok, %d record(s)', $archive->id, $db->count()));

            $problems = 0;
            $days = 0;
            $sharper = 0;
            foreach ($db->days() as $sod) {
                ++$days;
                $stored = $db->loadDay($sod, $db->unitSystem());
                [$fresh] = $db->dayFromRecords($sod);
                foreach ($fresh->types() as $obsType) {
                    $kind = $db->schema()->dayTypes[$obsType] ?? null;
                    if ($kind === null) {
                        // A field without a daily table, `interval` among them: nothing to compare.
                        continue;
                    }
                    if (!$stored->has($obsType)) {
                        $console->line(sprintf('%s %s: no stored row', Console::when($sod, $archive->timezone), $obsType));
                        ++$problems;
                        continue;
                    }
                    $names = Schema::dayColumns($kind);
                    $theirs = array_combine($names, self::tuple($stored->get($obsType)));
                    $ours = array_combine($names, self::tuple($fresh->get($obsType)));
                    foreach (self::SUMS as $column) {
                        if (isset($ours[$column]) && !self::sameSum($theirs[$column] ?? null, $ours[$column])) {
                            $console->line(sprintf('%s %s.%s: stored %s, records give %s', Console::when($sod, $archive->timezone), $obsType, $column, var_export($theirs[$column] ?? null, true), var_export($ours[$column], true)));
                            ++$problems;
                        }
                    }
                    $sharper += self::extremes($console, Console::when($sod, $archive->timezone), $obsType, $theirs, $ours, $problems);
                }
            }
            $console->line(sprintf('%d day(s) checked, %d problem(s), %d extreme(s) sharper than the records', $days, $problems, $sharper));
            return $problems === 0 ? 0 : 1;
        } finally {
            $db->close();
        }
    }

    /**
     * @param array<string, int|float|null> $theirs
     * @param array<string, int|float|null> $ours
     *
     * @return int How many stored extremes were sharper than the records.
     */
    private static function extremes(Console $console, string $day, string $obsType, array $theirs, array $ours, int &$problems): int
    {
        $sharper = 0;
        foreach ([['min', -1], ['max', 1]] as [$column, $direction]) {
            $stored = $theirs[$column] ?? null;
            $fresh = $ours[$column] ?? null;
            if ($fresh === null) {
                continue;
            }
            if ($stored === null) {
                $console->line(sprintf('%s %s.%s: stored nothing, records give %s', $day, $obsType, $column, var_export($fresh, true)));
                ++$problems;
                continue;
            }
            $difference = ($stored - $fresh) * $direction;
            if ($difference > 0) {
                ++$sharper;
            } elseif ($difference < 0) {
                $console->line(sprintf('%s %s.%s: stored %s is duller than the records\' %s', $day, $obsType, $column, var_export($stored, true), var_export($fresh, true)));
                ++$problems;
            }
        }
        return $sharper;
    }

    private static function sameSum(int|float|null $stored, int|float|null $fresh): bool
    {
        if ($stored === null || $fresh === null) {
            return $stored === null && $fresh === null;
        }
        $scale = max(abs($stored), abs($fresh), 1.0);
        return abs($stored - $fresh) <= self::TOLERANCE * $scale;
    }

    /** @return list<int|float|null> */
    private static function tuple(Stats $stats): array
    {
        return $stats instanceof ScalarStats || $stats instanceof VecStats ? $stats->statsTuple() : [];
    }
}
