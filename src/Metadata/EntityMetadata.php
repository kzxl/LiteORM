<?php

declare(strict_types=1);

namespace LiteORM\Metadata;

class EntityMetadata
{
    /** @var ColumnMetadata[] */
    public array $columns = [];

    /** @var RelationMetadata[] */
    public array $relations = [];

    public ?string $primaryKey = null;
    public ?string $primaryKeyColumn = null;
    public bool $hasAutoIncrement = false;
    public ?string $createdAtColumn = null;
    public ?string $updatedAtColumn = null;
    public bool $isSoftDeletable = false;
    public ?string $softDeleteColumn = null;

    public function __construct(
        public readonly string $className,
        public readonly string $tableName,
    ) {}

    public function getColumnByProperty(string $property): ?ColumnMetadata
    {
        foreach ($this->columns as $col) {
            if ($col->propertyName === $property) return $col;
        }
        return null;
    }

    public function getColumnByName(string $name): ?ColumnMetadata
    {
        foreach ($this->columns as $col) {
            if ($col->columnName === $name) return $col;
        }
        return null;
    }

    /** @return string[] */
    public function getInsertColumns(): array
    {
        $cols = [];
        foreach ($this->columns as $col) {
            if (!$col->isAutoIncrement) {
                $cols[] = $col->columnName;
            }
        }
        return $cols;
    }

    /** @return string[] */
    public function getUpdateColumns(): array
    {
        $cols = [];
        foreach ($this->columns as $col) {
            if (!$col->isPrimaryKey && !$col->isCreatedAt) {
                $cols[] = $col->columnName;
            }
        }
        return $cols;
    }
}
