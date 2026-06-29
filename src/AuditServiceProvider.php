<?php

declare(strict_types=1);

namespace LaravelAudit;

use Illuminate\Support\ServiceProvider;
use LaravelAudit\Analysis\AnalyzerRegistry;
use LaravelAudit\Analyzers\BestPractices\DebugStatementAnalyzer;
use LaravelAudit\Analyzers\BestPractices\FatControllerAnalyzer;
use LaravelAudit\Analyzers\BestPractices\LogicInRoutesAnalyzer;
use LaravelAudit\Analyzers\BestPractices\MissingFormRequestAnalyzer;
use LaravelAudit\Analyzers\BestPractices\SilentFailureAnalyzer;
use LaravelAudit\Analyzers\CodeQuality\LargeClassAnalyzer;
use LaravelAudit\Analyzers\CodeQuality\LongMethodAnalyzer;
use LaravelAudit\Analyzers\CodeQuality\NestingDepthAnalyzer;
use LaravelAudit\Analyzers\CodeQuality\RedundantBooleanReturnAnalyzer;
use LaravelAudit\Analyzers\CodeQuality\RedundantCatchRethrowAnalyzer;
use LaravelAudit\Analyzers\CodeQuality\RedundantClassExistsAnalyzer;
use LaravelAudit\Analyzers\CodeQuality\RedundantConfigFallbackAnalyzer;
use LaravelAudit\Analyzers\CodeQuality\RedundantElseAfterExitAnalyzer;
use LaravelAudit\Analyzers\CodeQuality\RedundantEmptyForeachGuardAnalyzer;
use LaravelAudit\Analyzers\CodeQuality\RedundantMethodExistsAnalyzer;
use LaravelAudit\Analyzers\CodeQuality\RedundantNullCoalesceAnalyzer;
use LaravelAudit\Analyzers\CodeQuality\RedundantTypeGuardAnalyzer;
use LaravelAudit\Analyzers\Performance\NPlusOneCandidateAnalyzer;
use LaravelAudit\Analyzers\Performance\SyncHeavyJobAnalyzer;
use LaravelAudit\Analyzers\Reliability\EnvAccessOutsideConfigAnalyzer;
use LaravelAudit\Analyzers\Reliability\GlobalVariablesAnalyzer;
use LaravelAudit\Analyzers\Reliability\MissingTransactionAnalyzer;
use LaravelAudit\Analyzers\Security\CommandInjectionAnalyzer;
use LaravelAudit\Analyzers\Security\DebugConfigurationAnalyzer;
use LaravelAudit\Analyzers\Security\EvalUsageAnalyzer;
use LaravelAudit\Analyzers\Security\HardcodedCredentialsAnalyzer;
use LaravelAudit\Analyzers\Security\MassAssignmentAnalyzer;
use LaravelAudit\Analyzers\Security\RawSqlAnalyzer;
use LaravelAudit\Analyzers\Security\SensitiveFieldExposureAnalyzer;
use LaravelAudit\Analyzers\Security\UnguardedModelAnalyzer;
use LaravelAudit\Analyzers\Security\UnvalidatedMassCreateAnalyzer;
use LaravelAudit\Analyzers\Security\WeakValidationAnalyzer;
use LaravelAudit\Audit\AuditEngine;
use LaravelAudit\Audit\AuditProgressTracker;
use LaravelAudit\Audit\AuditRunDispatcher;
use LaravelAudit\Audit\AuditRunExecutor;
use LaravelAudit\Audit\AuditRunJobTimeout;
use LaravelAudit\Audit\Contracts\AuditRunProcessLauncher;
use LaravelAudit\Audit\ExecAuditRunLauncher;
use LaravelAudit\Console\AnalyzeCommand;
use LaravelAudit\Console\RunStoredAuditCommand;
use LaravelAudit\Pattern\HeuristicPatternAdvisor;
use LaravelAudit\Pattern\JsonHttpClient;
use LaravelAudit\Pattern\LlmPatternAdvisor;
use LaravelAudit\Pattern\MethodFeatureExtractor;
use LaravelAudit\Pattern\MethodReviewQueue;
use LaravelAudit\Pattern\MethodSnippetExtractor;
use LaravelAudit\Pattern\PatternAdvisorFactory;
use LaravelAudit\Pattern\PatternInferenceEngine;
use LaravelAudit\Pattern\PatternModel;
use LaravelAudit\Project\ProjectScanner;
use LaravelAudit\Repositories\AuditReportRepository;
use LaravelAudit\Repositories\Contracts\AuditReportStore;
use LaravelAudit\Repositories\DatabaseAuditReportStore;
use LaravelAudit\Repositories\FileAuditReportStore;
use LaravelAudit\Runners\PhpStanConfigurationFactory;
use LaravelAudit\Runners\PhpStanRunner;
use LaravelAudit\Runners\PintRunner;

final class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/laravel-audit.php', 'laravel-audit');

        $this->app->singleton(ProjectScanner::class);
        $this->app->singleton(PintRunner::class);
        $this->app->singleton(PhpStanConfigurationFactory::class);
        $this->app->singleton(PhpStanRunner::class);

        $this->app->singleton(PatternModel::class, function (): PatternModel {
            return PatternModel::fromPath(__DIR__.'/../resources/pattern-model.json');
        });

        $this->app->singleton(PatternInferenceEngine::class);
        $this->app->singleton(MethodFeatureExtractor::class);
        $this->app->singleton(MethodSnippetExtractor::class);
        $this->app->singleton(MethodReviewQueue::class);
        $this->app->singleton(JsonHttpClient::class);

        $this->app->singleton(HeuristicPatternAdvisor::class, function ($app): HeuristicPatternAdvisor {
            $config = config('laravel-audit.patterns', []);

            return new HeuristicPatternAdvisor(
                engine: $app->make(PatternInferenceEngine::class),
                minConfidence: (float) data_get($config, 'min_confidence', 0.55),
                limit: (int) data_get($config, 'limit', 20),
            );
        });

        $this->app->singleton(LlmPatternAdvisor::class, function ($app): LlmPatternAdvisor {
            $config = config('laravel-audit.patterns.llm', []);
            $auditConfig = config('laravel-audit', []);

            return new LlmPatternAdvisor(
                heuristicAdvisor: $app->make(HeuristicPatternAdvisor::class),
                snippetExtractor: $app->make(MethodSnippetExtractor::class),
                httpClient: $app->make(JsonHttpClient::class),
                provider: (string) data_get($config, 'provider', 'openai_compatible'),
                endpoint: (string) data_get($config, 'endpoint', 'http://127.0.0.1:1234/v1/chat/completions'),
                model: (string) data_get($config, 'model', 'local-model'),
                apiKey: data_get($config, 'api_key'),
                timeout: (int) data_get($config, 'timeout', 120),
                reviewLimit: (int) data_get($config, 'review_limit', data_get($config, 'refine_top', 3)),
                maxAttempts: AuditRunJobTimeout::maxLlmAttempts($auditConfig),
            );
        });

        $this->app->singleton(PatternAdvisorFactory::class);

        $this->app->singleton(AuditEngine::class);
        $this->app->singleton(AuditProgressTracker::class, function (): AuditProgressTracker {
            $path = config('laravel-audit.dashboard.runs_path');

            return new AuditProgressTracker(is_string($path) && $path !== '' ? $path : storage_path('app/laravel-audit/runs'));
        });

        $this->app->singleton(AuditRunProcessLauncher::class, ExecAuditRunLauncher::class);
        $this->app->singleton(AuditRunDispatcher::class);
        $this->app->singleton(AuditRunExecutor::class);

        $this->app->singleton(AuditReportStore::class, function ($app): AuditReportStore {
            $driver = (string) config('laravel-audit.dashboard.storage', 'file');

            if ($driver === 'database') {
                return $app->make(DatabaseAuditReportStore::class);
            }

            $path = config('laravel-audit.dashboard.storage_path');

            return new FileAuditReportStore(is_string($path) && $path !== '' ? $path : storage_path('app/laravel-audit/reports'));
        });

        $this->app->singleton(AuditReportRepository::class, fn ($app): AuditReportRepository => new AuditReportRepository(
            $app->make(AuditReportStore::class),
        ));

        $this->app->singleton(AnalyzerRegistry::class, function (): AnalyzerRegistry {
            return new AnalyzerRegistry([
                new RawSqlAnalyzer,
                new MassAssignmentAnalyzer,
                new WeakValidationAnalyzer,
                new DebugConfigurationAnalyzer,
                new CommandInjectionAnalyzer,
                new EvalUsageAnalyzer,
                new HardcodedCredentialsAnalyzer,
                new UnguardedModelAnalyzer,
                new UnvalidatedMassCreateAnalyzer,
                new SensitiveFieldExposureAnalyzer,
                new NPlusOneCandidateAnalyzer,
                new SyncHeavyJobAnalyzer,
                new MissingTransactionAnalyzer,
                new EnvAccessOutsideConfigAnalyzer,
                new GlobalVariablesAnalyzer,
                new MissingFormRequestAnalyzer,
                new FatControllerAnalyzer,
                new LogicInRoutesAnalyzer,
                new SilentFailureAnalyzer,
                new DebugStatementAnalyzer,
                new LongMethodAnalyzer,
                new LargeClassAnalyzer,
                new NestingDepthAnalyzer,
                new RedundantBooleanReturnAnalyzer,
                new RedundantNullCoalesceAnalyzer,
                new RedundantEmptyForeachGuardAnalyzer,
                new RedundantCatchRethrowAnalyzer,
                new RedundantElseAfterExitAnalyzer,
                new RedundantTypeGuardAnalyzer,
                new RedundantMethodExistsAnalyzer,
                new RedundantClassExistsAnalyzer,
                new RedundantConfigFallbackAnalyzer,
            ]);
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/laravel-audit.php' => config_path('laravel-audit.php'),
        ], 'laravel-audit-config');

        $this->publishes([
            __DIR__.'/../database/migrations/' => database_path('migrations'),
        ], 'laravel-audit-migrations');

        if ((bool) config('laravel-audit.dashboard.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/audit.php');
            $this->loadViewsFrom(__DIR__.'/../resources/views', 'laravel-audit');

            if (config('laravel-audit.dashboard.storage', 'file') === 'database') {
                $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
            }
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                AnalyzeCommand::class,
                RunStoredAuditCommand::class,
            ]);
        }
    }
}
