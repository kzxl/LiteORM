<?php

declare(strict_types=1);

namespace LiteORM\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class ManyToMany
{
    public function __construct(
        public readonly string $target,
        public readonly string $pivotTable,
        public readonly string $localKey = 'id',
        public readonly string $foreignKey = 'id',
        public readonly ?string $localPivotKey = null,
        public readonly ?string $foreignPivotKey = null,
    ) {}
}
