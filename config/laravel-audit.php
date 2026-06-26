<?php

declare(strict_types=1);

return [
    'paths' => [
        'app',
        'routes',
        'config',
        'database/migrations',
        'tests',
    ],

    'exclude' => [
        'vendor',
        'storage',
        'bootstrap/cache',
        'node_modules',
    ],

    'tools' => [
        'pint' => [
            'enabled' => true,
            'binary' => env('LARAVEL_AUDIT_PINT_BINARY', 'vendor/bin/pint'),
            'arguments' => ['--test'],
        ],
        'phpstan' => [
            'enabled' => true,
            'binary' => env('LARAVEL_AUDIT_PHPSTAN_BINARY', 'vendor/bin/phpstan'),
            'arguments' => ['analyse', '--error-format=json'],
            'auto_larastan' => env('LARAVEL_AUDIT_PHPSTAN_AUTO_LARASTAN', true),
            'level' => (int) env('LARAVEL_AUDIT_PHPSTAN_LEVEL', 5),
        ],
    ],

    'reporting' => [
        'default_format' => 'console',
        'fail_on' => 'error',
    ],

    'dashboard' => [
        'enabled' => env('LARAVEL_AUDIT_DASHBOARD', true),
        'path' => env('LARAVEL_AUDIT_DASHBOARD_PATH', 'audit'),
        'middleware' => ['web'],
        'storage' => env('LARAVEL_AUDIT_DASHBOARD_STORAGE', 'file'),
        'storage_path' => env('LARAVEL_AUDIT_DASHBOARD_STORAGE_PATH'),
        'runs_path' => env('LARAVEL_AUDIT_DASHBOARD_RUNS_PATH'),
        'runner' => env('LARAVEL_AUDIT_DASHBOARD_RUNNER', 'queue'),
        'queue_connection' => env('LARAVEL_AUDIT_DASHBOARD_QUEUE_CONNECTION'),
        'queue' => env('LARAVEL_AUDIT_DASHBOARD_QUEUE', 'default'),
    ],

    'thresholds' => [
        'nesting_depth' => 4,
    ],

    'patterns' => [
        'enabled' => env('LARAVEL_AUDIT_PATTERNS', false),
        'min_confidence' => (float) env('LARAVEL_AUDIT_PATTERN_MIN_CONFIDENCE', 0.55),
        'limit' => (int) env('LARAVEL_AUDIT_PATTERN_LIMIT', 20),
        'model_path' => env('LARAVEL_AUDIT_PATTERN_MODEL', __DIR__.'/../resources/pattern-model.json'),
        'llm' => [
            'enabled' => env('LARAVEL_AUDIT_PATTERN_LLM', false),
            'provider' => env('LARAVEL_AUDIT_PATTERN_LLM_PROVIDER', 'openai_compatible'),
            'endpoint' => env('LARAVEL_AUDIT_PATTERN_LLM_ENDPOINT', 'http://127.0.0.1:1234/v1/chat/completions'),
            'model' => env('LARAVEL_AUDIT_PATTERN_LLM_MODEL', 'local-model'),
            'api_key' => env('LARAVEL_AUDIT_PATTERN_LLM_API_KEY'),
            'timeout' => (int) env('LARAVEL_AUDIT_PATTERN_LLM_TIMEOUT', 120),
            'refine_top' => (int) env('LARAVEL_AUDIT_PATTERN_LLM_REFINE_TOP', 3),
            'review_limit' => (int) env('LARAVEL_AUDIT_PATTERN_LLM_REVIEW_LIMIT', 3),
        ],
    ],

    'rules' => [
        'security.raw-sql' => true,
        'security.mass-assignment' => true,
        'security.weak-validation' => true,
        'security.debug-configuration' => true,
        'security.command-injection' => true,
        'security.eval-usage' => true,
        'security.hardcoded-credentials' => true,
        'security.unguarded-model' => true,
        'performance.n-plus-one-candidate' => true,
        'performance.sync-heavy-job' => true,
        'reliability.missing-transaction' => true,
        'reliability.env-access-outside-config' => true,
        'reliability.global-variables' => true,
        'best-practices.missing-form-request' => true,
        'best-practices.fat-controller' => true,
        'best-practices.logic-in-routes' => true,
        'best-practices.silent-failure' => true,
        'code-quality.long-method' => true,
        'code-quality.large-class' => true,
        'code-quality.nesting-depth' => true,
        'code-quality.redundant-boolean-return' => true,
        'code-quality.redundant-null-coalesce' => true,
        'code-quality.redundant-empty-foreach-guard' => true,
        'code-quality.redundant-catch-rethrow' => true,
        'code-quality.redundant-else-after-exit' => true,
        'code-quality.redundant-type-guard' => true,
        'code-quality.redundant-method-exists' => true,
        'code-quality.redundant-class-exists' => true,
        'code-quality.redundant-config-fallback' => true,
    ],
];
