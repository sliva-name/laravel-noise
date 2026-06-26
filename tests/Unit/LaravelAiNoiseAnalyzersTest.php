<?php

declare(strict_types=1);

namespace LaravelAudit\Tests\Unit;

use LaravelAudit\Analysis\AnalysisContext;
use LaravelAudit\Analysis\Issue;
use LaravelAudit\Analyzers\CodeQuality\RedundantClassExistsAnalyzer;
use LaravelAudit\Analyzers\CodeQuality\RedundantConfigFallbackAnalyzer;
use LaravelAudit\Analyzers\CodeQuality\RedundantMethodExistsAnalyzer;
use LaravelAudit\Analyzers\CodeQuality\RedundantTypeGuardAnalyzer;
use LaravelAudit\Project\PhpFile;
use LaravelAudit\Project\ProjectIndex;
use LaravelAudit\Tests\TestCase;
use PhpParser\ParserFactory;

final class LaravelAiNoiseAnalyzersTest extends TestCase
{
    public function test_detects_redundant_isset_and_is_array_guard(): void
    {
        $issues = (new RedundantTypeGuardAnalyzer)->analyze($this->context(<<<'PHP'
            <?php

            final class Example
            {
                public function handle(array $config): void
                {
                    $rules = $config['rules'] ?? [];

                    if (isset($rules) && is_array($rules)) {
                        foreach ($rules as $rule) {
                            echo $rule;
                        }
                    }
                }
            }
            PHP));

        self::assertRuleFound('code-quality.redundant-type-guard', $issues);
        self::assertStringContainsString('isset()', $issues[0]->message);
    }

    public function test_detects_redundant_is_string_guard_on_typed_parameter(): void
    {
        $issues = (new RedundantTypeGuardAnalyzer)->analyze($this->context(<<<'PHP'
            <?php

            final class Example
            {
                public function valid(string $email): bool
                {
                    if (is_string($email) && $email !== '') {
                        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
                    }

                    return false;
                }
            }
            PHP));

        self::assertRuleFound('code-quality.redundant-type-guard', $issues);
        self::assertStringContainsString('is_string()', $issues[0]->message);
    }

    public function test_detects_redundant_instanceof_on_typed_parameter(): void
    {
        $issues = (new RedundantTypeGuardAnalyzer)->analyze($this->context(<<<'PHP'
            <?php

            namespace App\Http\Controllers;

            use App\Models\User;

            final class ProfileController
            {
                public function show(User $user): string
                {
                    if ($user instanceof User) {
                        return $user->name;
                    }

                    return '';
                }
            }
            PHP));

        self::assertRuleFound('code-quality.redundant-type-guard', $issues);
        self::assertStringContainsString('instanceof', $issues[0]->message);
    }

    public function test_detects_redundant_method_exists_on_typed_dependency(): void
    {
        $issues = (new RedundantMethodExistsAnalyzer)->analyze($this->context(<<<'PHP'
            <?php

            namespace App\Services;

            final class AuditRunner
            {
                public function run(AuditEngine $engine): void
                {
                    if (method_exists($engine, 'run')) {
                        $engine->run();
                    }
                }
            }

            final class AuditEngine
            {
                public function run(): void {}
            }
            PHP));

        self::assertRuleFound('code-quality.redundant-method-exists', $issues);
    }

    public function test_detects_redundant_class_exists_for_project_class(): void
    {
        $serviceFile = $this->phpFile(<<<'PHP'
            <?php

            namespace App\Services;

            final class AuditEngine
            {
                public function run(): void {}
            }
            PHP, 'app/Services/AuditEngine.php', ['AuditEngine']);

        $consumerFile = $this->phpFile(<<<'PHP'
            <?php

            namespace App\Providers;

            use App\Services\AuditEngine;

            final class AuditServiceProvider
            {
                public function register(): void
                {
                    if (class_exists(AuditEngine::class)) {
                        app(AuditEngine::class);
                    }
                }
            }
            PHP, 'app/Providers/AuditServiceProvider.php', ['AuditServiceProvider']);

        $issues = (new RedundantClassExistsAnalyzer)->analyze(new AnalysisContext(
            basePath: __DIR__,
            project: new ProjectIndex([$serviceFile, $consumerFile], []),
            config: [],
        ));

        self::assertRuleFound('code-quality.redundant-class-exists', $issues);
    }

    public function test_detects_redundant_config_fallback(): void
    {
        $issues = (new RedundantConfigFallbackAnalyzer)->analyze($this->context(<<<'PHP'
            <?php

            final class Example
            {
                public function name(): string
                {
                    return config('app.name') ?? 'Laravel';
                }
            }
            PHP));

        self::assertRuleFound('code-quality.redundant-config-fallback', $issues);
    }

    public function test_does_not_flag_config_with_builtin_default_argument(): void
    {
        $issues = (new RedundantConfigFallbackAnalyzer)->analyze($this->context(<<<'PHP'
            <?php

            final class Example
            {
                public function name(): string
                {
                    return (string) config('app.name', 'Laravel');
                }
            }
            PHP));

        self::assertCount(0, $issues);
    }

    private function context(string $contents): AnalysisContext
    {
        return new AnalysisContext(
            basePath: __DIR__,
            project: new ProjectIndex([
                $this->phpFile($contents),
            ], []),
            config: [],
        );
    }

    /**
     * @param  list<string>  $classes
     */
    private function phpFile(string $contents, string $relativePath = 'app/Fixture.php', array $classes = []): PhpFile
    {
        $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($contents) ?? [];

        return new PhpFile(
            path: __DIR__.'/Fixture.php',
            relativePath: $relativePath,
            contents: $contents,
            ast: $ast,
            classes: $classes,
            methods: [],
            lines: substr_count($contents, PHP_EOL) + 1,
        );
    }

    /**
     * @param  list<Issue>  $issues
     */
    private static function assertRuleFound(string $ruleId, array $issues): void
    {
        self::assertContains($ruleId, array_map(
            fn ($issue): string => $issue->ruleId,
            $issues,
        ));
    }
}
