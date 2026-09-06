<?php

declare(strict_types=1);

namespace WeewxPhp\Admin;

use WeewxPhp\Extension\Catalog;
use WeewxPhp\Extension\Files;
use WeewxPhp\Extension\Installer;
use WeewxPhp\Upload\Http\HttpClient;

final class ExtensionPage
{
    public function __construct(private readonly ReadModel $read, private readonly Translator $language, private readonly string $csrf, private readonly HttpClient $http) {}

    private function t(string $key): string
    {
        return Page::escape($this->language->text($key));
    }

    private function form(string $action, string $label, string $id = '', string $release = ''): string
    {
        $out = '<form method="post">';
        foreach (['csrf' => $this->csrf, 'revision' => $this->read->revision, 'action' => 'extension.' . $action, 'extension' => $id, 'release' => $release] as $key => $value) {
            $out .= '<input type="hidden" name="' . $key . '" value="' . Page::escape($value) . '">';
        }
        return $out . '<button type="submit"' . ($action === 'install' ? ' class="primary"' : '') . '>' . $this->t($label) . '</button></form>';
    }

    public function render(): string
    {
        $files = new Files($this->read->config->settings->dataDir . '/extension-store');
        $catalog = new Catalog($files, $this->http, time());
        $installer = new Installer($files, $this->http);
        $out = '<div class="toolbar">' . $this->form('refresh', 'extension.refresh') . '<a href="https://github.com/weewx-php/extension-catalog">' . $this->t('extension.catalog') . '</a></div>';
        try {
            $releases = $catalog->load();
        } catch (\Throwable) {
            $releases = [];
            $out .= '<div class="notice error" role="alert">' . $this->t('error.extension_catalog') . '</div>';
        }
        $sections = $this->read->file->root()->optionalSection('Extensions')?->sections() ?? [];
        $ids = array_unique(array_merge(array_keys($releases), array_keys($sections)));
        if ($ids === []) {
            return $out . '<p class="empty">' . $this->t('extension.empty') . '</p>';
        }
        $out .= '<div class="extension-grid">';
        foreach ($ids as $id) {
            $available = $releases[$id] ?? null;
            $section = $sections[$id] ?? null;
            $installed = $section === null ? null : $installer->installed($id, $section);
            $managed = $section !== null && Installer::managed($section);
            $enabled = $section?->optional('enabled')?->bool() ?? false;
            $release = $available ?? $installed;
            $out .= '<article class="panel extension-card"><div class="panel-heading"><h2>' . Page::escape($release->name ?? $id) . '</h2><span class="badge">'
                . $this->t($section === null ? 'extension.available' : ($enabled ? 'extension.active' : 'extension.inactive')) . '</span></div>';
            if ($release !== null) {
                $out .= '<p>' . Page::escape($release->description) . '</p><dl class="extension-meta"><div><dt>' . $this->t('extension.version') . '</dt><dd>' . Page::escape($installed->version ?? $release->version) . '</dd></div>';
                if ($available !== null && $installed !== null && $available->fingerprint() !== $installed->fingerprint()) {
                    $out .= '<div><dt>' . $this->t('extension.update') . '</dt><dd>' . Page::escape($available->version) . '</dd></div>';
                }
                $out .= '</dl><div class="extension-links"><a href="https://github.com/' . Page::escape($release->repository) . '">' . $this->t('extension.repository') . '</a>';
                if ($available !== null) {
                    $out .= '<a href="https://github.com/' . Page::escape($available->repository . '/blob/' . $available->commit . '/' . $available->review) . '">' . $this->t('extension.reviewed') . ' · ' . Page::escape($available->reviewedAt) . '</a>';
                }
                $out .= '</div>';
            }
            if ($section !== null && $installed === null) {
                $out .= '<p class="extension-note">' . $this->t($managed ? 'extension.missing' : 'extension.manual') . '</p>';
            } elseif ($available === null) {
                $out .= '<p class="extension-note">' . $this->t('extension.unlisted') . '</p>';
            } elseif (!$available->compatible()) {
                $out .= '<p class="extension-note">' . $this->t('error.extension_incompatible') . ' PHP ≥ ' . Page::escape($available->php) . ' · API ' . $available->api . '</p>';
            }
            $out .= '<div class="extension-actions">';
            if ($installed !== null && $installed->settings !== null) {
                $out .= '<a class="button" href="' . Page::escape('?' . http_build_query(['page' => 'extensions', 'extension' => $id, 'lang' => $this->language->language])) . '">' . $this->t('nav.settings') . '</a>';
            }
            if ($available !== null && $available->compatible() && ($section === null || $managed)) {
                if ($installed === null || $installed->fingerprint() !== $available->fingerprint()) {
                    $out .= $this->form('install', $installed === null ? 'extension.install' : 'extension.update', $id, $available->fingerprint());
                } elseif (!$enabled) {
                    $out .= $this->form('enable', 'extension.enable', $id, $available->fingerprint());
                }
            }
            if ($enabled) {
                $out .= $this->form('disable', 'extension.disable', $id);
            }
            if ($managed) {
                $out .= $this->form('remove', 'extension.remove', $id);
            }
            $out .= '</div></article>';
        }
        return $out . '</div>';
    }
}
