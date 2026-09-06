<?php

declare(strict_types=1);

/** @var array{name: string, language: string, fields: array<string, array{label: string, formatted: string, status: string, statusText: string, asOf: ?int, time: string}>} $data */
/** @var Closure(string): string $escape */
/** @var \WeewxPhp\Frontend\Theme $theme */
?>
<!doctype html>
<html lang="<?= $escape($theme->language) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $escape($data['name']) ?></title>
    <link rel="stylesheet" href="theme-assets.php/basic/basic.css">
    <script src="theme-assets.php/basic/basic.js" defer></script>
    <script src="assets/visit.js" defer></script>
</head>
<body>
<main>
    <h1><?= $escape($data['name']) ?></h1>
    <p id="connection" role="status" data-error="<?= $theme->html('Live data unavailable') ?>"></p>
    <table>
        <caption><?= $theme->html('Live data') ?></caption>
        <thead><tr><th scope="col"><?= $theme->html('Measurement') ?></th><th scope="col"><?= $theme->html('Value') ?></th><th scope="col"><?= $theme->html('Measured at') ?></th></tr></thead>
        <tbody>
        <?php foreach ($data['fields'] as $key => $field): ?>
            <tr data-field="<?= $escape($key) ?>">
                <th scope="row"><?= $escape($field['label']) ?></th>
                <td><span data-value><?= $escape($field['formatted']) ?></span><small data-status><?= $escape($field['statusText']) ?></small></td>
                <td data-time><?= $escape($field['time']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</main>
</body>
</html>
