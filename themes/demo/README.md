# Demo theme

A responsive PHP theme with observations, temperature history (24 hours or
7 days), precipitation, sun times, monthly comparisons and records. Uses PHP,
CSS, SVG and lightweight JSON polling without additional libraries.

## Getting started

Requires an existing, configured archive database. `frontend.php` reads
`weewx-php.conf` in the project directory or the path from `WEEWX_PHP_CONF`.
The theme uses the first configured archive.

Run in the project directory:

```sh
php bin/weewx-php analytics sync demo themes/demo/data.php
php bin/weewx-php analytics run
php -S 127.0.0.1:8087 -t public
```

Preview: <http://127.0.0.1:8087/>. On the web host, use `public/` as the
document root; the PHP development server is only for local previews.

For a different configuration file, set the environment variable for both CLI
and web server, for example in PowerShell:

```powershell
$env:WEEWX_PHP_CONF = 'D:\Wetter\weewx-php.conf'
```

The regular tick updates registered queries. Without a running tick, update the
preview manually with `analytics run`. Pending calculations appear as placeholders;
results due for refresh have a status indicator. Larger archives may require
multiple worker runs.

## Customization

| File | Contents |
|---|---|
| `data.php` | Output profile, tags, periods and refresh schedules |
| `template.php` | HTML and SVG charts |
| `View.php` | View assembly, dates and chart coordinates |
| `../../public/data.php` | Fixed JSON dataset from prepared results |
| `../../public/assets/demo.js` | Updates and live display |
| `../../public/assets/demo.css` | Colors, typography and responsive layout |
| `../../public/index.php` | Entry point through `frontend.php` |

For example, `data.php` contains:

```php
'temperature' => $wx->current('outTemp'),
'temperature24h' => $wx->last('24h')->series('outTemp', '15m'),
'rainYear' => $wx->year()->sum('rain')->nightly(),
'sunrise' => $wx->almanac()->sun()->rise()->nightly('00:05'),
```

The theme synchronizes its queries automatically when loaded; alternatively, use
`analytics sync demo themes/demo/data.php`. Shared results are preserved.
All weather data come through the [PHP tags](../../docs/frontend.md);
the theme does not run its own SQL queries.

Output uses °C, km/h, hPa and mm, even with a US archive. Daily values and history
refer to the latest archive observation, whose date is visible. Sun times refer
to today's calendar date at the station. Missing values appear as “—”; gaps
interrupt the temperature line. Rain history covers exactly seven calendar dates,
including the date of the latest archive observation. Monthly and yearly totals
cover the observations actually present.

Temperature chart values are also available in an expandable table.
All controls work with the keyboard.

The output profile, cacheable comparisons and live display limits are described
in [Frontend recipes](../../docs/frontend-recipes.md). For short archives,
monthly records and reference years remain empty when the required coverage
is missing. These are missing comparison data, not zero rainfall.
