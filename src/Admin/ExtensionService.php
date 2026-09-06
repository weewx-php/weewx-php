<?php

declare(strict_types=1);

namespace WeewxPhp\Admin;

use WeewxPhp\Archive\Budget;
use WeewxPhp\Config\ConfFile;
use WeewxPhp\Extension\Catalog;
use WeewxPhp\Extension\Files;
use WeewxPhp\Extension\Installer;
use WeewxPhp\Extension\Release;
use WeewxPhp\Tick\ExecutionProfile;
use WeewxPhp\Tick\Lock;
use WeewxPhp\Time\SystemClock;
use WeewxPhp\Upload\Http\BudgetedHttpClient;
use WeewxPhp\Upload\Http\HttpClient;

/** Called only after the controller's authentication and CSRF checks. */
final class ExtensionService
{
    public function __construct(private readonly string $path, private readonly int $now, private readonly HttpClient $http) {}

    /** @param array<string, mixed> $input */
    public function execute(string $action, array $input): void
    {
        if (!in_array($action, ['extension.refresh', 'extension.install', 'extension.enable', 'extension.disable', 'extension.remove', 'extension.settings'], true)) {
            throw new Problem('error.action', status: 404);
        }
        $read = new ReadModel($this->path);
        if (!hash_equals($read->revision, Input::text($input, 'revision'))) {
            throw new Problem('error.conflict', status: 409);
        }
        $files = new Files($read->config->settings->dataDir . '/extension-store');
        $elapsed = max(0.0, microtime(true) - (is_float($_SERVER['REQUEST_TIME_FLOAT'] ?? null) ? $_SERVER['REQUEST_TIME_FLOAT'] : microtime(true)));
        $budget = Budget::of(new SystemClock(), min(20, ExecutionProfile::limit(20, (int) ini_get('max_execution_time'), $elapsed)), PHP_INT_MAX);
        $http = new BudgetedHttpClient($this->http, $budget);
        $catalog = new Catalog($files, $http, $this->now);
        $installer = new Installer($files, $http);
        $lock = null;
        try {
            $files->directory();
            $lock = Lock::tryAcquire($files->root . '/operation.lock') ?? throw new Problem('error.busy', status: 409);
            if ($action === 'extension.refresh') {
                $catalog->load(true);
                $auth = new Auth($read->config->settings);
                try {
                    $auth->audit($action, $this->now);
                } finally {
                    $auth->close();
                }
                return;
            }
            $id = Input::text($input, 'extension');
            Release::id($id);
            $section = $read->file->root()->optionalSection('Extensions')?->optionalSection($id);
            $installed = $section === null ? null : $installer->installed($id, $section);
            $release = null;
            $values = null;
            $archive = '';
            if ($action === 'extension.settings') {
                $schema = $installed === null ? null : \WeewxPhp\Extension\Settings::load($installed, $installer, $this->now);
                if ($schema === null || $section === null || Input::text($input, 'complete') !== '1'
                    || !hash_equals($schema->hash, Input::text($input, 'schema'))) {
                    throw new Problem('error.extension_settings');
                }
                $archive = Input::text($input, 'archive');
                if ($archive !== '' && ($schema->scope !== 'archive' || $read->config->archive($archive) === null)) {
                    throw new Problem('error.archive');
                }
                $options = $input['options'] ?? null;
                $clear = $input['clear'] ?? [];
                if (!is_array($options) || !is_array($clear)) {
                    throw new Problem('error.extension_settings');
                }
                try {
                    $values = $schema->validate(\WeewxPhp\Db\Json::object(json_encode($options, JSON_THROW_ON_ERROR)), \WeewxPhp\Db\Json::object(json_encode($clear, JSON_THROW_ON_ERROR)), \WeewxPhp\Extension\Settings::current($section, $archive), $read->config);
                } catch (\InvalidArgumentException) {
                    throw new Problem('error.extension_settings');
                }
            }
            if (in_array($action, ['extension.install', 'extension.enable'], true)) {
                $release = $catalog->load(true)[$id] ?? throw new Problem('error.extension_unapproved');
                if (!$release->compatible()) {
                    throw new Problem('error.extension_incompatible');
                }
                if (!hash_equals($release->fingerprint(), Input::text($input, 'release'))) {
                    throw new Problem('error.extension_changed', status: 409);
                }
                if ($section !== null && !Installer::managed($section)) {
                    throw new Problem('error.extension_manual');
                }
                if ($action === 'extension.install') {
                    $installer->prepare($release);
                } else {
                    if ($installed === null || $installed->fingerprint() !== $release->fingerprint()) {
                        throw new Problem('error.extension_changed', status: 409);
                    }
                    $installer->verify($installed);
                }
            } elseif ($section === null || ($action === 'extension.remove' && !Installer::managed($section))) {
                throw new Problem('error.extension_manual');
            }
            (new Changes($this->path, $this->now))->apply(
                Input::text($input, 'revision'),
                $action . '.' . $id,
                static function (ConfFile $file) use ($action, $id, $release, $installer, $values, $archive): void {
                    $extensions = Service::section($file->root(), 'Extensions');
                    if ($action === 'extension.remove') {
                        $extensions->remove($id);
                        return;
                    }
                    $section = Service::section($extensions, $id);
                    if ($values !== null) {
                        $options = $archive === '' ? Service::section($section, 'options') : Service::section(Service::section($section, 'archive_options'), $archive);
                        foreach ($values as $key => $value) {
                            $options->set($key, $value);
                        }
                        return;
                    }
                    if ($action === 'extension.install' && $release !== null) {
                        $section->set('entry', $installer->entry($release));
                        $section->set('managed_release', $release->fingerprint());
                        if (!$section->has('enabled')) {
                            $section->set('enabled', 'false');
                        }
                    } else {
                        $section->set('enabled', $action === 'extension.enable' ? 'true' : 'false');
                    }
                },
            );
        } catch (Problem $error) {
            throw $error;
        } catch (\Throwable $error) {
            error_log('weewx-php extension store: ' . $error::class);
            throw new Problem('error.extension_install', status: 502);
        } finally {
            $lock?->release();
        }
    }
}
