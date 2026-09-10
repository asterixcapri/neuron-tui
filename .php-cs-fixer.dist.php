<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

return (new Config())
    ->setRiskyAllowed(false)
    ->setRules([
        'array_indentation' => true,
        'binary_operator_spaces' => true,
        'blank_line_after_namespace' => true,
        'blank_line_between_import_groups' => true,
        'braces_position' => true,
        'indentation_type' => true,
        'line_ending' => true,
        'method_argument_space' => true,
        'no_extra_blank_lines' => ['tokens' => ['use']],
        'no_trailing_whitespace' => true,
        'no_trailing_whitespace_in_comment' => true,
        'no_unused_imports' => true,
        'ordered_imports' => [
            'imports_order' => ['class', 'function', 'const'],
            'sort_algorithm' => 'alpha',
        ],
        'single_blank_line_at_eof' => true,
        'statement_indentation' => true,
        'whitespace_after_comma_in_array' => true,
    ])
    ->setFinder((new Finder())->in(__DIR__)->append([__FILE__]));
