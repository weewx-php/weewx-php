<?php

declare(strict_types=1);

namespace WeewxPhp\Ingest;

enum Protocol: string
{
    case Ecowitt = 'ecowitt';
    case Wunderground = 'wunderground';

    public function reply(): Response
    {
        return $this === self::Ecowitt
            ? new Response(200, '{"errcode":"0","errmsg":"ok"}', 'application/json')
            : new Response(200, 'success');
    }
}
