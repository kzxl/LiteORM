<?php

declare(strict_types=1);

namespace LiteORM\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Column
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $type = null,
        public readonly ?int $length = null,
        public readonly ?int $precision = null,
        public readonly ?int $scale = null,
        public readonly bool $nullable = false,
        public readonly bool $unique = false,
        public readonly mixed $default = null,
    ) {}
}
