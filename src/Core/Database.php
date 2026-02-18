<?php

namespace SDS\Core;

/**
 * Database — Singleton PDO wrapper for MySQL.
 *
 * Provides convenient query helpers while exposing the raw PDO
 * for anything that falls outside the helper surface.
 */
class Database
{
    /** @var Database|null Singleton instance */
    private static ?Database $instance = null;

    /** @var \PDO Underlying PDO connection */
    private \PDO $pdo;

    /**
     * Create connection from config array.
     *
     * @param array $config  Keys: host, port, name, user, password, charset
     */
    public function __construct(array $config)
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'] ?? 3306,
            $config['name'],
            $config['charset'] ?? 'utf8mb4'
        );

        $options = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
            \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ];

        $this->pdo = new \PDO($dsn, $config['user'], $config['password'], $options);
    }

    /* ------------------------------------------------------------------
     *  Singleton management
     * ----------------------------------------------------------------*/

    /**
     * Initialise the singleton with a config array.
     */
    public static function init(array $config): self
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    /**
     * Return the singleton instance (must have been init'd first).
     *
     * @throws \RuntimeException
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('Database has not been initialised. Call Database::init() first.');
        }
        return self::$instance;
    }

    /**
     * Return the raw PDO handle.
     */
    public function getPdo(): \PDO
    {
        return $this->pdo;
    }

    /* ------------------------------------------------------------------
     *  Query helpers
     * ----------------------------------------------------------------*/

    /**
     * Execute a prepared statement and return the PDOStatement.
     *
     * @param string $sql    SQL with ? or named placeholders
     * @param array  $params Bind values
     * @return \PDOStatement
     */
    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Fetch a single row as associative array (or null).
     */
    public function fetch(string $sql, array $params = []): ?array
    {
        $stmt = $this->query($sql, $params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Fetch all rows as an array of associative arrays.
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Insert a row into $table using an associative array.
     *
     * @param string $table  Table name (unquoted — trusted input only)
     * @param array  $data   Column => value pairs
     * @return string        Last insert ID
     */
    public function insert(string $table, array $data): string
    {
        $columns      = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');

        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $table,
            implode('`, `', $columns),
            implode(', ', $placeholders)
        );

        $this->query($sql, array_values($data));
        return $this->pdo->lastInsertId();
    }

    /**
     * Update rows in $table.
     *
     * @param string $table       Table name
     * @param array  $data        Column => value pairs to SET
     * @param string $where       WHERE clause (e.g. "id = ?")
     * @param array  $whereParams Bind values for the WHERE clause
     * @return int                Number of affected rows
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $setParts = [];
        foreach (array_keys($data) as $col) {
            $setParts[] = "`{$col}` = ?";
        }

        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE %s',
            $table,
            implode(', ', $setParts),
            $where
        );

        $params = array_merge(array_values($data), $whereParams);
        $stmt   = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * Delete rows from $table.
     *
     * @param string $table       Table name
     * @param string $where       WHERE clause
     * @param array  $whereParams Bind values for the WHERE clause
     * @return int                Number of affected rows
     */
    public function delete(string $table, string $where, array $whereParams = []): int
    {
        $sql  = sprintf('DELETE FROM `%s` WHERE %s', $table, $where);
        $stmt = $this->query($sql, $whereParams);
        return $stmt->rowCount();
    }

    /**
     * Return the last insert ID from the connection.
     */
    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    /* ------------------------------------------------------------------
     *  Transaction helpers
     * ----------------------------------------------------------------*/

    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    public function rollback(): bool
    {
        return $this->pdo->rollBack();
    }
}
