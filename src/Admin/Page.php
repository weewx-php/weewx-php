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
    public const PAGES = ['overview', 'stations', 'archives', 'fields', 'themes', 'settings'];

    /** @param array<string, mixed> $draft */
    public function __construct(
        private readonly ReadModel $read,
        private readonly Translator $language,
        private readonly string $csrf,
        private readonly array $draft = [],
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
        return self::escape('?' . http_build_query(['page' => $page] + $parameters));
    }

    private function value(string $key, string $default = ''): string
    {
        $value = $this->draft[$key] ?? $default;
        return is_string($value) ? $value : $default;
    }

    /** @param array<string, string> $hidden */
    private function form(string $action, array $hidden = []): string
    {
        $out = '<form method="post" data-edit-form>';
        foreach (['action' => $action, 'csrf' => $this->csrf, 'revision' => $this->read->revision] + $hidden as $name => $value) {
            $out .= '<input type="hidden" name="' . self::escape($name) . '" value="' . self::escape($value) . '">';
        }
        return $out;
    }

    private function input(string $name, string $label, string $default = '', string $type = 'text', bool $required = false): string
    {
        $value = $type === 'password' ? '' : $this->value($name, $default);
        return '<label class="control"><span>' . $this->t($label) . '</span><input name="' . self::escape($name) . '" type="' . self::escape($type)
            . '" value="' . self::escape($value) . '"' . ($required ? ' required' : '') . ($type === 'password' ? ' autocomplete="current-password"' : '') . '></label>';
    }

    /** @param array<string, string> $options Labels are plain text. */
    private function select(string $name, string $label, array $options, string $default = ''): string
    {
        $value = $this->value($name, $default);
        $out = '<label class="control"><span>' . $this->t($label) . '</span><select name="' . self::escape($name) . '">';
        foreach ($options as $key => $text) {
            $out .= '<option value="' . self::escape($key) . '"' . ($value === $key ? ' selected' : '') . '>' . self::escape($text) . '</option>';
        }
        return $out . '</select></label>';
    }

    private function button(string $label = 'action.save', string $class = 'primary'): string
    {
        return '<button class="' . self::escape($class) . '" type="submit">' . $this->t($label) . '</button>';
    }

    private function endForm(string $label = 'action.save'): string
    {
        return '<div class="form-actions">' . $this->button($label) . '<button type="reset">' . $this->t('action.cancel') . '</button></div></form>';
    }

    private function shell(string $page, string $body, bool $authenticated = true): string
    {
        $out = '<!doctype html><html lang="' . self::escape($this->language->language) . '" dir="' . $this->language->direction() . '"><head>'
            . '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $this->t('nav.' . $page) . ' · weewx-php</title>'
            . '<link rel="stylesheet" href="admin.css"><script src="admin.js" defer></script></head><body data-unsaved="' . $this->t('status.unsaved') . '" data-history-loading="' . $this->t('status.loading') . '" data-history-error="' . $this->t('status.history_error') . '" data-last-kinds="' . self::escape(implode(',', array_filter(array_keys(Catalog::KINDS), Catalog::lastKind(...)))) . '">'
            . '<a class="skip" href="#main">' . $this->t('action.skip') . '</a><div class="app' . ($authenticated ? '' : ' login-app') . '">';
        if ($authenticated) {
            $out .= '<aside class="sidebar"><a class="brand" href="' . $this->url('overview') . '"><span class="brand-mark" aria-hidden="true">w</span><span>weewx-php<small>' . $this->t('label.administration') . '</small></span></a><nav aria-label="' . $this->t('label.navigation') . '">';
            foreach (self::PAGES as $index => $name) {
                $out .= '<a href="' . $this->url($name) . '"' . ($name === $page ? ' aria-current="page"' : '') . '><span class="nav-icon" aria-hidden="true">' . ['◫', '◉', '▤', '⇄', '◧', '⚙'][$index] . '</span>' . $this->t('nav.' . $name) . '</a>';
            }
            $out .= '</nav><div class="sidebar-bottom"><span class="version">PHP · SQLite</span>' . $this->form('logout') . $this->button('action.logout', 'quiet') . '</form></div></aside>';
        }
        $out .= '<div class="workspace"><header class="topbar"><span>' . $this->t('label.administration') . '</span><span class="topbar-end">'
            . self::escape(strtoupper($this->language->language)) . '<span class="avatar" aria-hidden="true">A</span></span></header><main id="main" tabindex="-1">' . $body . '</main></div></div></body></html>';
        return $out;
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
            $body .= $this->form('login') . $this->input('password', 'label.password', type: 'password', required: true) . $this->button('action.login') . '</form>';
        }
        return $this->shell('overview', $body . '</section>', false);
    }

    /** @param array<string, mixed> $query */
    public function render(string $page, array $query, string $error = '', string $detail = ''): string
    {
        $body = '<div class="page-heading"><div><div class="eyebrow">WEEWX-PHP</div><h1>' . $this->t('nav.' . $page) . '</h1></div></div>';
        if ($error !== '') {
            $body .= '<div class="notice error" role="alert">' . $this->t($error) . ($detail === '' ? '' : '<div>' . self::escape($this->language->text($detail)) . '</div>') . '</div>';
        } elseif (Input::text($query, 'saved') === '1') {
            $body .= '<div class="notice success" role="status">' . $this->t('status.saved') . '</div>';
        }
        $body .= match ($page) {
            'overview' => $this->overview(),
            'stations' => $this->stations($query),
            'archives' => $this->archives($query),
            'fields' => $this->fields($query),
            'themes' => $this->themes($query),
            'settings' => $this->settings($query),
            default => '',
        };
        return $this->shell($page, $body);
    }

    private function overview(): string
    {
        $stations = $this->read->stations();
        $pending = count(array_filter($stations, static fn(array $row): bool => $row['state'] === 'pending'));
        $adopted = count(array_filter($stations, static fn(array $row): bool => $row['state'] === 'adopted'));
        $out = '<div class="metrics">';
        foreach (['label.adopted' => $adopted, 'label.pending' => $pending, 'nav.archives' => count($this->read->config->archives)] as $label => $number) {
            $out .= '<section class="metric"><span>' . $this->t($label) . '</span><strong>' . $this->language->number($number) . '</strong></section>';
        }
        $out .= '</div><section class="panel"><div class="panel-heading"><h2>' . $this->t('label.archive_status') . '</h2><a href="' . $this->url('archives') . '">' . $this->t('action.manage') . '</a></div>' . $this->archiveTable() . '</section>';
        $runs = $this->read->rows('state.sdb', 'runs', 'SELECT started_at, trigger, summary FROM runs ORDER BY id DESC LIMIT 8');
        $out .= '<section class="panel"><h2>' . $this->t('label.recent_runs') . '</h2><div class="table-scroll"><table><thead><tr><th>' . $this->t('label.time') . '</th><th>' . $this->t('label.trigger') . '</th><th>' . $this->t('label.status') . '</th></tr></thead><tbody>';
        foreach ($runs as $run) {
            $summary = \WeewxPhp\Db\Json::object(Sqlite::text($run['summary']));
            $out .= '<tr><td>' . $this->when($run['started_at']) . '</td><td>' . self::escape(Sqlite::text($run['trigger'])) . '</td><td>' . $this->badge(is_string($summary['status'] ?? null) ? $summary['status'] : 'ok') . '</td></tr>';
        }
        return $out . '</tbody></table></div>' . ($runs === [] ? '<div class="empty">' . $this->t('status.no_runs') . '</div>' : '') . '</section>';
    }

    private function when(mixed $value, ?ArchiveConfig $archive = null): string
    {
        return self::escape($this->language->date(is_int($value) ? $value : null, $archive === null ? $this->read->config->settings->timezone : $archive->timezone));
    }

    private function badge(string $status): string
    {
        return '<span class="badge ' . (in_array($status, ['adopted', 'mapped', 'ok', 'complete'], true) ? 'good' : (in_array($status, ['error', 'conflict', 'failed'], true) ? 'bad' : 'neutral')) . '">' . $this->t('status.' . $status) . '</span>';
    }

    private function archiveTable(): string
    {
        $out = '<div class="table-scroll"><table><thead><tr><th>' . $this->t('label.archive') . '</th><th>' . $this->t('label.status') . '</th><th>' . $this->t('label.interval') . '</th><th>' . $this->t('label.last_record') . '</th></tr></thead><tbody>';
        foreach ($this->read->config->archives as $id => $archive) {
            try {
                $info = ReadModel::archive($archive);
                $last = $info['last'];
                $status = $archive->enabled ? 'enabled' : 'disabled';
            } catch (\Throwable) {
                $last = null;
                $status = 'error';
            }
            $out .= '<tr><td><a class="name" href="' . $this->url('archives', ['archive' => $id]) . '">' . self::escape($archive->name) . '</a><code class="subtle">' . self::escape($id) . '</code></td><td>' . $this->badge($status) . '</td><td>' . (string) ($archive->interval($this->read->config->settings) / 60) . ' min</td><td>' . $this->when($last, $archive) . '</td></tr>';
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
        $out = '<div class="toolbar">' . $this->form('station.connection') . $this->button('action.connection') . '</form></div>' . $tabs . '<section class="panel"><div class="table-scroll"><table><thead><tr>';
        foreach (['station', 'protocol', 'status', 'last_seen', 'actions'] as $label) {
            $out .= '<th>' . $this->t('label.' . $label) . '</th>';
        }
        $out .= '</tr></thead><tbody>';
        foreach ($this->read->stations() as $station) {
            $id = Sqlite::text($station['id']);
            $state = Sqlite::text($station['state']);
            if ($filter !== '' && $filter !== $state) {
                continue;
            }
            $out .= '<tr><td><a class="name" href="' . $this->url('stations', ['station' => $id]) . '">' . self::escape(Sqlite::text($station['name'])) . '</a><code class="subtle">' . self::escape($id) . '</code></td><td>' . self::escape(Sqlite::text($station['protocol'])) . '</td><td>' . $this->badge($state) . '</td><td>' . $this->when($station['last_seen']) . '</td><td><div class="row-actions">';
            if ($station['protocol'] !== 'journal') {
                foreach ($state === 'adopted' ? ['reject'] : ($state === 'pending' ? ['adopt', 'reject'] : ['adopt', 'restore']) as $action) {
                    $out .= $this->form('station.' . $action, ['station' => $id]) . $this->button('action.' . $action, 'small') . '</form>';
                }
            }
            $out .= '</div></td></tr>';
        }
        $out .= '</tbody></table></div></section>';
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
            $storedUnits = ReadModel::archive($archive)['units'];
            $unitSystem = \WeewxPhp\Weewx\UnitSystem::tryFrom($storedUnits ?? 0) ?? $archive->unitSystem;
            $out = '<div class="toolbar"><a href="' . $this->url('archives') . '">← ' . $this->t('nav.archives') . '</a><a class="button" href="' . $this->url('fields', ['archive' => $id]) . '">' . $this->t('nav.fields') . '</a></div>';
            $out .= '<section class="panel"><h2>' . self::escape($archive->name) . '</h2>' . $this->form('archive.save', ['archive' => $id]) . '<div class="form-grid">'
                . $this->input('name', 'label.name', $archive->name, required: true)
                . $this->select('enabled', 'label.status', ['true' => $this->language->text('status.enabled'), 'false' => $this->language->text('status.disabled')], $archive->enabled ? 'true' : 'false')
                . $this->input('location', 'label.location', $archive->location)
                . $this->input('timezone', 'label.timezone', $archive->timezone->getName(), required: true)
                . $this->input('archive_interval', 'label.interval_seconds', (string) $archive->interval($this->read->config->settings), 'number', true)
                . '<div class="control"><span>' . $this->t('label.storage_units') . '</span><output>' . $unitSystem->name . '</output></div>'
                . $this->input('latitude', 'label.latitude', $archive->latitude === null ? '' : (string) $archive->latitude)
                . $this->input('longitude', 'label.longitude', $archive->longitude === null ? '' : (string) $archive->longitude)
                . $this->input('altitude', 'label.altitude', $archive->altitude === null ? '' : $archive->altitude->value . ', ' . $archive->altitude->unit)
                . '</div><fieldset><legend>' . $this->t('nav.stations') . '</legend><div class="checks">';
            $selected = isset($this->draft['senders']) && is_array($this->draft['senders']) ? $this->draft['senders'] : ($archive->senders ?? array_keys($this->read->config->stations));
            foreach ($this->read->config->stations as $sender => $station) {
                $out .= '<label><input type="checkbox" name="senders[]" value="' . self::escape($sender) . '"' . (in_array($sender, $selected, true) ? ' checked' : '') . '>' . self::escape($station->name) . '</label>';
            }
            $out .= '</div></fieldset>' . $this->endForm() . '</section>' . $this->maintenance($archive);
            return $out;
        }
        $out = '<section class="panel">' . $this->archiveTable() . '</section><div class="two-columns">';
        foreach (['create', 'connect'] as $action) {
            $out .= '<section class="panel"><h2>' . $this->t('action.' . $action . '_archive') . '</h2>' . $this->form('archive.' . $action) . '<div class="form-grid">'
                . $this->input('archive', 'label.archive_id', required: true) . $this->input('name', 'label.name', required: true);
            if ($action === 'connect') {
                $out .= $this->input('database', 'label.database_path', required: true);
            }
            $out .= $this->select('unit_system', 'label.storage_units', ['US' => 'US', 'METRIC' => 'METRIC', 'METRICWX' => 'METRICWX'], 'METRICWX')
                . $this->input('timezone', 'label.timezone', $this->read->config->settings->timezone->getName(), required: true)
                . $this->input('archive_interval', 'label.interval_seconds', (string) $this->read->config->settings->archiveInterval, 'number', true)
                . '</div>' . $this->endForm('action.' . $action . '_archive') . '</section>';
        }
        return $out . '</div>';
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
            return $out . '<section class="panel empty"><a href="' . $this->url('archives', ['archive' => $id]) . '">' . $this->t('action.select_stations') . '</a></section>';
        }
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
                $options = ['' => $this->language->text('status.unmapped')];
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
            $out .= '<div class="mapping-actions"><input type="hidden" name="complete" value="1"><div class="form-actions">' . $this->button() . '<button type="reset">' . $this->t('action.cancel') . '</button></div></div></form>';
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
        $registry = new ThemeRegistry(dirname(__DIR__, 2) . '/themes');
        $themes = $registry->themes();
        if ($themes === []) {
            return '<section class="panel empty">' . $this->t('status.no_themes') . '</section>';
        }
        $selected = Input::text($query, 'theme', $themes[0]);
        $out = '<div class="tabs">';
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
            $out .= '</div><label class="check"><input type="checkbox" name="activate" value="true">' . $this->t('action.activate_theme') . '</label>' . $this->endForm() . '</section>';
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
            . $this->input('timezone', 'label.timezone', $settings->timezone->getName())
            . $this->select('language', 'label.language', Translator::available(), $this->language->language);
        foreach (['archive_delay' => $settings->archiveDelay, 'live_retention' => $settings->liveRetention, 'raw_retention' => $settings->rawRetention,
            'time_budget' => $settings->timeBudget, 'max_intervals_per_run' => $settings->maxIntervalsPerRun] as $key => $value) {
            $out .= $this->input($key, 'label.' . $key, (string) $value, 'number');
        }
        return $out . '</div>' . $this->endForm() . '</section>' . $this->uploads($query);
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
        $body = '<h1>' . $this->t('action.connection') . '</h1><section class="panel"><h2>Ecowitt</h2><code class="credential">' . self::escape($url . '/' . $keys['ecowitt'] . '/ecowitt/')
            . '</code></section><section class="panel"><h2>Wunderground</h2><code class="credential">' . self::escape($url . '/weatherstation/updateweatherstation.php')
            . '</code><dl><dt>PASSWORD</dt><dd><code>' . self::escape($keys['wunderground']) . '</code></dd></dl></section><a href="' . $this->url('stations') . '">' . $this->t('nav.stations') . '</a>';
        return $this->shell('stations', $body);
    }
}
