<?php

declare(strict_types=1);

namespace WeewxPhp\Frontend;

use JsonSerializable;

/** WeeWX wind vectors use east (real) and north (imaginary) components. */
final class Vector implements JsonSerializable
{
    public function __construct(public readonly float $real, public readonly float $imag) {}

    public static function polar(?float $speed, ?float $direction): ?self
    {
        if ($speed === null || ($speed !== 0.0 && $direction === null)) {
            return null;
        }
        return $speed === 0.0 ? new self(0, 0) : new self($speed * cos(deg2rad(90 - ($direction ?? 0))), $speed * sin(deg2rad(90 - ($direction ?? 0))));
    }

    public function magnitude(): float
    {
        return hypot($this->real, $this->imag);
    }
    public function direction(): ?float
    {
        return $this->real === 0.0 && $this->imag === 0.0 ? null : fmod(450 - rad2deg(atan2($this->imag, $this->real)), 360);
    }

    /** @return array{real: float, imag: float} */
    public function jsonSerialize(): array
    {
        return ['real' => $this->real, 'imag' => $this->imag];
    }
}
