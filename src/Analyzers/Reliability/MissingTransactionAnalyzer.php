<?php

declare(strict_types=1);

namespace LaravelAudit\Analyzers\Reliability;

use LaravelAudit\Analysis\AnalysisContext;
use LaravelAudit\Analysis\AnalyzerInterface;
use LaravelAudit\Analysis\Category;
use LaravelAudit\Analysis\Issue;
use LaravelAudit\Analysis\Severity;
use LaravelAudit\Analyzers\BaseAnalyzer;
use LaravelAudit\Project\PhpFile;
use PhpParser\Node;
use PhpParser\NodeFinder;

final class MissingTransactionAnalyzer extends BaseAnalyzer implements AnalyzerInterface
{
    /**
     * @var list<string>
     */
    private const WRITE_METHODS = ['save', 'delete', 'update', 'create', 'forceCreate', 'forceDelete'];

    /**
     * @var list<string>
     */
    private const STATIC_WRITE_METHODS = ['create', 'update', 'destroy', 'insert'];

    public function id(): string
    {
        return 'reliability.missing-transaction';
    }

    public function category(): Category
    {
        return Category::Reliability;
    }

    /**
     * @return list<Issue>
     */
    public function analyze(AnalysisContext $context): array
    {
        $issues = [];

        foreach ($context->project->phpFiles as $file) {
            if (! $this->isActionFile($file)) {
                continue;
            }

            $finder = new NodeFinder;

            /** @var list<Node\Stmt\ClassMethod> $methods */
            $methods = $finder->findInstanceOf($file->ast, Node\Stmt\ClassMethod::class);

            foreach ($methods as $method) {
                if ($method->getStmts() === null) {
                    continue;
                }

                $writeCount = $this->countWriteOperations($finder, $method->getStmts());

                if ($writeCount < 2) {
                    continue;
                }

                if ($this->usesDatabaseTransaction($finder, $method->getStmts())) {
                    continue;
                }

                $issues[] = $this->issue(
                    $this->id(),
                    $this->category(),
                    Severity::Warning,
                    'Multiple writes without visible transaction',
                    'This method performs multiple database writes without wrapping them in a transaction.',
                    $file,
                    $method->getStartLine(),
                    'Wrap related writes in DB::transaction() so partial persistence does not leave inconsistent state.',
                );
            }
        }

        return $issues;
    }

    private function isActionFile(PhpFile $file): bool
    {
        return str_contains($file->relativePath, 'Http/Controllers')
            || str_contains($file->relativePath, 'Http\\Controllers')
            || str_contains($file->relativePath, 'Actions/')
            || str_contains($file->relativePath, 'Actions\\');
    }

    /**
     * @param  list<Node\Stmt>  $statements
     */
    private function countWriteOperations(NodeFinder $finder, array $statements): int
    {
        $count = 0;

        foreach ($finder->findInstanceOf($statements, Node\Expr\MethodCall::class) as $call) {
            if (! $call->name instanceof Node\Identifier) {
                continue;
            }

            if (! in_array(strtolower($call->name->toString()), self::WRITE_METHODS, true)) {
                continue;
            }

            if (strtolower($call->name->toString()) === 'delete' && $this->isFilesystemOperation($call)) {
                continue;
            }

            $count++;
        }

        foreach ($finder->findInstanceOf($statements, Node\Expr\StaticCall::class) as $call) {
            if ($call->name instanceof Node\Identifier && in_array(strtolower($call->name->toString()), self::STATIC_WRITE_METHODS, true)) {
                $count++;
            }
        }

        return $count;
    }

    private function isFilesystemOperation(Node\Expr\MethodCall $call): bool
    {
        return $this->isFilesystemReceiver($call->var);
    }

    private function isFilesystemReceiver(Node\Expr $expression): bool
    {
        if ($expression instanceof Node\Expr\StaticCall && $expression->class instanceof Node\Name) {
            $class = strtolower($expression->class->getLast());

            return in_array($class, ['storage', 'file'], true);
        }

        if ($expression instanceof Node\Expr\MethodCall
            && $expression->name instanceof Node\Identifier
            && strtolower($expression->name->toString()) === 'disk') {
            return true;
        }

        if ($expression instanceof Node\Expr\MethodCall) {
            return $this->isFilesystemReceiver($expression->var);
        }

        return false;
    }

    /**
     * @param  list<Node\Stmt>  $statements
     */
    private function usesDatabaseTransaction(NodeFinder $finder, array $statements): bool
    {
        foreach ($finder->findInstanceOf($statements, Node\Expr\StaticCall::class) as $call) {
            if ($call->class instanceof Node\Name
                && strtoupper($call->class->toString()) === 'DB'
                && $call->name instanceof Node\Identifier
                && strtolower($call->name->toString()) === 'transaction') {
                return true;
            }
        }

        foreach ($finder->findInstanceOf($statements, Node\Expr\MethodCall::class) as $call) {
            if ($call->name instanceof Node\Identifier && strtolower($call->name->toString()) === 'transaction') {
                return true;
            }
        }

        return false;
    }
}
