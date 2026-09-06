<?php

declare(strict_types=1);

namespace WeewxPhp\Admin;

use WeewxPhp\Extension\Files;
use WeewxPhp\Extension\Installer;
use WeewxPhp\Extension\Settings;
use WeewxPhp\Upload\Http\Http;

/** Settings are read from the installed, checksummed JSON without executing PHP. */
final class ExtensionSettings
{
    public function __construct(private readonly ReadModel $read, private readonly Translator $language, private readonly string $csrf) {}

    /** @return array<string, string> */
    public static function menu(ReadModel $read): array
    {
        $installer = new Installer(new Files($read->config->settings->dataDir . '/extension-store'), Http::client());
        $result = [];
        foreach ($read->file->root()->optionalSection('Extensions')?->sections() ?? [] as $id => $section) {
            try {
                $release = $installer->installed($id, $section);
                if ($release !== null && $release->settings !== null) {
                    $result[$id] = $release->name;
                }
            } catch (\Throwable) {
                // A broken optional package cannot prevent access to core administration.
            }
        }
        return $result;
    }

    private function t(string $key): string
    {
        return Page::escape($this->language->text($key));
    }

    /** @param array<string, mixed> $query */
    public function render(array $query): string
    {
        $id = Input::text($query, 'extension');
        $installer = new Installer(new Files($this->read->config->settings->dataDir . '/extension-store'), Http::client());
        $section = $this->read->file->root()->optionalSection('Extensions')?->optionalSection($id);
        $release = $section === null ? null : $installer->installed($id, $section);
        if ($release === null || $section === null) {
            return '<div class="notice error">' . $this->t('error.not_found') . '</div>';
        }
        try {
            $settings = Settings::load($release, $installer, time());
        } catch (\Throwable) {
            $settings = null;
        }
        if ($settings === null) {
            return '<div class="notice error">' . $this->t('error.extension_settings') . '</div>';
        }
        $archive = Input::text($query, 'archive');
        if ($archive !== '' && ($settings->scope !== 'archive' || $this->read->config->archive($archive) === null)) {
            return '<div class="notice error">' . $this->t('error.archive') . '</div>';
        }
        $out = '<div class="toolbar"><a href="?page=extensions&amp;lang=' . Page::escape($this->language->language) . '">← ' . $this->t('nav.extensions') . '</a></div><section class="panel extension-settings"><h2>' . Page::escape($release->name) . '</h2>';
        if ($settings->scope === 'archive') {
            $out .= '<nav class="extension-scopes" aria-label="' . $this->t('label.archive') . '">';
            $choices = ['' => $this->language->text('extension.defaults')];
            foreach ($this->read->config->archives as $one) {
                $choices[$one->id] = $one->name;
            }
            foreach ($choices as $key => $name) {
                $out .= '<a href="' . Page::escape('?' . http_build_query(['page' => 'extensions', 'extension' => $id, 'archive' => $key, 'lang' => $this->language->language])) . '"' . ($archive === $key ? ' aria-current="page"' : '') . '>' . Page::escape($name) . '</a>';
            }
            $out .= '</nav>';
        }
        $current = Settings::current($section, $archive);
        $out .= '<form method="post" data-edit-form><div class="form-grid">';
        foreach (['action' => 'extension.settings', 'extension' => $id, 'archive' => $archive, 'csrf' => $this->csrf, 'revision' => $this->read->revision, 'schema' => $settings->hash] as $name => $value) {
            $out .= '<input type="hidden" name="' . $name . '" value="' . Page::escape($value) . '">';
        }
        foreach ($settings->fields as $key => $field) {
            $value = $current->optional($key)?->raw() ?? $field->default;
            if ($field->type === 'archives' && $current->optional($key) !== null) {
                $value = $current->value($key)->list();
            }
            $name = 'options[' . $key . ']';
            $out .= '<div><label class="control"><span>' . Page::escape($field->label($this->language->language)) . '</span>';
            if ($field->type === 'archives') {
                $out .= '<input type="hidden" name="' . $name . '[]" value=""><select multiple name="' . $name . '[]">';
                foreach ($this->read->config->archives as $one) {
                    $out .= '<option value="' . Page::escape($one->id) . '"' . (is_array($value) && in_array($one->id, $value, true) ? ' selected' : '') . '>' . Page::escape($one->name) . '</option>';
                }
                $out .= '</select>';
            } elseif ($field->type === 'boolean') {
                $out .= '<select name="' . $name . '">';
                foreach (['true' => 'extension.active', 'false' => 'extension.inactive'] as $keyValue => $label) {
                    $out .= '<option value="' . $keyValue . '"' . ($value === $keyValue ? ' selected' : '') . '>' . $this->t($label) . '</option>';
                }
                $out .= '</select>';
            } else {
                $secret = $field->type === 'secret';
                $out .= '<input name="' . $name . '" type="' . ($secret ? 'password' : ($field->type === 'integer' ? 'number' : 'text')) . '" value="' . ($secret ? '' : Page::escape(is_string($value) ? $value : '')) . '"'
                    . ($secret ? ' autocomplete="new-password"' : '')
                    . ($field->type === 'integer' ? ' step="1" min="' . $field->min . '" max="' . $field->max . '" required' : ' maxlength="1024"') . '>';
            }
            $out .= '</label>';
            if ($field->type === 'secret' && is_string($value) && $value !== '') {
                $out .= '<label class="extension-secret"><input type="checkbox" name="clear[' . $key . ']" value="1">' . $this->t('extension.clear_secret') . '</label>';
            }
            $out .= '</div>';
        }
        return $out . '</div><input type="hidden" name="complete" value="1"><div class="form-actions"><button type="submit" class="primary">' . $this->t('action.save') . '</button></div></form></section>';
    }
}
