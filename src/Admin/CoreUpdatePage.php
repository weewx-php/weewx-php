<?php

declare(strict_types=1);

namespace WeewxPhp\Admin;

use WeewxPhp\CoreUpdate\GitHub;
use WeewxPhp\Extension\Files;
use WeewxPhp\Upload\Http\Http;
use WeewxPhp\Version;

final class CoreUpdatePage
{
    public function __construct(private readonly ReadModel $read, private readonly Translator $language, private readonly string $csrf) {}

    private function t(string $key): string
    {
        return Page::escape($this->language->text($key));
    }

    public function render(): string
    {
        $channel = $this->read->file->root()->optionalSection('Admin')?->optional('update_channel')?->string() ?? 'stable';
        $github = new GitHub(new Files($this->read->config->settings->dataDir . '/core-updates'), Http::client(), $channel);
        $release = $github->cached();
        $out = '<section class="panel"><h2>' . $this->t('core.title') . '</h2><dl class="connection-fields"><div><dt>'
            . $this->t('core.installed') . '</dt><dd>' . Page::escape(Version::STRING) . '</dd></div>';
        if ($release !== null) {
            $out .= '<div><dt>' . $this->t('core.available') . '</dt><dd><a href="https://github.com/weewx-php/weewx-php/releases/tag/v'
                . Page::escape($release->version) . '" target="_blank" rel="noopener">' . Page::escape($release->version) . '</a></dd></div>';
        }
        $out .= '</dl><form method="post"><input type="hidden" name="csrf" value="' . Page::escape($this->csrf)
            . '"><input type="hidden" name="revision" value="' . Page::escape($this->read->revision) . '"><div class="form-actions">'
            . '<button type="submit" name="action" value="core.check">' . $this->t('core.check') . '</button>';
        if ($release !== null && $release->newer()) {
            $out .= '<input type="hidden" name="release" value="' . $release->fingerprint() . '"><button type="submit" class="primary" name="action" value="core.install">'
                . $this->t('core.install') . '</button>';
        } elseif ($github->checked()) {
            $out .= '<span class="form-status">' . $this->t($release === null ? 'core.no_release' : 'core.current') . '</span>';
        }
        return $out . '</div></form></section>';
    }
}
