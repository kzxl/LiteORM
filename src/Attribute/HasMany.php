<?php

declare(strict_types=1);

namespace LiteORM\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class HasMany
{
    public function __construct(
        public readonly string $target,
        public readonly string $foreignKey,
        public readonly ?string $orderBy = null,
    ) {}
}
