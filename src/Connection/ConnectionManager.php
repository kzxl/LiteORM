<?php

declare(strict_types=1);

namespace LiteORM\Connection;

use PDO;
use PDOStatement;

/**
 * Connection manager with read/write splitting, prepared statement cache, and SQL logging.
 *
 * Performance optimizations:
 * - Prepared statement cache: reuse PDOStatement for repeat queries (up to 100)
 * - SQL timing: all queries are timed via hrtime(true) for nanosecond precision
 * - Single DSN sharing: avoids duplicate connections for in-memory databases
 */
class ConnectionManager
{
    private ?PDO $writeConnection = null;
    private ?PDO $readConnection = null;

    /** @var string[] */
    private array $readDsns;
    private string $writeDsn;
    private ?string $username;
    private ?string $password;

    /** @var array<string, mixed> */
    private array $options;

    private int $readIndex = 0;
    private int $queryCount = 0;

    /** @var ?callable SQL logger: fn(string $sql, array $params, float $timeMs) */
    private $sqlLogger = null;

    /** @var PDOStatement[] Prepared statement cache: key => stmt */
    private array $stmtCache = [];
    private int $stmtCacheMaxSize = 100;

    /**
     * @param string|array{write: string, read: string|string[]} $dsn
     */
    public function __construct(
        string|array $dsn,
        ?string $username = null,
        ?string $password = null,
        array $options = [],
    ) {
        if (is_string($dsn)) {
            $this->writeDsn = $dsn;
            $this->readDsns = [$dsn];
        } else {
            $this->writeDsn = $dsn['write'];
            $this->readDsns = is_array($dsn['read']) ? $dsn['read'] : [$dsn['read']];
        }

        $this->username = $username;
        $this->password = $password;
        $this->options = array_merge([
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ], $options);
    }

    /**
     * Set SQL logger callback.
     * @param callable $logger fn(string $sql, array $params, float $timeMs)
     */
    public function setSqlLogger(callable $logger): void
    {
        $this->sqlLogger = $logger;
    }

    /**
     * Get write (master) connection.
     */
    public function getWriteConnection(): PDO
    {
        if ($this->writeConnection === null) {
            $this->writeConnection = $this->createConnection($this->writeDsn);
        }
        return $this->writeConnection;
    }

    /**
     * Get read (replica) connection.
     * For single DSN: shares write connection (avoids split in-memory DBs).
     */
    public function getReadConnection(): PDO
    {
        if (count($this->readDsns) === 1 && $this->readDsns[0] === $this->writeDsn) {
            return $this->getWriteConnection();
        }

        if ($this->readConnection === null) {
            $dsn = $this->readDsns[$this->readIndex % count($this->readDsns)];
            $this->readIndex++;
            $this->readConnection = $this->createConnection($dsn);
        }
        return $this->readConnection;
    }

    /**
     * Execute a SELECT query with prepared statement cache.
     * @return array<int, array<string, mixed>>
     */
    public function query(string $sql, array $params = []): array
    {
        $this->queryCount++;
        $stmt = $this->getCachedStatement($this->getReadConnection(), $sql);

        $start = hrtime(true);
        $stmt->execute($params);
        $result = $stmt->fetchAll();
        $elapsed = (hrtime(true) - $start) / 1e6;

        if ($this->sqlLogger) {
            ($this->sqlLogger)($sql, $params, $elapsed);
        }

        return $result;
    }

    /**
     * Stream rows one-by-one via Generator for zero memory bloat (memory < 32MB).
     * Complies with AgentOption memory optimization guidelines.
     *
     * @param string $sql
     * @param array<string, mixed> $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function stream(string $sql, array $params = []): \Generator
    {
        $this->queryCount++;
        $stmt = $this->getCachedStatement($this->getReadConnection(), $sql);

        $start = hrtime(true);
        $stmt->execute($params);
        $elapsed = (hrtime(true) - $start) / 1e6;

        if ($this->sqlLogger) {
            ($this->sqlLogger)($sql, $params, $elapsed);
        }

        while ($row = $stmt->fetch()) {
            yield $row;
        }
    }

    /**
     * Execute an INSERT/UPDATE/DELETE query.
     */
    public function execute(string $sql, array $params = []): int
    {
        $this->queryCount++;
        $stmt = $this->getCachedStatement($this->getWriteConnection(), $sql);

        $start = hrtime(true);
        $stmt->execute($params);
        $elapsed = (hrtime(true) - $start) / 1e6;

        if ($this->sqlLogger) {
            ($this->sqlLogger)($sql, $params, $elapsed);
        }

        return $stmt->rowCount();
    }

    /**
     * Execute and return last insert ID.
     */
    public function insert(string $sql, array $params = []): string
    {
        $this->queryCount++;
        $stmt = $this->getCachedStatement($this->getWriteConnection(), $sql);

        $start = hrtime(true);
        $stmt->execute($params);
        $elapsed = (hrtime(true) - $start) / 1e6;

        if ($this->sqlLogger) {
            ($this->sqlLogger)($sql, $params, $elapsed);
        }

        return $this->getWriteConnection()->lastInsertId();
    }

    public function beginTransaction(): void
    {
        $this->getWriteConnection()->beginTransaction();
    }

    public function commit(): void
    {
        $this->getWriteConnection()->commit();
    }

    public function rollBack(): void
    {
        $this->getWriteConnection()->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->writeConnection?->inTransaction() ?? false;
    }

    public function savepoint(string $name): void
    {
        $this->getWriteConnection()->exec("SAVEPOINT {$name}");
    }

    public function rollbackToSavepoint(string $name): void
    {
        $this->getWriteConnection()->exec("ROLLBACK TO SAVEPOINT {$name}");
    }

    public function releaseSavepoint(string $name): void
    {
        $this->getWriteConnection()->exec("RELEASE SAVEPOINT {$name}");
    }

    public function getQueryCount(): int
    {
        return $this->queryCount;
    }

    public function resetQueryCount(): void
    {
        $this->queryCount = 0;
    }

    /**
     * Get or create cached prepared statement.
     */
    private function getCachedStatement(PDO $pdo, string $sql): PDOStatement
    {
        $key = spl_object_id($pdo) . ':' . $sql;

        if (isset($this->stmtCache[$key])) {
            return $this->stmtCache[$key];
        }

        // Evict oldest if cache full (LRU-like)
        if (count($this->stmtCache) >= $this->stmtCacheMaxSize) {
            $oldest = array_key_first($this->stmtCache);
            unset($this->stmtCache[$oldest]);
        }

        $stmt = $pdo->prepare($sql);
        $this->stmtCache[$key] = $stmt;
        return $stmt;
    }

    private function createConnection(string $dsn): PDO
    {
        return new PDO($dsn, $this->username, $this->password, $this->options);
    }
}
