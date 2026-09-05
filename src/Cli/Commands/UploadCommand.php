<?php

declare(strict_types=1);

namespace WeewxPhp\Cli\Commands;

use DateTimeImmutable;
use Exception;
use Throwable;
use WeewxPhp\Archive\Archiver;
use WeewxPhp\Archive\Budget;
use WeewxPhp\Cli\Application;
use WeewxPhp\Cli\Command;
use WeewxPhp\Cli\Console;
use WeewxPhp\Config\UploadConfig;
use WeewxPhp\Tick\Runtime;

/**
 * The uploads, from the command line: what there is, whether a service
 * takes the credentials, and sending now rather than at the next tick.
 */
final class UploadCommand implements Command
{
    public function name(): string
    {
        return 'upload';
    }

    public function usage(): string
    {
        return 'upload list | check [<name>] | run [<name>] [--since <time>] [--again]';
    }

    public function summary(): string
    {
        return 'list the uploads, ask the services, or send now';
    }

    public function run(Application $app, array $args): int
    {
        $what = array_shift($args);
        return match ($what) {
            'list' => $this->list($app),
            'check' => $this->check($app, $args),
            'run' => $this->send($app, $args),
            default => $this->usageError($app),
        };
    }

    private function usageError(Application $app): int
    {
        $app->console()->error('usage: ' . $this->usage());
        return Application::USAGE_ERROR;
    }

    private function list(Application $app): int
    {
        $runtime = $app->runtime();
        $console = $app->console();
        $uploads = $runtime->config->uploads;
        if ($uploads === []) {
            $console->line('no uploads configured; see [Uploads] in the configuration');
            return 0;
        }
        $zone = $runtime->config->settings->timezone;
        foreach ($uploads as $id => $config) {
            $state = $runtime->state()->upload($id);
            $console->line(sprintf('%s  %s, archive %s, %s', $id, $config->kind->label(), $config->archive, self::rhythm($config)));
            try {
                $described = $runtime->uploads()->make($config)->describe();
                $parts = [];
                foreach ($described as $key => $value) {
                    $parts[] = sprintf('%s %s', $key, self::plain($value));
                }
                $console->line('    ' . implode(', ', $parts));
            } catch (Throwable $error) {
                $console->line('    not usable: ' . $error->getMessage());
            }
            $console->line(sprintf(
                '    sent up to %s, last run %s, %d run(s), %d sent, %d failure(s) in a row%s',
                $state->through > 0 ? Console::when($state->through, $zone) : 'nothing yet',
                Console::when($state->lastRunAt, $zone),
                $state->runs,
                $state->sent,
                $state->failures,
                $state->blocked === null ? '' : sprintf("\n    switched off since %s: %s", Console::when($state->blockedAt, $zone), $state->blocked),
            ));
            if ($state->lastSummary !== null) {
                $console->line('    last: ' . $state->lastSummary);
            }
        }
        return 0;
    }

    /** @param list<string> $args */
    private function check(Application $app, array $args): int
    {
        $runtime = $app->runtime();
        $console = $app->console();
        $chosen = $this->chosen($app, $args[0] ?? null);
        if ($chosen === null) {
            return Application::USAGE_ERROR;
        }
        $problems = 0;
        foreach ($chosen as $id => $config) {
            try {
                $answer = $runtime->uploads()->make($config)->check();
            } catch (Throwable $error) {
                $answer = 'not usable: ' . $error->getMessage();
            }
            $refused = str_starts_with($answer, 'refused') || str_starts_with($answer, 'not usable') || str_starts_with($answer, 'could not') || str_starts_with($answer, 'no ');
            if ($refused) {
                ++$problems;
            } elseif ($runtime->state()->upload($id)->blocked !== null) {
                // The service takes the credentials again: no need to wait out the hour.
                $runtime->state()->unblockUpload($id);
            }
            $console->line(sprintf('%s: %s', $id, $answer));
        }
        return $problems === 0 ? 0 : 1;
    }

    /** @param list<string> $args */
    private function send(Application $app, array $args): int
    {
        $runtime = $app->runtime();
        $console = $app->console();
        $name = null;
        $since = null;
        $again = false;
        for ($i = 0, $n = count($args); $i < $n; ++$i) {
            if ($args[$i] === '--again') {
                $again = true;
            } elseif ($args[$i] === '--since') {
                $since = $args[$i + 1] ?? '';
                ++$i;
            } elseif ($name === null) {
                $name = $args[$i];
            } else {
                return $this->usageError($app);
            }
        }
        $chosen = $this->chosen($app, $name);
        if ($chosen === null) {
            return Application::USAGE_ERROR;
        }
        $sinceAt = null;
        if ($since !== null) {
            $sinceAt = self::moment($since, $runtime);
            if ($sinceAt === null) {
                $console->error('--since takes a timestamp or a local time like "2026-09-05 14:00"');
                return Application::USAGE_ERROR;
            }
        }
        foreach (array_keys($chosen) as $id) {
            $runtime->state()->unblockUpload($id);
            if ($again) {
                $runtime->state()->rewindUpload($id, 0);
            } elseif ($sinceAt !== null) {
                // Everything after that moment goes again; a mark one second
                // before it takes the record stamped exactly there along.
                $runtime->state()->rewindUpload($id, $sinceAt - 1);
            }
        }

        $now = $runtime->clock->now();
        $archivers = [];
        try {
            foreach ($chosen as $config) {
                if (!isset($archivers[$config->archive])) {
                    $archive = $runtime->config->archive($config->archive);
                    if ($archive === null) {
                        continue;
                    }
                    $archivers[$config->archive] = Archiver::open($archive, $runtime->config->settings, $runtime->live(), $runtime->state(), $runtime->log, $now);
                }
            }
            $outcome = $runtime->uploads()->run(Budget::unlimited($runtime->clock), $archivers, $now, forced: true, only: array_keys($chosen));
        } finally {
            foreach ($archivers as $archiver) {
                $archiver->close();
            }
        }
        $failed = 0;
        foreach ($outcome as $id => $result) {
            $line = $result['summary'] ?? $result['error'] ?? (isset($result['sent']) ? 'nothing new' : 'not run');
            if (isset($result['error'])) {
                ++$failed;
            }
            $console->line(sprintf('%s: %s', $id, is_string($line) ? $line : '?'));
        }
        return $failed === 0 ? 0 : 1;
    }

    /**
     * The uploads a command applies to: one by name, or every one.
     *
     * @return array<string, UploadConfig>|null
     */
    private function chosen(Application $app, ?string $name): ?array
    {
        $uploads = $app->runtime()->config->uploads;
        if ($name === null) {
            if ($uploads === []) {
                $app->console()->error('no uploads configured; see [Uploads] in the configuration');
                return null;
            }
            return $uploads;
        }
        if (!isset($uploads[$name])) {
            $names = implode(', ', array_keys($uploads));
            $app->console()->error(sprintf('no upload %s; there are: %s', $name, $names === '' ? 'none' : $names));
            return null;
        }
        return [$name => $uploads[$name]];
    }

    /** A described value as one word or a few: hosts, ids, a list of servers. */
    private static function plain(mixed $value): string
    {
        if (is_array($value)) {
            return implode(' ', array_map(self::plain(...), array_values($value)));
        }
        return is_scalar($value) ? (string) $value : '?';
    }

    private static function rhythm(UploadConfig $config): string
    {
        return match ($config->trigger->value) {
            'record' => 'after every record',
            'interval' => sprintf('every %d min', intdiv($config->every, 60)),
            'live' => 'the newest packet, every tick',
            default => 'only when asked',
        };
    }

    private static function moment(string $text, Runtime $runtime): ?int
    {
        if (preg_match('/^\d{9,}$/', $text) === 1) {
            return (int) $text;
        }
        try {
            return (new DateTimeImmutable($text, $runtime->config->settings->timezone))->getTimestamp();
        } catch (Exception) {
            return null;
        }
    }
}
