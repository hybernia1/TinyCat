<?php
declare(strict_types=1);

namespace TinyCat\Tools\DemoImport;

use PDO;
use RuntimeException;

final class BatchWriter
{
    private ?int $autoIncrementStep = null;

    public function __construct(private readonly PDO $database, private readonly int $rowLimit = 500)
    {
    }

    /**
     * @param list<string> $columns
     * @param list<list<mixed>> $rows
     * @return array{affected: int, ids: list<int>}
     */
    public function insert(string $table, array $columns, array $rows, bool $ignore = false, bool $autoIds = false): array
    {
        if ($rows === []) {
            return ['affected' => 0, 'ids' => []];
        }
        self::identifier($table);
        foreach ($columns as $column) {
            self::identifier($column);
        }
        if ($autoIds && $ignore) {
            throw new RuntimeException('Auto-ID batch inserts cannot ignore duplicate rows.');
        }
        if ($autoIds && $this->autoIncrementStep() !== 1) {
            throw new RuntimeException('Demo importer requires auto_increment_increment = 1.');
        }

        $affected = 0;
        $ids = [];
        foreach (array_chunk($rows, max(1, $this->rowLimit)) as $chunk) {
            $width = count($columns);
            foreach ($chunk as $row) {
                if (count($row) !== $width) {
                    throw new RuntimeException('Batch row width does not match its columns.');
                }
            }
            $rowPlaceholder = '(' . implode(', ', array_fill(0, $width, '?')) . ')';
            $sql = 'INSERT ' . ($ignore ? 'IGNORE ' : '') . 'INTO `' . $table . '` (`'
                . implode('`, `', $columns) . '`) VALUES '
                . implode(', ', array_fill(0, count($chunk), $rowPlaceholder));
            $values = [];
            foreach ($chunk as $row) {
                array_push($values, ...$row);
            }
            $statement = $this->database->prepare($sql);
            $statement->execute($values);
            $affected += $statement->rowCount();

            if ($autoIds) {
                $first = (int) $this->database->lastInsertId();
                if ($first < 1) {
                    throw new RuntimeException('Unable to resolve the first generated ID.');
                }
                for ($offset = 0; $offset < count($chunk); $offset++) {
                    $ids[] = $first + $offset;
                }
            }
        }

        return ['affected' => $affected, 'ids' => $ids];
    }

    private function autoIncrementStep(): int
    {
        if ($this->autoIncrementStep === null) {
            $statement = $this->database->query('SELECT @@auto_increment_increment');
            if ($statement === false) {
                throw new RuntimeException('Unable to read auto_increment_increment.');
            }
            $this->autoIncrementStep = (int) $statement->fetchColumn();
        }

        return $this->autoIncrementStep;
    }

    private static function identifier(string $identifier): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $identifier) !== 1) {
            throw new RuntimeException('Unsafe SQL identifier: ' . $identifier);
        }
    }
}
