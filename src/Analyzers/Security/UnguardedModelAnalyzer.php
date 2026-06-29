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

final class UnguardedModelAnalyzer extends BaseAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly EloquentModelPropertyReader $propertyReader = new EloquentModelPropertyReader,
    ) {}

    public function id(): string
    {
        return 'security.unguarded-model';
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

        foreach ($context->project->phpFiles as $file) {
            foreach ($this->matchingLines($file, '/::unguard\s*\(/') as $match) {
                $issues[] = $this->globalUnguardIssue($file, $match['line']);
            }
        }

        foreach ($context->project->models() as $file) {
            $properties = $this->propertyReader->read($file);

            if (! $properties['hasUnguarded']) {
                continue;
            }

            $issues[] = $this->attributeUnguardIssue($file, $properties['guardedLine'] ?? 1);
        }

        return $issues;
    }

    private function globalUnguardIssue(PhpFile $file, int $line): Issue
    {
        return $this->issue(
            $this->id(),
            $this->category(),
            Severity::Critical,
            'Mass assignment protection disabled',
            'Model::unguard() disables Eloquent mass-assignment protection for all attributes.',
            $file,
            $line,
            'Use explicit $fillable lists or narrowly scoped reguard() calls instead of global unguard().',
        );
    }

    private function attributeUnguardIssue(PhpFile $file, int $line): Issue
    {
        return $this->issue(
            $this->id(),
            $this->category(),
            Severity::Critical,
            'Model declares unrestricted mass assignment',
            'The #[Unguarded] attribute disables mass-assignment protection for every column on this model.',
            $file,
            $line,
            'Replace #[Unguarded] with an explicit #[Fillable] list or a narrow #[Guarded] definition.',
        );
    }
}
