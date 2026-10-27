<?php
declare(strict_types=1);

namespace TinyCat\Database;

use InvalidArgumentException;

final class Query
{
    private string $baseSql;
    /** @var list<string> */
    private array $joins = [];
    /** @var list<string> */
    private array $wheres = [];
    /** @var array<string|int, mixed> */
    private array $params = [];
    private string $groupSql = '';
    private string $orderSql = '';
    private ?int $limitValue = null;
    private int $offsetValue = 0;

    /** @param array<string|int, mixed> $params */
    public function __construct(string $sql, array $params = [])
    {
        $sql = rtrim(trim($sql), ';');

        if ($sql === '') {
            throw new InvalidArgumentException('Query SQL cannot be empty.');
        }

        $this->baseSql = $sql;
        $this->params = $params;
    }

    public function join(string $sql): self
    {
        $sql = rtrim(trim($sql), ';');

        if ($sql !== '') {
            $this->joins[] = $sql;
        }

        return $this;
    }

    public function where(string $condition, mixed ...$params): self
    {
        $condition = trim($condition);

        if ($condition !== '') {
            $this->wheres[] = $condition;
            $this->addParams($params);
        }

        return $this;
    }

    /**
     * @param array<mixed> $values
     */
    public function whereIn(string $column, array $values): self
    {
        $column = trim($column);

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $column)) {
            throw new InvalidArgumentException('Invalid SQL column: ' . $column);
        }

        $values = array_values($values);

        if ($values === []) {
            return $this->where('1 = 0');
        }

        return $this->where($column . ' IN (' . implode(', ', array_fill(0, count($values), '?')) . ')', $values);
    }

    public function order(string $sql): self
    {
        $this->orderSql = trim($sql);

        return $this;
    }

    public function group(string $sql): self
    {
        $this->groupSql = trim($sql);

        return $this;
    }

    public function limit(?int $limit, int $offset = 0): self
    {
        $this->limitValue = $limit === null ? null : max(0, $limit);
        $this->offsetValue = max(0, $offset);

        return $this;
    }

    /**
     * @return array<mixed>
     */
    public function all(): array
    {
        return Connection::all($this->sql(), $this->params);
    }

    /**
     * @return array<mixed>|null
     */
    public function one(): ?array
    {
        return Connection::one($this->sql(), $this->params);
    }

    public function value(): mixed
    {
        return Connection::value($this->sql(), $this->params);
    }

    private function sql(): string
    {
        return $this->buildSql(true);
    }

    private function buildSql(bool $includeOrderAndLimit): string
    {
        $sql = $this->baseSql;

        if ($this->joins !== []) {
            $sql .= "\n" . implode("\n", $this->joins);
        }

        if ($this->wheres !== []) {
            $sql .= "\nWHERE " . implode("\n    AND ", array_map(
                static fn (string $condition): string => '(' . $condition . ')',
                $this->wheres
            ));
        }

        if ($this->groupSql !== '') {
            $sql .= "\nGROUP BY " . $this->groupSql;
        }

        if (!$includeOrderAndLimit) {
            return $sql;
        }

        if ($this->orderSql !== '') {
            $sql .= "\nORDER BY " . $this->orderSql;
        }

        if ($this->limitValue !== null) {
            $sql .= "\nLIMIT " . $this->limitValue;

            if ($this->offsetValue > 0) {
                $sql .= ' OFFSET ' . $this->offsetValue;
            }
        } elseif ($this->offsetValue > 0) {
            $sql .= "\nLIMIT " . PHP_INT_MAX . ' OFFSET ' . $this->offsetValue;
        }

        return $sql;
    }

    /**
     * @param array<mixed> $params
     */
    private function addParams(array $params): void
    {
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $this->addParams($value);
                continue;
            }

            if (is_string($key)) {
                $this->params[$key] = $value;
            } else {
                $this->params[] = $value;
            }
        }
    }
}
