<?php

declare(strict_types=1);

namespace LiteORM\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class HasOne
{
    public function __construct(
        public readonly string $target,
        public readonly string $foreignKey,
    ) {}
}
