<?php

declare(strict_types=1);

namespace LaravelAudit\Audit;

use Illuminate\Contracts\Foundation\Application;
use LaravelAudit\Analysis\AnalysisContext;
use LaravelAudit\Analysis\AnalyzerRegistry;
use LaravelAudit\Analysis\IssueFactory;
use LaravelAudit\Pattern\PatternAdvisorFactory;
use LaravelAudit\Pattern\PatternSuggestion;
use LaravelAudit\Project\ProjectScanner;
use LaravelAudit\Reporting\AuditReport;
use LaravelAudit\Runners\PhpStanRunner;
use LaravelAudit\Runners\PintRunner;

final class AuditEngine
{
    /** @var callable(AuditProgressUpdate): void|null */
    private $onProgress = null;

    public function __construct(
        private readonly Application $app,
        private readonly ProjectScanner $scanner,
        private readonly AnalyzerRegistry $registry,
        private readonly PintRunner $pint,
        private readonly PhpStanRunner $phpstan,
        private readonly PatternAdvisorFactory $patternAdvisorFactory,
    ) {}

    /**
     * @param  callable(AuditProgressUpdate): void|null  $onProgress
     */
    public function run(?AuditOptions $options = null, ?callable $onProgress = null): AuditReport
    {
        $this->onProgress = $onProgress;
        $options ??= new AuditOptions;
        $startedAt = microtime(true);
        $config = config('laravel-audit', []);
        $basePath = $this->app->basePath();
        $context = new AnalysisContext($basePath, $this->scanner->scan($config), $config);
        $issues = [];
        $toolResults = [];

        $analyzers = $this->registry->enabledFor($context, $options->categories);
        $totalSteps = $this->totalSteps($options, $config, count($analyzers));
        $step = 0;

        $this->progress('Scanning project files', ++$step, $totalSteps);

        foreach ($analyzers as $analyzer) {
            $this->progress('Running analyzer: '.$analyzer->id(), ++$step, $totalSteps);
            array_push($issues, ...$analyzer->analyze($context));
        }

        if (! $options->noTools) {
            if ((bool) data_get($config, 'tools.pint.enabled', true)) {
                $this->progress('Running Pint', ++$step, $totalSteps);
                $toolResults[] = $this->pint->run($basePath, data_get($config, 'tools.pint', []));
            }

            if ((bool) data_get($config, 'tools.phpstan.enabled', true)) {
                $this->progress('Running PHPStan', ++$step, $totalSteps);
                $phpstanConfig = data_get($config, 'tools.phpstan', []);

                if (is_array($phpstanConfig)) {
                    $phpstanConfig['paths'] = $config['paths'] ?? [];
                }

                $toolResults[] = $this->phpstan->run($basePath, is_array($phpstanConfig) ? $phpstanConfig : []);
            }

            foreach ($toolResults as $toolResult) {
                array_push($issues, ...$toolResult->issues);
            }
        }

        $patternSuggestions = [];

        if ($this->shouldInferPatterns($config, $options)) {
            $useLlm = $options->llm || (bool) data_get($config, 'patterns.llm.enabled', false);
            $useHeuristic = $options->patterns || (bool) data_get($config, 'patterns.enabled', false);

            if ($useHeuristic) {
                $this->progress('Scoring refactoring patterns', ++$step, $totalSteps);
            }

            if ($useLlm) {
                $this->progress('Confirming patterns with LLM', ++$step, $totalSteps);
            }

            $patternAdvisor = $this->patternAdvisorFactory->make($config, $useHeuristic, $useLlm);
            $patternSuggestions = $patternAdvisor->suggest(
                $context->project,
                $issues,
                $options->llmHypothesisKeys,
            );
        }

        $this->progress('Finalizing report', ++$step, $totalSteps);

        return new AuditReport(
            issues: $issues,
            toolResults: $toolResults,
            durationSeconds: microtime(true) - $startedAt,
            patternSuggestions: $patternSuggestions,
        );
    }

    /**
     * @param  list<string>  $hypothesisKeys
     * @param  array<string, mixed>  $reportPayload
     * @return list<PatternSuggestion>
     */
    public function confirmStoredReportPatterns(array $hypothesisKeys, array $reportPayload): array
    {
        if ($hypothesisKeys === []) {
            return [];
        }

        @set_time_limit(0);

        $config = config('laravel-audit', []);
        $context = new AnalysisContext(
            $this->app->basePath(),
            $this->scanner->scan($config),
            $config,
        );
        $issues = IssueFactory::fromReportPayload($reportPayload);

        return $this->patternAdvisorFactory
            ->make($config, useHeuristic: false, useLlm: true)
            ->suggest($context->project, $issues, $hypothesisKeys);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function totalSteps(AuditOptions $options, array $config, int $analyzerCount): int
    {
        $steps = 1 + $analyzerCount + 1;

        if (! $options->noTools) {
            if ((bool) data_get($config, 'tools.pint.enabled', true)) {
                $steps++;
            }

            if ((bool) data_get($config, 'tools.phpstan.enabled', true)) {
                $steps++;
            }
        }

        if ($this->shouldInferPatterns($config, $options)) {
            $useLlm = $options->llm || (bool) data_get($config, 'patterns.llm.enabled', false);
            $useHeuristic = $options->patterns || (bool) data_get($config, 'patterns.enabled', false);

            if ($useHeuristic) {
                $steps++;
            }

            if ($useLlm) {
                $steps++;
            }
        }

        return $steps;
    }

    private function progress(string $message, int $currentStep, int $totalSteps): void
    {
        if ($this->onProgress === null) {
            return;
        }

        ($this->onProgress)(new AuditProgressUpdate($message, $currentStep, $totalSteps));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function shouldInferPatterns(array $config, AuditOptions $options): bool
    {
        if ($options->patterns || $options->llm) {
            return true;
        }

        return (bool) data_get($config, 'patterns.enabled', false)
            || (bool) data_get($config, 'patterns.llm.enabled', false);
    }
}
