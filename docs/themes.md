# Themes

The core ships only `themes/basic`: a single page displaying live measurements,
units and measurement times. It refreshes every 15 seconds and reads only the
live journal. An archive file or prepared analytics cache is not required.
A configured archive determines sensor mappings. Missing readings display `—`;
readings older than 120 seconds display `Stale`. Archived values are never
presented as live readings when no live input exists.

English is the default. Select English or German under **Admin → Themes → basic →
Language**. Text, number formatting, measurement times and status messages follow
the selected language. Locale resources live in `themes/basic/locales/`.

## Select display units

In Demo and Cookbook, select **Units** and choose **Apply**. The selection is
remembered for this browser. **Station default** restores the operator's default.
Language and units can be selected independently. The page reload updates values,
charts, tables and unit labels together; subsequent refreshes retain the profile.
Demo also applies the selection to live readings, forecasts, climate values and
station altitude. Basic uses the shared profile but has no selection control.

Set the site default with `units = metric`, `us` or `metricwx` under `[Themes]`.
The [display-units reference](display-units.md) describes the profiles and PHP/API
contract. Theme authors can follow the [cookbook example](theme-cookbook.md#visitor-unit-selection).

## Install and select a theme

Under **Admin → Themes**, install a package from the approved catalog, configure
it through **Settings**, then choose **Activate**. Installation does not switch
the active theme. Updates preserve saved settings and the active selection.
**Deactivate** and removal of the active package return the site to Basic.
Basic is included in the core and cannot be updated, removed or deactivated by
the shop.

The shop uses the same bounded, checksum-verified installer as extensions, with
separate storage under `data/theme-store/`. Its approval list is
`weewx-php/theme-catalog/main/catalog.json`. The catalog is cached for six hours;
installation and activation always fetch a fresh approval list. A changed entry
requires reloading the shop before proceeding. Failed downloads leave the current
package and configuration unchanged. Runtime PHP is never executed during
installation or activation.

The catalog source is prepared at `4_Themes/theme-catalog` in this workspace.
It is initially empty; the catalog and reviewed Demo/Cookbook releases still need
publication before these packages can be downloaded from the shop. There is no
fallback to unreviewed or placeholder releases. Core and installed theme settings
remain accessible when the catalog cannot be loaded.

Manual installation also remains available: copy an independent package into
`themes/<id>` and activate it under **Admin → Themes**. Manual packages are not
replaced or removed by the shop. Installed packages are ignored by the core Git
repository; only `basic` is included in core releases.

A package can also live outside the installation:

```ini
[Themes]
    active = demo
    [[demo]]
        directory = /srv/weather/themes/theme-demo
        default_range = 24h
```

Relative `directory` paths resolve against the configuration file. In this
development workspace, register the sibling repositories as follows:

```ini
[Themes]
    active = basic
    [[demo]]
        directory = ../4_Themes/theme-demo
    [[cookbook]]
        directory = ../4_Themes/theme-cookbook
```

Without `active`, the site uses `basic`. Missing packages, invalid settings
definitions or missing entry files fall back to `basic`, preserving saved
settings. Renderer failures return HTTP 503. The bundled `basic` directory
cannot be replaced through package configuration.

Theme code is trusted application code. Only local operator configuration
selects executable files; URL parameters cannot select a different theme or
arbitrary PHP file. Shop packages are approved code, not sandboxed code.
Removal unregisters the package and removes its saved theme settings; downloaded
versions remain on disk so requests already in flight can finish. Archive data,
analytics caches and manual theme directories are preserved.

Shop updates change the immutable package directory recorded under `[Themes]`.
Configuration changes use the existing revision check, writer lock, recovery and
audit mechanisms. Managed themes must be activated through the shop; saving their
settings cannot bypass the fresh approval and file-integrity checks.

## Demo

The previous Atmos demo is the separate `theme-demo` repository at
`4_Themes/theme-demo` in this workspace. It contains its renderer, forecast view,
landscape, CSS, JavaScript, settings translations, analytics recipes and tests.
The ID remains `demo`, preserving existing settings and analytics ownership.

After installing into `themes/demo`, run from the core directory:

```sh
php bin/weewx-php analytics sync demo themes/demo/data.php
php bin/weewx-php analytics run
```

For upgrades, install the complete package before activating it. The core now
owns `public/index.php` and `public/data.php`; neither file needs theme-specific
edits. Installations that previously showed Demo without an explicit active
theme will show Basic until Demo is selected.

## Cookbook

The separate `theme-cookbook` repository is at `4_Themes/theme-cookbook`. It owns
its chart page, analytics recipes, feed definitions and presentation assets.
The reusable feed API, JavaScript clients and chart library remain in the core.

After installing into `themes/cookbook`, select it under **Admin → Themes**:

```sh
php bin/weewx-php analytics sync cookbook themes/cookbook/data.php
php bin/weewx-php analytics run
```

The active Cookbook publishes its feeds through `api/v1.php`. An existing
`public-feeds.php` or explicit `WEEWX_PHP_FEEDS` takes precedence. Other themes
do not publish Cookbook feeds. `/cookbook.php` redirects to the active homepage.

## Package contract

| File | Purpose |
|---|---|
| `settings.json` | Theme ID, `schema_version: 1`, declarative settings fields |
| `locales/<language>.json` | Flat translation dictionary; English fallback |
| `theme.php` | Returns a closure producing an HTML string |
| `snapshot.php` | Optional closure producing the JSON snapshot at `data.php` |
| `feeds.php` | Optional public feed definitions for the active theme |
| `data.php` | Optional analytics recipes for the CLI and background worker |
| `assets/` | Static CSS, JavaScript, image and font files |
| `theme.json` | Informational package ID, version, PHP and API metadata |

Renderers receive `Weather $wx`, `Theme $theme` and the HTTP query array. They
validate their own query parameters and return content without sending headers.
The core sets HTTP status, caching and security headers. `$wx` uses `cacheOnly()`
for analytics; `$wx->live()` reads the live journal directly.

`Theme::configured()` loads locale dictionaries without executing theme code.
The theme's `language` setting, then `[Themes] language`, selects the locale;
the default is English. An explicit language argument overrides configuration.
Unsupported languages and missing translations fall back to English. Use
`$theme->text()` for plain text and `$theme->html()` for escaped HTML output.

```php
<?php
declare(strict_types=1);

use WeewxPhp\Frontend\Theme;
use WeewxPhp\Frontend\Weather;

return static function (Weather $wx, Theme $theme, array $query): string {
    return '<h1>' . $theme->html('Temperature') . '</h1>'
        . $wx->live('outTemp')->html();
};
```

Assets use `theme-assets.php/<id>/<file>`. The web server must support PHP
`PATH_INFO`; configure FastCGI accordingly when using Nginx. Resolution is
restricted to static local package assets. PHP, JSON, path traversal and symlinks
escaping the package are rejected. Nested directories and relative JavaScript
module imports are supported. Scripts and styles load from the same origin.

## Backups

Installation backups preserve configuration, not theme source code. Keep
external packages separately and verify their paths after restoring. Missing
packages do not prevent the bundled Basic theme from working.

## Tests

Always run tests in Docker and start Docker if necessary. Build the core test
image using `docker compose -f tests/docker/compose.yml build unit`.
Run core unit checks with the `unit` service and JavaScript checks with
`frontend-js`. The Demo repository provides its own Docker Compose test service;
set `WEEWX_PHP_ROOT` to the host path of the core checkout before running it.
