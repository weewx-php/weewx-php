# Display units

For theme integration examples, see [the cookbook](theme-cookbook.md#visitor-unit-selection).
For installation and visitor controls, see [Themes](themes.md#select-display-units).

## Profiles

Display profiles are independent of the archive's `unit_system` and the theme's
language. Conversion runs when results are presented. Archive records, query
identities and prepared calculation results remain in their existing units.

| Profile | Temperature | Wind | Pressure | Rain | Rain rate | Altitude |
|---|---|---|---|---|---|---|
| `metric` (default) | °C | km/h | hPa | mm | mm/h | m |
| `us` | °F | mph | inHg | in | in/h | ft |
| `metricwx` | °C | m/s | hPa | mm | mm/h | m |

Distance, length, volume, degree days, pressure rate and squared wind speed use
the corresponding WeeWX system units. Display `metric` uses millimetres for
rainfall; WeeWX's `METRIC` storage system uses centimetres.

## Station setting

`[Themes] units` sets the default for all participating themes. Default: `metric`.
Allowed values: `metric`, `us`, `metricwx`. An invalid configuration is an error.
The setting is independent of `[Archives][[id]] unit_system`.

```ini
[Themes]
    active = demo
    units = metric
```

## Visitor selection

The homepage and `data.php` accept `units=metric`, `units=us`,
`units=metricwx` and `units=station`.

Selection precedence is the valid URL parameter, then the saved `weewx_units`
cookie, then the station setting. `units=station` selects the station setting and
clears the saved preference. Unknown values and non-string HTTP input fall back
to the saved preference or station setting.

A successful homepage GET with a valid selection saves the preference for one
year. The cookie is scoped by the browser to the public page directory, uses
`HttpOnly` and `SameSite=Lax`, and adds `Secure` when PHP reports HTTPS. HEAD and
snapshot requests do not write cookies. HTML and snapshots use `Cache-Control:
no-store`. Without cookies, include the selected profile in navigation and
refresh URLs.

## PHP interface

| Interface | Result |
|---|---|
| `$theme->units->profile` | Effective profile ID |
| `$theme->units->selection` | Visitor selection, including `station` |
| `$theme->unitOptions()` | Selection IDs and localized labels, with English fallback |
| `$theme->output()` | Output profile using the theme language and effective units |
| `$theme->output($format)` | Profile combined with an `Output` formatter |
| `UnitPreferences::resolve($query, $cookies, $default)` | Validated selection for custom entry points |
| `(new UnitPreferences('us'))->output($format)` | Profile for CLI or custom publishers |
| `$value->unitLabel()` | Plain display symbol without surrounding spacing |
| `$wx->unit('outTemp')` | Output unit identifier, label and printf format |

`ThemeSite` passes an already configured `Weather` instance to the renderer.
If a theme replaces its output formatter, it must use `$theme->output($format)`
to preserve the selection. Explicit `Output::$units` entries override profile
defaults; use them only for quantities that intentionally require fixed units.
Themes supply translations for `Units`, `Apply`, `Station default`, `Metric`,
`US` and `Metric (m/s)` in their locale resources.

Precision follows the existing observation → unit → group precedence. Profiles
add two decimal places for inches, inches/hour and inHg, and three for inHg/hour.
Explicit precision for the same key takes precedence. Numeric chart values remain
unrounded. Null readings and gaps remain null. Temperature differences convert
without a temperature-zero offset. Reports and extension tags use the same
output conversion; opaque report metadata is not interpreted as measurements.

## JSON feeds

`api/v1.php?feed=charts&units=us` requests a display profile for an existing public
feed. The API accepts the three profile IDs, returns HTTP 400 for any other
explicit value, and retains the publisher's output profile if `units` is absent.
It does not read or write visitor cookies. Feed definitions still determine the
published fields, intervals and CORS permissions.

The requested profile replaces the feed's unit overrides while preserving its
language, explicit precision, missing-value text and custom labels. Conversion
applies to prepared results and live values. ETags identify the resulting JSON;
an ETag for a different representation does not produce a 304 response.

Values and series include these presentation fields alongside their existing
numeric data and unit identifiers:

| Field | Meaning |
|---|---|
| `unit` | Machine identifier, for example `degree_F` |
| `unitLabel` | Display symbol, for example `°F` |
| `decimals` | Suggested decimal places for axes, tables and tooltips |

Single-value feeds also contain `formatted`. Series retain numeric `points` and
optional hardware `fallback` spans. Chart code uses those numbers directly and
binds axis names and tooltip formatting to the metadata.
