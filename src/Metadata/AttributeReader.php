<?php

declare(strict_types=1);

namespace LiteORM\Metadata;

use LiteORM\Attribute\{Entity, Table, Column, Id, AutoIncrement, CreatedAt, UpdatedAt, HasMany, BelongsTo, HasOne, SoftDelete};
use ReflectionClass;
use ReflectionProperty;
use ReflectionNamedType;

class AttributeReader
{
    /** @var EntityMetadata[] */
    private static array $cache = [];

    public static function read(string $className): EntityMetadata
    {
        if (isset(self::$cache[$className])) {
            return self::$cache[$className];
        }

        $ref = new ReflectionClass($className);

        $entityAttrs = $ref->getAttributes(Entity::class);
        if (empty($entityAttrs)) {
            throw new \RuntimeException("Class {$className} must have #[Entity] attribute");
        }

        $tableAttrs = $ref->getAttributes(Table::class);
        $tableName = !empty($tableAttrs)
            ? $tableAttrs[0]->newInstance()->name
            : self::classToTableName($ref->getShortName());

        $meta = new EntityMetadata($className, $tableName);

        $softDeleteAttrs = $ref->getAttributes(SoftDelete::class);
        if (!empty($softDeleteAttrs)) {
            $meta->isSoftDeletable = true;
            $meta->softDeleteColumn = $softDeleteAttrs[0]->newInstance()->column;
        }

        foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            $relation = self::readRelation($prop);
            if ($relation) {
                $meta->relations[] = $relation;
                continue;
            }

            $column = self::readColumn($prop);
            if ($column) {
                $meta->columns[] = $column;
                if ($column->isPrimaryKey) {
                    $meta->primaryKey = $column->propertyName;
                    $meta->primaryKeyColumn = $column->columnName;
                }
                if ($column->isAutoIncrement) $meta->hasAutoIncrement = true;
                if ($column->isCreatedAt) $meta->createdAtColumn = $column->columnName;
                if ($column->isUpdatedAt) $meta->updatedAtColumn = $column->columnName;
            }
        }

        self::$cache[$className] = $meta;
        return $meta;
    }

    private static function readColumn(ReflectionProperty $prop): ?ColumnMetadata
    {
        $colAttrs = $prop->getAttributes(Column::class);
        $isId = !empty($prop->getAttributes(Id::class));
        $isAuto = !empty($prop->getAttributes(AutoIncrement::class));
        $isCreated = !empty($prop->getAttributes(CreatedAt::class));
        $isUpdated = !empty($prop->getAttributes(UpdatedAt::class));

        $colAttr = !empty($colAttrs) ? $colAttrs[0]->newInstance() : null;

        $phpType = 'string';
        if ($prop->hasType()) {
            $type = $prop->getType();
            if ($type instanceof ReflectionNamedType) {
                $phpType = $type->getName();
            }
        }

        $columnName = $colAttr?->name ?? self::propertyToColumnName($prop->getName());

        return new ColumnMetadata(
            propertyName: $prop->getName(),
            columnName: $columnName,
            phpType: $phpType,
            dbType: $colAttr?->type,
            length: $colAttr?->length,
            precision: $colAttr?->precision,
            scale: $colAttr?->scale,
            nullable: $colAttr?->nullable ?? ($prop->hasType() && $prop->getType()->allowsNull()),
            unique: $colAttr?->unique ?? false,
            isPrimaryKey: $isId,
            isAutoIncrement: $isAuto,
            isCreatedAt: $isCreated,
            isUpdatedAt: $isUpdated,
            default: $colAttr?->default,
        );
    }

    private static function readRelation(ReflectionProperty $prop): ?RelationMetadata
    {
        $hasMany = $prop->getAttributes(HasMany::class);
        if (!empty($hasMany)) {
            $attr = $hasMany[0]->newInstance();
            return new RelationMetadata(
                propertyName: $prop->getName(),
                type: 'hasMany',
                target: $attr->target,
                foreignKey: $attr->foreignKey,
                orderBy: $attr->orderBy,
            );
        }

        $belongsTo = $prop->getAttributes(BelongsTo::class);
        if (!empty($belongsTo)) {
            $attr = $belongsTo[0]->newInstance();
            $fk = $attr->foreignKey ?? self::propertyToColumnName($prop->getName()) . '_id';
            return new RelationMetadata(
                propertyName: $prop->getName(),
                type: 'belongsTo',
                target: $attr->target,
                foreignKey: $fk,
            );
        }

        $hasOne = $prop->getAttributes(HasOne::class);
        if (!empty($hasOne)) {
            $attr = $hasOne[0]->newInstance();
            return new RelationMetadata(
                propertyName: $prop->getName(),
                type: 'hasOne',
                target: $attr->target,
                foreignKey: $attr->foreignKey,
            );
        }

        return null;
    }

    private static function classToTableName(string $name): string
    {
        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
        if (str_ends_with($snake, 'y')) return substr($snake, 0, -1) . 'ies';
        if (str_ends_with($snake, 's') || str_ends_with($snake, 'x') || str_ends_with($snake, 'ch')) return $snake . 'es';
        return $snake . 's';
    }

    private static function propertyToColumnName(string $name): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
