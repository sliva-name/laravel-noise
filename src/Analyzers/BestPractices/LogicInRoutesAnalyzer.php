<?php

declare(strict_types=1);

namespace LaravelAudit\Analyzers\BestPractices;

use LaravelAudit\Analysis\AnalysisContext;
use LaravelAudit\Analysis\AnalyzerInterface;
use LaravelAudit\Analysis\Category;
use LaravelAudit\Analysis\Issue;
use LaravelAudit\Analysis\Severity;
use LaravelAudit\Analyzers\BaseAnalyzer;
use PhpParser\Node;
use PhpParser\NodeFinder;

final class LogicInRoutesAnalyzer extends BaseAnalyzer implements AnalyzerInterface
{
    private const MIN_CLOSURE_LINES = 15;

    public function id(): string
    {
        return 'best-practices.logic-in-routes';
    }

    public function category(): Category
    {
        return Category::BestPractices;
    }

    /**
     * @return list<Issue>
     */
    public function analyze(AnalysisContext $context): array
    {
        $issues = [];
        $finder = new NodeFinder;

        foreach ($context->project->phpFiles as $file) {
            if (! $file->inDirectory('routes')) {
                continue;
            }

            /** @var list<Node\Expr\Closure> $closures */
            $closures = $finder->findInstanceOf($file->ast, Node\Expr\Closure::class);

            foreach ($closures as $closure) {
                $length = $closure->getEndLine() - $closure->getStartLine() + 1;

                if ($length < self::MIN_CLOSURE_LINES) {
                    continue;
                }

                if ($this->isRouteRegistrationClosure($closure)) {
                    continue;
                }

                $issues[] = $this->issue(
                    $this->id(),
                    $this->category(),
                    Severity::Warning,
                    'Business logic in route closure',
                    "Route closure spans {$length} lines and likely mixes routing with application logic.",
                    $file,
                    $closure->getStartLine(),
                    'Move the workflow into a controller action or invokable class to preserve MVC separation.',
                );
            }
        }

        return $issues;
    }

    private function isRouteRegistrationClosure(Node\Expr\Closure $closure): bool
    {
        $statements = $closure->stmts ?? [];

        if ($statements === []) {
            return true;
        }

        foreach ($statements as $statement) {
            if (! $this->isRouteRegistrationStatement($statement)) {
                return false;
            }
        }

        return true;
    }

    private function isRouteRegistrationStatement(Node\Stmt $statement): bool
    {
        if (! $statement instanceof Node\Stmt\Expression) {
            return false;
        }

        return $this->isRouteRegistrationExpression($statement->expr);
    }

    private function isRouteRegistrationExpression(Node\Expr $expression): bool
    {
        if ($expression instanceof Node\Expr\StaticCall) {
            return $this->isRouteClass($expression->class);
        }

        if ($expression instanceof Node\Expr\MethodCall) {
            return $this->expressionOriginatesFromRoute($expression);
        }

        return false;
    }

    private function expressionOriginatesFromRoute(Node\Expr\MethodCall $call): bool
    {
        $current = $call->var;

        while ($current instanceof Node\Expr\MethodCall) {
            if ($this->isRouteRegistrationExpression($current)) {
                return true;
            }

            $current = $current->var;
        }

        return $this->isRouteRegistrationExpression($current);
    }

    private function isRouteClass(?Node\Name $class): bool
    {
        if (! $class instanceof Node\Name) {
            return false;
        }

        return strcasecmp($class->getLast(), 'Route') === 0;
    }
}
