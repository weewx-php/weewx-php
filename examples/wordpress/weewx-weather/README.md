# WeeWX PHP Weather

Install and activate the contents of the generated ZIP file as a plugin.
Requires WordPress 5.8+, PHP 7.4+ and a browser with ES modules,
Custom Elements and Shadow DOM.

## Sidebars and posts

Add a **Shortcode block** in the widget editor:

```text
[weewx_weather api="https://wetter.example.org/api/v1.php?feed=live" fields="temperature,humidity,wind" title="Weather at home"]
```

Classic sidebars also offer the **WeeWX Wetter** widget with the same three
settings. An empty field selection displays all individual values in the feed.
Multiple widgets share one request when their API URLs match.

`feed=live` displays LOOP observations only. The cookbook's `feed=sidebar`
displays the latest prepared archive observation. If no live journal exists,
the widget displays a no-data message. It never silently falls back to archive values.

## Setup

1. Publish the feed on the weather server; see `docs/theme-cookbook.md`.
2. Allow the exact WordPress origin there, for example `https://blog.example.org`,
   or explicitly allow public embedding with `origins: ['*']`.
3. Enter the complete HTTPS API URL in the widget or shortcode.

The browser loads weather data directly. There is no WordPress cron job,
server proxy or API key in the HTML. Page caches may store the widget HTML;
observations update independently. A CSP plugin must allow the weather origin
in `connect-src`. JavaScript and CSS are local to the plugin; the widget has
no ECharts dependency.

On errors, the last displayed data remain visible with a connection-lost message.
Timestamps and status remain visible. Hidden tabs pause polling; errors delay
retries, and normal requests follow `pollSeconds`.

## Styling

```css
weewx-weather {
  --weather-background: #ffffff;
  --weather-text: #183e37;
  --weather-muted: #52665f;
  --weather-border: #d6e1dc;
}
```

## Building the package

Run `python examples/wordpress/package.py` in the project directory.
The script copies the shared widget assets unchanged and creates
`data/artifacts/weewx-weather.zip`. No Node or Composer installation is needed.
