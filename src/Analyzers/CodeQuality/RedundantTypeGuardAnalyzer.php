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

final class RedundantTypeGuardAnalyzer extends BaseAnalyzer implements AnalyzerInterface
{
    private const SOURCE_ARRAY_FALLBACK = 'array_fallback';

    private const SOURCE_JSON_DECODE = 'json_decode';

    private const SOURCE_TYPED_ARRAY = 'typed_array';

    public function __construct(
        private readonly TypedParameterInspector $typedParameterInspector = new TypedParameterInspector,
    ) {}

    public function id(): string
    {
        return 'code-quality.redundant-type-guard';
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
            $namespace = $this->namespaceName($file->ast);

            $issues = [
                ...$issues,
                ...$this->analyzeStatements($file, $file->ast, [], [], $namespace),
            ];

            /** @var list<Node\Expr\Ternary> $ternaries */
            $ternaries = $finder->findInstanceOf($file->ast, Node\Expr\Ternary::class);

            foreach ($ternaries as $ternary) {
                $issue = $this->redundantCoalesceGuardTernaryIssue($file, $ternary);

                if ($issue !== null) {
                    $issues[] = $issue;
                }
            }
        }

        return $issues;
    }

    /**
     * @param  list<Node>  $statements
     * @param  array<string, string>  $variableSources
     * @param  array<string, string>  $typedParameters
     * @return list<Issue>
     */
    private function analyzeStatements(
        PhpFile $file,
        array $statements,
        array $variableSources = [],
        array $typedParameters = [],
        ?string $namespace = null,
    ): array {
        $issues = [];

        foreach ($statements as $statement) {
            if ($statement instanceof Node\Stmt\Expression && $statement->expr instanceof Node\Expr\Assign) {
                $this->trackAssignment($statement->expr, $variableSources);
            }

            if ($statement instanceof Node\Stmt\If_) {
                foreach ($this->redundantGuardIssues($file, $statement, $variableSources, $typedParameters, $namespace) as $issue) {
                    $issues[] = $issue;
                }

                $issues = [
                    ...$issues,
                    ...$this->analyzeStatements($file, $statement->stmts, $variableSources, $typedParameters, $namespace),
                ];

                foreach ($statement->elseifs as $elseif) {
                    $issues = [
                        ...$issues,
                        ...$this->analyzeStatements($file, $elseif->stmts, $variableSources, $typedParameters, $namespace),
                    ];
                }

                if ($statement->else !== null) {
                    $issues = [
                        ...$issues,
                        ...$this->analyzeStatements($file, $statement->else->stmts, $variableSources, $typedParameters, $namespace),
                    ];
                }
            }

            if ($statement instanceof Node\Stmt\Return_) {
                $issue = $this->redundantJsonDecodeTernaryIssue($file, $statement->expr, $variableSources);

                if ($issue !== null) {
                    $issues[] = $issue;
                }
            }

            if ($statement instanceof Node\Stmt\Namespace_) {
                $issues = [
                    ...$issues,
                    ...$this->analyzeStatements(
                        $file,
                        $statement->stmts,
                        [],
                        [],
                        $statement->name?->toString() ?? $namespace,
                    ),
                ];
            }

            if ($statement instanceof Node\Stmt\ClassLike) {
                $issues = [
                    ...$issues,
                    ...$this->analyzeStatements($file, $statement->stmts ?? [], [], [], $namespace),
                ];
            }

            if ($statement instanceof Node\Stmt\ClassMethod || $statement instanceof Node\Stmt\Function_) {
                $typedParameters = $this->typedParameterInspector->nonNullableParameterTypes($statement);

                $issues = [
                    ...$issues,
                    ...$this->analyzeStatements(
                        $file,
                        $statement->stmts ?? [],
                        $this->typedArrayParameterSources($typedParameters),
                        $typedParameters,
                        $namespace,
                    ),
                ];
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, string>  $variableSources
     */
    private function trackAssignment(Node\Expr\Assign $assign, array &$variableSources): void
    {
        if (! $assign->var instanceof Node\Expr\Variable || ! is_string($assign->var->name)) {
            return;
        }

        if ($this->hasArrayFallback($assign->expr)) {
            $variableSources[$assign->var->name] = self::SOURCE_ARRAY_FALLBACK;

            return;
        }

        if ($this->isJsonDecodeAssociative($assign->expr)) {
            $variableSources[$assign->var->name] = self::SOURCE_JSON_DECODE;

            return;
        }

        unset($variableSources[$assign->var->name]);
    }

    /**
     * @param  array<string, string>  $typedParameters
     * @return array<string, string>
     */
    private function typedArrayParameterSources(array $typedParameters): array
    {
        $sources = [];

        foreach ($typedParameters as $variable => $type) {
            if ($type === 'array') {
                $sources[$variable] = self::SOURCE_TYPED_ARRAY;
            }
        }

        return $sources;
    }

    /**
     * @param  array<string, string>  $variableSources
     * @param  array<string, string>  $typedParameters
     * @return list<Issue>
     */
    private function redundantGuardIssues(
        PhpFile $file,
        Node\Stmt\If_ $if,
        array $variableSources,
        array $typedParameters,
        ?string $namespace,
    ): array {
        $issues = [];

        $illogicalOrIssue = $this->illogicalOrGuardIssue($file, $if->cond);

        if ($illogicalOrIssue !== null) {
            $issues[] = $illogicalOrIssue;
        }

        $coalesceGuardIssue = $this->redundantCoalesceGuardIfIssue($file, $if);

        if ($coalesceGuardIssue !== null) {
            $issues[] = $coalesceGuardIssue;
        }

        $issetArrayGuardIssue = $this->redundantIssetArrayGuardIssue($file, $if->cond, $variableSources);

        if ($issetArrayGuardIssue !== null) {
            $issues[] = $issetArrayGuardIssue;
        }

        foreach ($this->redundantStringGuardIssues($file, $if, $typedParameters) as $issue) {
            $issues[] = $issue;
        }

        foreach ($this->redundantInstanceofIssues($file, $if, $typedParameters, $namespace) as $issue) {
            $issues[] = $issue;
        }

        if ($illogicalOrIssue === null) {
            foreach ($this->redundantIsArrayVariables($if->cond, $variableSources) as $match) {
                if ($match['source'] === self::SOURCE_JSON_DECODE && $this->ifBodyThrowsOnInvalidType($if->stmts)) {
                    continue;
                }

                $issues[] = $this->issue(
                    $this->id(),
                    $this->category(),
                    Severity::Info,
                    'Redundant type guard candidate',
                    $match['message'],
                    $file,
                    $if->getStartLine(),
                    $match['recommendation'],
                );
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, string>  $variableSources
     * @return list<array{source: string, message: string, recommendation: string}>
     */
    private function redundantIsArrayVariables(Node\Expr $condition, array $variableSources): array
    {
        $matches = [];

        foreach ($this->flattenBooleanAnd($condition) as $expression) {
            $isArrayCall = $this->unwrapBooleanNot($expression);

            if (! $isArrayCall instanceof Node\Expr\FuncCall || ! $this->isFunctionCallNamed($isArrayCall, 'is_array')) {
                continue;
            }

            $variable = $this->firstArgumentVariableName($isArrayCall);

            if ($variable === null || ! isset($variableSources[$variable])) {
                continue;
            }

            $source = $variableSources[$variable];
            $hasArrayKeyExists = $this->conditionUsesArrayKeyExistsOn($condition, $variable);

            if ($source === self::SOURCE_ARRAY_FALLBACK) {
                if ($hasArrayKeyExists) {
                    $matches[] = [
                        'source' => $source,
                        'message' => "Variable \${$variable} is assigned with an array fallback and then guarded with is_array() before array_key_exists().",
                        'recommendation' => 'Remove the type guard when the surrounding contract already guarantees an array.',
                    ];

                    continue;
                }

                $matches[] = [
                    'source' => $source,
                    'message' => "Variable \${$variable} is assigned with an array fallback and then checked again with is_array().",
                    'recommendation' => 'Remove the redundant is_array() guard or normalize the value once with ?? [].',
                ];

                continue;
            }

            if ($source === self::SOURCE_JSON_DECODE) {
                $matches[] = [
                    'source' => $source,
                    'message' => "Variable \${$variable} comes from json_decode(..., true) and is checked again with is_array().",
                    'recommendation' => 'Prefer ?? [] or an explicit exception on invalid JSON instead of a redundant is_array() guard.',
                ];

                continue;
            }

            if ($source === self::SOURCE_TYPED_ARRAY) {
                $matches[] = [
                    'source' => $source,
                    'message' => "Parameter \${$variable} is already typed as array, but is still checked with is_array().",
                    'recommendation' => 'Remove the redundant guard and rely on the parameter type contract.',
                ];
            }
        }

        return $matches;
    }

    /**
     * @param  array<string, string>  $variableSources
     */
    private function redundantIssetArrayGuardIssue(
        PhpFile $file,
        Node\Expr $condition,
        array $variableSources,
    ): ?Issue {
        $issetVariables = [];
        $isArrayVariables = [];

        foreach ($this->flattenBooleanAnd($condition) as $expression) {
            if ($expression instanceof Node\Expr\Isset_) {
                foreach ($expression->vars as $var) {
                    $variable = $this->variableName($var);

                    if ($variable !== null) {
                        $issetVariables[$variable] = true;
                    }
                }
            }

            $isArrayCall = $this->unwrapBooleanNot($expression);

            if (! $isArrayCall instanceof Node\Expr\FuncCall || ! $this->isFunctionCallNamed($isArrayCall, 'is_array')) {
                continue;
            }

            $variable = $this->firstArgumentVariableName($isArrayCall);

            if ($variable !== null && isset($variableSources[$variable])) {
                $isArrayVariables[$variable] = true;
            }
        }

        foreach (array_keys($issetVariables) as $variable) {
            if (! isset($isArrayVariables[$variable])) {
                continue;
            }

            return $this->issue(
                $this->id(),
                $this->category(),
                Severity::Info,
                'Redundant type guard candidate',
                "Variable \${$variable} is checked with isset() and is_array() even though the value is already normalized to an array.",
                $file,
                $condition->getStartLine(),
                'Remove the duplicate guards and keep a single ?? [] normalization or array_key_exists() check.',
            );
        }

        return null;
    }

    /**
     * @param  array<string, string>  $typedParameters
     * @return list<Issue>
     */
    private function redundantStringGuardIssues(
        PhpFile $file,
        Node\Stmt\If_ $if,
        array $typedParameters,
    ): array {
        $issues = [];

        foreach ($this->flattenBooleanAnd($if->cond) as $expression) {
            if (! $expression instanceof Node\Expr\FuncCall || ! $this->isFunctionCallNamed($expression, 'is_string')) {
                continue;
            }

            $variable = $this->firstArgumentVariableName($expression);

            if ($variable === null || ($typedParameters[$variable] ?? null) !== 'string') {
                continue;
            }

            $issues[] = $this->issue(
                $this->id(),
                $this->category(),
                Severity::Info,
                'Redundant type guard candidate',
                "Parameter \${$variable} is already typed as string, but is still checked with is_string().",
                $file,
                $if->getStartLine(),
                'Remove is_string() and keep only the empty/non-empty check if needed.',
            );
        }

        return $issues;
    }

    /**
     * @param  array<string, string>  $typedParameters
     * @return list<Issue>
     */
    private function redundantInstanceofIssues(
        PhpFile $file,
        Node\Stmt\If_ $if,
        array $typedParameters,
        ?string $namespace,
    ): array {
        $issues = [];

        foreach ($this->flattenBooleanAnd($if->cond) as $expression) {
            if (! $expression instanceof Node\Expr\Instanceof_) {
                continue;
            }

            $variable = $this->variableName($expression->expr);

            if ($variable === null || ! isset($typedParameters[$variable])) {
                continue;
            }

            $parameterType = $typedParameters[$variable];

            if (in_array($parameterType, ['array', 'string', 'int', 'float', 'bool', 'callable', 'iterable', 'object', 'resource', 'null', 'mixed'], true)) {
                continue;
            }

            if (! $expression->class instanceof Node\Name) {
                continue;
            }

            $instanceofType = $this->typedParameterInspector->resolveClassName($expression->class, $namespace);

            if (! $this->sameClassHint($parameterType, $instanceofType)) {
                continue;
            }

            $issues[] = $this->issue(
                $this->id(),
                $this->category(),
                Severity::Info,
                'Redundant type guard candidate',
                "Parameter \${$variable} is already typed as {$parameterType}, but is checked again with instanceof.",
                $file,
                $if->getStartLine(),
                'Remove the redundant instanceof guard and rely on the parameter type contract.',
            );
        }

        return $issues;
    }

    private function sameClassHint(string $parameterType, string $instanceofType): bool
    {
        if ($parameterType === $instanceofType) {
            return true;
        }

        return $this->shortClassName($parameterType) === $this->shortClassName($instanceofType);
    }

    private function shortClassName(string $class): string
    {
        $normalized = strtolower(ltrim($class, '\\'));
        $segments = explode('\\', $normalized);

        return (string) end($segments);
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

    /**
     * @param  array<string, string>  $variableSources
     */
    private function redundantJsonDecodeTernaryIssue(
        PhpFile $file,
        ?Node\Expr $expression,
        array $variableSources,
    ): ?Issue {
        if (! $expression instanceof Node\Expr\Ternary || $expression->if === null) {
            return null;
        }

        $variable = $this->isArrayVariableFromTernary($expression->cond, $variableSources);

        if ($variable === null) {
            return null;
        }

        return $this->issue(
            $this->id(),
            $this->category(),
            Severity::Info,
            'Redundant type guard candidate',
            "Variable \${$variable} comes from json_decode(..., true) and is filtered again with is_array() in a ternary.",
            $file,
            $expression->getStartLine(),
            'Return the decoded value directly or normalize it once with ?? [] or ?? null.',
        );
    }

    private function redundantCoalesceGuardTernaryIssue(PhpFile $file, Node\Expr\Ternary $ternary): ?Issue
    {
        if ($ternary->if === null || ! $this->isArrayGuardOnCoalesce($ternary->cond)) {
            return null;
        }

        return $this->issue(
            $this->id(),
            $this->category(),
            Severity::Info,
            'Redundant type guard candidate',
            'is_array() guards a null-coalesced array access that can be normalized directly with ?? [].',
            $file,
            $ternary->getStartLine(),
            'Replace the guard with a single ?? [] fallback on the array access.',
        );
    }

    private function redundantCoalesceGuardIfIssue(PhpFile $file, Node\Stmt\If_ $if): ?Issue
    {
        if (! $this->isArrayGuardOnCoalesce($if->cond)) {
            return null;
        }

        return $this->issue(
            $this->id(),
            $this->category(),
            Severity::Info,
            'Redundant type guard candidate',
            'is_array() guards a null-coalesced array access that can be normalized directly with ?? [].',
            $file,
            $if->getStartLine(),
            'Replace the guard with a single ?? [] fallback on the array access.',
        );
    }

    private function illogicalOrGuardIssue(PhpFile $file, Node\Expr $condition): ?Issue
    {
        foreach ($this->flattenBooleanOr($condition) as $expression) {
            if (! $expression instanceof Node\Expr\FuncCall || ! $this->isFunctionCallNamed($expression, 'is_array')) {
                continue;
            }

            $variable = $this->firstArgumentVariableName($expression);

            if ($variable === null) {
                continue;
            }

            foreach ($this->flattenBooleanOr($condition) as $otherExpression) {
                if ($otherExpression === $expression) {
                    continue;
                }

                if ($this->expressionReadsArrayOffset($otherExpression, $variable)) {
                    return $this->issue(
                        $this->id(),
                        $this->category(),
                        Severity::Info,
                        'Illogical type guard candidate',
                        "Condition combines is_array(\${$variable}) with || and then reads \${$variable}[...], which is unsafe when the value is not an array.",
                        $file,
                        $condition->getStartLine(),
                        'Use ! is_array($variable) || ! array_key_exists(...) or normalize the value once with ?? [].',
                    );
                }
            }
        }

        return null;
    }

    private function hasArrayFallback(Node\Expr $expression): bool
    {
        return $expression instanceof Node\Expr\Array_
            || ($expression instanceof Node\Expr\BinaryOp\Coalesce && $expression->right instanceof Node\Expr\Array_);
    }

    private function isJsonDecodeAssociative(Node\Expr $expression): bool
    {
        if (! $expression instanceof Node\Expr\FuncCall || ! $this->isFunctionCallNamed($expression, 'json_decode')) {
            return false;
        }

        $associative = $expression->args[1]->value ?? null;

        return $associative instanceof Node\Expr\ConstFetch
            && strtolower($associative->name->toString()) === 'true';
    }

    private function isArrayGuardOnCoalesce(?Node\Expr $expression): bool
    {
        if (! $expression instanceof Node\Expr\FuncCall || ! $this->isFunctionCallNamed($expression, 'is_array')) {
            return false;
        }

        $argument = $expression->args[0]->value ?? null;

        return $argument instanceof Node\Expr\BinaryOp\Coalesce
            && $argument->right instanceof Node\Expr\ConstFetch
            && strtolower($argument->right->name->toString()) === 'null'
            && $argument->left instanceof Node\Expr\ArrayDimFetch;
    }

    /**
     * @param  array<string, string>  $variableSources
     */
    private function isArrayVariableFromTernary(Node\Expr $condition, array $variableSources): ?string
    {
        if (! $condition instanceof Node\Expr\FuncCall || ! $this->isFunctionCallNamed($condition, 'is_array')) {
            return null;
        }

        $variable = $this->firstArgumentVariableName($condition);

        if ($variable === null || ($variableSources[$variable] ?? null) !== self::SOURCE_JSON_DECODE) {
            return null;
        }

        return $variable;
    }

    private function conditionUsesArrayKeyExistsOn(Node\Expr $condition, string $variable): bool
    {
        foreach ($this->flattenBooleanAnd($condition) as $expression) {
            if (! $expression instanceof Node\Expr\FuncCall || ! $this->isFunctionCallNamed($expression, 'array_key_exists')) {
                continue;
            }

            if ($this->argumentVariableName($expression, 1) === $variable) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<Node\Stmt>|null  $statements
     */
    private function ifBodyThrowsOnInvalidType(?array $statements): bool
    {
        if ($statements === null || count($statements) !== 1) {
            return false;
        }

        $statement = $statements[0];

        return $statement instanceof Node\Stmt\Expression
            && $statement->expr instanceof Node\Expr\Throw_;
    }

    private function expressionReadsArrayOffset(Node\Expr $expression, string $variable): bool
    {
        if ($expression instanceof Node\Expr\Empty_) {
            return $this->arrayDimFetchVariable($expression->expr) === $variable;
        }

        if ($expression instanceof Node\Expr\Isset_) {
            foreach ($expression->vars as $var) {
                if ($this->arrayDimFetchVariable($var) === $variable) {
                    return true;
                }
            }
        }

        return $this->arrayDimFetchVariable($expression) === $variable;
    }

    private function arrayDimFetchVariable(?Node\Expr $expression): ?string
    {
        if (! $expression instanceof Node\Expr\ArrayDimFetch) {
            return null;
        }

        return $this->variableName($expression->var);
    }

    private function unwrapBooleanNot(Node\Expr $expression): Node\Expr
    {
        if ($expression instanceof Node\Expr\BooleanNot) {
            return $expression->expr;
        }

        return $expression;
    }

    /**
     * @return list<Node\Expr>
     */
    private function flattenBooleanAnd(Node\Expr $expression): array
    {
        if ($expression instanceof Node\Expr\BinaryOp\BooleanAnd || $expression instanceof Node\Expr\BinaryOp\LogicalAnd) {
            return [
                ...$this->flattenBooleanAnd($expression->left),
                ...$this->flattenBooleanAnd($expression->right),
            ];
        }

        return [$expression];
    }

    /**
     * @return list<Node\Expr>
     */
    private function flattenBooleanOr(Node\Expr $expression): array
    {
        if ($expression instanceof Node\Expr\BinaryOp\BooleanOr || $expression instanceof Node\Expr\BinaryOp\LogicalOr) {
            return [
                ...$this->flattenBooleanOr($expression->left),
                ...$this->flattenBooleanOr($expression->right),
            ];
        }

        return [$expression];
    }

    private function isFunctionCallNamed(Node\Expr\FuncCall $expression, string $name): bool
    {
        return $expression->name instanceof Node\Name
            && strtolower($expression->name->toString()) === $name;
    }

    private function firstArgumentVariableName(Node\Expr\FuncCall $call): ?string
    {
        return $this->argumentVariableName($call, 0);
    }

    private function argumentVariableName(Node\Expr\FuncCall $call, int $index): ?string
    {
        $argument = $call->args[$index]->value ?? null;

        return $this->variableName($argument);
    }

    private function variableName(?Node\Expr $expression): ?string
    {
        if (! $expression instanceof Node\Expr\Variable || ! is_string($expression->name)) {
            return null;
        }

        return $expression->name;
    }
}
