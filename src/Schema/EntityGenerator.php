<?php

declare(strict_types=1);

namespace LiteORM\Schema;

use LiteORM\Connection\ConnectionManager;

/**
 * Generates PHP entity classes from existing database tables.
 * Supports MySQL, PostgreSQL, and SQLite.
 *
 * Universe Architecture #4: Registry — auto-discover entities from DB schema.
 *
 * Usage:
 *   $gen = new EntityGenerator($connection);
 *   $gen->generate('users');                     // Single table
 *   $gen->generateAll();                         // All tables
 *   $gen->generateToFile('users', './Entity/');  // To file
 */
class EntityGenerator
{
    private ConnectionManager $conn;
    private string $driver;
    private string $namespace;

    public function __construct(
        ConnectionManager $conn,
        string $namespace = 'App\\Entity',
    ) {
        $this->conn = $conn;
        $this->namespace = $namespace;

        // Detect driver
        $pdo = $conn->getReadConnection();
        $this->driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }

    /**
     * Get all table names from the database.
     * @return string[]
     */
    public function getTables(): array
    {
        $sql = match ($this->driver) {
            'mysql' => "SHOW TABLES",
            'pgsql' => "SELECT tablename FROM pg_tables WHERE schemaname = 'public'",
            'sqlite' => "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'",
            default => throw new \RuntimeException("Unsupported driver: {$this->driver}"),
        };

        $rows = $this->conn->query($sql);
        return array_map(fn($row) => array_values($row)[0], $rows);
    }

    /**
     * Get column information for a table.
     * @return array<int, array{name: string, type: string, nullable: bool, pk: bool, default: mixed}>
     */
    public function getColumns(string $table): array
    {
        return match ($this->driver) {
            'mysql' => $this->getMysqlColumns($table),
            'pgsql' => $this->getPgsqlColumns($table),
            'sqlite' => $this->getSqliteColumns($table),
            default => throw new \RuntimeException("Unsupported driver: {$this->driver}"),
        };
    }

    /**
     * Generate PHP entity class code for a table.
     */
    public function generate(string $table): string
    {
        $columns = $this->getColumns($table);
        $className = $this->tableToClassName($table);

        $lines = [];
        $lines[] = "<?php";
        $lines[] = "";
        $lines[] = "declare(strict_types=1);";
        $lines[] = "";
        $lines[] = "namespace {$this->namespace};";
        $lines[] = "";
        $lines[] = "use LiteORM\\Attribute\\{Entity, Table, Column, Id, AutoIncrement, CreatedAt, UpdatedAt};";
        $lines[] = "";
        $lines[] = "#[Entity]";
        $lines[] = "#[Table('{$table}')]";
        $lines[] = "class {$className}";
        $lines[] = "{";

        foreach ($columns as $col) {
            $attributes = $this->generateColumnAttributes($col);
            foreach ($attributes as $attr) {
                $lines[] = "    {$attr}";
            }

            $phpType = $this->dbTypeToPhpType($col['type']);
            $nullable = $col['nullable'] && !$col['pk'] ? '?' : '';
            $default = $col['nullable'] && !$col['pk'] ? ' = null' : '';

            $propName = $this->columnToPropertyName($col['name']);
            $lines[] = "    public {$nullable}{$phpType} \${$propName}{$default};";
            $lines[] = "";
        }

        // Remove trailing empty line
        if (end($lines) === '') array_pop($lines);
        $lines[] = "}";
        $lines[] = "";

        return implode("\n", $lines);
    }

    /**
     * Generate entity classes for all tables.
     * @return array<string, string> table => code
     */
    public function generateAll(): array
    {
        $result = [];
        foreach ($this->getTables() as $table) {
            $result[$table] = $this->generate($table);
        }
        return $result;
    }

    /**
     * Generate and save entity class to a file.
     */
    public function generateToFile(string $table, string $outputDir): string
    {
        $code = $this->generate($table);
        $className = $this->tableToClassName($table);

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $filePath = rtrim($outputDir, '/\\') . "/{$className}.php";
        file_put_contents($filePath, $code);
        return $filePath;
    }

    /**
     * Generate all entities to files.
     * @return string[] Generated file paths
     */
    public function generateAllToFiles(string $outputDir): array
    {
        $paths = [];
        foreach ($this->getTables() as $table) {
            $paths[] = $this->generateToFile($table, $outputDir);
        }
        return $paths;
    }

    // ─── Column Readers ───────────────────────────────────────────

    private function getMysqlColumns(string $table): array
    {
        $rows = $this->conn->query("DESCRIBE `{$table}`");
        return array_map(fn($row) => [
            'name' => $row['Field'],
            'type' => $row['Type'],
            'nullable' => $row['Null'] === 'YES',
            'pk' => $row['Key'] === 'PRI',
            'default' => $row['Default'],
            'auto' => str_contains($row['Extra'] ?? '', 'auto_increment'),
        ], $rows);
    }

    private function getPgsqlColumns(string $table): array
    {
        $sql = "SELECT column_name, data_type, is_nullable, column_default 
                FROM information_schema.columns 
                WHERE table_name = :table ORDER BY ordinal_position";
        $rows = $this->conn->query($sql, ['table' => $table]);

        // Get primary key
        $pkSql = "SELECT a.attname FROM pg_index i 
                  JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
                  WHERE i.indrelid = :table::regclass AND i.indisprimary";
        $pkRows = $this->conn->query($pkSql, ['table' => $table]);
        $pks = array_column($pkRows, 'attname');

        return array_map(fn($row) => [
            'name' => $row['column_name'],
            'type' => $row['data_type'],
            'nullable' => $row['is_nullable'] === 'YES',
            'pk' => in_array($row['column_name'], $pks),
            'default' => $row['column_default'],
            'auto' => str_contains($row['column_default'] ?? '', 'nextval'),
        ], $rows);
    }

    private function getSqliteColumns(string $table): array
    {
        $rows = $this->conn->query("PRAGMA table_info(`{$table}`)");
        return array_map(fn($row) => [
            'name' => $row['name'],
            'type' => $row['type'] ?: 'TEXT',
            'nullable' => $row['notnull'] == 0,
            'pk' => $row['pk'] == 1,
            'default' => $row['dflt_value'],
            'auto' => $row['pk'] == 1 && strtoupper($row['type'] ?: '') === 'INTEGER',
        ], $rows);
    }

    // ─── Type Mapping ─────────────────────────────────────────────

    private function dbTypeToPhpType(string $dbType): string
    {
        $type = strtolower(preg_replace('/\(.*\)/', '', $dbType));
        $type = trim($type);

        return match (true) {
            in_array($type, ['int', 'integer', 'bigint', 'smallint', 'tinyint', 'mediumint']) => 'int',
            in_array($type, ['float', 'double', 'decimal', 'numeric', 'real', 'double precision']) => 'float',
            in_array($type, ['bool', 'boolean']) => 'bool',
            in_array($type, ['datetime', 'timestamp', 'timestamp without time zone', 'timestamp with time zone']) => 'DateTimeImmutable',
            in_array($type, ['date']) => 'DateTimeImmutable',
            default => 'string',
        };
    }

    private function generateColumnAttributes(array $col): array
    {
        $attrs = [];

        if ($col['pk']) {
            $attrStr = '#[Id';
            if ($col['auto'] ?? false) {
                $attrStr .= ', AutoIncrement';
            }
            $attrStr .= ']';
            $attrs[] = $attrStr;
        } elseif ($this->isTimestampColumn($col['name'], 'created')) {
            $attrs[] = '#[CreatedAt]';
        } elseif ($this->isTimestampColumn($col['name'], 'updated')) {
            $attrs[] = '#[UpdatedAt]';
        }

        // Build #[Column(...)] with relevant params
        $colParams = [];
        if ($col['name'] !== $this->columnToPropertyName($col['name'])) {
            // Only add name if it differs from auto-generated
            $colParams[] = "name: '{$col['name']}'";
        }
        if ($col['nullable'] && !$col['pk']) {
            $colParams[] = 'nullable: true';
        }

        // Detect length from type like varchar(255)
        if (preg_match('/\((\d+)\)/', $col['type'], $m)) {
            $colParams[] = "length: {$m[1]}";
        }

        if (!empty($colParams)) {
            $attrs[] = '#[Column(' . implode(', ', $colParams) . ')]';
        } elseif (empty($attrs)) {
            $attrs[] = '#[Column]';
        }

        return $attrs;
    }

    private function isTimestampColumn(string $name, string $type): bool
    {
        $name = strtolower($name);
        return match ($type) {
            'created' => in_array($name, ['created_at', 'createdat', 'date_created', 'create_date']),
            'updated' => in_array($name, ['updated_at', 'updatedat', 'date_modified', 'modify_date', 'modified_at']),
            default => false,
        };
    }

    // ─── Naming ───────────────────────────────────────────────────

    private function tableToClassName(string $table): string
    {
        // users → User, order_items → OrderItem
        $singular = $this->singularize($table);
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $singular)));
    }

    private function columnToPropertyName(string $column): string
    {
        // user_id → userId, first_name → firstName
        $parts = explode('_', $column);
        $first = array_shift($parts);
        return $first . implode('', array_map('ucfirst', $parts));
    }

    private function singularize(string $word): string
    {
        if (str_ends_with($word, 'ies')) {
            return substr($word, 0, -3) . 'y';
        }
        if (str_ends_with($word, 'ses') || str_ends_with($word, 'xes') || str_ends_with($word, 'ches') || str_ends_with($word, 'shes')) {
            return substr($word, 0, -2);
        }
        if (str_ends_with($word, 's') && !str_ends_with($word, 'ss')) {
            return substr($word, 0, -1);
        }
        return $word;
    }
}
