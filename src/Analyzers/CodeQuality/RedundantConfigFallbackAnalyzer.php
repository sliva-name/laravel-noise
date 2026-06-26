<?php

declare(strict_types=1);

namespace LaravelAudit\Analyzers\CodeQuality;

use LaravelAudit\Analysis\AnalysisContext;
use LaravelAudit\Analysis\AnalyzerInterface;
use LaravelAudit\Analysis\Category;
use LaravelAudit\Analysis\Issue;
use LaravelAudit\Analysis\Severity;
use LaravelAudit\Analyzers\BaseAnalyzer;
use PhpParser\Node;
use PhpParser\NodeFinder;

final class RedundantConfigFallbackAnalyzer extends BaseAnalyzer implements AnalyzerInterface
{
    public function id(): string
    {
        return 'code-quality.redundant-config-fallback';
    }

    public function category(): Category
    {
        return Category::CodeQuality;
    }

    /**
     * @return list<Issue>
     */
    public function analyze(AnalysisContext $context): array
    {
        $issues = [];
        $finder = new NodeFinder;

        foreach ($context->project->phpFiles as $file) {
            /** @var list<Node\Expr\BinaryOp\Coalesce> $coalesces */
            $coalesces = $finder->findInstanceOf($file->ast, Node\Expr\BinaryOp\Coalesce::class);

            foreach ($coalesces as $coalesce) {
                if ($this->singleArgumentConfigCall($coalesce->left) === null) {
                    continue;
                }

                $issues[] = $this->issue(
                    $this->id(),
                    $this->category(),
                    Severity::Info,
                    'Redundant config fallback candidate',
                    'config() is followed by ?? even though Laravel config() already accepts a default value.',
                    $file,
                    $coalesce->getStartLine(),
                    'Use config(\'key\', $default) instead of config(\'key\') ?? $default.',
                );
            }
        }

        return $issues;
    }

    private function singleArgumentConfigCall(Node\Expr $expression): ?Node\Expr\FuncCall
    {
        if (! $expression instanceof Node\Expr\FuncCall || ! $this->isFunctionCallNamed($expression, 'config')) {
            return null;
        }

        if (count($expression->args) !== 1) {
            return null;
        }

        return $expression;
    }

    private function isFunctionCallNamed(Node\Expr\FuncCall $expression, string $name): bool
    {
        return $expression->name instanceof Node\Name
            && strtolower($expression->name->toString()) === $name;
    }
}
