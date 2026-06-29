<?php

declare(strict_types=1);

namespace LaravelAudit\Tests\Unit;

use LaravelAudit\Analysis\AnalysisContext;
use LaravelAudit\Analysis\Issue;
use LaravelAudit\Analyzers\BestPractices\LogicInRoutesAnalyzer;
use LaravelAudit\Analyzers\BestPractices\SilentFailureAnalyzer;
use LaravelAudit\Analyzers\CodeQuality\NestingDepthAnalyzer;
use LaravelAudit\Analyzers\Reliability\GlobalVariablesAnalyzer;
use LaravelAudit\Analyzers\Security\CommandInjectionAnalyzer;
use LaravelAudit\Analyzers\Security\EvalUsageAnalyzer;
use LaravelAudit\Analyzers\Security\HardcodedCredentialsAnalyzer;
use LaravelAudit\Analyzers\Security\UnguardedModelAnalyzer;
use LaravelAudit\Project\PhpFile;
use LaravelAudit\Project\ProjectIndex;
use LaravelAudit\Tests\TestCase;
use PhpParser\ParserFactory;

final class ShieldCiStyleAnalyzersTest extends TestCase
{
    public function test_detects_excessive_nesting_depth(): void
    {
        $issues = (new NestingDepthAnalyzer)->analyze($this->context(<<<'PHP'
            <?php

            final class Example
            {
                public function handle(bool $a, bool $b, bool $c, bool $d, bool $e): void
                {
                    if ($a) {
                        if ($b) {
                            if ($c) {
                                if ($d) {
                                    if ($e) {
                                        echo 'deep';
                                    }
                                }
                            }
                        }
                    }
                }
            }
            PHP));

        self::assertRuleFound('code-quality.nesting-depth', $issues);
    }

    public function test_detects_silent_failure(): void
    {
        $issues = (new SilentFailureAnalyzer)->analyze($this->context(<<<'PHP'
            <?php

            final class Example
            {
                public function handle(Service $service): void
                {
                    try {
                        $service->run();
                    } catch (Throwable $exception) {
                    }
                }
            }
            PHP));

        self::assertRuleFound('best-practices.silent-failure', $issues);
    }

    public function test_detects_command_injection_candidate(): void
    {
        $issues = (new CommandInjectionAnalyzer)->analyze($this->context(<<<'PHP'
            <?php

            final class Example
            {
                public function run(string $command): void
                {
                    shell_exec($command);
                }
            }
            PHP, 'app/Example.php'));

        self::assertRuleFound('security.command-injection', $issues);
    }

    public function test_detects_eval_usage(): void
    {
        $issues = (new EvalUsageAnalyzer)->analyze($this->context(<<<'PHP'
            <?php

            final class Example
            {
                public function run(string $code): mixed
                {
                    return eval($code);
                }
            }
            PHP, 'app/Example.php'));

        self::assertRuleFound('security.eval-usage', $issues);
    }

    public function test_detects_unguarded_model(): void
    {
        $issues = (new UnguardedModelAnalyzer)->analyze($this->context(<<<'PHP'
            <?php

            use Illuminate\Database\Eloquent\Model;

            final class Setup
            {
                public function boot(): void
                {
                    Model::unguard();
                }
            }
            PHP, 'app/Providers/AppServiceProvider.php'));

        self::assertRuleFound('security.unguarded-model', $issues);
    }

    public function test_detects_unguarded_model_attribute(): void
    {
        $issues = (new UnguardedModelAnalyzer)->analyze($this->context(<<<'PHP'
            <?php

            namespace App\Models;

            use Illuminate\Database\Eloquent\Attributes\Unguarded;
            use Illuminate\Database\Eloquent\Model;

            #[Unguarded]
            final class User extends Model
            {
            }
            PHP, 'app/Models/User.php'));

        self::assertRuleFound('security.unguarded-model', $issues);
    }

    public function test_detects_superglobal_access(): void
    {
        $issues = (new GlobalVariablesAnalyzer)->analyze($this->context(<<<'PHP'
            <?php

            final class Example
            {
                public function handle(): void
                {
                    $id = $_GET['id'];
                }
            }
            PHP, 'app/Example.php'));

        self::assertRuleFound('reliability.global-variables', $issues);
    }

    public function test_detects_hardcoded_credentials(): void
    {
        $issues = (new HardcodedCredentialsAnalyzer)->analyze($this->context(<<<'PHP'
            <?php

            final class Example
            {
                private string $apiKey = 'sk_live_0123456789abcdef';
            }
            PHP, 'app/Example.php'));

        self::assertRuleFound('security.hardcoded-credentials', $issues);
    }

    public function test_detects_logic_in_routes(): void
    {
        $issues = (new LogicInRoutesAnalyzer)->analyze($this->context(<<<'PHP'
            <?php

            use Illuminate\Support\Facades\Route;

            Route::get('/report', function () {
                $rows = [];
                $rows[] = 1;
                $rows[] = 2;
                $rows[] = 3;
                $rows[] = 4;
                $rows[] = 5;
                $rows[] = 6;
                $rows[] = 7;
                $rows[] = 8;
                $rows[] = 9;
                $rows[] = 10;
                $rows[] = 11;
                $rows[] = 12;
                $rows[] = 13;
                $rows[] = 14;
                $rows[] = 15;

                return response()->json($rows);
            });
            PHP, 'routes/web.php'));

        self::assertRuleFound('best-practices.logic-in-routes', $issues);
    }

    private function context(string $contents, string $relativePath = 'app/Fixture.php'): AnalysisContext
    {
        return new AnalysisContext(
            basePath: __DIR__,
            project: new ProjectIndex([
                $this->phpFile($contents, $relativePath),
            ], []),
            config: [],
        );
    }

    private function phpFile(string $contents, string $relativePath): PhpFile
    {
        $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($contents) ?? [];

        return new PhpFile(
            path: __DIR__.'/Fixture.php',
            relativePath: $relativePath,
            contents: $contents,
            ast: $ast,
            classes: [],
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
