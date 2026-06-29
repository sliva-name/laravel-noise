<?php

declare(strict_types=1);

namespace LaravelAudit\Analyzers\Security;

use LaravelAudit\Analysis\AnalysisContext;
use LaravelAudit\Analysis\AnalyzerInterface;
use LaravelAudit\Analysis\Category;
use LaravelAudit\Analysis\Issue;
use LaravelAudit\Analysis\Severity;
use LaravelAudit\Analyzers\BaseAnalyzer;
use LaravelAudit\Project\PhpFile;

final class RawSqlAnalyzer extends BaseAnalyzer implements AnalyzerInterface
{
    public function id(): string
    {
        return 'security.raw-sql';
    }

    public function category(): Category
    {
        return Category::Security;
    }

    /**
     * @return list<Issue>
     */
    public function analyze(AnalysisContext $context): array
    {
        $issues = [];
        $pattern = '/(DB::raw|->(?:selectRaw|whereRaw|orWhereRaw|havingRaw|orderByRaw|groupByRaw)|DB::(?:statement|unprepared))/';

        foreach ($context->project->phpFiles as $file) {
            if ($this->isMigrationFile($file)) {
                continue;
            }

            foreach ($this->matchingLines($file, $pattern) as $match) {
                $issues[] = $this->issue(
                    $this->id(),
                    $this->category(),
                    Severity::Critical,
                    'Raw SQL usage requires review',
                    'Raw SQL can bypass query binding and increase SQL injection risk when user input is interpolated.',
                    $file,
                    $match['line'],
                    'Prefer query builder bindings, parameterized expressions, or narrowly review this raw SQL statement.',
                );
            }
        }

        return $issues;
    }

    private function isMigrationFile(PhpFile $file): bool
    {
        return str_starts_with($file->relativePath, 'database/migrations/');
    }
}
