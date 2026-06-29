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
use PhpParser\Node;
use PhpParser\NodeFinder;

final class RawSqlAnalyzer extends BaseAnalyzer implements AnalyzerInterface
{
    /**
     * @var list<string>
     */
    private const RAW_METHODS = [
        'selectraw',
        'whereraw',
        'orwhereraw',
        'havingraw',
        'orderbyraw',
        'groupbyraw',
    ];

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
        $finder = new NodeFinder;

        foreach ($context->project->phpFiles as $file) {
            if ($this->isMigrationFile($file)) {
                continue;
            }

            foreach ($this->findRawSqlCalls($finder, $file) as $call) {
                $dynamic = $this->usesDynamicSql($call['node']);

                $issues[] = $this->issue(
                    $this->id(),
                    $this->category(),
                    $dynamic ? Severity::Critical : Severity::Warning,
                    $dynamic ? 'Raw SQL usage requires review' : 'Static raw SQL usage',
                    $dynamic
                        ? 'Raw SQL can bypass query binding and increase SQL injection risk when user input is interpolated.'
                        : 'This statement uses a fixed raw SQL fragment. Review it, but it does not appear to interpolate runtime input.',
                    $file,
                    $call['line'],
                    $dynamic
                        ? 'Prefer query builder bindings, parameterized expressions, or narrowly review this raw SQL statement.'
                        : 'Prefer query builder helpers when possible, or document why this static raw SQL fragment is required.',
                );
            }
        }

        return $issues;
    }

    /**
     * @return list<array{node: Node\Expr, line: int}>
     */
    private function findRawSqlCalls(NodeFinder $finder, PhpFile $file): array
    {
        $calls = [];

        foreach ($finder->findInstanceOf($file->ast, Node\Expr\StaticCall::class) as $call) {
            if (! $call->class instanceof Node\Name || strtoupper($call->class->toString()) !== 'DB') {
                continue;
            }

            if (! $call->name instanceof Node\Identifier) {
                continue;
            }

            $method = strtolower($call->name->toString());

            if (in_array($method, ['raw', 'statement', 'unprepared'], true)) {
                $calls[] = ['node' => $call, 'line' => $call->getStartLine()];
            }
        }

        foreach ($finder->findInstanceOf($file->ast, Node\Expr\MethodCall::class) as $call) {
            if (! $call->name instanceof Node\Identifier) {
                continue;
            }

            if (in_array(strtolower($call->name->toString()), self::RAW_METHODS, true)) {
                $calls[] = ['node' => $call, 'line' => $call->getStartLine()];
            }
        }

        return $calls;
    }

    private function usesDynamicSql(Node\Expr $call): bool
    {
        $argument = $this->sqlArgument($call);

        if ($argument === null) {
            return true;
        }

        return $this->expressionContainsRuntimeValue($argument);
    }

    private function sqlArgument(Node\Expr $call): ?Node\Expr
    {
        if ($call instanceof Node\Expr\StaticCall || $call instanceof Node\Expr\MethodCall) {
            return $call->args[0]->value ?? null;
        }

        return null;
    }

    private function expressionContainsRuntimeValue(Node\Expr $expression): bool
    {
        if ($expression instanceof Node\Scalar\String_) {
            return false;
        }

        if ($expression instanceof Node\Expr\Variable
            || $expression instanceof Node\Expr\FuncCall
            || $expression instanceof Node\Expr\MethodCall
            || $expression instanceof Node\Expr\StaticCall
            || $expression instanceof Node\Expr\PropertyFetch) {
            return true;
        }

        if ($expression instanceof Node\Expr\BinaryOp\Concat
            || $expression instanceof Node\Scalar\Encapsed
            || $expression instanceof Node\Expr\Cast) {
            return true;
        }

        if ($expression instanceof Node\Expr\Array_) {
            foreach ($expression->items as $item) {
                if ($this->expressionContainsRuntimeValue($item->value)) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    private function isMigrationFile(PhpFile $file): bool
    {
        return str_starts_with($file->relativePath, 'database/migrations/');
    }
}
