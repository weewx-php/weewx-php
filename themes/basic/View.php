<?php

declare(strict_types=1);

namespace WeewxPhp\BasicTheme;

use WeewxPhp\Frontend\Output;
use WeewxPhp\Frontend\Span;
use WeewxPhp\Frontend\Theme;
use WeewxPhp\Frontend\Weather;

final class View
{
    /** @return array{name: string, language: string, fields: array<string, array{label: string, formatted: string, status: string, statusText: string, asOf: ?int, time: string}>} */
    public static function snapshot(Weather $wx, Theme $theme = new Theme()): array
    {
        $wx = $wx->output($theme->output(new Output($theme->language === 'de' ? 'de' : 'en', decimals: ['group_percent' => 0, 'group_direction' => 0])));
        $fields = [];
        foreach (['outTemp' => 'Temperature', 'outHumidity' => 'Humidity', 'barometer' => 'Pressure',
            'windSpeed' => 'Wind', 'windGust' => 'Gusts', 'windDir' => 'Wind direction', 'rainRate' => 'Rain rate'] as $observation => $label) {
            $value = $wx->live($observation);
            $fields[$observation] = ['label' => $theme->text($label), 'formatted' => $value->format(), 'status' => $value->status,
                'statusText' => $value->status === 'stale' ? $theme->text('Stale') : '',
                'asOf' => $value->asOf, 'time' => $value->asOf === null ? '—' : Span::date($value->asOf, $wx->configuration()->timezone)->format($theme->text('Y-m-d H:i:s'))];
        }
        return ['name' => $wx->configuration()->name, 'language' => $theme->language, 'fields' => $fields];
    }

    public static function render(Weather $wx, Theme $theme = new Theme()): string
    {
        $data = self::snapshot($wx, $theme);
        $escape = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        ob_start();
        try {
            require __DIR__ . '/template.php';
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
