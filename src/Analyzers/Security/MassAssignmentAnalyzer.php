<?php

declare(strict_types=1);

namespace LaravelAudit\Analyzers\Security;

use LaravelAudit\Analysis\AnalysisContext;
use LaravelAudit\Analysis\AnalyzerInterface;
use LaravelAudit\Analysis\Category;
use LaravelAudit\Analysis\Issue;
use LaravelAudit\Analysis\Severity;
use LaravelAudit\Analyzers\BaseAnalyzer;
use LaravelAudit\Analyzers\Support\EloquentModelPropertyReader;
use LaravelAudit\Project\PhpFile;

final class MassAssignmentAnalyzer extends BaseAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly EloquentModelPropertyReader $propertyReader = new EloquentModelPropertyReader,
    ) {}

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
            $properties = $this->propertyReader->read($file);

            if (! $properties['hasFillable'] && ! $properties['hasGuarded'] && ! $properties['hasUnguarded']) {
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

            if ($properties['hasEmptyGuarded']) {
                $issues[] = $this->issue(
                    $this->id(),
                    $this->category(),
                    Severity::Critical,
                    'Model allows unrestricted mass assignment',
                    'An empty $guarded array permits mass assignment for every column.',
                    $file,
                    $properties['guardedLine'] ?? 1,
                    'Replace empty $guarded with a narrow $fillable list for request-driven writes.',
                );
            }

            if ($properties['hasGuarded'] && ! $properties['hasFillable'] && ! $properties['hasEmptyGuarded']) {
                $issues[] = $this->guardedWithoutFillableIssue($file, $properties['guardedLine']);
            }
        }

        return $issues;
    }

    private function guardedWithoutFillableIssue(PhpFile $file, ?int $line = null): Issue
    {
        if ($line === null) {
            foreach ($this->matchingLines($file, '/protected\s+\$guarded\s*=/') as $match) {
                $line = $match['line'];

                break;
            }
        }

        return $this->issue(
            $this->id(),
            $this->category(),
            Severity::Info,
            'Model defines $guarded without $fillable',
            'Using only $guarded makes allowed request-driven attributes harder to spot than an explicit $fillable list.',
            $file,
            $line ?? 1,
            'Prefer an explicit $fillable list for attributes that may be mass-assigned from HTTP input.',
        );
    }
}
