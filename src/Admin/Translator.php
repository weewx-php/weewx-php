<?php

declare(strict_types=1);

namespace WeewxPhp\Admin;

use DateTimeImmutable;
use DateTimeZone;
use WeewxPhp\Db\Json;

/** Locale packs provide text, number separators, date formats and plural categories. */
final class Translator
{
    /** @var array<string, mixed> */
    private array $pack;
    /** @var array<string, mixed> */
    private array $english;
    public readonly string $language;

    public function __construct(string $language = 'en', ?string $directory = null)
    {
        $directory ??= dirname(__DIR__, 2) . '/resources/admin/locales';
        $this->english = self::read($directory . '/en.json');
        $available = preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D', $language) === 1 && is_file($directory . '/' . $language . '.json');
        $this->language = $available ? $language : 'en';
        $this->pack = $available ? self::read($directory . '/' . $language . '.json') : $this->english;
    }

    /**
     * @return array<string, mixed> */
    private static function read(string $path): array
    {
        $text = file_get_contents($path);
        return $text === false ? [] : Json::object($text);
    }

    /** @return array<string, string> */
    public static function available(?string $directory = null): array
    {
        $directory ??= dirname(__DIR__, 2) . '/resources/admin/locales';
        $result = [];
        $paths = glob($directory . '/*.json');
        foreach ($paths === false ? [] : $paths as $path) {
            $language = basename($path, '.json');
            if (preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D', $language) !== 1) {
                continue;
            }
            $pack = self::read($path);
            $result[$language] = is_string($pack['name'] ?? null) ? $pack['name'] : $language;
        }
        return $result;
    }

    public function unit(string $unit): string
    {
        return ['degree_C' => '°C', 'degree_F' => '°F', 'degree_K' => 'K', 'percent' => '%',
            'degree_compass' => '°', 'watt_per_meter_squared' => 'W/m²', 'uv_index' => 'UV',
            'mile_per_hour' => 'mph', 'km_per_hour' => 'km/h', 'meter_per_second' => 'm/s',
            'inch' => 'in', 'inch_per_hour' => 'in/h', 'mm_per_hour' => 'mm/h', 'cm_per_hour' => 'cm/h',
            'mbar' => 'hPa', 'centibar' => 'cb', 'microgram_per_meter_cubed' => 'µg/m³',
            'volt' => 'V', 'watt' => 'W', 'watt_hour' => 'Wh', 'second' => 's', 'minute' => 'min',
            'microsiemens_per_centimeter' => 'µS/cm',
            'count' => '', 'boolean' => '', 'fraction' => '', 'unix_epoch' => ''] [$unit] ?? $unit;
    }

    /** @param array<string, string|int> $parameters */
    public function text(string $key, array $parameters = [], ?int $count = null): string
    {
        $messages = $this->pack['messages'] ?? [];
        $fallback = $this->english['messages'] ?? [];
        $value = is_array($messages) ? ($messages[$key] ?? null) : null;
        $value ??= is_array($fallback) ? ($fallback[$key] ?? $key) : $key;
        if (is_array($value)) {
            $rule = $this->pack['plural'] ?? 'one_other';
            $category = match ($rule) {
                'other' => 'other',
                'zero_one_other' => $count === 0 ? 'zero' : ($count === 1 ? 'one' : 'other'),
                'arabic' => $count === 0 ? 'zero' : ($count === 1 ? 'one' : ($count === 2 ? 'two' : (($count ?? 0) % 100 >= 3 && ($count ?? 0) % 100 <= 10 ? 'few' : (($count ?? 0) % 100 >= 11 ? 'many' : 'other')))),
                default => $count === 1 ? 'one' : 'other',
            };
            $value = $value[$category] ?? $value['other'] ?? $key;
        }
        $text = is_string($value) ? $value : $key;
        if ($count !== null) {
            $parameters['count'] = $count;
        }
        foreach ($parameters as $name => $parameter) {
            $text = str_replace('{' . $name . '}', (string) $parameter, $text);
        }
        return $text;
    }

    public function direction(): string
    {
        return ($this->pack['direction'] ?? 'ltr') === 'rtl' ? 'rtl' : 'ltr';
    }

    public function number(int|float $number): string
    {
        $decimal = $this->pack['decimal'] ?? '.';
        $thousands = $this->pack['thousands'] ?? ',';
        return number_format($number, is_int($number) ? 0 : 2, is_string($decimal) ? $decimal : '.', is_string($thousands) ? $thousands : ',');
    }

    public function date(?int $stamp, DateTimeZone $zone): string
    {
        if ($stamp === null) {
            return '—';
        }
        $format = $this->pack['date_format'] ?? 'Y-m-d H:i:s';
        return (new DateTimeImmutable('@' . $stamp))->setTimezone($zone)->format(is_string($format) ? $format : 'Y-m-d H:i:s');
    }

    public function age(?int $stamp, int $now): string
    {
        if ($stamp === null) {
            return '—';
        }
        $seconds = max(0, $now - $stamp);
        [$unit, $count] = match (true) {
            $seconds < 60 => ['seconds', $seconds],
            $seconds < 3600 => ['minutes', intdiv($seconds, 60)],
            $seconds < 86400 => ['hours', intdiv($seconds, 3600)],
            default => ['days', intdiv($seconds, 86400)],
        };
        return $this->text('age.ago', ['value' => $this->text('age.' . $unit, count: $count)]);
    }
}
