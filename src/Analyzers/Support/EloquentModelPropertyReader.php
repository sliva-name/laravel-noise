<?php

declare(strict_types=1);

namespace LaravelAudit\Analyzers\Support;

use LaravelAudit\Project\PhpFile;
use PhpParser\Node;
use PhpParser\NodeFinder;

final class EloquentModelPropertyReader
{
    /**
     * @return array{
     *     hasFillable: bool,
     *     hasGuarded: bool,
     *     hasEmptyGuarded: bool,
     *     hasUnguarded: bool,
     *     guardedLine: int|null
     * }
     */
    public function read(PhpFile $file): array
    {
        $result = [
            'hasFillable' => false,
            'hasGuarded' => false,
            'hasEmptyGuarded' => false,
            'hasUnguarded' => false,
            'guardedLine' => null,
        ];

        $finder = new NodeFinder;

        /** @var list<Node\Stmt\Class_> $classes */
        $classes = $finder->findInstanceOf($file->ast, Node\Stmt\Class_::class);

        foreach ($classes as $class) {
            $this->readClassAttributes($class, $result);
            $this->readClassProperties($class, $result);
        }

        return $result;
    }

    /**
     * @param  array{
     *     hasFillable: bool,
     *     hasGuarded: bool,
     *     hasEmptyGuarded: bool,
     *     hasUnguarded: bool,
     *     guardedLine: int|null
     * }  $result
     */
    private function readClassAttributes(Node\Stmt\Class_ $class, array &$result): void
    {
        foreach ($class->attrGroups as $attributeGroup) {
            foreach ($attributeGroup->attrs as $attribute) {
                if ($this->attributeIs($attribute, 'Fillable')) {
                    $result['hasFillable'] = true;

                    continue;
                }

                if ($this->attributeIs($attribute, 'Unguarded')) {
                    $result['hasUnguarded'] = true;
                    $result['guardedLine'] = $attribute->getStartLine();

                    continue;
                }

                if (! $this->attributeIs($attribute, 'Guarded')) {
                    continue;
                }

                $result['hasGuarded'] = true;
                $result['guardedLine'] = $attribute->getStartLine();

                if ($this->attributeGuardsNothing($attribute)) {
                    $result['hasEmptyGuarded'] = true;
                }
            }
        }
    }

    /**
     * @param  array{
     *     hasFillable: bool,
     *     hasGuarded: bool,
     *     hasEmptyGuarded: bool,
     *     hasUnguarded: bool,
     *     guardedLine: int|null
     * }  $result
     */
    private function readClassProperties(Node\Stmt\Class_ $class, array &$result): void
    {
        foreach ($class->getProperties() as $property) {
            $name = $property->props[0]->name->toString();

            if ($name === 'fillable') {
                $result['hasFillable'] = true;
            }

            if ($name !== 'guarded') {
                continue;
            }

            $result['hasGuarded'] = true;
            $default = $property->props[0]->default;

            if ($default instanceof Node\Expr\Array_ && $default->items === []) {
                $result['hasEmptyGuarded'] = true;
                $result['guardedLine'] = $property->getStartLine();
            }
        }
    }

    private function attributeIs(Node\Attribute $attribute, string $shortName): bool
    {
        return strcasecmp($attribute->name->getLast(), $shortName) === 0;
    }

    private function attributeGuardsNothing(Node\Attribute $attribute): bool
    {
        if ($attribute->args === []) {
            return false;
        }

        $argument = $attribute->args[0]->value ?? null;

        return $argument instanceof Node\Expr\Array_ && $argument->items === [];
    }
}
