<?php

declare(strict_types=1);

// Copy beside station.conf as public-feeds.php and adjust this absolute path.
// $wx is provided by public/api/v1.php. Only the returned feeds become public.
return require '/opt/weewx-php/themes/cookbook/feeds.php';
