# Cookbook examples

Complete guide: [Theme cookbook](../../docs/theme-cookbook.md).

`data.php` defines the data to prepare. `feeds.php` explicitly publishes the
three public example feeds `sidebar`, `live` and `charts`. Include it through
a local `public-feeds.php` beside the station configuration.
The `public/cookbook.php` page shows four Apache ECharts charts, a data table
and two independently embeddable widgets.

```sh
php bin/weewx-php --config station.conf analytics sync cookbook themes/cookbook/data.php
php bin/weewx-php --config station.conf analytics run
```

Allow additional worker runs for a large initial build. The page does not start
calculations. Live values require an existing LOOP input; historical comparisons
require sufficiently complete previous years.
