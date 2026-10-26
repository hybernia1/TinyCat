<?php
declare(strict_types=1);

namespace TinyCat\Tools\Performance;

use PDO;
use PDOStatement;

/** Benchmark-only instrumentation; never loaded by the application. */
final class ProfileDatabase extends PDO
{
    /** @var list<array{sql: string, ms: float}> */
    public array $queries = [];

    public function __construct(string $dsn, string $user, string $password)
    {
        parent::__construct($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [ProfileStatement::class, [$this]]);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $start = hrtime(true);
        try {
            return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
        } finally {
            $this->record($query, $start);
        }
    }

    public function exec(string $statement): int|false
    {
        $start = hrtime(true);
        try {
            return parent::exec($statement);
        } finally {
            $this->record($statement, $start);
        }
    }

    public function record(string $sql, int $start): void
    {
        $this->queries[] = ['sql' => $sql, 'ms' => round((hrtime(true) - $start) / 1_000_000, 3)];
    }
}

final class ProfileStatement extends PDOStatement
{
    protected function __construct(private readonly ProfileDatabase $database)
    {
    }

    public function execute(?array $params = null): bool
    {
        $start = hrtime(true);
        try {
            return parent::execute($params);
        } finally {
            $this->database->record($this->queryString, $start);
        }
    }
}
