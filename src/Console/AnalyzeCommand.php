<?php

declare(strict_types=1);

namespace LaravelAudit\Console;

use Illuminate\Console\Command;
use LaravelAudit\Analysis\Severity;
use LaravelAudit\Audit\AuditEngine;
use LaravelAudit\Audit\AuditOptions;
use LaravelAudit\Reporting\AuditReport;
use LaravelAudit\Reporting\ConsoleReporter;
use LaravelAudit\Reporting\JsonReporter;
use LaravelAudit\Reporting\SarifReporter;
use LaravelAudit\Repositories\AuditReportRepository;

final class AnalyzeCommand extends Command
{
    protected $signature = 'audit:analyze
        {--format=console : Output format: console, json, or sarif}
        {--fail-on= : Minimum severity that should produce a non-zero exit code}
        {--only= : Comma-separated analyzer categories to run}
        {--no-tools : Skip Pint and PHPStan runners}
        {--patterns : Score refactoring patterns with the weighted heuristic model}
        {--llm : Confirm top heuristic pattern hypotheses with an LLM}
        {--llm-pick=* : Confirm only selected hypothesis keys with an LLM (pattern:file::method)}
        {--store : Save the report to the audit panel database}';

    protected $description = 'Analyze a Laravel project with Pint, PHPStan/Larastan, and Laravel-specific audit rules.';

    public function handle(AuditEngine $engine, AuditReportRepository $reports): int
    {
        $config = config('laravel-audit', []);
        $llmHypothesisKeys = array_values(array_filter(array_map(
            strval(...),
            (array) $this->option('llm-pick'),
        )));
        $useLlm = (bool) $this->option('llm') || $llmHypothesisKeys !== [];

        $options = new AuditOptions(
            categories: $this->categories(),
            noTools: (bool) $this->option('no-tools'),
            patterns: (bool) $this->option('patterns') || $useLlm,
            llm: $useLlm,
            llmHypothesisKeys: $llmHypothesisKeys,
            failOn: $this->failOn($config),
        );

        $report = $engine->run($options);

        if ($this->option('store') && (bool) config('laravel-audit.dashboard.enabled', true)) {
            $record = $reports->store($report, $options);
            $this->info('Report saved: '.route('laravel-audit.reports.show', $record->uuid));
        }

        $this->renderReport($report);

        return $report->shouldFail($options->failOn) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function categories(): array
    {
        $only = $this->option('only');

        if (! is_string($only) || trim($only) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $only))));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function failOn(array $config): Severity
    {
        $value = $this->option('fail-on') ?: data_get($config, 'reporting.fail_on', 'error');

        return Severity::tryFrom((string) $value) ?? Severity::Error;
    }

    private function renderReport(AuditReport $report): void
    {
        if ($this->option('format') === 'json') {
            $this->line((new JsonReporter)->render($report));

            return;
        }

        if ($this->option('format') === 'sarif') {
            $this->line((new SarifReporter)->render($report));

            return;
        }

        (new ConsoleReporter)->render($this, $report);
    }
}
