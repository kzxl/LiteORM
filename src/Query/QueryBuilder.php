<?php

declare(strict_types=1);

namespace LiteORM\Query;

use LiteORM\EntityManager;
use LiteORM\Metadata\{AttributeReader, EntityMetadata};

/**
 * Fluent query builder with automatic entity hydration.
 */
class QueryBuilder
{
    private EntityMetadata $meta;
    private EntityManager $em;

    private array $selects = [];
    private array $wheres = [];
    private array $orderBys = [];
    private array $params = [];
    private ?int $limitVal = null;
    private ?int $offsetVal = null;
    private array $eagerLoads = [];
    private ?int $cacheTtl = null;
    private array $joins = [];
    private ?string $groupByVal = null;
    private ?string $havingVal = null;
    private bool $distinctFlag = false;
    private bool $asNoTracking = false;
    private int $paramIndex = 0;

    public function __construct(
        private readonly string $entityClass,
        EntityManager $em,
    ) {
        $this->meta = AttributeReader::read($entityClass);
        $this->em = $em;
    }

    // ─── SELECT ───────────────────────────────────────────────────

    public function select(string ...$columns): static
    {
        $this->selects = array_merge($this->selects, $columns);
        return $this;
    }

    // ─── WHERE ────────────────────────────────────────────────────

    public function where(string $column, mixed $operatorOrValue = null, mixed $value = null): static
    {
        return $this->addWhere('AND', $column, $operatorOrValue, $value);
    }

    public function orWhere(string $column, mixed $operatorOrValue = null, mixed $value = null): static
    {
        return $this->addWhere('OR', $column, $operatorOrValue, $value);
    }

    public function whereNull(string $column): static
    {
        $this->wheres[] = ['type' => 'AND', 'sql' => "{$column} IS NULL"];
        return $this;
    }

    public function whereNotNull(string $column): static
    {
        $this->wheres[] = ['type' => 'AND', 'sql' => "{$column} IS NOT NULL"];
        return $this;
    }

    public function whereIn(string $column, array $values): static
    {
        $placeholders = [];
        foreach ($values as $v) {
            $key = $this->nextParam();
            $this->params[$key] = $v;
            $placeholders[] = ":{$key}";
        }
        $this->wheres[] = [
            'type' => 'AND',
            'sql' => "{$column} IN (" . implode(', ', $placeholders) . ")",
        ];
        return $this;
    }

    public function whereNotIn(string $column, array $values): static
    {
        $placeholders = [];
        foreach ($values as $v) {
            $key = $this->nextParam();
            $this->params[$key] = $v;
            $placeholders[] = ":{$key}";
        }
        $this->wheres[] = [
            'type' => 'AND',
            'sql' => "{$column} NOT IN (" . implode(', ', $placeholders) . ")",
        ];
        return $this;
    }

    public function whereBetween(string $column, mixed $min, mixed $max): static
    {
        $k1 = $this->nextParam();
        $k2 = $this->nextParam();
        $this->params[$k1] = $min;
        $this->params[$k2] = $max;
        $this->wheres[] = [
            'type' => 'AND',
            'sql' => "{$column} BETWEEN :{$k1} AND :{$k2}",
        ];
        return $this;
    }

    public function whereLike(string $column, string $pattern): static
    {
        $key = $this->nextParam();
        $this->params[$key] = $pattern;
        $this->wheres[] = ['type' => 'AND', 'sql' => "{$column} LIKE :{$key}"];
        return $this;
    }

    // ─── ORDER / LIMIT / OFFSET ───────────────────────────────────

    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $dir = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $this->orderBys[] = "{$column} {$dir}";
        return $this;
    }

    public function limit(int $limit): static
    {
        $this->limitVal = $limit;
        return $this;
    }

    public function offset(int $offset): static
    {
        $this->offsetVal = $offset;
        return $this;
    }

    // ─── JOIN ─────────────────────────────────────────────────────

    public function join(string $table, string $on, string $type = 'INNER'): static
    {
        $this->joins[] = "{$type} JOIN {$table} ON {$on}";
        return $this;
    }

    public function leftJoin(string $table, string $on): static
    {
        return $this->join($table, $on, 'LEFT');
    }

    // ─── GROUP BY / HAVING ────────────────────────────────────────

    public function groupBy(string $column): static
    {
        $this->groupByVal = $column;
        return $this;
    }

    public function having(string $condition): static
    {
        $this->havingVal = $condition;
        return $this;
    }

    // ─── EAGER LOADING ────────────────────────────────────────────

    public function with(string ...$relations): static
    {
        $this->eagerLoads = array_merge($this->eagerLoads, $relations);
        return $this;
    }

    // ─── CACHE / TRACKING ─────────────────────────────────────────

    public function cache(int $ttl = 300): static
    {
        $this->cacheTtl = $ttl;
        return $this;
    }

    public function asNoTracking(): static
    {
        $this->asNoTracking = true;
        return $this;
    }

    // ─── EXECUTE ──────────────────────────────────────────────────

    /**
     * Execute and return hydrated entities.
     * @return object[]
     */
    public function get(): array
    {
        $sql = $this->buildSelectSql();
        $rows = $this->em->getConnection()->query($sql, $this->params);
        $entities = $this->hydrateAll($rows);

        // Eager load relations
        if (!empty($this->eagerLoads) && !empty($entities)) {
            $this->loadEagerRelations($entities);
        }

        // Apply identity map tracking unless AsNoTracking
        if (!$this->asNoTracking) {
            foreach ($entities as $entity) {
                // If the entity already exists in identity map, we should arguably merge or ignore.
                // In LiteORM, we will just track the fresh state if it's not present or attach it.
                $this->em->internalTrack($entity, $this->meta);
            }
        }

        return $entities;
    }

    /**
     * Alias for get() — LINQ ToList().
     * @return object[]
     */
    public function toList(): array
    {
        return $this->get();
    }

    /**
     * LINQ FirstOrDefault — return first result or null.
     */
    public function first(): ?object
    {
        $this->limitVal = 1;
        $results = $this->get();
        return $results[0] ?? null;
    }

    /**
     * LINQ First — return first result, throw if empty.
     * @throws \RuntimeException
     */
    public function firstOrFail(): object
    {
        $result = $this->first();
        if ($result === null) {
            throw new \RuntimeException("No entity found for {$this->entityClass}");
        }
        return $result;
    }

    /**
     * LINQ SingleOrDefault — return exactly one result or null.
     * Throws if more than one result.
     * @throws \RuntimeException
     */
    public function single(): ?object
    {
        $this->limitVal = 2;
        $results = $this->get();
        if (count($results) > 1) {
            throw new \RuntimeException("Expected zero or one result, got multiple for {$this->entityClass}");
        }
        return $results[0] ?? null;
    }

    /**
     * LINQ Single — return exactly one result, throw if empty or multiple.
     * @throws \RuntimeException
     */
    public function singleOrFail(): object
    {
        $this->limitVal = 2;
        $results = $this->get();
        if (count($results) === 0) {
            throw new \RuntimeException("No entity found for {$this->entityClass}");
        }
        if (count($results) > 1) {
            throw new \RuntimeException("Expected exactly one result, got multiple for {$this->entityClass}");
        }
        return $results[0];
    }

    /**
     * LINQ Last — return last result or null (reverses first orderBy).
     */
    public function last(): ?object
    {
        // Reverse existing orderBy, or use PK DESC
        if (empty($this->orderBys)) {
            $this->orderBys[] = "{$this->meta->primaryKeyColumn} DESC";
        } else {
            $this->orderBys = array_map(function($o) {
                if (str_ends_with($o, 'ASC')) return str_replace('ASC', 'DESC', $o);
                if (str_ends_with($o, 'DESC')) return str_replace('DESC', 'ASC', $o);
                return $o;
            }, $this->orderBys);
        }
        return $this->first();
    }

    /**
     * LINQ Distinct — add DISTINCT to SELECT.
     */
    public function distinct(): static
    {
        // Will be applied in buildSelectSql()
        $this->selects = array_map(fn($s) => $s, $this->selects);
        $this->distinctFlag = true;
        return $this;
    }

    /**
     * LINQ Take — alias for limit().
     */
    public function take(int $count): static
    {
        return $this->limit($count);
    }

    /**
     * LINQ Skip — alias for offset().
     */
    public function skip(int $count): static
    {
        return $this->offset($count);
    }

    /**
     * Count matching rows.
     */
    public function count(): int
    {
        $sql = $this->buildAggregateSql('COUNT(*)');
        $rows = $this->em->getConnection()->query($sql, $this->params);
        return (int)($rows[0]['aggregate'] ?? 0);
    }

    /**
     * Sum a column.
     */
    public function sum(string $column): float
    {
        $sql = $this->buildAggregateSql("SUM({$column})");
        $rows = $this->em->getConnection()->query($sql, $this->params);
        return (float)($rows[0]['aggregate'] ?? 0);
    }

    /**
     * Average a column.
     */
    public function avg(string $column): float
    {
        $sql = $this->buildAggregateSql("AVG({$column})");
        $rows = $this->em->getConnection()->query($sql, $this->params);
        return (float)($rows[0]['aggregate'] ?? 0);
    }

    /**
     * Max of a column.
     */
    public function max(string $column): mixed
    {
        $sql = $this->buildAggregateSql("MAX({$column})");
        $rows = $this->em->getConnection()->query($sql, $this->params);
        return $rows[0]['aggregate'] ?? null;
    }

    /**
     * Min of a column.
     */
    public function min(string $column): mixed
    {
        $sql = $this->buildAggregateSql("MIN({$column})");
        $rows = $this->em->getConnection()->query($sql, $this->params);
        return $rows[0]['aggregate'] ?? null;
    }

    /**
     * Check if any rows exist.
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Delete matching rows.
     */
    public function delete(): int
    {
        $sql = "DELETE FROM {$this->meta->tableName}";
        $sql .= $this->buildWhereSql();
        return $this->em->getConnection()->execute($sql, $this->params);
    }

    /**
     * Update matching rows.
     * @param array<string, mixed> $values
     */
    public function update(array $values): int
    {
        $sets = [];
        foreach ($values as $col => $val) {
            $key = $this->nextParam();
            $sets[] = "{$col} = :{$key}";
            $this->params[$key] = $val;
        }
        $sql = "UPDATE {$this->meta->tableName} SET " . implode(', ', $sets);
        $sql .= $this->buildWhereSql();
        return $this->em->getConnection()->execute($sql, $this->params);
    }

    /**
     * Get the raw SQL string (for debugging).
     */
    public function toSql(): string
    {
        return $this->buildSelectSql();
    }

    /**
     * Get bound params (for debugging).
     */
    public function getParams(): array
    {
        return $this->params;
    }

    // ─── SQL Building ─────────────────────────────────────────────

    private function buildSelectSql(): string
    {
        $cols = empty($this->selects) ? '*' : implode(', ', $this->selects);
        $distinct = $this->distinctFlag ? 'DISTINCT ' : '';
        $sql = "SELECT {$distinct}{$cols} FROM {$this->meta->tableName}";

        foreach ($this->joins as $join) {
            $sql .= " {$join}";
        }

        $sql .= $this->buildWhereSql();

        if ($this->groupByVal) {
            $sql .= " GROUP BY {$this->groupByVal}";
        }
        if ($this->havingVal) {
            $sql .= " HAVING {$this->havingVal}";
        }
        if (!empty($this->orderBys)) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orderBys);
        }
        if ($this->limitVal !== null) {
            $sql .= " LIMIT {$this->limitVal}";
        }
        if ($this->offsetVal !== null) {
            $sql .= " OFFSET {$this->offsetVal}";
        }

        return $sql;
    }

    private function buildAggregateSql(string $function): string
    {
        $sql = "SELECT {$function} AS aggregate FROM {$this->meta->tableName}";
        $sql .= $this->buildWhereSql();
        return $sql;
    }

    private function buildWhereSql(): string
    {
        if (empty($this->wheres)) return '';

        $parts = [];
        foreach ($this->wheres as $i => $w) {
            if ($i === 0) {
                $parts[] = $w['sql'];
            } else {
                $parts[] = "{$w['type']} {$w['sql']}";
            }
        }

        return ' WHERE ' . implode(' ', $parts);
    }

    // ─── Hydration ────────────────────────────────────────────────

    private function hydrateAll(array $rows): array
    {
        $entities = [];
        foreach ($rows as $row) {
            $entities[] = $this->hydrateOne($row);
        }
        return $entities;
    }

    private function hydrateOne(array $row): object
    {
        $class = $this->entityClass;
        $entity = new $class();

        foreach ($this->meta->columns as $col) {
            if (!array_key_exists($col->columnName, $row)) continue;
            $value = $this->castValue($row[$col->columnName], $col->phpType, $col->nullable);
            $entity->{$col->propertyName} = $value;
        }

        return $entity;
    }

    private function castValue(mixed $value, string $phpType, bool $nullable): mixed
    {
        if ($value === null) return $nullable ? null : $this->defaultForType($phpType);

        return match ($phpType) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => (bool) $value,
            'string' => (string) $value,
            'DateTimeImmutable', \DateTimeImmutable::class => new \DateTimeImmutable($value),
            'DateTime', \DateTime::class => new \DateTime($value),
            default => $value,
        };
    }

    private function defaultForType(string $type): mixed
    {
        return match ($type) {
            'int' => 0,
            'float' => 0.0,
            'bool' => false,
            'string' => '',
            default => null,
        };
    }

    // ─── Eager Loading ────────────────────────────────────────────

    private function loadEagerRelations(array &$entities): void
    {
        foreach ($this->eagerLoads as $relationName) {
            $relation = null;
            foreach ($this->meta->relations as $rel) {
                if ($rel->propertyName === $relationName) {
                    $relation = $rel;
                    break;
                }
            }
            if (!$relation) continue;

            if ($relation->type === 'hasMany') {
                $this->eagerLoadHasMany($entities, $relation);
            } elseif ($relation->type === 'belongsTo') {
                $this->eagerLoadBelongsTo($entities, $relation);
            }
        }
    }

    private function eagerLoadHasMany(array &$entities, \LiteORM\Metadata\RelationMetadata $relation): void
    {
        $pk = $this->meta->primaryKey;
        $ids = array_map(fn($e) => $e->{$pk}, $entities);
        if (empty($ids)) return;

        $targetMeta = AttributeReader::read($relation->target);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT * FROM {$targetMeta->tableName} WHERE {$relation->foreignKey} IN ({$placeholders})";
        if ($relation->orderBy) {
            $sql .= " ORDER BY {$relation->orderBy}";
        }

        $rows = $this->em->getConnection()->query($sql, array_values($ids));

        // Group by FK
        $grouped = [];
        foreach ($rows as $row) {
            $fkValue = $row[$relation->foreignKey];
            $grouped[$fkValue][] = $row;
        }

        // Create sub-hydrator for target
        $targetBuilder = new self($relation->target, $this->em);

        foreach ($entities as $entity) {
            $entityId = $entity->{$pk};
            $relatedRows = $grouped[$entityId] ?? [];
            $entity->{$relation->propertyName} = array_map(
                fn($row) => $targetBuilder->hydrateOne($row),
                $relatedRows
            );
        }
    }

    private function eagerLoadBelongsTo(array &$entities, \LiteORM\Metadata\RelationMetadata $relation): void
    {
        $fkProp = null;
        foreach ($this->meta->columns as $col) {
            if ($col->columnName === $relation->foreignKey) {
                $fkProp = $col->propertyName;
                break;
            }
        }
        if (!$fkProp) return;

        $ids = array_filter(array_unique(array_map(fn($e) => $e->{$fkProp} ?? null, $entities)));
        if (empty($ids)) return;

        $targetMeta = AttributeReader::read($relation->target);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT * FROM {$targetMeta->tableName} WHERE {$targetMeta->primaryKeyColumn} IN ({$placeholders})";

        $rows = $this->em->getConnection()->query($sql, array_values($ids));

        $targetBuilder = new self($relation->target, $this->em);
        $indexed = [];
        foreach ($rows as $row) {
            $obj = $targetBuilder->hydrateOne($row);
            $indexed[$row[$targetMeta->primaryKeyColumn]] = $obj;
        }

        foreach ($entities as $entity) {
            $fkVal = $entity->{$fkProp} ?? null;
            $entity->{$relation->propertyName} = $indexed[$fkVal] ?? null;
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────

    private function addWhere(string $type, string $column, mixed $operatorOrValue, mixed $value): static
    {
        if ($value === null) {
            // Two-argument form: where('col', 'value') → col = value
            $operator = '=';
            $value = $operatorOrValue;
        } else {
            $operator = $operatorOrValue;
        }

        $key = $this->nextParam();
        $this->params[$key] = $value;
        $this->wheres[] = [
            'type' => $type,
            'sql' => "{$column} {$operator} :{$key}",
        ];
        return $this;
    }

    private function nextParam(): string
    {
        return 'p' . ($this->paramIndex++);
    }
}
