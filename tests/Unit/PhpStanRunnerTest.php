<?php

declare(strict_types=1);

namespace LaravelAudit\Tests\Unit;

use LaravelAudit\Runners\PhpStanRunner;
use LaravelAudit\Tests\TestCase;

final class PhpStanRunnerTest extends TestCase
{
    public function test_uses_generated_config_when_phpstan_config_is_missing(): void
    {
        $basePath = $this->makeProject();

        mkdir($basePath.'/app', 0777, true);
        mkdir($basePath.'/routes', 0777, true);

        $result = (new PhpStanRunner)->run($basePath, [
            'binary' => 'vendor/bin/phpstan',
            'arguments' => ['analyse', '--error-format=json'],
            'paths' => ['app', 'routes', 'missing'],
        ]);

        $output = json_decode($result->output, true);

        self::assertSame('analyse', $output['argv'][0]);
        self::assertSame('--error-format=json', $output['argv'][1]);
        self::assertSame('--no-progress', $output['argv'][2]);
        self::assertSame('--no-ansi', $output['argv'][3]);
        self::assertSame('--memory-limit=1G', $output['argv'][4]);
        self::assertStringStartsWith('--configuration=', $output['argv'][5]);
        self::assertStringContainsString('audit-phpstan.neon', $output['argv'][5]);
    }

    public function test_wraps_existing_project_config_with_writable_cache_paths(): void
    {
        $basePath = $this->makeProject();

        mkdir($basePath.'/app', 0777, true);
        file_put_contents($basePath.'/phpstan.neon', "parameters:\n");

        $result = (new PhpStanRunner)->run($basePath, [
            'binary' => 'vendor/bin/phpstan',
            'arguments' => ['analyse', '--error-format=json'],
            'paths' => ['app'],
        ]);

        $output = json_decode($result->output, true);

        self::assertStringStartsWith('--configuration=', $output['argv'][5]);
        $configPath = substr($output['argv'][5], strlen('--configuration='));
        $configContents = file_get_contents($configPath);

        self::assertIsString($configContents);
        self::assertStringContainsString($basePath.'/phpstan.neon', str_replace('\\', '/', $configContents));
        self::assertStringContainsString('resultCachePath:', $configContents);
    }

    public function test_uses_generated_larastan_config_when_extension_is_available(): void
    {
        $basePath = $this->makeProject(withLarastan: true);

        mkdir($basePath.'/app', 0777, true);
        mkdir($basePath.'/routes', 0777, true);

        $result = (new PhpStanRunner)->run($basePath, [
            'binary' => 'vendor/bin/phpstan',
            'arguments' => ['analyse', '--error-format=json'],
            'paths' => ['app', 'routes'],
            'level' => 5,
        ]);

        $output = json_decode($result->output, true);

        self::assertCount(6, $output['argv']);
        self::assertStringStartsWith('--configuration=', $output['argv'][5]);
        $configPath = substr($output['argv'][5], strlen('--configuration='));
        $configContents = file_get_contents($configPath);

        self::assertIsString($configContents);
        self::assertStringContainsString('vendor/larastan/larastan/extension.neon', $configContents);
        self::assertStringContainsString('level: 5', $configContents);
    }

    public function test_skips_auto_larastan_when_disabled(): void
    {
        $basePath = $this->makeProject(withLarastan: true);

        mkdir($basePath.'/app', 0777, true);

        $result = (new PhpStanRunner)->run($basePath, [
            'binary' => 'vendor/bin/phpstan',
            'arguments' => ['analyse', '--error-format=json'],
            'paths' => ['app'],
            'auto_larastan' => false,
        ]);

        $output = json_decode($result->output, true);

        self::assertStringStartsWith('--configuration=', $output['argv'][5]);
        $configContents = file_get_contents(substr($output['argv'][5], strlen('--configuration=')));

        self::assertIsString($configContents);
        self::assertStringNotContainsString('larastan/larastan/extension.neon', $configContents);
    }

    public function test_parses_json_stdout_when_phpstan_writes_progress_to_stderr(): void
    {
        $basePath = $this->makeProject(<<<'PHP'
            #!/usr/bin/env php
            <?php

            fwrite(STDERR, "progress output\n");
            echo json_encode([
                'files' => [
                    'app/Foo.php' => [
                        'messages' => [
                            [
                                'message' => 'Example PHPStan issue.',
                                'line' => 12,
                                'identifier' => 'example.issue',
                            ],
                        ],
                    ],
                ],
            ]);
            PHP);

        mkdir($basePath.'/app', 0777, true);

        $result = (new PhpStanRunner)->run($basePath, [
            'binary' => 'vendor/bin/phpstan',
            'arguments' => ['analyse', '--error-format=json'],
            'paths' => ['app'],
            'auto_larastan' => false,
        ]);

        self::assertCount(1, $result->issues);
        self::assertSame('example.issue', $result->issues[0]->ruleId);
        self::assertStringContainsString('progress output', $result->output);
    }

    public function test_parses_json_from_stdout_when_progress_bar_is_present(): void
    {
        $basePath = $this->makeProject(<<<'PHP'
            #!/usr/bin/env php
            <?php

            echo "\x1B[1G\x1B[2K 423/423 [============================] 100%\n";
            echo json_encode([
                'files' => [
                    'app/Foo.php' => [
                        'messages' => [
                            [
                                'message' => 'Example PHPStan issue.',
                                'line' => 12,
                                'identifier' => 'example.issue',
                            ],
                        ],
                    ],
                ],
            ]);
            PHP);

        mkdir($basePath.'/app', 0777, true);

        $result = (new PhpStanRunner)->run($basePath, [
            'binary' => 'vendor/bin/phpstan',
            'arguments' => ['analyse', '--error-format=json'],
            'paths' => ['app'],
            'auto_larastan' => false,
        ]);

        self::assertCount(1, $result->issues);
        self::assertSame('example.issue', $result->issues[0]->ruleId);
    }

    public function test_returns_no_issues_when_phpstan_reports_zero_errors(): void
    {
        $basePath = $this->makeProject(<<<'PHP'
            #!/usr/bin/env php
            <?php

            echo json_encode([
                'totals' => ['errors' => 0, 'file_errors' => 0],
                'files' => [],
            ]);
            PHP);

        mkdir($basePath.'/app', 0777, true);

        $result = (new PhpStanRunner)->run($basePath, [
            'binary' => 'vendor/bin/phpstan',
            'arguments' => ['analyse', '--error-format=json'],
            'paths' => ['app'],
            'auto_larastan' => false,
        ]);

        self::assertSame(0, $result->exitCode);
        self::assertSame([], $result->issues);
    }

    public function test_parses_plain_text_result_cache_errors(): void
    {
        $basePath = $this->makeProject(<<<'PHP'
            #!/usr/bin/env php
            <?php

            echo "Could not write file: /tmp/phpstan/resultCache.php (unknown cause)\n";
            exit(1);
            PHP);

        mkdir($basePath.'/app', 0777, true);

        $result = (new PhpStanRunner)->run($basePath, [
            'binary' => 'vendor/bin/phpstan',
            'arguments' => ['analyse', '--error-format=json'],
            'paths' => ['app'],
            'auto_larastan' => false,
        ]);

        self::assertCount(1, $result->issues);
        self::assertSame('tooling.phpstan.runner', $result->issues[0]->ruleId);
        self::assertStringContainsString('/tmp/phpstan/resultCache.php', $result->issues[0]->message);
    }

    public function test_parses_top_level_errors_from_json_output(): void
    {
        $basePath = $this->makeProject(<<<'PHP'
            #!/usr/bin/env php
            <?php

            echo json_encode([
                'totals' => ['errors' => 1, 'file_errors' => 0],
                'files' => [],
                'errors' => [
                    "Child process error (exit code 255):\n\n  Trait \"Laravel\\Sanctum\\HasApiTokens\" not found\n\n  at app/Models/User.php:11",
                ],
            ]);
            PHP);

        mkdir($basePath.'/app', 0777, true);

        $result = (new PhpStanRunner)->run($basePath, [
            'binary' => 'vendor/bin/phpstan',
            'arguments' => ['analyse', '--error-format=json'],
            'paths' => ['app'],
            'auto_larastan' => false,
        ]);

        self::assertCount(1, $result->issues);
        self::assertSame('tooling.phpstan.runner', $result->issues[0]->ruleId);
        self::assertSame('Trait "Laravel\Sanctum\HasApiTokens" not found', $result->issues[0]->message);
        self::assertSame('app/Models/User.php', $result->issues[0]->location->file);
        self::assertSame(11, $result->issues[0]->location->line);
    }

    private function makeProject(?string $binaryContents = null, bool $withLarastan = false): string
    {
        $basePath = sys_get_temp_dir().'/laravel-audit-phpstan-runner-'.bin2hex(random_bytes(6));

        mkdir($basePath.'/vendor/bin', 0777, true);
        mkdir($basePath.'/storage/framework/cache', 0777, true);

        if ($withLarastan) {
            mkdir($basePath.'/vendor/larastan/larastan', 0777, true);
            file_put_contents($basePath.'/vendor/larastan/larastan/extension.neon', "parameters:\n");
        }

        $binary = $basePath.'/vendor/bin/phpstan';
        file_put_contents($binary, $binaryContents ?? <<<'PHP'
            #!/usr/bin/env php
            <?php

            echo json_encode([
                'files' => [],
                'argv' => array_slice($argv, 1),
            ]);
            PHP);
        chmod($binary, 0755);

        return $basePath;
    }
}
