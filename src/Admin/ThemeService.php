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

/** Called after authentication and CSRF checks; installation never loads theme PHP. */
final class ThemeService
{
    public function __construct(private readonly string $path, private readonly int $now, private readonly HttpClient $http) {}

    public static function compatible(Release $release): bool
    {
        return $release->api === 1 && $release->compatible();
    }

    /** @param array<string, mixed> $input */
    public function execute(string $action, array $input): void
    {
        if (!in_array($action, ['theme.refresh', 'theme.install', 'theme.enable', 'theme.disable', 'theme.remove'], true)) {
            throw new Problem('error.action', status: 404);
        }
        $read = new ReadModel($this->path);
        if (!hash_equals($read->revision, Input::text($input, 'revision'))) {
            throw new Problem('error.conflict', status: 409);
        }
        $files = new Files($read->config->settings->dataDir . '/theme-store');
        $elapsed = max(0.0, microtime(true) - (is_float($_SERVER['REQUEST_TIME_FLOAT'] ?? null) ? $_SERVER['REQUEST_TIME_FLOAT'] : microtime(true)));
        $budget = Budget::of(new SystemClock(), min(20, ExecutionProfile::limit(20, (int) ini_get('max_execution_time'), $elapsed)), PHP_INT_MAX);
        $http = new BudgetedHttpClient($this->http, $budget);
        $catalog = new Catalog($files, $http, $this->now, themes: true);
        $installer = new Installer($files, $http);
        $lock = null;
        try {
            $files->directory();
            $lock = Lock::tryAcquire($files->root . '/operation.lock') ?? throw new Problem('error.busy', status: 409);
            if ($action === 'theme.refresh') {
                $catalog->load(true);
                $auth = new Auth($read->config->settings);
                try {
                    $auth->audit($action, $this->now);
                } finally {
                    $auth->close();
                }
                return;
            }
            $id = Input::id(Input::text($input, 'theme'));
            if ($id === 'basic' && $action !== 'theme.enable') {
                throw new Problem('error.theme_basic');
            }
            $registry = ThemeRegistry::configured($this->path, file: $read->file);
            $section = $read->file->root()->optionalSection('Themes')?->optionalSection($id);
            $managed = $section !== null && Installer::managed($section);
            $installed = $section === null ? null : $installer->installed($id, $section, theme: true);
            $release = null;
            if ($action === 'theme.install' || ($action === 'theme.enable' && $managed)) {
                if (!$managed && ($section !== null || in_array($id, $registry->themes(), true))) {
                    throw new Problem('error.theme_manual');
                }
                $release = $catalog->load(true)[$id] ?? throw new Problem('error.theme_unapproved');
                if (!self::compatible($release)) {
                    throw new Problem('error.theme_incompatible');
                }
                if (!hash_equals($release->fingerprint(), Input::text($input, 'release'))) {
                    throw new Problem('error.theme_changed', status: 409);
                }
                if ($action === 'theme.install') {
                    $installer->prepare($release);
                } else {
                    if ($installed === null || $installed->fingerprint() !== $release->fingerprint()) {
                        throw new Problem('error.theme_changed', status: 409);
                    }
                    $installer->verify($release);
                }
                // Validate declarative metadata without requiring theme.php.
                $registry = new ThemeRegistry(dirname(__DIR__, 2) . '/themes', [$id => $installer->directory($release)]);
                $registry->definition($id);
                $registry->texts($id, 'en');
            } elseif ($action === 'theme.remove' && !$managed) {
                throw new Problem('error.theme_manual');
            } elseif (!in_array($id, $registry->themes(), true)) {
                throw new Problem('error.theme');
            }
            if ($action === 'theme.enable' || ($action === 'theme.install' && $registry->active($read->file) === $id)) {
                if ($registry->file($id, 'theme.php') === null) {
                    throw new Problem('error.theme');
                }
                $registry->settings($id, $read->file, $read->config);
            }
            (new Changes($this->path, $this->now))->apply(
                Input::text($input, 'revision'),
                $action . '.' . $id,
                static function (ConfFile $file) use ($action, $id, $release, $installer): void {
                    $themes = Service::section($file->root(), 'Themes');
                    if ($action === 'theme.remove') {
                        $themes->remove($id);
                    }
                    if (in_array($action, ['theme.remove', 'theme.disable'], true)) {
                        if ($themes->optional('active')?->string() === $id) {
                            $themes->set('active', 'basic');
                        }
                    } elseif ($action === 'theme.enable') {
                        $themes->set('active', $id);
                    } elseif ($release !== null) {
                        $section = Service::section($themes, $id);
                        $section->set('directory', $installer->directory($release));
                        $section->set('managed_release', $release->fingerprint());
                    }
                },
            );
        } catch (Problem $error) {
            throw $error;
        } catch (\Throwable $error) {
            error_log('weewx-php theme store: ' . $error::class);
            throw new Problem('error.theme_install', status: 502);
        } finally {
            $lock?->release();
        }
    }
}
