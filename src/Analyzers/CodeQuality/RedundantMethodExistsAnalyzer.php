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

final class RedundantMethodExistsAnalyzer extends BaseAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly TypedParameterInspector $typedParameterInspector = new TypedParameterInspector,
    ) {}

    public function id(): string
    {
        return 'code-quality.redundant-method-exists';
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
            /** @var list<Node\FunctionLike> $functions */
            $functions = $finder->find($file->ast, fn (Node $node): bool => $node instanceof Node\FunctionLike);

            foreach ($functions as $function) {
                if ($function->getStmts() === null) {
                    continue;
                }

                $typedParameters = $this->typedParameterInspector->nonNullableParameterTypes($function);

                /** @var list<Node\Expr\FuncCall> $calls */
                $calls = $finder->findInstanceOf($function->getStmts(), Node\Expr\FuncCall::class);

                foreach ($calls as $call) {
                    $issue = $this->redundantMethodExistsIssue($file, $call, $typedParameters);

                    if ($issue !== null) {
                        $issues[] = $issue;
                    }
                }
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, string>  $typedParameters
     */
    private function redundantMethodExistsIssue(
        PhpFile $file,
        Node\Expr\FuncCall $call,
        array $typedParameters,
    ): ?Issue {
        if (! $this->isFunctionCallNamed($call, 'method_exists')) {
            return null;
        }

        $objectVariable = $this->variableName($call->args[0]->value ?? null);
        $methodName = $this->stringLiteral($call->args[1]->value ?? null);

        if ($objectVariable === null || $methodName === null || ! isset($typedParameters[$objectVariable])) {
            return null;
        }

        $type = $typedParameters[$objectVariable];

        if (in_array($type, ['array', 'string', 'int', 'float', 'bool', 'callable', 'iterable', 'object', 'resource', 'null', 'mixed'], true)) {
            return null;
        }

        return $this->issue(
            $this->id(),
            $this->category(),
            Severity::Info,
            'Redundant method_exists candidate',
            "Parameter \${$objectVariable} is typed as {$type}, but method_exists() is still used for '{$methodName}'.",
            $file,
            $call->getStartLine(),
            'Rely on the typed contract or extract an interface instead of runtime method_exists() checks.',
        );
    }

    private function isFunctionCallNamed(Node\Expr\FuncCall $expression, string $name): bool
    {
        return $expression->name instanceof Node\Name
            && strtolower($expression->name->toString()) === $name;
    }

    private function variableName(?Node\Expr $expression): ?string
    {
        if (! $expression instanceof Node\Expr\Variable || ! is_string($expression->name)) {
            return null;
        }

        return $expression->name;
    }

    private function stringLiteral(?Node\Expr $expression): ?string
    {
        return $expression instanceof Node\Scalar\String_ ? $expression->value : null;
    }
}
