<?php

declare(strict_types=1);

namespace LaravelAudit\Analyzers\Support;

use LaravelAudit\Project\PhpFile;
use PhpParser\Node;
use PhpParser\NodeFinder;

final class EloquentModelSerializationReader
{
    /**
     * @var list<string>
     */
    private const SENSITIVE_FIELD_FRAGMENTS = [
        'password',
        'remember_token',
        'api_token',
        'secret',
        'api_key',
        'access_token',
        'refresh_token',
        'private_key',
        'credit_card',
        'card_number',
        'cvv',
        'ssn',
    ];

    /**
     * @return list<string>
     */
    public function unhiddenSensitiveColumns(PhpFile $file): array
    {
        $finder = new NodeFinder;

        /** @var list<Node\Stmt\Class_> $classes */
        $classes = $finder->findInstanceOf($file->ast, Node\Stmt\Class_::class);

        if ($classes === []) {
            return [];
        }

        $class = $classes[0];
        $hidden = $this->hiddenColumns($class);
        $sensitive = array_unique(array_merge(
            $this->sensitiveFillableColumns($class),
            $this->sensitiveCastColumns($finder, $class),
        ));

        return array_values(array_filter(
            $sensitive,
            fn (string $column): bool => ! in_array($column, $hidden, true),
        ));
    }

    /**
     * @return list<string>
     */
    private function hiddenColumns(Node\Stmt\Class_ $class): array
    {
        $hidden = [];

        foreach ($class->attrGroups as $attributeGroup) {
            foreach ($attributeGroup->attrs as $attribute) {
                if ($this->attributeIs($attribute, 'Hidden')) {
                    $hidden = array_merge($hidden, $this->attributeColumns($attribute));
                }
            }
        }

        foreach ($class->getProperties() as $property) {
            if ($property->props[0]->name->toString() !== 'hidden') {
                continue;
            }

            $default = $property->props[0]->default;

            if ($default instanceof Node\Expr\Array_) {
                $hidden = array_merge($hidden, $this->stringValuesFromArray($default));
            }
        }

        return array_values(array_unique($hidden));
    }

    /**
     * @return list<string>
     */
    private function sensitiveFillableColumns(Node\Stmt\Class_ $class): array
    {
        $fillable = [];

        foreach ($class->attrGroups as $attributeGroup) {
            foreach ($attributeGroup->attrs as $attribute) {
                if ($this->attributeIs($attribute, 'Fillable')) {
                    $fillable = array_merge($fillable, $this->attributeColumns($attribute));
                }
            }
        }

        foreach ($class->getProperties() as $property) {
            if ($property->props[0]->name->toString() !== 'fillable') {
                continue;
            }

            $default = $property->props[0]->default;

            if ($default instanceof Node\Expr\Array_) {
                $fillable = array_merge($fillable, $this->stringValuesFromArray($default));
            }
        }

        return array_values(array_filter(
            array_unique($fillable),
            fn (string $column): bool => $this->isSensitiveColumn($column),
        ));
    }

    /**
     * @return list<string>
     */
    private function sensitiveCastColumns(NodeFinder $finder, Node\Stmt\Class_ $class): array
    {
        $columns = [];

        foreach ($class->getMethods() as $method) {
            if (strtolower($method->name->toString()) !== 'casts') {
                continue;
            }

            foreach ($finder->findInstanceOf($method->getStmts() ?? [], Node\Expr\Array_::class) as $array) {
                foreach ($array->items as $item) {
                    $column = $this->stringValue($item->key);

                    if ($column !== null && $this->isSensitiveColumn($column)) {
                        $columns[] = $column;
                    }
                }
            }
        }

        return array_values(array_unique($columns));
    }

    /**
     * @return list<string>
     */
    private function attributeColumns(Node\Attribute $attribute): array
    {
        if ($attribute->args === []) {
            return [];
        }

        $columns = [];

        foreach ($attribute->args as $argument) {
            if ($argument->value instanceof Node\Scalar\String_) {
                $columns[] = $argument->value->value;

                continue;
            }

            if ($argument->value instanceof Node\Expr\Array_) {
                $columns = array_merge($columns, $this->stringValuesFromArray($argument->value));
            }
        }

        return $columns;
    }

    /**
     * @return list<string>
     */
    private function stringValuesFromArray(Node\Expr\Array_ $array): array
    {
        $values = [];

        foreach ($array->items as $item) {
            $value = $this->stringValue($item->value);

            if ($value !== null) {
                $values[] = $value;
            }
        }

        return $values;
    }

    private function stringValue(?Node\Expr $expression): ?string
    {
        if ($expression instanceof Node\Scalar\String_) {
            return $expression->value;
        }

        return null;
    }

    private function isSensitiveColumn(string $column): bool
    {
        $normalized = strtolower($column);

        foreach (self::SENSITIVE_FIELD_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return str_ends_with($normalized, '_token') || str_ends_with($normalized, '_secret');
    }

    private function attributeIs(Node\Attribute $attribute, string $shortName): bool
    {
        return strcasecmp($attribute->name->getLast(), $shortName) === 0;
    }
}
