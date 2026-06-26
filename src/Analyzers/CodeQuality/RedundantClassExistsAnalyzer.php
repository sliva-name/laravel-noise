<?php

declare(strict_types=1);

namespace LaravelAudit\Analyzers\CodeQuality;

use LaravelAudit\Analysis\AnalysisContext;
use LaravelAudit\Analysis\AnalyzerInterface;
use LaravelAudit\Analysis\Category;
use LaravelAudit\Analysis\Issue;
use LaravelAudit\Analysis\Severity;
use LaravelAudit\Analyzers\BaseAnalyzer;
use LaravelAudit\Analyzers\CodeQuality\Support\TypedParameterInspector;
use LaravelAudit\Project\PhpFile;
use PhpParser\Node;
use PhpParser\NodeFinder;

final class RedundantClassExistsAnalyzer extends BaseAnalyzer implements AnalyzerInterface
{
    /**
     * @var array<string, true>
     */
    private array $projectClasses = [];

    public function __construct(
        private readonly TypedParameterInspector $typedParameterInspector = new TypedParameterInspector,
    ) {}

    public function id(): string
    {
        return 'code-quality.redundant-class-exists';
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
        $this->projectClasses = $this->projectClassIndex($context);

        foreach ($context->project->phpFiles as $file) {
            $namespace = $this->namespaceName($file->ast);
            $imports = $this->useImports($file->ast);

            /** @var list<Node\Expr\FuncCall> $calls */
            $calls = $finder->findInstanceOf($file->ast, Node\Expr\FuncCall::class);

            foreach ($calls as $call) {
                $issue = $this->redundantClassExistsIssue($file, $call, $namespace, $imports);

                if ($issue !== null) {
                    $issues[] = $issue;
                }
            }
        }

        return $issues;
    }

    /**
     * @return array<string, true>
     */
    private function projectClassIndex(AnalysisContext $context): array
    {
        $classes = [];

        foreach ($context->project->phpFiles as $file) {
            $namespace = $this->namespaceName($file->ast);

            foreach ($file->classes as $class) {
                $classes[$this->typedParameterInspector->resolveClassName(
                    new Node\Name($class),
                    $namespace,
                )] = true;
            }
        }

        return $classes;
    }

    /**
     * @param  array<string, string>  $imports
     */
    private function redundantClassExistsIssue(
        PhpFile $file,
        Node\Expr\FuncCall $call,
        ?string $namespace,
        array $imports,
    ): ?Issue {
        if (! $this->isFunctionCallNamed($call, 'class_exists')) {
            return null;
        }

        $className = $this->classConstFetchName($call->args[0]->value ?? null, $namespace, $imports);

        if ($className === null || ! isset($this->projectClasses[$className])) {
            return null;
        }

        return $this->issue(
            $this->id(),
            $this->category(),
            Severity::Info,
            'Redundant class_exists candidate',
            "class_exists() guards an application class ({$className}) that is already part of the scanned Laravel project.",
            $file,
            $call->getStartLine(),
            'Remove the guard and rely on Composer autoloading or constructor dependency injection.',
        );
    }

    /**
     * @param  array<string, string>  $imports
     */
    private function classConstFetchName(?Node\Expr $expression, ?string $namespace, array $imports): ?string
    {
        if (! $expression instanceof Node\Expr\ClassConstFetch) {
            return null;
        }

        if (! $expression->class instanceof Node\Name) {
            return null;
        }

        if (! $expression->name instanceof Node\Identifier || strtolower($expression->name->toString()) !== 'class') {
            return null;
        }

        if ($expression->class->isFullyQualified()) {
            return strtolower(ltrim($expression->class->toString(), '\\'));
        }

        $shortName = strtolower($expression->class->toString());

        if (isset($imports[$shortName])) {
            return $imports[$shortName];
        }

        return $this->typedParameterInspector->resolveClassName($expression->class, $namespace);
    }

    /**
     * @param  list<Node>  $statements
     * @return array<string, string>
     */
    private function useImports(array $statements): array
    {
        $imports = [];

        foreach ($statements as $statement) {
            if ($statement instanceof Node\Stmt\Namespace_) {
                return [
                    ...$imports,
                    ...$this->useImports($statement->stmts),
                ];
            }

            if (! $statement instanceof Node\Stmt\Use_) {
                continue;
            }

            foreach ($statement->uses as $use) {
                $alias = strtolower($use->alias?->toString() ?? $use->name->getLast());
                $imports[$alias] = strtolower($use->name->toString());
            }
        }

        return $imports;
    }

    /**
     * @param  list<Node>  $statements
     */
    private function namespaceName(array $statements): ?string
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Node\Stmt\Namespace_) {
                return $statement->name?->toString();
            }
        }

        return null;
    }

    private function isFunctionCallNamed(Node\Expr\FuncCall $expression, string $name): bool
    {
        return $expression->name instanceof Node\Name
            && strtolower($expression->name->toString()) === $name;
    }
}
