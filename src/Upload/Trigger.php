<?php

declare(strict_types=1);

namespace WeewxPhp\Upload;

/** What makes an upload run: weewx-evo's four, in a world of ticks. */
enum Trigger: string
{
    /** Whenever the archive holds a record the service has not had. The default, and almost always right. */
    case Record = 'record';

    /** On its own rhythm, on the hour's grid, for a service that asks for less often than the archive interval. */
    case Interval = 'interval';

    /** The newest packet, every tick, for a broker feeding a dashboard. Only MQTT offers it. */
    case Live = 'live';

    /** Only when `weewx-php upload run` asks. */
    case Manual = 'manual';
}
