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

final class MassAssignmentAnalyzer extends BaseAnalyzer implements AnalyzerInterface
{
    public function id(): string
    {
        return 'security.mass-assignment';
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

        foreach ($context->project->models() as $file) {
            $hasFillable = str_contains($file->contents, '$fillable');
            $hasGuarded = str_contains($file->contents, '$guarded');
            $emptyGuardedMatches = $this->matchingLines($file, '/protected\s+\$guarded\s*=\s*\[\s*\]/');

            if (! $hasFillable && ! $hasGuarded) {
                $issues[] = $this->issue(
                    $this->id(),
                    $this->category(),
                    Severity::Warning,
                    'Model has no mass assignment policy',
                    'Neither $fillable nor $guarded is defined, so mass-assignment protection is implicit and easy to miss during review.',
                    $file,
                    1,
                    'Add an explicit $fillable list for request-driven writes, or define $guarded when the model never receives mass-assigned input.',
                );

                continue;
            }

            foreach ($emptyGuardedMatches as $match) {
                $issues[] = $this->issue(
                    $this->id(),
                    $this->category(),
                    Severity::Critical,
                    'Model allows unrestricted mass assignment',
                    'An empty $guarded array permits mass assignment for every column.',
                    $file,
                    $match['line'],
                    'Replace empty $guarded with a narrow $fillable list for request-driven writes.',
                );
            }

            if ($hasGuarded && ! $hasFillable && $emptyGuardedMatches === []) {
                $issues[] = $this->guardedWithoutFillableIssue($file);
            }
        }

        return $issues;
    }

    private function guardedWithoutFillableIssue(PhpFile $file): Issue
    {
        $line = 1;

        foreach ($this->matchingLines($file, '/protected\s+\$guarded\s*=/') as $match) {
            $line = $match['line'];

            break;
        }

        return $this->issue(
            $this->id(),
            $this->category(),
            Severity::Info,
            'Model defines $guarded without $fillable',
            'Using only $guarded makes allowed request-driven attributes harder to spot than an explicit $fillable list.',
            $file,
            $line,
            'Prefer an explicit $fillable list for attributes that may be mass-assigned from HTTP input.',
        );
    }
}
