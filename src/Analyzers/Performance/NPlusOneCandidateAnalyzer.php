<?php

declare(strict_types=1);

namespace LaravelAudit\Analyzers\Performance;

use LaravelAudit\Analysis\AnalysisContext;
use LaravelAudit\Analysis\AnalyzerInterface;
use LaravelAudit\Analysis\Category;
use LaravelAudit\Analysis\Issue;
use LaravelAudit\Analysis\Severity;
use LaravelAudit\Analyzers\BaseAnalyzer;

final class NPlusOneCandidateAnalyzer extends BaseAnalyzer implements AnalyzerInterface
{
    public function id(): string
    {
        return 'performance.n-plus-one-candidate';
    }

    public function category(): Category
    {
        return Category::Performance;
    }

    /**
     * @return list<Issue>
     */
    public function analyze(AnalysisContext $context): array
    {
        $issues = [];

        foreach ($context->project->phpFiles as $file) {
            $lines = preg_split('/\R/', $file->contents) ?: [];

            foreach ($lines as $index => $line) {
                if (preg_match('/foreach\s*\([^)]+\s+as\s+\$(\w+)/', $line, $matches) !== 1) {
                    continue;
                }

                if ($this->isQueryBuilderMacroContext($lines, $index)) {
                    continue;
                }

                $loopVariable = $matches[1];
                $window = implode("\n", array_slice($lines, $index, 20));

                if ($this->hasRelationshipPropertyAccess($window, $loopVariable) && preg_match('/with\(|load\(/', $window) !== 1) {
                    $issues[] = $this->issue(
                        $this->id(),
                        $this->category(),
                        Severity::Warning,
                        'Potential N+1 query in loop',
                        'A model property is accessed inside a loop without visible eager loading nearby.',
                        $file,
                        $index + 1,
                        'Check whether this is an Eloquent relationship and eager load it with with(), load(), or loadMissing().',
                    );
                }
            }
        }

        return $issues;
    }

    /**
     * @param  list<string>  $lines
     */
    private function isQueryBuilderMacroContext(array $lines, int $index): bool
    {
        $context = implode("\n", array_slice($lines, max(0, $index - 15), 16));

        return preg_match('/\b(?:Builder|Eloquent\\Builder|Query\\Builder)::macro\s*\(/', $context) === 1;
    }

    private function hasRelationshipPropertyAccess(string $window, string $loopVariable): bool
    {
        if (preg_match('/\$\w+->\w+->\w++(?!\s*\()/', $window) === 1) {
            return true;
        }

        if (preg_match_all('/\$'.preg_quote($loopVariable, '/').'->(\w++)(?!\s*\()/', $window, $matches) !== 1) {
            return false;
        }

        foreach ($matches[1] as $property) {
            if (! str_contains($property, '_')) {
                return true;
            }
        }

        return false;
    }
}
