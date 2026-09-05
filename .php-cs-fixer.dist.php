<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

// Only what exists: the directories and entry points arrive one milestone at
// a time, and a finder that names a missing one refuses to run at all.
$directories = array_filter([__DIR__ . '/src', __DIR__ . '/tests/Unit', __DIR__ . '/public', __DIR__ . '/themes'], 'is_dir');
$files = array_filter([__DIR__ . '/bin/weewx-php', __DIR__ . '/frontend.php', __FILE__], 'is_file');

$finder = Finder::create()
    ->in($directories)
    ->append($files)
    ->name('*.php');

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        '@PER-CS2.0:risky' => true,
        'declare_strict_types' => true,
        'strict_comparison' => true,
        'strict_param' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha', 'imports_order' => ['class', 'function', 'const']],
        'no_unused_imports' => true,
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arguments', 'arrays', 'parameters']],
        'void_return' => true,
        'nullable_type_declaration_for_default_null_value' => true,
        'modernize_strpos' => true,
        'no_useless_else' => true,
        'no_useless_return' => true,
        'self_accessor' => true,
        'phpdoc_trim' => true,
        'no_empty_phpdoc' => true,
        'no_superfluous_phpdoc_tags' => ['allow_mixed' => true],
    ])
    ->setFinder($finder)
    ->setCacheFile(sys_get_temp_dir() . '/php-cs-fixer.cache');
