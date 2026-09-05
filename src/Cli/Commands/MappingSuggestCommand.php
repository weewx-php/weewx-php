<?php

declare(strict_types=1);

namespace WeewxPhp\Cli\Commands;

use WeewxPhp\Archive\ArchiveDb;
use WeewxPhp\Archive\Mapping;
use WeewxPhp\Cli\Application;
use WeewxPhp\Cli\Command;
use WeewxPhp\Live\PacketKind;
use WeewxPhp\Weewx\Policy;
use WeewxPhp\Weewx\Units;
use WeewxPhp\Weewx\Wview;

/**
 * A `[[[fields]]]` block to start from, worked out of what each selected
 * sender last sent and which columns the archive still has free: an
 * additional sender's temperatures go to the spare `extraTemp` columns,
 * its humidities to `extraHumid`, and what has no spare column of its
 * kind is placed nowhere, in writing, so that somebody decides.
 */
final class MappingSuggestCommand implements Command
{
    /** Spare columns by unit group, in the order they are handed out. */
    private const SPARES = [
        'group_temperature' => ['extraTemp1', 'extraTemp2', 'extraTemp3', 'extraTemp4', 'extraTemp5', 'extraTemp6', 'extraTemp7', 'extraTemp8'],
        'group_percent' => ['extraHumid1', 'extraHumid2', 'extraHumid3', 'extraHumid4', 'extraHumid5', 'extraHumid6', 'extraHumid7', 'extraHumid8'],
    ];

    public function name(): string
    {
        return 'mapping-suggest';
    }

    public function usage(): string
    {
        return 'mapping-suggest <archive>';
    }

    public function summary(): string
    {
        return 'print a [[[fields]]] block from what the senders send and which columns are free';
    }

    public function run(Application $app, array $args): int
    {
        $console = $app->console();
        $id = $args[0] ?? null;
        $archive = $id === null ? null : $app->runtime()->config->archive($id);
        if ($id === null || $archive === null) {
            $console->error('which archive? one of: ' . implode(', ', array_keys($app->runtime()->config->archives)));
            return Application::USAGE_ERROR;
        }
        $runtime = $app->runtime();
        $live = $runtime->live();

        $columns = array_map(static fn(array $column): string => $column[0], Wview::ARCHIVE_TABLE);
        $occupied = [];
        if (is_file($archive->database)) {
            $db = ArchiveDb::open($archive->database, $runtime->config->settings->journalMode, new Policy(), $archive->timezone);
            try {
                $columns = $db->schema()->columns;
                $occupied = $db->occupied();
            } finally {
                $db->close();
            }
        }
        $taken = array_flip($columns);
        $primary = Mapping::resolvePrimary($archive, $live->senders());
        $console->line(sprintf('# %s: primary %s; a reading of the primary goes to the column of its name', $id, $primary ?? 'nobody yet'));
        $console->line('        [[[fields]]]');
        $spares = self::SPARES;
        foreach ($live->senders() as $identity) {
            $sender = $identity->sender;
            if (!$archive->selects($sender)) {
                continue;
            }
            $packet = $live->lastBefore($runtime->clock->now(), PacketKind::Loop, [$sender]);
            $console->line(sprintf('            [[[[%s]]]]%s', $sender, $sender === $primary ? '    # the primary' : '    # writes only what is placed here'));
            if ($packet === null || $packet->dialect !== null) {
                $console->line('                # nothing readable from this sender yet');
                continue;
            }
            foreach (array_keys($packet->data) as $raw) {
                $raw = (string) $raw;
                if (in_array($raw, Wview::NOT_OBSERVATIONS, true)) {
                    continue;
                }
                $existing = $archive->fields[$sender][$raw] ?? null;
                if ($existing !== null) {
                    $console->line(sprintf('                %s = %s    # as configured', $raw, $existing));
                    continue;
                }
                if ($sender === $primary) {
                    $console->line(isset($taken[$raw])
                        ? sprintf('                # %s -> %s%s', $raw, $raw, isset($occupied[$raw]) ? sprintf(' (%d record(s) there)', $occupied[$raw][0]) : '')
                        : sprintf('                # %s = -    # no column of that name; add one under [[[columns]]] or leave this line', $raw));
                    continue;
                }
                if (Mapping::keeps($raw)) {
                    $console->line(sprintf('                # %s is kept as it is', $raw));
                    continue;
                }
                $group = Units::groupOf($raw);
                $spare = null;
                if ($group !== null && isset($spares[$group])) {
                    while ($spares[$group] !== []) {
                        $candidate = array_shift($spares[$group]);
                        if (isset($taken[$candidate]) && !isset($occupied[$candidate])) {
                            $spare = $candidate;
                            break;
                        }
                    }
                }
                $console->line($spare === null
                    ? sprintf('                %s = -    # no spare column of its kind', $raw)
                    : sprintf('                %s = %s    # free', $raw, $spare));
            }
        }
        return 0;
    }
}
