<?php

declare(strict_types=1);

namespace WeewxPhp\Admin;

use WeewxPhp\Archive\Budget;
use WeewxPhp\CoreUpdate\GitHub;
use WeewxPhp\CoreUpdate\Installer;
use WeewxPhp\Extension\Files;
use WeewxPhp\Tick\Lock;
use WeewxPhp\Time\SystemClock;
use WeewxPhp\Upload\Http\BudgetedHttpClient;
use WeewxPhp\Upload\Http\HttpClient;

/** Only the authenticated controller dispatches update actions. */
final class CoreUpdateService
{
    public function __construct(private readonly string $path, private readonly int $now, private readonly HttpClient $http, private readonly string $root = __DIR__ . '/../..') {}

    /** @param array<string, mixed> $input */
    public function execute(string $action, array $input): void
    {
        if (!in_array($action, ['core.check', 'core.install'], true)) {
            throw new Problem('error.action', status: 404);
        }
        $read = new ReadModel($this->path);
        if (!hash_equals($read->revision, Input::text($input, 'revision'))) {
            throw new Problem('error.conflict', status: 409);
        }
        $channel = $read->file->root()->optionalSection('Admin')?->optional('update_channel')?->string() ?? 'stable';
        $github = new GitHub(new Files($read->config->settings->dataDir . '/core-updates'), new BudgetedHttpClient($this->http, Budget::of(new SystemClock(), 15, PHP_INT_MAX)), $channel);
        $lock = null;
        try {
            $release = $github->check();
            if ($action === 'core.install') {
                if ($release === null || !$release->newer() || !hash_equals($release->fingerprint(), Input::text($input, 'release'))) {
                    throw new Problem('error.core_changed', status: 409);
                }
                $package = $github->download($release);
                $lock = Lock::tryAcquire($read->config->settings->lockPath()) ?? throw new Problem('error.busy', status: 409);
                $root = realpath($this->root);
                if ($root === false) {
                    throw new \RuntimeException('Core root unavailable');
                }
                $this->audit($read, 'core.install.started');
                (new Installer($root))->install($package);
            }
            $this->audit($read, $action);
        } catch (Problem $error) {
            throw $error;
        } catch (\Throwable $error) {
            error_log('weewx-php core update: ' . $error::class . ': ' . $error->getMessage());
            throw new Problem('error.core_update', status: 502);
        } finally {
            $lock?->release();
        }
    }

    private function audit(ReadModel $read, string $action): void
    {
        $auth = new Auth($read->config->settings);
        try {
            $auth->audit($action, $this->now);
        } finally {
            $auth->close();
        }
    }
}
