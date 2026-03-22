<?php

declare(strict_types=1);

namespace LiteORM\Metadata;

class RelationMetadata
{
    public function __construct(
        public readonly string $propertyName,
        public readonly string $type,
        public readonly string $target,
        public readonly string $foreignKey,
        public readonly ?string $orderBy = null,
    ) {}
}
