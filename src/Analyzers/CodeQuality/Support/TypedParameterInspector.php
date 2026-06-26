<?php

declare(strict_types=1);

namespace LaravelAudit\Analyzers\CodeQuality\Support;

use PhpParser\Node;

final class TypedParameterInspector
{
    /**
     * @return array<string, string> variable name => normalized type name
     */
    public function nonNullableParameterTypes(Node\FunctionLike $function): array
    {
        $parameters = [];

        foreach ($function->getParams() as $parameter) {
            if (! $parameter->var instanceof Node\Expr\Variable || ! is_string($parameter->var->name)) {
                continue;
            }

            if ($parameter->type === null || $this->typeAllowsNull($parameter->type)) {
                continue;
            }

            $type = $this->typeName($parameter->type);

            if ($type === null) {
                continue;
            }

            $parameters[$parameter->var->name] = $type;
        }

        return $parameters;
    }

    public function resolveClassName(Node\Name $name, ?string $namespace): string
    {
        $class = $name->toString();

        if ($name->isFullyQualified()) {
            return strtolower(ltrim($class, '\\'));
        }

        if (str_contains($class, '\\')) {
            return strtolower($class);
        }

        return $namespace !== null
            ? strtolower($namespace.'\\'.$class)
            : strtolower($class);
    }

    public function typeAllowsNull(Node $type): bool
    {
        if ($type instanceof Node\NullableType) {
            return true;
        }

        if ($type instanceof Node\UnionType) {
            foreach ($type->types as $unionType) {
                if ($this->typeName($unionType) === 'null') {
                    return true;
                }
            }
        }

        return $this->typeName($type) === 'mixed';
    }

    public function typeName(Node $type): ?string
    {
        if (! $type instanceof Node\Name && ! $type instanceof Node\Identifier) {
            return null;
        }

        return strtolower($type->toString());
    }
}
