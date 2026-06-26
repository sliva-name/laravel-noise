<?php

declare(strict_types=1);

namespace LaravelAudit\Tests\Unit;

use LaravelAudit\Analysis\AnalysisContext;
use LaravelAudit\Analysis\Issue;
use LaravelAudit\Analyzers\CodeQuality\RedundantTypeGuardAnalyzer;
use LaravelAudit\Project\PhpFile;
use LaravelAudit\Project\ProjectIndex;
use LaravelAudit\Tests\TestCase;
use PhpParser\ParserFactory;

final class RedundantTypeGuardAnalyzerTest extends TestCase
{
    public function test_detects_redundant_array_guard_after_array_fallback_with_array_key_exists(): void
    {
        $issues = $this->analyze(<<<'PHP'
            <?php

            final class Context
            {
                public function ruleEnabled(string $ruleId): bool
                {
                    $rules = $this->config['rules'] ?? [];

                    if (is_array($rules) && array_key_exists($ruleId, $rules)) {
                        return (bool) $rules[$ruleId];
                    }

                    return true;
                }
            }
            PHP);

        self::assertCount(1, $issues);
        self::assertSame('code-quality.redundant-type-guard', $issues[0]->ruleId);
    }

    public function test_detects_redundant_array_guard_after_array_fallback_without_array_key_exists(): void
    {
        $issues = $this->analyze(<<<'PHP'
            <?php

            final class Example
            {
                public function handle(): void
                {
                    $items = $this->config['items'] ?? [];

                    if (is_array($items)) {
                        foreach ($items as $item) {
                            echo $item;
                        }
                    }
                }
            }
            PHP);

        self::assertCount(1, $issues);
        self::assertStringContainsString('checked again with is_array()', $issues[0]->message);
    }

    public function test_detects_redundant_array_guard_after_json_decode(): void
    {
        $issues = $this->analyze(<<<'PHP'
            <?php

            final class Example
            {
                public function decode(string $payload): ?array
                {
                    $decoded = json_decode($payload, true);

                    if (is_array($decoded)) {
                        return $decoded;
                    }

                    return null;
                }
            }
            PHP);

        self::assertCount(1, $issues);
        self::assertStringContainsString('json_decode(..., true)', $issues[0]->message);
    }

    public function test_detects_redundant_json_decode_guard_in_ternary_return(): void
    {
        $issues = $this->analyze(<<<'PHP'
            <?php

            final class Example
            {
                public function decode(string $payload): ?array
                {
                    $decoded = json_decode($payload, true);

                    return is_array($decoded) ? $decoded : null;
                }
            }
            PHP);

        self::assertCount(1, $issues);
        self::assertStringContainsString('filtered again with is_array()', $issues[0]->message);
    }

    public function test_detects_redundant_array_guard_on_typed_array_parameter(): void
    {
        $issues = $this->analyze(<<<'PHP'
            <?php

            final class Example
            {
                public function handle(array $items): void
                {
                    if (! is_array($items)) {
                        return;
                    }

                    foreach ($items as $item) {
                        echo $item;
                    }
                }
            }
            PHP);

        self::assertCount(1, $issues);
        self::assertStringContainsString('already typed as array', $issues[0]->message);
    }

    public function test_detects_illogical_or_guard_with_empty(): void
    {
        $issues = $this->analyze(<<<'PHP'
            <?php

            final class Example
            {
                public function enabled(array $config, string $ruleId): bool
                {
                    if (is_array($config) || empty($config[$ruleId])) {
                        return false;
                    }

                    return true;
                }
            }
            PHP);

        self::assertCount(1, $issues);
        self::assertSame('Illogical type guard candidate', $issues[0]->title);
    }

    public function test_detects_redundant_coalesce_guard_in_ternary(): void
    {
        $issues = $this->analyze(<<<'PHP'
            <?php

            final class Example
            {
                public function payload(array $data): array
                {
                    return is_array($data['payload'] ?? null) ? $data['payload'] : [];
                }
            }
            PHP);

        self::assertCount(1, $issues);
        self::assertStringContainsString('null-coalesced array access', $issues[0]->message);
    }

    public function test_does_not_flag_json_decode_guard_when_body_throws(): void
    {
        $issues = $this->analyze(<<<'PHP'
            <?php

            final class Example
            {
                public function decode(string $payload): array
                {
                    $decoded = json_decode($payload, true);

                    if (! is_array($decoded)) {
                        throw new \RuntimeException('Invalid JSON.');
                    }

                    return $decoded;
                }
            }
            PHP);

        self::assertCount(0, $issues);
    }

    public function test_does_not_flag_untracked_variable_guard(): void
    {
        $issues = $this->analyze(<<<'PHP'
            <?php

            final class Example
            {
                public function handle(mixed $payload): void
                {
                    if (is_array($payload)) {
                        foreach ($payload as $item) {
                            echo $item;
                        }
                    }
                }
            }
            PHP);

        self::assertCount(0, $issues);
    }

    /**
     * @return list<Issue>
     */
    private function analyze(string $contents): array
    {
        $context = new AnalysisContext(
            basePath: __DIR__,
            project: new ProjectIndex([
                $this->phpFile($contents),
            ], []),
            config: [],
        );

        return (new RedundantTypeGuardAnalyzer)->analyze($context);
    }

    private function phpFile(string $contents): PhpFile
    {
        $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($contents) ?? [];

        return new PhpFile(
            path: __DIR__.'/Fixture.php',
            relativePath: 'app/Fixture.php',
            contents: $contents,
            ast: $ast,
            classes: [],
            methods: [],
            lines: substr_count($contents, PHP_EOL) + 1,
        );
    }
}
