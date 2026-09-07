<?php

declare(strict_types=1);

namespace WeewxPhp\Admin;

use WeewxPhp\Archive\Mapping;
use WeewxPhp\Config\ArchiveConfig;
use WeewxPhp\Db\Sqlite;
use WeewxPhp\Measurement\Catalog;

/** Fixed core views. All dynamic output passes through context escaping. */
final class Page
{
    public const PAGES = ['overview', 'stations', 'archives', 'fields', 'backups', 'themes', 'extensions', 'settings'];

    /** @param array<string, mixed> $draft */
    public function __construct(
        private readonly ReadModel $read,
        private readonly Translator $language,
        private readonly string $csrf,
        private readonly array $draft = [],
        private readonly ?\WeewxPhp\Upload\Http\HttpClient $http = null,
        private readonly ?int $now = null,
    ) {}

    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function t(string $key): string
    {
        return self::escape($this->language->text($key));
    }

    /** @param array<string, string> $parameters */
    private function url(string $page, array $parameters = []): string
    {
        return self::escape('?' . http_build_query(['page' => $page] + $parameters + ['lang' => $this->language->language]));
    }

    private function value(string $key, string $default = ''): string
    {
        $value = $this->draft[$key] ?? $default;
        return is_string($value) ? $value : $default;
    }

    /** @param array<string, string> $hidden */
    private function form(string $action, array $hidden = []): string
    {
        $out = '<form method="post" data-edit-form' . (($this->draft['action'] ?? '') === $action && $action !== 'login' ? ' data-unsaved-draft' : '') . '>';
        foreach (['action' => $action, 'csrf' => $this->csrf, 'revision' => $this->read->revision] + $hidden as $name => $value) {
            $out .= '<input type="hidden" name="' . self::escape($name) . '" value="' . self::escape($value) . '">';
        }
        return $out;
    }

    private function input(string $name, string $label, string $default = '', string $type = 'text', bool $required = false, string $autocomplete = 'new-password'): string
    {
        $value = $type === 'password' ? '' : $this->value($name, $default);
        return '<label class="control"><span>' . $this->t($label) . '</span><input name="' . self::escape($name) . '" type="' . self::escape($type)
            . '" value="' . self::escape($value) . '"' . ($required ? ' required' : '') . ($type === 'password' ? ' autocomplete="' . self::escape($autocomplete) . '"' : '') . '></label>';
    }

    /** @param array<string, string> $options Labels are plain text. */
    private function select(string $name, string $label, array $options, string $default = '', string $hint = ''): string
    {
        $value = $this->value($name, $default);
        $description = $hint === '' ? '' : ' aria-describedby="' . self::escape($name . '-hint') . '"';
        $out = '<label class="control"><span>' . $this->t($label) . '</span><select name="' . self::escape($name) . '"' . $description . '>';
        foreach ($options as $key => $text) {
            $out .= '<option value="' . self::escape($key) . '"' . ($value === $key ? ' selected' : '') . '>' . self::escape($text) . '</option>';
        }
        return $out . '</select>' . ($hint === '' ? '' : '<small class="control-hint" id="' . self::escape($name . '-hint') . '">' . $this->t($hint) . '</small>') . '</label>';
    }

    private function button(string $label = 'action.save', string $class = 'primary'): string
    {
        return '<button class="' . self::escape($class) . '" type="submit">' . $this->t($label) . '</button>';
    }

    private function timezone(string $default): string
    {
        $options = [];
        foreach (\DateTimeZone::listIdentifiers() as $name) {
            $options[$name] = str_replace('_', ' ', $name);
        }
        return $this->select('timezone', 'label.timezone', $options, $default);
    }

    private function number(string $name, string $label, string $value, string $step = 'any', string $limits = ''): string
    {
        return '<label class="control"><span>' . $this->t($label) . '</span><input type="number" inputmode="decimal" name="' . self::escape($name)
            . '" value="' . self::escape($this->value($name, $value)) . '" step="' . self::escape($step) . '" ' . $limits . '></label>';
    }

    private function endForm(string $label = 'action.save'): string
    {
        return '<div class="form-actions">' . $this->button($label) . '<button type="reset">' . $this->t('action.cancel') . '</button></div></form>';
    }

    private function shell(string $page, string $body, bool $authenticated = true): string
    {
        $feedback = [];
        foreach (['working', 'slow', 'reset', 'invalid', 'loading', 'download', 'unchanged', 'menu', 'close'] as $key) {
            $feedback[$key] = $this->language->text('feedback.' . $key);
        }
        $out = '<!doctype html><html lang="' . self::escape($this->language->language) . '" dir="' . $this->language->direction() . '"><head>'
            . '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $this->t('nav.' . $page) . ' · weewx-php</title>'
            . '<link rel="stylesheet" href="admin.css"><link rel="stylesheet" href="admin-shell.css"><script src="admin.js" defer></script><script src="import.js" defer></script><script src="location.js" defer></script></head><body data-unsaved="' . $this->t('status.unsaved') . '" data-history-loading="' . $this->t('status.loading') . '" data-history-error="' . $this->t('status.history_error') . '" data-last-kinds="' . self::escape(implode(',', array_filter(array_keys(Catalog::KINDS), Catalog::lastKind(...)))) . '">'
            . '<div id="action-feedback" class="action-feedback" role="status" aria-live="polite" aria-atomic="true" data-labels="' . self::escape(json_encode($feedback, JSON_THROW_ON_ERROR)) . '"></div>'
            . '<a class="skip" href="#main">' . $this->t('action.skip') . '</a><div class="app' . ($authenticated ? '' : ' login-app') . '">';
        if ($authenticated) {
            $out .= '<aside class="sidebar"><a class="brand" href="' . $this->url('overview') . '"><span class="brand-mark" aria-hidden="true">w</span><span>weewx-php<small>' . $this->t('label.administration') . '</small></span></a><button type="button" class="menu-toggle quiet" aria-expanded="false" aria-controls="admin-navigation">' . $this->t('feedback.menu') . '</button><nav id="admin-navigation" aria-label="' . $this->t('label.navigation') . '">';
            foreach (['overview', 'stations', 'archives', 'fields', 'themes', 'extensions', 'backups', 'settings'] as $name) {
                if (in_array($name, ['overview', 'themes', 'backups'], true)) {
                    $out .= '<span class="nav-group">' . $this->t('nav.group.' . $name) . '</span>';
                }
                $out .= '<a href="' . $this->url($name) . '"' . ($name === $page ? ' aria-current="page"' : '') . '>' . $this->icon($name) . $this->t('nav.' . $name) . '</a>';
                if ($name === 'extensions') {
                    foreach (ExtensionSettings::menu($this->read) as $id => $label) {
                        $out .= '<a class="extension-nav" href="' . $this->url('extensions', ['extension' => $id]) . '">' . self::escape($label) . '</a>';
                    }
                }
            }
            $out .= '</nav><div class="sidebar-bottom"><a href="../" class="site-link">' . $this->t('action.open_website') . ' ↗</a>' . $this->form('logout') . $this->button('action.logout', 'quiet') . '</form></div></aside>';
        }
        $out .= '<div class="workspace"><header class="topbar"><span>' . $this->t('label.administration') . '<span class="breadcrumb-separator">/</span><strong>' . $this->t('nav.' . $page) . '</strong></span><div class="topbar-end">'
            . ($authenticated ? '<span class="snapshot">' . $this->t('label.snapshot') . ' <time>' . $this->when($this->now ?? time()) . '</time></span><button type="button" class="quiet small" data-refresh>' . $this->icon('refresh') . $this->t('action.refresh') . '</button>' : '')
            . '<span class="language-tag">' . self::escape(strtoupper($this->language->language)) . '</span></div></header><main id="main" tabindex="-1">' . $body . '</main></div></div></body></html>';
        return $out;
    }

    private function icon(string $name): string
    {
        $path = match ($name) {
            'overview' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
            'stations' => '<circle cx="12" cy="12" r="2"/><path d="M7 7a7 7 0 0 0 0 10M17 7a7 7 0 0 1 0 10M4 4a11 11 0 0 0 0 16M20 4a11 11 0 0 1 0 16M12 14v7"/>',
            'archives' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 4 16 4 16 0V5M4 12c0 4 16 4 16 0"/>',
            'fields' => '<path d="M4 7h16m-4-4 4 4-4 4M20 17H4m4-4-4 4 4 4"/>',
            'themes' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M9 9v11"/>',
            'extensions' => '<path d="M9 3H4v6a3 3 0 1 1 0 6v6h6a3 3 0 1 1 6 0h5v-6a3 3 0 1 1 0-6V3h-6a3 3 0 1 1-6 0Z"/>',
            'backups' => '<path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z"/><path d="m8 12 3 3 5-6"/>',
            'settings' => '<path d="M4 7h16M4 17h16"/><circle cx="9" cy="7" r="3"/><circle cx="15" cy="17" r="3"/>',
            default => '<path d="M20 8a8 8 0 1 0 0 8M20 3v5h-5"/>',
        };
        return '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
    }

    public function login(bool $configured, string $error): string
    {
        $body = '<section class="login-card"><span class="brand-mark" aria-hidden="true">w</span><h1>' . $this->t('action.login') . '</h1>';
        if ($error !== '') {
            $body .= '<div class="notice error" role="alert">' . $this->t($error) . '</div>';
        }
        if (!$configured) {
            $body .= '<p>' . $this->t('status.setup') . '</p><code>php bin/weewx-php admin password-stdin</code>';
        } else {
            $body .= $this->form('login') . $this->input('password', 'label.password', type: 'password', required: true, autocomplete: 'current-password') . $this->button('action.login') . '</form>';
        }
        return $this->shell('overview', $body . '</section>', false);
    }

    /** @param array<string, mixed> $query */
    public function render(string $page, array $query, string $error = '', string $detail = ''): string
    {
        $archive = $this->read->config->archive(Input::text($query, 'archive'));
        $body = '<div class="page-heading"><div><div class="eyebrow">' . $this->t('label.weather_workspace') . '</div><h1>' . $this->t('nav.' . $page) . '</h1>'
            . ($archive === null ? '' : '<p class="page-context">' . self::escape($archive->name) . '</p>') . '</div></div>';
        if ($error !== '') {
            $body .= '<div class="notice error" role="alert">' . $this->t($error) . ($detail === '' ? '' : '<div>' . self::escape($this->language->text($detail)) . '</div>') . '</div>';
        } elseif (Input::text($query, 'saved') === '1') {
            $result = Input::text($query, 'result');
            $body .= '<div class="notice success" role="status" tabindex="-1">' . $this->t(in_array($result, ['queued', 'installed', 'enabled', 'disabled', 'removed', 'refreshed', 'checked', 'updated'], true) ? 'feedback.' . $result : 'status.saved') . '</div>';
        }
        $body .= match ($page) {
            'overview' => $this->overview(),
            'stations' => $this->stations($query),
            'archives' => $this->archives($query),
            'fields' => $this->fields($query),
            'themes' => Input::text($query, 'theme') === ''
                ? (new ThemePage($this->read, $this->language, $this->csrf, $this->http ?? \WeewxPhp\Upload\Http\Http::client()))->render()
                : $this->themes($query),
            'settings' => $this->settings($query),
            'backups' => $this->backups(),
            'extensions' => Input::text($query, 'extension') === ''
                ? (new ExtensionPage($this->read, $this->language, $this->csrf, $this->http ?? \WeewxPhp\Upload\Http\Http::client()))->render()
                : (new ExtensionSettings($this->read, $this->language, $this->csrf))->render($query),
            default => '',
        };
        return $this->shell($page, $body);
    }

    private function backups(): string
    {
        $backups = new \WeewxPhp\Backup\Backups($this->read->config->settings);
        $status = $backups->status();
        $out = '<section class="panel">' . $this->form('backup.create') . $this->endForm('action.backup_now')
            . '<div class="job">' . $this->badge($status['status']) . '<span>' . $this->t('label.last_backup') . ': ' . ($status['completed'] > 0 ? $this->when($status['completed']) : '—') . '</span></div>'
            . '<div class="table-scroll"><table><thead><tr><th>' . $this->t('label.time') . '</th><th>' . $this->t('label.size') . '</th><th></th></tr></thead><tbody>';
        foreach ($backups->files() as $file) {
            $out .= '<tr><td>' . $this->when($file['created']) . '</td><td>' . $this->language->number(round($file['size'] / 1048576, 1)) . ' MiB</td><td><a class="button small" href="'
                . $this->url('backups', ['download' => $file['name']]) . '">' . $this->t('action.download') . '</a></td></tr>';
        }
        return $out . '</tbody></table></div></section>';
    }

    private function overview(): string
    {
        $stations = $this->read->stations();
        $pending = count(array_filter($stations, static fn(array $row): bool => $row['state'] === 'pending'));
        $adopted = count(array_filter($stations, static fn(array $row): bool => $row['state'] === 'adopted'));
        $now = $this->now ?? time();
        $warnings = \WeewxPhp\Backup\Health::warnings($this->read->config, $now);
        $active = array_filter($stations, static fn(array $row): bool => $row['state'] !== 'ignored');
        $recent = count(array_filter($active, fn(array $row): bool => $this->freshness($row['last_seen'], $this->receptionWindow()) === 'current'));
        $attention = $pending > 0 || count($active) > $recent || $warnings !== [];
        $ready = 0;
        foreach ($this->read->config->archives as $archive) {
            if ($this->archiveConnectionStatus($archive) === 'setup.ready') {
                ++$ready;
                try {
                    $inventory = ReadModel::archive($archive);
                    $attention = $attention || $this->freshness($inventory['last'], max(900, 3 * $archive->interval($this->read->config->settings))) !== 'current';
                } catch (\Throwable) {
                    $attention = true;
                }
            }
        }
        $setup = $active === [] || $this->read->config->archives === [] || $ready < count($this->read->config->archives);
        $headline = $setup ? 'dashboard.setup' : ($attention ? 'dashboard.attention' : 'dashboard.receiving');
        $destination = $pending > 0 || $active === [] || count($active) > $recent ? 'stations' : ($setup ? 'archives' : ($warnings !== [] ? 'settings' : 'archives'));
        $out = '<section class="station-hero"><div><span class="hero-kicker">' . $this->t('dashboard.station_status') . '</span><h2>' . $this->t($headline) . '</h2><div class="hero-signals">'
            . '<span>' . $this->t('dashboard.receiving_count') . ' <strong>' . $recent . ' / ' . count($active) . '</strong></span><span>' . $this->t('dashboard.archives_ready') . ' <strong>' . $ready . ' / ' . count($this->read->config->archives) . '</strong></span></div></div>'
            . '<a class="button hero-action" href="' . $this->url($destination) . '">' . $this->t($setup ? 'action.continue_setup' : 'action.check_status') . ' →</a></section>';
        $out .= '<div class="metrics">';
        foreach ([['stations', 'label.adopted', $adopted, 'dashboard.view_stations'], ['stations', 'label.pending', $pending, 'dashboard.review_stations'], ['archives', 'nav.archives', count($this->read->config->archives), 'dashboard.view_archives']] as [$page, $label, $number, $link]) {
            $out .= '<a class="metric" href="' . $this->url($page, $label === 'label.pending' ? ['filter' => 'pending'] : []) . '"><span class="metric-label">' . $this->t($label) . $this->icon($page) . '</span><strong>' . $this->language->number($number) . '</strong><span class="metric-link">' . $this->t($link) . ' <span aria-hidden="true">↗</span></span></a>';
        }
        $out .= '</div><div class="dashboard-columns"><section class="panel"><div class="panel-heading"><h2>' . $this->t('dashboard.reception') . '</h2><a href="' . $this->url('stations') . '">' . $this->t('action.manage') . ' →</a></div>';
        foreach ($active as $station) {
            $out .= '<a class="station-row" href="' . $this->url('stations', ['station' => Sqlite::text($station['id'])]) . '"><span class="station-symbol">' . $this->icon('stations') . '</span><span class="station-identity"><strong>' . self::escape(Sqlite::text($station['name'])) . '</strong><span>' . $this->t('label.last_seen') . ' · ' . $this->when($station['last_seen']) . '</span></span>' . $this->freshnessBadge($station['last_seen'], $this->receptionWindow()) . '</a>';
        }
        if ($active === []) {
            $out .= '<div class="empty"><div class="empty-symbol">' . $this->icon('stations') . '</div><p>' . $this->t('dashboard.no_reception') . '</p><a class="button primary" href="' . $this->url('stations') . '">' . $this->t('action.connect_station') . '</a></div>';
        }
        $out .= '</section><section class="panel next-steps"><h2>' . $this->t('dashboard.next_steps') . '</h2>';
        if ($pending > 0) {
            $out .= '<a class="attention-row" href="' . $this->url('stations', ['filter' => 'pending']) . '"><span>' . $this->t('dashboard.review_stations') . '</span><strong>' . $pending . ' →</strong></a>';
        }
        foreach ($warnings as $warning) {
            $out .= '<a class="attention-row" href="' . $this->url($warning === 'health.tick' ? 'settings' : 'backups') . '"><span>' . $this->t($warning) . '</span><span aria-hidden="true">→</span></a>';
        }
        $out .= '<ol class="workflow">';
        foreach ([['stations', 'action.connect_station', $adopted > 0], ['archives', 'dashboard.create_archive', $this->read->config->archives !== []], ['fields', 'action.assign_fields', $ready > 0 && $ready === count($this->read->config->archives)]] as [$page, $label, $done]) {
            $out .= '<li class="' . ($done ? 'done' : '') . '"><a href="' . $this->url($page) . '"><span>' . $this->t($label) . '</span><small>' . $this->t($done ? 'status.complete' : 'dashboard.open') . '</small></a></li>';
        }
        $out .= '</ol></section></div><section class="panel"><div class="panel-heading"><h2>' . $this->t('label.archive_status') . '</h2><a href="' . $this->url('archives') . '">' . $this->t('action.manage') . ' →</a></div>' . $this->archiveTable() . '</section>';
        $runs = $this->read->rows('state.sdb', 'runs', 'SELECT started_at, trigger, summary FROM runs ORDER BY id DESC LIMIT 8');
        $out .= '<details class="panel activity-panel"><summary>' . $this->t('label.recent_runs') . '</summary><div class="table-scroll"><table><thead><tr><th>' . $this->t('label.time') . '</th><th>' . $this->t('label.trigger') . '</th><th>' . $this->t('label.status') . '</th></tr></thead><tbody>';
        foreach ($runs as $run) {
            $summary = \WeewxPhp\Db\Json::object(Sqlite::text($run['summary']));
            $out .= '<tr><td>' . $this->when($run['started_at']) . '</td><td>' . self::escape(Sqlite::text($run['trigger'])) . '</td><td>' . $this->badge(is_string($summary['status'] ?? null) ? $summary['status'] : 'ok') . '</td></tr>';
        }
        return $out . '</tbody></table></div>' . ($runs === [] ? '<div class="empty">' . $this->t('status.no_runs') . '</div>' : '') . '</details>';
    }

    private function receptionWindow(): int
    {
        return max(900, 3 * $this->read->config->settings->archiveInterval);
    }

    private function freshness(mixed $last, int $window): string
    {
        if (!is_int($last) || $last <= 0) {
            return 'waiting';
        }
        return ($this->now ?? time()) - $last > $window ? 'stale' : 'current';
    }

    private function freshnessBadge(mixed $last, int $window): string
    {
        return $this->badge($this->freshness($last, $window));
    }

    private function when(mixed $value, ?ArchiveConfig $archive = null): string
    {
        return self::escape($this->language->date(is_int($value) ? $value : null, $archive === null ? $this->read->config->settings->timezone : $archive->timezone));
    }

    private function badge(string $status): string
    {
        return '<span class="badge ' . (in_array($status, ['adopted', 'mapped', 'ok', 'complete', 'current'], true) ? 'good' : (in_array($status, ['error', 'conflict', 'failed'], true) ? 'bad' : (in_array($status, ['stale', 'pending'], true) ? 'warning' : 'neutral'))) . '">' . $this->t('status.' . $status) . '</span>';
    }

    private function archiveTable(): string
    {
        $out = '<div class="table-scroll"><table class="archive-table"><thead><tr><th>' . $this->t('label.archive') . '</th><th>' . $this->t('label.status') . '</th><th>' . $this->t('label.interval') . '</th><th>' . $this->t('label.last_record') . '</th></tr></thead><tbody>';
        foreach ($this->read->config->archives as $id => $archive) {
            try {
                $info = ReadModel::archive($archive);
                $last = $info['last'];
                $status = $this->archiveConnectionStatus($archive);
            } catch (\Throwable) {
                $last = null;
                $status = 'error';
            }
            $out .= '<tr><td><a class="name" href="' . $this->url('archives', ['archive' => $id]) . '">' . self::escape($archive->name) . '</a></td><td>' . ($status === 'error' ? $this->badge($status) : '<span class="badge ' . ($status === 'setup.ready' ? 'good' : 'neutral') . '">' . $this->t($status) . '</span>') . ($status === 'setup.ready' ? '' : '<a class="setup-next" href="' . $this->url('archives', ['archive' => $id]) . '#archive-setup">' . $this->t('action.configure_connection') . ' →</a>') . '</td><td>' . (string) ($archive->interval($this->read->config->settings) / 60) . ' min</td><td>' . $this->when($last, $archive) . ($status === 'setup.ready' ? '<div class="record-freshness">' . $this->freshnessBadge($last, max(900, 3 * $archive->interval($this->read->config->settings))) . '</div>' : '') . '</td></tr>';
        }
        return $out . '</tbody></table></div>' . ($this->read->config->archives === [] ? '<div class="empty">' . $this->t('status.no_archives') . '</div>' : '');
    }

    /** @param array<string, mixed> $query */
    private function stations(array $query): string
    {
        $selected = Input::text($query, 'station');
        $filter = Input::text($query, 'filter');
        $tabs = '<div class="tabs">';
        foreach (['' => 'label.all', 'pending' => 'status.pending', 'adopted' => 'status.adopted', 'ignored' => 'status.ignored'] as $value => $label) {
            $tabs .= '<a href="' . $this->url('stations', ['filter' => $value]) . '"' . ($value === $filter ? ' aria-current="page"' : '') . '>' . $this->t($label) . '</a>';
        }
        $tabs .= '</div>';
        $out = '<div class="toolbar">' . $this->form('station.connection') . $this->button('action.connection') . '</form></div>' . $tabs . '<section class="panel"><div class="table-scroll"><table class="station-table"><thead><tr>';
        foreach (['station', 'protocol', 'status', 'last_seen', 'actions'] as $label) {
            $out .= '<th>' . $this->t('label.' . $label) . '</th>';
        }
        $out .= '</tr></thead><tbody>';
        $visible = 0;
        foreach ($this->read->stations() as $station) {
            $id = Sqlite::text($station['id']);
            $state = Sqlite::text($station['state']);
            if ($filter !== '' && $filter !== $state) {
                continue;
            }
            ++$visible;
            $out .= '<tr><td><a class="name" href="' . $this->url('stations', ['station' => $id]) . '">' . self::escape(Sqlite::text($station['name'])) . '</a><code class="subtle">' . self::escape($id) . '</code></td><td>' . self::escape(Sqlite::text($station['protocol'])) . '</td><td>' . $this->badge($state) . '</td><td>' . $this->when($station['last_seen']) . ($state === 'ignored' ? '' : '<div class="record-freshness">' . $this->freshnessBadge($station['last_seen'], $this->receptionWindow()) . '</div>') . '</td><td><div class="row-actions">';
            if ($station['protocol'] !== 'journal') {
                foreach ($state === 'adopted' ? ['reject'] : ($state === 'pending' ? ['adopt', 'reject'] : ['adopt', 'restore']) as $action) {
                    $out .= $this->form('station.' . $action, ['station' => $id]) . $this->button('action.' . $action, 'small') . '</form>';
                }
            }
            if ($state === 'adopted') {
                $out .= '<a class="button small" href="' . $this->url('archives') . '">' . $this->t('action.connect_to_archive') . '</a>';
            }
            $out .= '</div></td></tr>';
        }
        $out .= '</tbody></table></div>' . ($visible === 0 ? '<div class="empty">' . $this->t('status.no_stations') . '</div>' : '') . '</section>';
        if ($selected !== '') {
            $stationConfig = $this->read->config->station($selected);
            if ($stationConfig !== null) {
                $out .= '<section class="panel">' . $this->form('station.rename', ['station' => $selected]) . $this->input('name', 'label.name', $stationConfig->name, required: true) . $this->endForm() . '</section>';
            }
            $out .= '<section class="panel"><h2>' . $this->t('label.received_fields') . ' <code>' . self::escape($selected) . '</code></h2><div class="table-scroll"><table><thead><tr>';
            foreach (['source_field', 'observation', 'latest_value', 'last_seen'] as $label) {
                $out .= '<th>' . $this->t('label.' . $label) . '</th>';
            }
            $out .= '</tr></thead><tbody>';
            foreach ($this->read->fields($selected) as $field) {
                $out .= '<tr><td><code>' . self::escape(Sqlite::text($field['native'])) . '</code></td><td><code>' . self::escape(is_string($field['observation']) ? $field['observation'] : '—') . '</code></td><td>' . $this->reading($field) . '</td><td>' . $this->when($field['last_seen']) . '</td></tr>';
            }
            $out .= '</tbody></table></div></section>';
        }
        return $out;
    }

    /** @param array<string, mixed> $field */
    private function reading(array $field): string
    {
        $value = $field['value'] ?? null;
        return (is_int($value) || is_float($value) ? self::escape($this->language->number($value)) : '—') . ' <span class="subtle">' . self::escape($this->language->unit(is_string($field['unit'] ?? null) ? $field['unit'] : '')) . '</span>';
    }

    /** @param array<string, mixed> $query */
    private function archives(array $query): string
    {
        $id = Input::text($query, 'archive');
        $archive = $this->read->config->archive($id);
        if ($archive !== null) {
            $inventory = ReadModel::archive($archive);
            $storedUnits = $inventory['units'];
            $unitSystem = \WeewxPhp\Weewx\UnitSystem::tryFrom($storedUnits ?? 0) ?? $archive->unitSystem;
            $out = '<div class="toolbar"><a href="' . $this->url('archives') . '">← ' . $this->t('nav.archives') . '</a><a class="button" href="' . $this->url('fields', ['archive' => $id]) . '">' . $this->t('nav.fields') . '</a></div>';
            $out .= $this->archiveSetup($archive, true);
            $out .= '<section class="panel archive-settings" id="archive-settings"><h2>' . self::escape($archive->name) . '</h2>' . $this->form('archive.save', ['archive' => $id, 'preserve_senders' => '1']) . '<fieldset><legend>' . $this->t('label.general') . '</legend><div class="form-grid">'
                . $this->input('name', 'label.name', $archive->name, required: true)
                . $this->select('enabled', 'label.status', ['true' => $this->language->text('status.enabled'), 'false' => $this->language->text('status.disabled')], $archive->enabled ? 'true' : 'false')
                . '</div></fieldset><fieldset><legend>' . $this->t('label.location') . '</legend>' . $this->locationSearch()
                . '<div class="form-grid location-fields">'
                . $this->input('location', 'label.location', $archive->location)
                . $this->number('latitude', 'label.latitude', $archive->latitude === null ? '' : (string) $archive->latitude, limits: 'min="-90" max="90"')
                . $this->number('longitude', 'label.longitude', $archive->longitude === null ? '' : (string) $archive->longitude, limits: 'min="-180" max="180"')
                . '<div class="height-fields">' . $this->number('altitude_value', 'label.altitude', $archive->altitude === null ? '' : (string) $archive->altitude->value)
                . $this->select('altitude_unit', 'label.unit', ['meter' => 'm', 'foot' => 'ft'], $archive->altitude->unit ?? 'meter') . '</div>'
                . '</div></fieldset><fieldset><legend>' . $this->t('label.archive_settings') . '</legend><div class="form-grid">'
                . ($inventory['first'] === null ? $this->timezone($archive->timezone->getName())
                    . $this->number('archive_interval_minutes', 'label.interval_minutes', (string) ($archive->interval($this->read->config->settings) / 60), '1', 'min="1" max="60" required')
                    : '<div class="control"><span>' . $this->t('label.timezone') . '</span><output>' . self::escape($archive->timezone->getName()) . '</output></div>'
                    . '<div class="control"><span>' . $this->t('label.interval_minutes') . '</span><output>' . (int) ($archive->interval($this->read->config->settings) / 60) . '</output></div>')
                . '<div class="control"><span>' . $this->t('label.storage_units') . '</span><output>' . $unitSystem->name . '</output></div>'
                . '</div></fieldset>' . $this->endForm() . '</section>' . $this->maintenance($archive);
            return $out;
        }
        $out = '<section class="panel">' . $this->archiveTable() . '</section><div class="two-columns">';
        $out .= '<section class="panel"><h2>' . $this->t('action.create_archive') . '</h2>' . $this->form('archive.create') . '<div class="form-grid">'
            . $this->input('name', 'label.name', required: true)
            . $this->timezone($this->read->config->settings->timezone->getName())
            . $this->select('unit_system', 'label.storage_units', ['US' => 'US', 'METRIC' => 'METRIC', 'METRICWX' => 'METRICWX'], 'US', 'hint.storage_units')
            . $this->number('archive_interval_minutes', 'label.interval_minutes', (string) ($this->read->config->settings->archiveInterval / 60), '1', 'min="1" max="60" required')
            . '</div>' . $this->endForm('action.create_archive') . '</section>';
        $labels = [];
        foreach (['uploading', 'searching', 'checking', 'repairing', 'ready', 'complete', 'no_files', 'no_zones', 'zones', 'choose_zone', 'resume', 'resume_select', 'pause', 'failed', 'limited', 'paused', 'pausing', 'discarded', 'discarding', 'all_zones_shown'] as $label) {
            $labels[$label] = $this->language->text('import.' . $label);
        }
        $out .= '<section class="panel archive-import" data-archive-import data-csrf="' . self::escape($this->csrf) . '" data-labels="' . self::escape(json_encode($labels, JSON_THROW_ON_ERROR)) . '"><h2>' . $this->t('action.connect_archive') . '</h2>'
            . '<div class="import-sources"><label class="button import-upload">' . $this->t('action.upload_archive') . '<input type="file" accept=".sdb,.db,.sqlite,.sqlite3" data-import-file></label>'
            . '<button type="button" data-import-search>' . $this->t('action.search_archives') . '</button></div>'
            . '<div class="import-results" data-import-results></div><div class="import-progress" hidden><progress max="100" value="0"></progress><button type="button" data-import-pause>' . $this->t('import.pause') . '</button></div>'
            . '<p class="import-status" data-import-status role="status" aria-live="polite"></p><button type="button" data-import-discard hidden>' . $this->t('import.discard') . '</button>'
            . '<form data-import-form hidden><div class="import-file-summary" data-import-summary></div><div class="form-grid">'
            . $this->input('name', 'label.name', required: true)
            . '<div class="import-zone">' . $this->timezone($this->read->config->settings->timezone->getName()) . '<button type="button" data-import-detect>' . $this->t('action.detect_timezone') . '</button><button type="button" data-import-all-zones hidden>' . $this->t('action.all_timezones') . '</button></div>'
            . '</div><p class="control-hint">' . $this->t('hint.import_timezone') . '</p><div class="form-actions"><button class="primary" type="submit">' . $this->t('action.connect_archive') . '</button></div></form>'
            . '<noscript><p>' . $this->t('import.javascript') . '</p></noscript></section>';
        return $out . '</div>';
    }

    private function mappingSuggestions(ArchiveConfig $archive): string
    {
        $candidates = MappingSuggestions::candidates($this->read, $archive);
        if ($candidates === []) {
            return '';
        }
        $last = MappingSuggestions::lastValues($archive, array_keys($candidates));
        $out = '<section class="panel mapping-suggestions"><div class="panel-heading"><h2>' . $this->t('suggestions.title') . '</h2><span class="badge neutral">' . count($candidates) . '</span></div><div class="suggestion-values">';
        foreach ($candidates as $source => $field) {
            $old = $last[$source];
            $out .= '<article class="suggestion"><h3><code>' . self::escape($field['native']) . '</code><span aria-hidden="true"> → </span><code>' . self::escape($source) . '</code></h3><div class="suggestion-comparison"><div><span>' . $this->t('suggestions.current') . '</span><strong>' . $this->reading($field) . '</strong><small>' . $this->when($field['last_seen'], $archive) . '</small></div>'
                . '<div><span>' . $this->t('suggestions.previous') . '</span><strong>' . $this->reading($old) . '</strong><small>' . ($old['last_seen'] === null ? $this->t('status.column_empty') : $this->when($old['last_seen'], $archive)) . '</small></div></div></article>';
        }
        $out .= '</div><details class="suggestion-confirm"><summary class="button primary">' . $this->t('suggestions.accept') . '</summary><div class="suggestion-confirmation">'
            . '<p>' . self::escape($this->language->text('suggestions.confirm', ['count' => count($candidates)])) . '</p>'
            . $this->form('mapping.accept_suggestions', ['archive' => $archive->id, 'suggestion' => MappingSuggestions::fingerprint($archive, $candidates)])
            . '<input type="hidden" name="complete" value="1"><button class="primary" type="submit" name="confirm" value="yes">' . $this->t('suggestions.confirm_action') . '</button></form></div></details></section>';
        return $out;
    }

    private function archiveConnectionStatus(ArchiveConfig $archive): string
    {
        $senders = $archive->senders ?? array_keys($this->read->config->stations);
        if ($senders === []) {
            return 'setup.no_station';
        }
        $mapping = new Mapping($archive, $this->read->primary($archive));
        foreach ($senders as $sender) {
            $targets = array_filter($archive->fields[$sender] ?? [], static fn(string $target): bool => $target !== '-');
            if (!$archive->explicitMapping) {
                foreach ($this->read->fields($sender) as $field) {
                    $source = $field['observation'];
                    if (is_string($source) && $mapping->target($sender, $source) !== null) {
                        $targets[] = $mapping->target($sender, $source);
                    }
                }
            }
            if ($targets === []) {
                return 'setup.no_fields';
            }
            foreach ($this->read->fields($sender) as $field) {
                $source = $field['observation'];
                if (is_string($source) && $mapping->target($sender, $source) === null && !in_array($source, $mapping->rainSources($sender), true) && ($archive->fields[$sender][$source] ?? null) !== '-') {
                    return 'setup.no_fields';
                }
            }
        }
        return $archive->enabled ? 'setup.ready' : 'status.disabled';
    }

    private function archiveSetup(ArchiveConfig $archive, bool $editStations): string
    {
        $senders = $archive->senders ?? array_keys($this->read->config->stations);
        $state = $this->archiveConnectionStatus($archive);
        $connected = $state !== 'setup.no_station';
        $mapped = $connected && $state !== 'setup.no_fields';
        $ready = $state === 'setup.ready';
        $tag = $ready && $editStations ? 'details' : 'section';
        $out = '<' . $tag . ' class="panel archive-setup" id="archive-setup">'
            . ($tag === 'details' ? '<summary>' . $this->t('label.archive_connection') . ' <span class="badge good">' . $this->t('setup.ready') . '</span></summary>' : '')
            . '<div class="panel-heading"><div><small class="subtle">' . $this->t('label.archive_connection') . '</small><h2>' . self::escape($archive->name) . '</h2></div><span class="badge ' . ($ready ? 'good' : 'neutral') . '">'
            . $this->t($state) . '</span></div>';
        $out .= '<ol class="setup-steps">';
        foreach (['action.connect_station' => $connected, 'action.assign_fields' => $mapped, 'setup.ready' => $ready] as $label => $done) {
            $out .= '<li class="' . ($done ? 'done' : '') . '">' . $this->t($label) . '</li>';
        }
        $out .= '</ol>';
        if ($editStations) {
            $out .= $this->form('archive.stations', ['archive' => $archive->id]) . '<fieldset><legend>' . $this->t('action.connect_station') . '</legend><div class="station-choices">';
            $selected = ($this->draft['action'] ?? '') === 'archive.stations' ? (is_array($this->draft['senders'] ?? null) ? $this->draft['senders'] : []) : $senders;
            foreach ($this->read->config->stations as $id => $station) {
                $out .= '<label><input type="checkbox" name="senders[]" value="' . self::escape($id) . '"' . (in_array($id, $selected, true) ? ' checked' : '') . '><span><strong>' . self::escape($station->name) . '</strong><small>' . self::escape($id) . '</small></span></label>';
            }
            $out .= '</div></fieldset>';
            if ($this->read->config->stations === []) {
                $out .= '<p>' . $this->t('status.no_approved_stations') . '</p><a class="button" href="' . $this->url('stations') . '">' . $this->t('nav.stations') . '</a>';
            } else {
                $out .= '<input type="hidden" name="complete" value="1"><div class="form-actions">' . $this->button('action.connect_and_assign') . '</div>';
            }
            $out .= '</form>';
            if ($connected) {
                $out .= '<a class="setup-next" href="' . $this->url('fields', ['archive' => $archive->id]) . '">' . $this->t('action.assign_fields') . ' →</a>';
            }
        } else {
            $out .= '<div class="toolbar"><strong>' . self::escape($archive->name) . '</strong><a href="' . $this->url('archives', ['archive' => $archive->id]) . '#archive-setup">' . $this->t('action.change_stations') . '</a></div>';
            if ($connected) {
                $out .= '<p class="control-hint">' . $this->t('hint.assign_existing_fields') . '</p>';
            }
        }
        if ($mapped && !$archive->enabled) {
            $out .= '<a class="button primary" href="' . $this->url('archives', ['archive' => $archive->id]) . '#archive-settings">' . $this->t('action.activate_archive') . '</a>';
        }
        return $out . '</' . $tag . '>';
    }

    private function locationSearch(): string
    {
        $labels = [];
        foreach (['searching', 'empty', 'failed', 'applied'] as $key) {
            $labels[$key] = $this->language->text('location.' . $key);
        }
        $labels['invalid'] = $this->language->text('error.location_query');
        return '<div class="location-search" data-location-search data-labels="' . self::escape(json_encode($labels, JSON_THROW_ON_ERROR)) . '">'
            . '<div class="location-query"><label class="control"><span>' . $this->t('label.location_query') . '</span><input type="search" maxlength="160" data-location-query></label>'
            . '<button type="button" data-location-submit>' . $this->t('action.search') . '</button></div>'
            . '<div data-location-results class="location-results"></div><p data-location-status role="status" aria-live="polite" class="control-hint"></p>'
            . '<small class="location-attribution"><a href="https://open-meteo.com/" target="_blank" rel="noreferrer">Open-Meteo</a> · <a href="https://www.geonames.org/" target="_blank" rel="noreferrer">GeoNames</a></small></div>';
    }

    private function maintenance(ArchiveConfig $archive): string
    {
        $out = '<section class="panel"><h2>' . $this->t('label.maintenance') . '</h2><div class="row-actions">';
        foreach (['backup', 'verify'] as $kind) {
            $out .= $this->form('maintenance.queue', ['archive' => $archive->id, 'kind' => $kind, 'job_key' => bin2hex(random_bytes(16))]) . $this->button('action.' . $kind, 'small') . '</form>';
        }
        $out .= '</div><details><summary>' . $this->t('action.rebuild') . '</summary>' . $this->form('maintenance.queue', ['archive' => $archive->id, 'kind' => 'rebuild', 'job_key' => bin2hex(random_bytes(16))])
            . '<p class="consequence">' . $this->t('status.rebuild_consequence') . '</p><div class="form-grid">' . $this->input('from', 'label.from', type: 'datetime-local', required: true) . $this->input('to', 'label.to', type: 'datetime-local', required: true) . '</div>' . $this->endForm('action.rebuild') . '</details>';
        $jobs = $this->read->rows('state.sdb', 'admin_job', 'SELECT kind, status, result FROM admin_job WHERE archive = ? ORDER BY created DESC LIMIT 10', [$archive->id]);
        foreach ($jobs as $job) {
            $out .= '<div class="job"><strong>' . $this->t('action.' . Sqlite::text($job['kind'])) . '</strong>' . $this->badge(Sqlite::text($job['status'])) . '<span>' . self::escape($this->language->text(Sqlite::text($job['result']))) . '</span></div>';
        }
        return $out . '</section>';
    }

    /** @param array<string, mixed> $query */
    private function fields(array $query): string
    {
        $id = Input::text($query, 'archive', array_key_first($this->read->config->archives) ?? '');
        $archive = $this->read->config->archive($id);
        $choices = [];
        foreach ($this->read->config->archives as $key => $item) {
            $choices[$key] = $item->name;
        }
        $filter = Input::text($query, 'filter');
        $out = '<form method="get" class="filterbar"><input type="hidden" name="page" value="fields">'
            . $this->select('archive', 'label.archive', $choices, $id)
            . $this->select('filter', 'label.status', ['' => $this->language->text('label.all'), 'unmapped' => $this->language->text('status.unmapped'), 'conflict' => $this->language->text('status.conflict')], $filter)
            . $this->button('action.apply', 'small') . '</form>';
        if ($archive === null) {
            return $out . '<section class="panel empty">' . $this->t('status.no_archives') . '</section>';
        }
        $out .= $this->archiveSetup($archive, false);
        $senders = $archive->senders ?? array_keys($this->read->config->stations);
        $schema = ReadModel::archive($archive)['schema'];
        $mapping = new Mapping($archive, $this->read->primary($archive));
        $targets = [];
        foreach ($senders as $sender) {
            foreach ($this->read->fields($sender) as $field) {
                $source = $field['observation'];
                $target = is_string($source) ? $mapping->target($sender, $source) : null;
                if ($target !== null && $schema->hasColumn($target)) {
                    $targets[] = $target;
                }
            }
            foreach ($archive->fields[$sender] ?? [] as $target) {
                if ($target !== '-' && $schema->hasColumn($target)) {
                    $targets[] = $target;
                }
            }
        }
        $inventory = Inventory::read($archive, $targets);
        $out .= '<div class="archive-inventory"><section><span>' . $this->t('label.stored_records') . '</span><strong>' . self::escape($this->language->number($inventory['count'])) . '</strong></section>';
        foreach (['first' => 'label.oldest_record', 'last' => 'label.newest_record'] as $key => $label) {
            $out .= '<section><span>' . $this->t($label) . '</span><strong>' . $this->when($inventory[$key], $archive) . '</strong><small>' . self::escape($this->language->age($inventory[$key], time())) . '</small></section>';
        }
        $out .= '</div>';
        if (!$archive->explicitMapping) {
            $out .= '<div class="notice">' . $this->t('status.legacy_mapping') . $this->form('mapping.explicit', ['archive' => $id]) . $this->button('action.explicit', 'small') . '</form></div>';
        }
        if ($senders === []) {
            return $out . '<section class="panel empty"><a href="' . $this->url('archives', ['archive' => $id]) . '">' . $this->t('action.connect_station') . '</a></section>';
        }
        $out .= $this->mappingSuggestions($archive);
        $out .= $this->form('mapping.save_all', ['archive' => $id]);
        foreach ($senders as $station) {
            $stationConfig = $this->read->config->station($station);
            $name = $stationConfig === null ? $station : $stationConfig->name;
            $out .= '<section class="panel station-fields"><div class="panel-heading"><h2>' . self::escape($name) . '</h2><a href="' . $this->url('stations', ['station' => $station]) . '"><code>' . self::escape($station) . '</code></a></div><div class="table-scroll"><table><thead><tr>';
            foreach (['source_field', 'latest_value', 'archive_column', 'status'] as $label) {
                $out .= '<th>' . $this->t('label.' . $label) . '</th>';
            }
            $out .= '</tr></thead><tbody>';
            $fields = $this->read->fields($station);
            $present = [];
            foreach ($fields as $field) {
                if (is_string($field['observation'])) {
                    $present[$field['observation']] = true;
                }
            }
            foreach ($archive->fields[$station] ?? [] as $source => $unused) {
                if (!isset($present[$source])) {
                    $fields[] = ['native' => $source, 'observation' => $source, 'value' => null, 'unit' => null, 'last_seen' => null];
                }
            }
            $rendered = [];
            $count = 0;
            foreach ($fields as $field) {
                $source = is_string($field['observation']) ? $field['observation'] : null;
                $row = $source ?? 'native:' . Sqlite::text($field['native']);
                if (isset($rendered[$row])) {
                    continue;
                }
                $rendered[$row] = true;
                $target = $source === null ? null : $mapping->target($station, $source);
                $status = ($archive->fields[$station][$source ?? ''] ?? null) === '-' ? 'excluded' : ($target === null ? 'unmapped' : 'mapped');
                if ($status === 'unmapped' && in_array($source, $mapping->rainSources($station), true)) {
                    $status = 'rain_input';
                }
                if ($target !== null && (!$schema->hasColumn($target) || !Catalog::compatible($source ?? '', $target, $archive->measurementKinds))) {
                    $status = 'conflict';
                }
                foreach ($archive->fields as $other => $placements) {
                    foreach ($placements as $otherSource => $otherTarget) {
                        if ($target !== null && $otherTarget === $target && ($other !== $station || $otherSource !== $source)) {
                            $status = 'conflict';
                        }
                    }
                }
                if ($filter !== '' && $status !== $filter) {
                    continue;
                }
                ++$count;
                $draft = $this->draft['mapping'] ?? [];
                $stationDraft = is_array($draft) ? ($draft[$station] ?? []) : [];
                $chosen = is_array($stationDraft) && is_string($stationDraft[$row] ?? null) ? $stationDraft[$row] : ($target ?? ($status === 'excluded' ? '-' : ''));
                $options = ['' => $this->language->text($status === 'rain_input' ? 'status.rain_input' : 'status.unmapped')];
                if ($source !== null) {
                    $options['-'] = $this->language->text('action.ignore');
                    foreach ($schema->observations() as $column) {
                        if ($column === $chosen || Catalog::compatible($source, $column, $archive->measurementKinds)) {
                            $options[$column] = $column . ($column === 'rain' && Catalog::kind($source, $archive->measurementKinds) === 'rain_counter' ? ' · ' . $this->language->text('label.counter_delta') : '');
                        }
                    }
                }
                $kind = $source === null ? null : Catalog::kind($source, $archive->measurementKinds);
                $creatable = $source === null || ($kind !== null && $kind !== 'direction');
                if ($creatable) {
                    $options['__new__'] = $this->language->text('action.new_column');
                }
                $out .= '<tr><td><code>' . self::escape(Sqlite::text($field['native'])) . '</code>'
                    . ($source === null || $source === $field['native'] ? '' : '<span class="subtle">' . self::escape($source) . '</span>')
                    . '</td><td>' . $this->reading($field) . '<small class="subtle">' . $this->when($field['last_seen']) . '</small></td><td><select data-column-target data-original-value="' . self::escape($target ?? ($status === 'excluded' ? '-' : '')) . '" data-confirmation-name="history[' . self::escape($station) . '][' . self::escape($row) . ']" aria-label="' . $this->t('label.archive_column') . ': ' . self::escape(Sqlite::text($field['native'])) . '" name="mapping[' . self::escape($station) . '][' . self::escape($row) . ']"' . (!$archive->explicitMapping ? ' disabled' : '') . '>';
                foreach ($options as $value => $label) {
                    $out .= '<option value="' . self::escape($value) . '"' . ($chosen === $value ? ' selected' : '') . '>' . self::escape($label) . '</option>';
                }
                $out .= '</select><div class="column-history" data-column-history data-column="' . self::escape($chosen) . '">';
                if ($schema->hasColumn($chosen)) {
                    $stats = $inventory['columns'][$chosen] ?? Inventory::read($archive, [$chosen])['columns'][$chosen];
                    $changed = $chosen !== $target && $stats['count'] > 0;
                    $historyDraft = $this->draft['history'] ?? [];
                    $stationHistory = is_array($historyDraft) ? ($historyDraft[$station] ?? []) : [];
                    $confirmed = is_array($stationHistory) && ($stationHistory[$row] ?? null) === $chosen;
                    $out .= $this->historyHtml($this->historyData($archive, $chosen, $stats), $changed ? 'history[' . $station . '][' . $row . ']' : null, $confirmed, $chosen);
                }
                $out .= '</div></td><td>' . $this->badge($status) . '</td></tr>';
                if ($creatable && $archive->explicitMapping) {
                    $out .= $this->newColumn($station, $row, $source, $kind, $chosen === '__new__');
                }
            }
            $out .= '</tbody></table></div>' . ($count === 0 ? '<div class="empty">' . $this->t('status.no_fields') . '</div>' : '') . '</section>';
        }
        if ($archive->explicitMapping) {
            $out .= '<div class="mapping-actions"><input type="hidden" name="complete" value="1"><div class="form-actions">' . $this->button('action.save_mapping') . '<button type="reset">' . $this->t('action.cancel') . '</button></div></div></form>';
        } else {
            $out .= '</form>';
        }
        return $out;
    }

    /** @param array{count: int, first: ?int, last: ?int} $stats
     * @return array{occupied: bool, warning: string, confirmation: string, summary: string, items: list<array{label: string, value: string}>, historyLabel: string, sourceSummary: string, sources: list<string>}
     */
    public function historyData(ArchiveConfig $archive, string $column, array $stats): array
    {
        $items = [];
        $sources = [];
        $sourceNames = [];
        if ($stats['first'] !== null && $stats['last'] !== null) {
            foreach (['first' => 'label.oldest_value', 'last' => 'label.newest_value'] as $key => $label) {
                $items[] = ['label' => $this->language->text($label), 'value' => $this->language->date($stats[$key], $archive->timezone) . ' · ' . $this->language->age($stats[$key], time())];
            }
            foreach (Inventory::sources($this->read, $archive, $column, $stats['first'], $stats['last']) as $period) {
                $names = $period['sources'] === [] ? $this->language->text($period['calculated'] ? 'status.calculated' : 'status.source_unknown') : implode(', ', $period['sources']);
                $sourceNames[$names] = true;
                $sources[] = $names . ' · ' . $this->language->date($period['from'], $archive->timezone) . ' – ' . $this->language->date($period['to'], $archive->timezone);
            }
        }
        return ['occupied' => $stats['count'] > 0, 'warning' => $this->language->text('warning.existing_data'), 'confirmation' => $this->language->text('label.continue_column'), 'summary' => $stats['count'] === 0 ? $this->language->text('status.column_empty') : $this->language->text('count.stored_values', ['number' => $this->language->number($stats['count'])], $stats['count']),
            'items' => $items, 'historyLabel' => $this->language->text('label.mapping_history'), 'sourceSummary' => implode('; ', array_keys($sourceNames)), 'sources' => $sources];
    }

    /** @param array{occupied: bool, warning: string, confirmation: string, summary: string, items: list<array{label: string, value: string}>, historyLabel: string, sourceSummary: string, sources: list<string>} $data */
    private function historyHtml(array $data, ?string $confirmationName = null, bool $confirmed = false, string $column = ''): string
    {
        $out = $confirmationName === null ? '' : '<div class="existing-data-warning" role="status"><strong class="warning-title">' . self::escape($data['warning']) . '</strong>';
        $out .= '<strong class="history-count">' . self::escape($data['summary']) . '</strong><dl>';
        foreach ($data['items'] as $item) {
            $out .= '<dt>' . self::escape($item['label']) . '</dt><dd>' . self::escape($item['value']) . '</dd>';
        }
        $out .= '</dl><span class="history-source">' . self::escape($data['sourceSummary']) . '</span>';
        if ($data['sources'] !== []) {
            $out .= '<details><summary>' . self::escape($data['historyLabel']) . '</summary><ul>';
            foreach ($data['sources'] as $source) {
                $out .= '<li>' . self::escape($source) . '</li>';
            }
            $out .= '</ul></details>';
        }
        if ($confirmationName !== null) {
            $out .= '<label class="check history-confirmation"><input type="checkbox" name="' . self::escape($confirmationName) . '" value="' . self::escape($column) . '" required' . ($confirmed ? ' checked' : '') . '>' . self::escape($data['confirmation']) . '</label></div>';
        }
        return $out;
    }

    private function newColumn(string $station, string $row, ?string $source, ?string $kind, bool $open): string
    {
        $prefix = 'columns[' . $station . '][' . $row . ']';
        $all = $this->draft['columns'] ?? [];
        $stationDraft = is_array($all) ? ($all[$station] ?? []) : [];
        $draft = is_array($stationDraft) ? ($stationDraft[$row] ?? []) : [];
        $draft = is_array($draft) ? \WeewxPhp\Db\Json::object(json_encode($draft, JSON_THROW_ON_ERROR)) : [];
        $name = Input::text($draft, 'column', $source === null ? (preg_replace('/[^A-Za-z0-9_]/', '_', substr($row, 7)) ?? '') : substr($source, 0, 58) . 'Custom');
        $out = '<tr class="column-draft' . ($open ? ' is-open' : '') . '"><td colspan="4"><div class="column-heading">' . $this->t('action.new_column') . '</div><div class="column-grid">'
            . $this->input($prefix . '[column]', 'label.column_name', $name)
            . $this->input($prefix . '[label]', 'label.label', Input::text($draft, 'label'));
        if ($source === null) {
            $kinds = $this->kinds();
            unset($kinds['direction']);
            $out .= $this->select($prefix . '[kind]', 'label.measurement_type', $kinds, Input::text($draft, 'kind', 'temperature'))
                . $this->input($prefix . '[unit]', 'label.source_unit', Input::text($draft, 'unit', 'degree_C'));
        } else {
            $out .= '<div class="control"><span>' . $this->t('label.measurement_type') . '</span><output>' . $this->t('kind.' . $kind) . '</output></div>';
        }
        $out .= $this->select($prefix . '[storage_type]', 'label.storage_type', ['REAL' => 'REAL', 'INTEGER' => 'INTEGER'], Input::text($draft, 'storage_type', 'REAL'))
            . $this->select($prefix . '[aggregation]', 'label.aggregation', Catalog::lastKind($kind) ? ['last' => $this->language->text('aggregation.last')] : $this->aggregations(), Input::text($draft, 'aggregation', Catalog::lastKind($kind) ? 'last' : 'avg'));
        return $out . '</div></td></tr>';
    }

    /**
     * @return array<string, string> */
    private function kinds(): array
    {
        $result = [];
        foreach (array_keys(Catalog::KINDS) as $kind) {
            $result[$kind] = $this->language->text('kind.' . $kind);
        }
        return $result;
    }

    /**
     * @return array<string, string> */
    private function aggregations(): array
    {
        $result = [];
        foreach (['avg', 'sum', 'min', 'max', 'first', 'last'] as $key) {
            $result[$key] = $this->language->text('aggregation.' . $key);
        }
        return $result;
    }

    /** @param array<string, mixed> $query */
    private function themes(array $query): string
    {
        $registry = ThemeRegistry::configured($this->read->path, file: $this->read->file);
        $themes = $registry->themes();
        if ($themes === []) {
            return '<section class="panel empty">' . $this->t('status.no_themes') . '</section>';
        }
        $active = $registry->active($this->read->file);
        $selected = Input::text($query, 'theme', $active);
        $out = '<div class="tabs"><a href="' . $this->url('themes') . '">' . $this->t('theme.shop') . '</a>';
        foreach ($themes as $theme) {
            $out .= '<a href="' . $this->url('themes', ['theme' => $theme]) . '"' . ($theme === $selected ? ' aria-current="page"' : '') . '>' . self::escape($theme) . '</a>';
        }
        $out .= '</div>';
        try {
            $definition = $registry->definition($selected);
            $saved = $this->read->file->root()->optionalSection('Themes')?->optionalSection($selected);
            $out .= '<section class="panel"><h2>' . self::escape($selected) . '</h2>' . $this->form('theme.save', ['theme' => $selected]) . '<div class="form-grid">';
            foreach ($registry->fields($definition) as $field) {
                $key = Input::text($field, 'key');
                $label = $registry->label($selected, Input::text($field, 'label', $key), $this->language->language);
                $default = $field['default'] ?? '';
                $value = $saved?->optional($key)?->string() ?? (is_bool($default) ? ($default ? 'true' : 'false') : (is_scalar($default) ? (string) $default : ''));
                $type = Input::text($field, 'type');
                $options = [];
                if ($type === 'boolean') {
                    $options = ['true' => $this->language->text('label.yes'), 'false' => $this->language->text('label.no')];
                } elseif ($type === 'select') {
                    foreach (is_array($field['options'] ?? null) ? $field['options'] : [] as $option => $text) {
                        if (is_string($text)) {
                            $options[(string) $option] = $registry->label($selected, $text, $this->language->language);
                        }
                    }
                } elseif (in_array($type, ['archive', 'archive_field'], true)) {
                    foreach ($this->read->config->archives as $id => $archive) {
                        if ($type === 'archive') {
                            $options[$id] = $archive->name;
                        } else {
                            foreach (ReadModel::archive($archive)['schema']->observations() as $column) {
                                $options[$id . ':' . $column] = $archive->name . ' / ' . $column;
                            }
                        }
                    }
                }
                $control = $options !== [] ? $this->select($key, $label, $options, $value) : $this->input($key, $label, $value, in_array($type, ['integer', 'number'], true) ? 'number' : ($type === 'color' ? 'color' : 'text'));
                $out .= $control;
            }
            $out .= '</div>';
            if ($saved === null || !\WeewxPhp\Extension\Installer::managed($saved)) {
                $out .= '<label class="check"><input type="checkbox" name="activate" value="true"' . ($selected === $active ? ' checked' : '') . '>' . $this->t('action.activate_theme') . '</label>';
            }
            $out .= $this->endForm() . '</section>';
        } catch (\Throwable) {
            $out .= '<div class="notice error">' . $this->t('error.theme') . '</div>';
        }
        return $out;
    }

    /** @param array<string, mixed> $query */
    private function settings(array $query): string
    {
        $settings = $this->read->config->settings;
        $out = '<section class="panel"><h2>' . $this->t('label.general') . '</h2>' . $this->form('settings.save') . '<div class="form-grid">'
            . $this->timezone($settings->timezone->getName())
            . $this->select('language', 'label.language', Translator::available(), $this->language->language)
            . $this->select('update_channel', 'core.channel', ['stable' => $this->language->text('core.stable'), 'beta' => $this->language->text('core.beta')], $this->read->file->root()->optionalSection('Admin')?->optional('update_channel')?->string() ?? 'stable');
        $out .= $this->select('backup_enabled', 'label.backup_enabled', ['true' => $this->language->text('label.yes'), 'false' => $this->language->text('label.no')], $settings->backupEnabled ? 'true' : 'false')
            . $this->input('backup_retention_days', 'label.backup_retention_days', (string) $settings->backupRetentionDays, 'number', true);
        $out .= $this->select('visit_tick_enabled', 'label.visit_tick_enabled', ['true' => $this->language->text('label.yes'), 'false' => $this->language->text('label.no')], $settings->visitTickEnabled ? 'true' : 'false', 'hint.visit_tick');
        foreach (['archive_delay' => $settings->archiveDelay, 'live_retention' => $settings->liveRetention, 'raw_retention' => $settings->rawRetention,
            'time_budget' => $settings->timeBudget, 'max_intervals_per_run' => $settings->maxIntervalsPerRun] as $key => $value) {
            $out .= $this->input($key, 'label.' . $key, (string) $value, 'number');
        }
        return $out . '</div>' . $this->endForm() . '</section>' . (new CoreUpdatePage($this->read, $this->language, $this->csrf))->render() . $this->uploads($query);
    }

    /** @param array<string, mixed> $query */
    private function uploads(array $query): string
    {
        $id = Input::text($query, 'upload');
        $saved = $this->read->file->root()->optionalSection('Uploads')?->optionalSection($id);
        $kind = \WeewxPhp\Upload\Kind::tryFrom($saved?->optional('kind')?->string() ?? Input::text($query, 'kind', 'wunderground')) ?? \WeewxPhp\Upload\Kind::Wunderground;
        $out = '<section class="panel"><h2>' . $this->t('label.uploads') . '</h2><div class="table-scroll"><table><thead><tr><th>' . $this->t('label.name') . '</th><th>' . $this->t('label.service') . '</th><th>' . $this->t('label.archive') . '</th></tr></thead><tbody>';
        foreach ($this->read->config->uploads as $key => $upload) {
            $out .= '<tr><td><a href="' . $this->url('settings', ['upload' => $key]) . '">' . self::escape($key) . '</a></td><td>' . self::escape($upload->kind->label()) . '</td><td>' . self::escape($upload->archive) . '</td></tr>';
        }
        $kinds = [];
        foreach (\WeewxPhp\Upload\Kind::cases() as $item) {
            $kinds[$item->value] = $item->label();
        }
        $out .= '</tbody></table></div><details' . ($id !== '' || isset($query['kind']) ? ' open' : '') . '><summary>' . $this->t('action.save_upload') . '</summary><form method="get" class="filterbar"><input type="hidden" name="page" value="settings">'
            . $this->select('kind', 'label.service', $kinds, $kind->value) . $this->button('action.apply', 'small') . '</form>'
            . $this->form('upload.save', ['kind' => $kind->value]) . '<div class="form-grid">' . $this->input('upload', 'label.name', $id, required: true);
        $archives = [];
        foreach ($this->read->config->archives as $key => $archive) {
            $archives[$key] = $archive->name;
        }
        $out .= $this->select('archive', 'label.archive', $archives, $saved?->optional('archive')?->string() ?? '');
        foreach ($kind->spec() as $key => $spec) {
            $default = $spec->default;
            $value = $spec->type === \WeewxPhp\Upload\OptionSpec::SECRET ? '' : ($saved?->optional($key)?->raw() ?? $default);
            $text = is_array($value) ? implode(', ', $value) : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
            if ($spec->type === \WeewxPhp\Upload\OptionSpec::BOOL) {
                $out .= $this->select($key, 'upload.' . $key, ['true' => $this->language->text('label.yes'), 'false' => $this->language->text('label.no')], $text);
            } elseif ($spec->type === \WeewxPhp\Upload\OptionSpec::CHOICE) {
                $out .= $this->select($key, 'upload.' . $key, array_combine($spec->choices, $spec->choices), $text);
            } else {
                $type = $spec->type === \WeewxPhp\Upload\OptionSpec::SECRET ? 'password' : (in_array($spec->type, [\WeewxPhp\Upload\OptionSpec::INT, \WeewxPhp\Upload\OptionSpec::FLOAT], true) ? 'number' : 'text');
                $out .= $this->input($key, 'upload.' . $key, $text, $type, $spec->required && ($saved === null || $type !== 'password'));
            }
        }
        return $out . '</div>' . $this->endForm() . '</details></section>';
    }

    /** @param array{ecowitt: string, wunderground: string} $keys */
    public function connection(array $keys): string
    {
        $url = $this->read->config->ingest->publicUrl;
        $parts = parse_url($url);
        $scheme = is_array($parts) && isset($parts['scheme']) ? $parts['scheme'] : 'http';
        $host = is_array($parts) && isset($parts['host']) ? $parts['host'] : '—';
        $port = is_array($parts) && isset($parts['port']) ? $parts['port'] : ($scheme === 'https' ? 443 : 80);
        $base = is_array($parts) && isset($parts['path']) ? rtrim($parts['path'], '/') : '';
        $body = '<h1>' . $this->t('action.connection') . '</h1><p>' . $this->t($this->read->config->ingest->enabled ? 'connection.active' : 'connection.disabled') . '</p>';
        if ($url === '') {
            $body .= '<p class="notice error">' . $this->t('connection.missing_url') . '</p>';
        }
        foreach (['Ecowitt' => '/' . $keys['ecowitt'] . '/ecowitt/', 'Wunderground' => '/weatherstation/updateweatherstation.php'] as $protocol => $suffix) {
            $body .= '<section class="panel"><h2>' . $protocol . '</h2><dl class="connection-fields">';
            foreach (['label.protocol' => $protocol, 'label.transport' => strtoupper($scheme), 'label.server' => $host, 'label.port' => (string) $port, 'label.path' => $base . $suffix] as $label => $value) {
                $body .= '<div><dt>' . $this->t($label) . '</dt><dd><code>' . self::escape($value) . '</code></dd></div>';
            }
            if ($protocol === 'Wunderground') {
                $body .= '<div><dt>PASSWORD</dt><dd><code>' . self::escape($keys['wunderground']) . '</code></dd></div>';
            }
            $body .= '</dl><code class="credential">' . self::escape($url . $suffix) . '</code></section>';
        }
        $body .= '<a href="' . $this->url('stations') . '">' . $this->t('nav.stations') . '</a>';
        return $this->shell('stations', $body);
    }
}
