<?php

declare(strict_types=1);

namespace LiteORM;

use LiteORM\Connection\ConnectionManager;
use LiteORM\Metadata\{AttributeReader, EntityMetadata};
use LiteORM\Query\QueryBuilder;

/**
 * Main ORM entry point. Manages entity persistence with Unit of Work pattern.
 *
 * Usage:
 *   $em = new EntityManager('sqlite::memory:');
 *   $user = $em->find(User::class, 1);
 *   $user->name = 'Updated';
 *   $em->flush();
 */
class EntityManager
{
    private ConnectionManager $conn;

    /** @var array<string, array<string|int, object>> Identity map: class -> [id -> entity] */
    private array $identityMap = [];

    /** @var array<string, array<string|int, array<string, mixed>>> Original snapshots for change tracking */
    private array $snapshots = [];

    /** @var object[] Entities scheduled for insert */
    private array $inserts = [];

    /** @var object[] Entities scheduled for removal */
    private array $removals = [];

    private bool $debugMode = false;

    /** @var ?callable SQL logger: fn(string $sql, array $params, float $timeMs) */
    private $sqlLogger = null;

    /** @var array<string, list<callable>> Event listeners */
    private array $eventListeners = [];

    public function __construct(
        string|array|ConnectionManager $connection,
        ?string $username = null,
        ?string $password = null,
        array $options = [],
    ) {
        if ($connection instanceof ConnectionManager) {
            $this->conn = $connection;
        } else {
            $this->conn = new ConnectionManager($connection, $username, $password, $options);
        }
    }

    /**
     * Enable debug mode (N+1 detection warnings).
     */
    public function setDebugMode(bool $debug): void
    {
        $this->debugMode = $debug;
    }

    /**
     * Set SQL logger callback.
     * Callback signature: fn(string $sql, array $params, float $timeMs)
     */
    public function setSqlLogger(callable $logger): void
    {
        $this->sqlLogger = $logger;
        $this->conn->setSqlLogger($logger);
    }

    /**
     * Register an entity lifecycle event listener.
     * Events: 'insert', 'update', 'delete', or '*' for all events.
     * Callback signature: fn(string $event, object $entity, ?array $oldValues, ?array $newValues)
     */
    public function on(string $event, callable $listener): void
    {
        $this->eventListeners[$event][] = $listener;
    }

    /**
     * Attach an existing entity to the identity map for change tracking.
     * Like EF Core Attach() — entity is treated as "existing" (will UPDATE on flush, not INSERT).
     */
    public function attach(object $entity): void
    {
        $class = get_class($entity);
        $meta = AttributeReader::read($class);
        $id = $entity->{$meta->primaryKey} ?? null;
        if ($id === null) {
            throw new \RuntimeException('Cannot attach entity without primary key value');
        }
        $this->internalTrack($entity, $meta);
    }

    // ─── Find / Query ─────────────────────────────────────────────

    /**
     * Find entity by primary key.
     */
    public function find(string $class, int|string $id): ?object
    {
        // Check identity map first
        if (isset($this->identityMap[$class][$id])) {
            return $this->identityMap[$class][$id];
        }

        $meta = AttributeReader::read($class);
        $sql = "SELECT * FROM {$meta->tableName} WHERE {$meta->primaryKeyColumn} = :id LIMIT 1";
        $rows = $this->conn->query($sql, ['id' => $id]);

        if (empty($rows)) return null;

        $entity = $this->hydrateEntity($class, $meta, $rows[0]);
        $this->internalTrack($entity, $meta);

        return $entity;
    }

    /**
     * Find all entities of a class.
     * @return object[]
     */
    public function findAll(string $class): array
    {
        $meta = AttributeReader::read($class);
        $sql = "SELECT * FROM {$meta->tableName}";
        $rows = $this->conn->query($sql);

        $entities = [];
        foreach ($rows as $row) {
            $entity = $this->hydrateEntity($class, $meta, $row);
            $this->internalTrack($entity, $meta);
            $entities[] = $entity;
        }
        return $entities;
    }

    /**
     * Create a fluent query builder.
     */
    public function query(string $class): QueryBuilder
    {
        return new QueryBuilder($class, $this);
    }

    /**
     * Execute raw SQL and return rows.
     */
    public function raw(string $sql, array $params = []): array
    {
        return $this->conn->query($sql, $params);
    }

    // ─── Persist / Remove ─────────────────────────────────────────

    /**
     * Schedule entity for insertion.
     */
    public function persist(object $entity): void
    {
        $meta = AttributeReader::read(get_class($entity));

        // Check if entity already has an ID (update vs insert)
        if ($meta->primaryKey && isset($entity->{$meta->primaryKey})) {
            $id = $entity->{$meta->primaryKey};
            if ($id && !$meta->hasAutoIncrement) {
                // Track for update
                $this->internalTrack($entity, $meta);
                return;
            }
            if ($id && isset($this->identityMap[get_class($entity)][$id])) {
                // Already tracked
                return;
            }
        }

        $this->inserts[] = $entity;
    }

    /**
     * Schedule entity for removal.
     */
    public function remove(object $entity): void
    {
        $this->removals[] = $entity;
    }

    // ─── Flush (Unit of Work) ─────────────────────────────────────

    /**
     * Flush all pending changes to the database.
     * Executes INSERTs, UPDATEs (dirty fields only), and DELETEs.
     */
    public function flush(): void
    {
        $hasOuterTx = $this->conn->inTransaction();
        if (!$hasOuterTx) {
            $this->conn->beginTransaction();
        }
        try {
            // 1. Batch inserts by class (reuse prepared statement)
            $insertsByClass = [];
            foreach ($this->inserts as $entity) {
                $class = get_class($entity);
                $insertsByClass[$class][] = $entity;
            }
            foreach ($insertsByClass as $class => $entities) {
                $this->executeBatchInsert($class, $entities);
            }
            $this->inserts = [];

            // 2. Updates (dirty checking)
            foreach ($this->identityMap as $class => $entities) {
                $meta = AttributeReader::read($class);
                foreach ($entities as $id => $entity) {
                    $this->executeUpdate($entity, $meta, $id);
                }
            }

            // 3. Deletes
            foreach ($this->removals as $entity) {
                $this->executeDelete($entity);
            }
            $this->removals = [];

            if (!$hasOuterTx) {
                $this->conn->commit();
            }
        } catch (\Throwable $e) {
            if (!$hasOuterTx) {
                $this->conn->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Execute a callback within an explicit database transaction.
     * Flushes changes automatically and rolls back on failure.
     * Complies with AgentOption database transaction guidelines.
     *
     * @template T
     * @param callable(EntityManager): T $callback
     * @return T
     * @throws \Throwable
     */
    public function transaction(callable $callback): mixed
    {
        $this->conn->beginTransaction();
        try {
            $result = $callback($this);
            $this->flush();
            $this->conn->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            $this->clear();
            throw $e;
        }
    }

    /**
     * Clear the identity map and all pending operations.
     */
    public function clear(): void
    {
        $this->identityMap = [];
        $this->snapshots = [];
        $this->inserts = [];
        $this->removals = [];
    }

    /**
     * Detach an entity from the identity map.
     */
    public function detach(object $entity): void
    {
        $class = get_class($entity);
        $meta = AttributeReader::read($class);
        $id = $entity->{$meta->primaryKey} ?? null;
        if ($id !== null) {
            unset($this->identityMap[$class][$id]);
            unset($this->snapshots[$class][$id]);
        }
    }

    // ─── Schema ───────────────────────────────────────────────────

    /**
     * Create table for an entity class.
     */
    public function createTable(string $class): void
    {
        $meta = AttributeReader::read($class);
        $columns = [];

        foreach ($meta->columns as $col) {
            $colSql = $col->columnName . ' ' . $this->resolveDbType($col);
            if ($col->isPrimaryKey) $colSql .= ' PRIMARY KEY';
            if ($col->isAutoIncrement) $colSql .= ' AUTOINCREMENT';
            if (!$col->nullable && !$col->isPrimaryKey) $colSql .= ' NOT NULL';
            if ($col->unique) $colSql .= ' UNIQUE';
            if ($col->default !== null) {
                $colSql .= ' DEFAULT ' . (is_string($col->default) ? "'{$col->default}'" : $col->default);
            }
            $columns[] = $colSql;
        }

        $sql = "CREATE TABLE IF NOT EXISTS {$meta->tableName} (\n  " . implode(",\n  ", $columns) . "\n)";
        $this->conn->execute($sql);
    }

    /**
     * Drop table for an entity class.
     */
    public function dropTable(string $class): void
    {
        $meta = AttributeReader::read($class);
        $this->conn->execute("DROP TABLE IF EXISTS {$meta->tableName}");
    }

    // ─── Accessors ────────────────────────────────────────────────

    public function getConnection(): ConnectionManager
    {
        return $this->conn;
    }

    public function getQueryCount(): int
    {
        return $this->conn->getQueryCount();
    }

    // ─── Internal ─────────────────────────────────────────────────

    /**
     * Batch insert entities of the same class, reusing prepared statement.
     * @param object[] $entities
     */
    private function executeBatchInsert(string $class, array $entities): void
    {
        $meta = AttributeReader::read($class);

        // Build SQL template once
        $columns = [];
        $placeholders = [];
        foreach ($meta->columns as $col) {
            if ($col->isAutoIncrement) continue;
            $columns[] = $col->columnName;
            $placeholders[] = ":{$col->columnName}";
        }
        $sql = "INSERT INTO {$meta->tableName} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";

        // Prepare once, execute many
        $stmt = $this->conn->getWriteConnection()->prepare($sql);

        foreach ($entities as $entity) {
            // Set timestamps
            $this->applyTimestamps($entity, $meta);

            $params = [];
            foreach ($meta->columns as $col) {
                if ($col->isAutoIncrement) continue;
                $params[$col->columnName] = $this->extractValue($entity, $col);
            }

            $start = hrtime(true);
            $stmt->execute($params);
            $elapsed = (hrtime(true) - $start) / 1e6;

            if ($this->sqlLogger) {
                ($this->sqlLogger)($sql, $params, $elapsed);
            }

            // Set auto-increment ID
            if ($meta->hasAutoIncrement && $meta->primaryKey) {
                $entity->{$meta->primaryKey} = (int) $this->conn->getWriteConnection()->lastInsertId();
            }

            $this->internalTrack($entity, $meta);
            $newSnapshot = $this->snapshots[$class][$entity->{$meta->primaryKey}] ?? $this->takeSnapshot($entity, $meta);
            $this->dispatchEntityEvent('insert', $entity, null, $newSnapshot);
        }
    }

    private function applyTimestamps(object $entity, EntityMetadata $meta): void
    {
        if ($meta->createdAtColumn) {
            $col = $meta->getColumnByName($meta->createdAtColumn);
            if ($col && !isset($entity->{$col->propertyName})) {
                $entity->{$col->propertyName} = new \DateTimeImmutable();
            }
        }
        if ($meta->updatedAtColumn) {
            $col = $meta->getColumnByName($meta->updatedAtColumn);
            if ($col) {
                $entity->{$col->propertyName} = new \DateTimeImmutable();
            }
        }
    }

    private function executeUpdate(object $entity, EntityMetadata $meta, int|string $id): void
    {
        $class = get_class($entity);
        $snapshot = $this->snapshots[$class][$id] ?? null;
        if (!$snapshot) return;

        // Find dirty fields
        $dirty = [];
        foreach ($meta->columns as $col) {
            if ($col->isPrimaryKey || $col->isCreatedAt) continue;
            $currentValue = $this->extractValue($entity, $col);
            $snapshotValue = $snapshot[$col->columnName] ?? null;
            if ($currentValue !== $snapshotValue) {
                $dirty[$col->columnName] = $currentValue;
            }
        }

        if (empty($dirty)) return;

        // Auto-update UpdatedAt
        if ($meta->updatedAtColumn && !isset($dirty[$meta->updatedAtColumn])) {
            $dirty[$meta->updatedAtColumn] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        }

        $sets = [];
        $params = [];
        foreach ($dirty as $col => $val) {
            $sets[] = "{$col} = :{$col}";
            $params[$col] = $val;
        }
        $params['__pk'] = $id;

        $sql = "UPDATE {$meta->tableName} SET " . implode(', ', $sets)
            . " WHERE {$meta->primaryKeyColumn} = :__pk";

        $this->conn->execute($sql, $params);

        // Update snapshot
        $oldSnapshot = $snapshot;
        $newSnapshot = $this->takeSnapshot($entity, $meta);
        $this->snapshots[$class][$id] = $newSnapshot;

        $this->dispatchEntityEvent('update', $entity, $oldSnapshot, $newSnapshot);
    }

    private function executeDelete(object $entity): void
    {
        $class = get_class($entity);
        $meta = AttributeReader::read($class);
        $id = $entity->{$meta->primaryKey} ?? null;
        if ($id === null) return;

        $sql = "DELETE FROM {$meta->tableName} WHERE {$meta->primaryKeyColumn} = :id";
        $this->conn->execute($sql, ['id' => $id]);

        $oldSnapshot = $this->snapshots[$class][$id] ?? null;

        // Remove from identity map
        unset($this->identityMap[$class][$id]);
        unset($this->snapshots[$class][$id]);

        $this->dispatchEntityEvent('delete', $entity, $oldSnapshot, null);
    }

    private function dispatchEntityEvent(string $event, object $entity, ?array $old, ?array $new): void
    {
        foreach ($this->eventListeners[$event] ?? [] as $listener) {
            $listener($event, $entity, $old, $new);
        }
        if ($event !== '*') {
            foreach ($this->eventListeners['*'] ?? [] as $listener) {
                $listener($event, $entity, $old, $new);
            }
        }
    }

    /**
     * @internal Used by QueryBuilder to track hydrated entities
     */
    public function internalTrack(object $entity, EntityMetadata $meta): void
    {
        $class = get_class($entity);
        $id = $entity->{$meta->primaryKey} ?? null;
        if ($id === null) return;

        $this->identityMap[$class][$id] = $entity;
        $this->snapshots[$class][$id] = $this->takeSnapshot($entity, $meta);
    }

    private function takeSnapshot(object $entity, EntityMetadata $meta): array
    {
        $snapshot = [];
        foreach ($meta->columns as $col) {
            $snapshot[$col->columnName] = $this->extractValue($entity, $col);
        }
        return $snapshot;
    }

    private function extractValue(object $entity, \LiteORM\Metadata\ColumnMetadata $col): mixed
    {
        $value = $entity->{$col->propertyName} ?? null;

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return $value;
    }

    private function hydrateEntity(string $class, EntityMetadata $meta, array $row): object
    {
        $entity = new $class();
        foreach ($meta->columns as $col) {
            if (!array_key_exists($col->columnName, $row)) continue;
            $entity->{$col->propertyName} = $this->castValue($row[$col->columnName], $col->phpType, $col->nullable);
        }
        return $entity;
    }

    private function castValue(mixed $value, string $phpType, bool $nullable): mixed
    {
        if ($value === null) return null;
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

    private function resolveDbType(\LiteORM\Metadata\ColumnMetadata $col): string
    {
        if ($col->dbType) return strtoupper($col->dbType);

        return match ($col->phpType) {
            'int' => 'INTEGER',
            'float' => 'REAL',
            'bool' => 'INTEGER',
            'string' => $col->length ? "VARCHAR({$col->length})" : 'TEXT',
            'DateTimeImmutable', 'DateTime', \DateTimeImmutable::class, \DateTime::class => 'DATETIME',
            default => 'TEXT',
        };
    }
}
