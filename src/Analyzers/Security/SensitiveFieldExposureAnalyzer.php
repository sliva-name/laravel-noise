<?php

declare(strict_types=1);

namespace LaravelAudit\Analyzers\Security;

use LaravelAudit\Analysis\AnalysisContext;
use LaravelAudit\Analysis\AnalyzerInterface;
use LaravelAudit\Analysis\Category;
use LaravelAudit\Analysis\Issue;
use LaravelAudit\Analysis\Severity;
use LaravelAudit\Analyzers\BaseAnalyzer;
use LaravelAudit\Analyzers\Support\EloquentModelSerializationReader;
use LaravelAudit\Project\PhpFile;
use PhpParser\Node;
use PhpParser\NodeFinder;

final class SensitiveFieldExposureAnalyzer extends BaseAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private readonly EloquentModelSerializationReader $serializationReader = new EloquentModelSerializationReader,
    ) {}

    public function id(): string
    {
        return 'security.sensitive-field-exposure';
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
        /** @var array<string, list<string>> $modelsWithSensitiveFields */
        $modelsWithSensitiveFields = [];

        foreach ($context->project->models() as $file) {
            $className = $this->modelClassName($file);

            if ($className === null) {
                continue;
            }

            $columns = $this->serializationReader->unhiddenSensitiveColumns($file);

            if ($columns !== []) {
                $modelsWithSensitiveFields[$className] = $columns;
            }
        }

        if ($modelsWithSensitiveFields === []) {
            return [];
        }

        $issues = [];

        foreach ($context->project->controllers() as $file) {
            $issues = array_merge(
                $issues,
                $this->analyzeController($file, $modelsWithSensitiveFields),
            );
        }

        return $issues;
    }

    /**
     * @param  array<string, list<string>>  $modelsWithSensitiveFields
     * @return list<Issue>
     */
    private function analyzeController(PhpFile $file, array $modelsWithSensitiveFields): array
    {
        $issues = [];
        $finder = new NodeFinder;

        /** @var list<Node\Stmt\ClassMethod> $methods */
        $methods = $finder->findInstanceOf($file->ast, Node\Stmt\ClassMethod::class);

        foreach ($methods as $method) {
            if ($method->getStmts() === null) {
                continue;
            }

            $variableTypes = $this->variableTypes($finder, $method);

            foreach ($this->exposures($finder, $method->getStmts()) as $exposure) {
                foreach ($exposure['variables'] as $variable) {
                    $modelClass = $variableTypes[$variable] ?? null;

                    if ($modelClass === null || ! isset($modelsWithSensitiveFields[$modelClass])) {
                        continue;
                    }

                    $columns = implode(', ', $modelsWithSensitiveFields[$modelClass]);

                    $issues[] = $this->issue(
                        $this->id(),
                        $this->category(),
                        Severity::Error,
                        'Sensitive model fields may be exposed in response',
                        "This response passes a {$modelClass} instance without an API resource while the model still exposes sensitive columns ({$columns}).",
                        $file,
                        $exposure['line'],
                        'Hide the fields with $hidden or #[Hidden], or return an API Resource / Inertia DTO instead of the raw model.',
                    );
                }
            }
        }

        return $issues;
    }

    private function modelClassName(PhpFile $file): ?string
    {
        $finder = new NodeFinder;

        /** @var list<Node\Stmt\Class_> $classes */
        $classes = $finder->findInstanceOf($file->ast, Node\Stmt\Class_::class);

        return $classes[0]->name?->toString() ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function variableTypes(NodeFinder $finder, Node\Stmt\ClassMethod $method): array
    {
        $types = [];

        foreach ($method->params as $parameter) {
            if (! $parameter->var instanceof Node\Expr\Variable || ! is_string($parameter->var->name)) {
                continue;
            }

            $type = $this->shortClassName($parameter->type);

            if ($type !== null) {
                $types[$parameter->var->name] = $type;
            }
        }

        foreach ($finder->findInstanceOf($method->getStmts() ?? [], Node\Expr\Assign::class) as $assignment) {
            if (! $assignment->var instanceof Node\Expr\Variable || ! is_string($assignment->var->name)) {
                continue;
            }

            $type = $this->modelTypeFromExpression($assignment->expr);

            if ($type !== null) {
                $types[$assignment->var->name] = $type;
            }
        }

        return $types;
    }

    /**
     * @param  list<Node\Stmt>  $statements
     * @return list<array{line: int, variables: list<string>}>
     */
    private function exposures(NodeFinder $finder, array $statements): array
    {
        $exposures = [];

        foreach ($finder->findInstanceOf($statements, Node\Stmt\Return_::class) as $return) {
            if ($return->expr === null || $this->isResourceWrapped($return->expr)) {
                continue;
            }

            $variables = $this->extractVariables($return->expr);

            if ($variables !== []) {
                $exposures[] = [
                    'line' => $return->getStartLine(),
                    'variables' => $variables,
                ];
            }
        }

        foreach ($finder->findInstanceOf($statements, Node\Expr\StaticCall::class) as $call) {
            if (! $this->isInertiaRenderCall($call)) {
                continue;
            }

            $props = $call->args[1]->value ?? null;

            if (! $props instanceof Node\Expr\Array_) {
                continue;
            }

            foreach ($props->items as $item) {
                if ($this->isResourceWrapped($item->value)) {
                    continue;
                }

                $variables = $this->extractVariables($item->value);

                if ($variables === []) {
                    continue;
                }

                $exposures[] = [
                    'line' => $call->getStartLine(),
                    'variables' => $variables,
                ];
            }
        }

        foreach ($finder->findInstanceOf($statements, Node\Expr\MethodCall::class) as $call) {
            if (! $this->isJsonResponseCall($call)) {
                continue;
            }

            $payload = $call->args[0]->value ?? null;

            if ($payload === null || $this->isResourceWrapped($payload)) {
                continue;
            }

            $variables = $this->extractVariables($payload);

            if ($variables !== []) {
                $exposures[] = [
                    'line' => $call->getStartLine(),
                    'variables' => $variables,
                ];
            }
        }

        return $exposures;
    }

    /**
     * @return list<string>
     */
    private function extractVariables(Node\Expr $expression): array
    {
        if ($expression instanceof Node\Expr\Variable && is_string($expression->name)) {
            return [$expression->name];
        }

        return [];
    }

    private function modelTypeFromExpression(Node\Expr $expression): ?string
    {
        if ($expression instanceof Node\Expr\StaticCall && $expression->class instanceof Node\Name) {
            return $expression->class->getLast();
        }

        return null;
    }

    private function shortClassName(?Node $type): ?string
    {
        if ($type instanceof Node\Name) {
            return $type->getLast();
        }

        if ($type instanceof Node\NullableType) {
            return $this->shortClassName($type->type);
        }

        return null;
    }

    private function isInertiaRenderCall(Node\Expr\StaticCall $call): bool
    {
        return $call->class instanceof Node\Name
            && strcasecmp($call->class->getLast(), 'Inertia') === 0
            && $call->name instanceof Node\Identifier
            && strcasecmp($call->name->toString(), 'render') === 0;
    }

    private function isJsonResponseCall(Node\Expr\MethodCall $call): bool
    {
        return $call->name instanceof Node\Identifier
            && strcasecmp($call->name->toString(), 'json') === 0;
    }

    private function isResourceWrapped(Node\Expr $expression): bool
    {
        if ($expression instanceof Node\Expr\New_
            && $expression->class instanceof Node\Name
            && str_contains($expression->class->toString(), 'Resource')) {
            return true;
        }

        if ($expression instanceof Node\Expr\StaticCall
            && $expression->class instanceof Node\Name
            && str_contains($expression->class->toString(), 'Resource')
            && $expression->name instanceof Node\Identifier
            && in_array(strtolower($expression->name->toString()), ['make', 'collection'], true)) {
            return true;
        }

        return false;
    }
}
