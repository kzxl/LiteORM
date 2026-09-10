<?php

declare(strict_types=1);

namespace LiteORM\Attribute;

use Attribute;

/**
 * Attribute to enable soft deletion on an entity.
 * Instead of physically deleting rows, a timestamp column (default: deleted_at) is set.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class SoftDelete
{
    public function __construct(
        public readonly string $column = 'deleted_at',
    ) {}
}
