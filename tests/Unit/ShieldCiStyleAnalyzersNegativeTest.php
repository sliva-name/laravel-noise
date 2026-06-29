<?php

declare(strict_types=1);

namespace LaravelAudit\Tests\Unit;

use LaravelAudit\Analyzers\BestPractices\LogicInRoutesAnalyzer;
use LaravelAudit\Analyzers\BestPractices\SilentFailureAnalyzer;
use LaravelAudit\Analyzers\CodeQuality\NestingDepthAnalyzer;
use LaravelAudit\Analyzers\Reliability\GlobalVariablesAnalyzer;
use LaravelAudit\Analyzers\Security\CommandInjectionAnalyzer;
use LaravelAudit\Analyzers\Security\EvalUsageAnalyzer;
use LaravelAudit\Analyzers\Security\HardcodedCredentialsAnalyzer;
use LaravelAudit\Analyzers\Security\UnguardedModelAnalyzer;
use LaravelAudit\Tests\Support\AnalyzesPhpFixtures;
use LaravelAudit\Tests\TestCase;

final class ShieldCiStyleAnalyzersNegativeTest extends TestCase
{
    use AnalyzesPhpFixtures;

    public function test_does_not_flag_moderate_nesting_depth(): void
    {
        $issues = (new NestingDepthAnalyzer)->analyze($this->analysisContext(<<<'PHP'
            <?php

            final class Example
            {
                public function handle(bool $a, bool $b, bool $c): void
                {
                    if ($a) {
                        if ($b) {
                            if ($c) {
                                echo 'ok';
                            }
                        }
                    }
                }
            }
            PHP, 'app/Fixture.php'));

        self::assertNoIssues($issues);
    }

    public function test_does_not_flag_catch_blocks_that_handle_failures(): void
    {
        $issues = (new SilentFailureAnalyzer)->analyze($this->analysisContext(<<<'PHP'
            <?php

            use Illuminate\Support\Facades\Log;

            final class Example
            {
                public function handle(Service $service): void
                {
                    try {
                        $service->run();
                    } catch (Throwable $exception) {
                        Log::error('Service failed', ['exception' => $exception]);
                    }
                }
            }
            PHP, 'app/Fixture.php'));

        self::assertNoIssues($issues);
    }

    public function test_does_not_flag_laravel_request_access(): void
    {
        $issues = (new GlobalVariablesAnalyzer)->analyze($this->analysisContext(<<<'PHP'
            <?php

            final class Example
            {
                public function handle(): string
                {
                    return (string) request()->input('id');
                }
            }
            PHP, 'app/Fixture.php'));

        self::assertNoIssues($issues);
    }

    public function test_does_not_flag_placeholder_credentials(): void
    {
        $issues = (new HardcodedCredentialsAnalyzer)->analyze($this->analysisContext(<<<'PHP'
            <?php

            final class Example
            {
                private string $apiKey = 'changeme';
            }
            PHP, 'app/Fixture.php'));

        self::assertNoIssues($issues);
    }

    public function test_does_not_flag_credentials_inside_test_directory(): void
    {
        $issues = (new HardcodedCredentialsAnalyzer)->analyze($this->analysisContext(<<<'PHP'
            <?php

            final class Example
            {
                private string $apiKey = 'sk_live_0123456789abcdef';
            }
            PHP, 'tests/Feature/ExampleTest.php'));

        self::assertNoIssues($issues);
    }

    public function test_does_not_flag_short_route_closures(): void
    {
        $issues = (new LogicInRoutesAnalyzer)->analyze($this->analysisContext(<<<'PHP'
            <?php

            use Illuminate\Support\Facades\Route;

            Route::get('/health', fn () => response()->json(['ok' => true]));
            PHP, 'routes/web.php'));

        self::assertNoIssues($issues);
    }

    public function test_does_not_flag_large_route_group_closures(): void
    {
        $issues = (new LogicInRoutesAnalyzer)->analyze($this->analysisContext(<<<'PHP'
            <?php

            use Illuminate\Support\Facades\Route;

            Route::middleware('auth')->group(function () {
                Route::get('/one', fn () => 'one');
                Route::get('/two', fn () => 'two');
                Route::get('/three', fn () => 'three');
                Route::get('/four', fn () => 'four');
                Route::get('/five', fn () => 'five');
                Route::get('/six', fn () => 'six');
                Route::get('/seven', fn () => 'seven');
                Route::get('/eight', fn () => 'eight');
                Route::get('/nine', fn () => 'nine');
                Route::get('/ten', fn () => 'ten');
                Route::get('/eleven', fn () => 'eleven');
                Route::get('/twelve', fn () => 'twelve');
                Route::get('/thirteen', fn () => 'thirteen');
                Route::get('/fourteen', fn () => 'fourteen');
                Route::get('/fifteen', fn () => 'fifteen');
            });
            PHP, 'routes/web.php'));

        self::assertNoIssues($issues);
    }

    public function test_does_not_flag_models_without_unguard(): void
    {
        $issues = (new UnguardedModelAnalyzer)->analyze($this->analysisContext(<<<'PHP'
            <?php

            use Illuminate\Database\Eloquent\Model;

            final class Setup
            {
                public function boot(): void
                {
                    Model::reguard();
                }
            }
            PHP, 'app/Providers/AppServiceProvider.php'));

        self::assertNoIssues($issues);
    }

    public function test_does_not_flag_process_usage_without_shell_exec(): void
    {
        $issues = (new CommandInjectionAnalyzer)->analyze($this->analysisContext(<<<'PHP'
            <?php

            use Symfony\Component\Process\Process;

            final class Example
            {
                public function run(): void
                {
                    (new Process(['php', 'artisan', 'migrate']))->run();
                }
            }
            PHP, 'app/Fixture.php'));

        self::assertNoIssues($issues);
    }

    public function test_does_not_flag_files_without_dynamic_execution(): void
    {
        $issues = (new EvalUsageAnalyzer)->analyze($this->analysisContext(<<<'PHP'
            <?php

            final class Example
            {
                public function evaluate(bool $allowed): bool
                {
                    return $allowed;
                }
            }
            PHP, 'app/Fixture.php'));

        self::assertNoIssues($issues);
    }
}
