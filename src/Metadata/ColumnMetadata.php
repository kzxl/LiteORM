<?php

declare(strict_types=1);

namespace LiteORM\Metadata;

class ColumnMetadata
{
    public function __construct(
        public readonly string $propertyName,
        public readonly string $columnName,
        public readonly string $phpType,
        public readonly ?string $dbType = null,
        public readonly ?int $length = null,
        public readonly ?int $precision = null,
        public readonly ?int $scale = null,
        public readonly bool $nullable = false,
        public readonly bool $unique = false,
        public readonly bool $isPrimaryKey = false,
        public readonly bool $isAutoIncrement = false,
        public readonly bool $isCreatedAt = false,
        public readonly bool $isUpdatedAt = false,
        public readonly mixed $default = null,
    ) {}
}
