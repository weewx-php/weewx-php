# Install WeeWX-PHP

Requirements: PHP 8.1 or newer with SQLite3, JSON and Phar. Apache installations
must allow the supplied `public/.htaccess` rules.

1. Extract `weewx-php-core.zip` into the installation directory.
2. Set the domain document root to that directory's `public/` subdirectory.
3. Copy `weewx-php.conf.example` to `weewx-php.conf` and configure your station.
   Replace the example tokens before enabling public endpoints.
4. Give PHP write access to the configured data directory. Admin core updates
   also require write access to the installation directory and its core files.
5. Run `php bin/weewx-php check-config` from the installation directory.
6. Open `/admin/` over HTTPS to set up administration.
7. Schedule `php /path/to/weewx-php/bin/weewx-php tick` every five minutes.

The archive includes the core and Basic theme. Optional themes, extensions and
the separate Python collector are installed independently.

For later updates, use **Settings → Core update** in the admin. Select **Stable**
or **Beta**, check for updates, and install the offered version. Keep backups of
your configuration and weather databases.

Full documentation: [WeeWX-PHP](https://github.com/weewx-php/weewx-php#documentation).
