<?php

declare(strict_types=1);

namespace LiteORM\Connection;

use PDO;
use PDOStatement;

/**
 * Connection manager with read/write splitting support.
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
     * Get read (replica) connection. Round-robin across replicas.
     * For single DSN setup, returns the same connection as write to avoid
     * split in-memory databases (SQLite).
     */
    public function getReadConnection(): PDO
    {
        // If read DSNs are the same as write, share connection
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
     * Execute a SELECT query.
     * @return array<int, array<string, mixed>>
     */
    public function query(string $sql, array $params = []): array
    {
        $this->queryCount++;
        $stmt = $this->getReadConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Execute an INSERT/UPDATE/DELETE query.
     */
    public function execute(string $sql, array $params = []): int
    {
        $this->queryCount++;
        $stmt = $this->getWriteConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Execute and return last insert ID.
     */
    public function insert(string $sql, array $params = []): string
    {
        $this->queryCount++;
        $stmt = $this->getWriteConnection()->prepare($sql);
        $stmt->execute($params);
        return $this->getWriteConnection()->lastInsertId();
    }

    /**
     * Begin a transaction on the write connection.
     */
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

    /**
     * Total number of queries executed.
     */
    public function getQueryCount(): int
    {
        return $this->queryCount;
    }

    public function resetQueryCount(): void
    {
        $this->queryCount = 0;
    }

    private function createConnection(string $dsn): PDO
    {
        return new PDO($dsn, $this->username, $this->password, $this->options);
    }
}
