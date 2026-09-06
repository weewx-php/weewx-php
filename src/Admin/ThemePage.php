<?php

declare(strict_types=1);

namespace WeewxPhp\Admin;

use WeewxPhp\Extension\Catalog;
use WeewxPhp\Extension\Files;
use WeewxPhp\Extension\Installer;
use WeewxPhp\Upload\Http\HttpClient;

/** The same catalog and package controls as the extension shop. */
final class ThemePage
{
    public function __construct(private readonly ReadModel $read, private readonly Translator $language, private readonly string $csrf, private readonly HttpClient $http) {}

    private function t(string $key): string
    {
        return Page::escape($this->language->text($key));
    }

    private function form(string $action, string $label, string $id = '', string $release = ''): string
    {
        $out = '<form method="post">';
        foreach (['csrf' => $this->csrf, 'revision' => $this->read->revision, 'action' => 'theme.' . $action, 'theme' => $id, 'release' => $release] as $key => $value) {
            $out .= '<input type="hidden" name="' . $key . '" value="' . Page::escape($value) . '">';
        }
        return $out . '<button type="submit"' . ($action === 'install' ? ' class="primary"' : '') . '>' . $this->t($label) . '</button></form>';
    }

    public function render(): string
    {
        $files = new Files($this->read->config->settings->dataDir . '/theme-store');
        $catalog = new Catalog($files, $this->http, time(), themes: true);
        $installer = new Installer($files, $this->http);
        $registry = ThemeRegistry::configured($this->read->path, file: $this->read->file);
        $active = $registry->active($this->read->file);
        $local = $registry->themes();
        $out = '<div class="toolbar">' . $this->form('refresh', 'theme.refresh') . '<a href="https://github.com/weewx-php/theme-catalog">' . $this->t('theme.catalog') . '</a></div>';
        try {
            $releases = $catalog->load();
        } catch (\Throwable) {
            $releases = [];
            $out .= '<div class="notice error" role="alert">' . $this->t('error.theme_catalog') . '</div>';
        }
        $sections = $this->read->file->root()->optionalSection('Themes')?->sections() ?? [];
        $ids = array_unique(array_merge(['basic'], $local, array_keys($releases), array_keys($sections)));
        $out .= '<div class="extension-grid">';
        foreach ($ids as $id) {
            $available = $releases[$id] ?? null;
            $section = $sections[$id] ?? null;
            $installed = $section === null ? null : $installer->installed($id, $section, theme: true);
            $managed = $section !== null && Installer::managed($section);
            $present = in_array($id, $local, true) || $section !== null;
            $release = $available ?? $installed;
            $name = $id === 'basic' ? $this->t('theme.basic') : Page::escape($release?->text('name', $this->language->language) ?? $id);
            $out .= '<article class="panel extension-card"><div class="panel-heading"><h2>' . $name . '</h2><span class="badge">'
                . $this->t($active === $id ? 'theme.active' : ($present ? 'theme.inactive' : 'theme.available')) . '</span></div>';
            if ($release !== null) {
                $out .= '<p>' . Page::escape($release->text('description', $this->language->language)) . '</p><dl class="extension-meta"><div><dt>' . $this->t('theme.version') . '</dt><dd>' . Page::escape($installed->version ?? $release->version) . '</dd></div>';
                if ($available !== null && $installed !== null && $available->fingerprint() !== $installed->fingerprint()) {
                    $out .= '<div><dt>' . $this->t('theme.update') . '</dt><dd>' . Page::escape($available->version) . '</dd></div>';
                }
                $out .= '</dl><div class="extension-links"><a href="https://github.com/' . Page::escape($release->repository) . '">' . $this->t('theme.repository') . '</a>';
                if ($available !== null) {
                    $out .= '<a href="https://github.com/' . Page::escape($available->repository . '/blob/' . $available->commit . '/' . $available->review) . '">' . $this->t('theme.reviewed') . ' · ' . Page::escape($available->reviewedAt) . '</a>';
                }
                $out .= '</div>';
            }
            if ($id === 'basic') {
                $out .= '<p class="extension-note">' . $this->t('theme.bundled') . '</p>';
            } elseif ($present && $installed === null) {
                $out .= '<p class="extension-note">' . $this->t($managed ? 'theme.missing' : 'theme.manual') . '</p>';
            } elseif ($available === null) {
                $out .= '<p class="extension-note">' . $this->t('theme.unlisted') . '</p>';
            } elseif (!ThemeService::compatible($available)) {
                $out .= '<p class="extension-note">' . $this->t('error.theme_incompatible') . '</p>';
            }
            $out .= '<div class="extension-actions">';
            if (in_array($id, $local, true)) {
                $out .= '<a class="button" href="' . Page::escape('?' . http_build_query(['page' => 'themes', 'theme' => $id, 'lang' => $this->language->language])) . '">' . $this->t('nav.settings') . '</a>';
            }
            if ($id !== 'basic' && $available !== null && ThemeService::compatible($available) && (!$present || $managed)
                && ($installed === null || $installed->fingerprint() !== $available->fingerprint())) {
                $out .= $this->form('install', $installed === null ? 'theme.install' : 'theme.update', $id, $available->fingerprint());
            }
            if ($active !== $id && $present && (!$managed || ($available !== null && $installed !== null
                && ThemeService::compatible($available) && $installed->fingerprint() === $available->fingerprint()))) {
                $out .= $this->form('enable', 'theme.enable', $id, $available?->fingerprint() ?? '');
            }
            if ($active === $id && $id !== 'basic') {
                $out .= $this->form('disable', 'theme.disable', $id);
            }
            if ($managed && $id !== 'basic') {
                $out .= $this->form('remove', 'theme.remove', $id);
            }
            $out .= '</div></article>';
        }
        return $out . '</div>';
    }
}
