<?php

/**
 * In-memory stand-in for WHMCS\Database\Capsule (the Illuminate query builder
 * WHMCS exposes to modules), so the entry file's database branches - the
 * cross-product property reader, the per-server service rows, the usage
 * writer, the adoption property writer - run in tests instead of only their
 * "class is absent" fallbacks.
 *
 * DISABLED BY DEFAULT. WHMCS core is absent from the test runtime, and the
 * module resolves this class with class_exists() before touching it. Because
 * the class now exists for every test, a query made while the double is
 * disabled throws CapsuleUnavailableException - a RuntimeException carrying
 * the same text production raises when the class is missing - so a test that
 * has not opted in sees exactly the behaviour of a host without the class.
 * A test opts in with Capsule::enable() or Capsule::seed().
 *
 * Fidelity notes:
 * - Rows come back as stdClass objects, as Illuminate returns them for
 *   table() queries; get()/pluck() return an iterable collection double.
 * - Joins are equality joins evaluated as nested loops. Unqualified column
 *   names resolve against the base table first, then the joined tables in
 *   join order (MySQL would reject an ambiguous name; the double resolves it
 *   silently, so qualify names in production code as WHMCS's schema requires).
 * - where() chains are evaluated left to right (and/or without precedence).
 * - Rows are returned in insertion order, which is what MySQL returns for a
 *   primary-key scan; a query that needs a particular order must say so.
 * - Capsule::raw('now()') resolves to Capsule::$now on write, so a test can
 *   pin the clock a `lastupdate` column is stamped with.
 */

declare(strict_types=1);

namespace WHMCS\Database;

final class CapsuleUnavailableException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('WHMCS\\Database\\Capsule is unavailable');
    }
}

final class Capsule
{
    public static bool $enabled = false;

    /** @var array<string, array<int, array<string, mixed>>> table => rows keyed by position */
    public static array $tables = [];

    /** What Capsule::raw('now()') stamps on write. */
    public static string $now = '2026-09-24 12:00:00';

    /** @var list<array{table: string, op: string, values: array<string, mixed>, affected: int}> */
    public static array $writes = [];

    /** @var array<string, int> */
    private static array $nextIds = [];

    public static function reset(): void
    {
        self::$enabled = false;
        self::$tables = [];
        self::$writes = [];
        self::$nextIds = [];
        self::$now = '2026-09-24 12:00:00';
    }

    public static function enable(): void
    {
        self::$enabled = true;
    }

    /**
     * Enable the double and append rows to a table. A row without an `id`
     * gets the next auto-increment value for that table.
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function seed(string $table, array $rows): void
    {
        self::$enabled = true;
        foreach ($rows as $row) {
            self::append($table, $row);
        }
    }

    /** @return list<array<string, mixed>> */
    public static function rows(string $table): array
    {
        return array_values(self::$tables[$table] ?? []);
    }

    public static function table(string $table): CapsuleQueryDouble
    {
        return new CapsuleQueryDouble($table);
    }

    public static function raw(string $expression): CapsuleRawDouble
    {
        return new CapsuleRawDouble($expression);
    }

    /**
     * @param array<string, mixed> $row
     * @internal used by the query double
     */
    public static function append(string $table, array $row): int
    {
        $next = self::$nextIds[$table] ?? 1;
        if (!isset($row['id']) || !is_numeric($row['id'])) {
            $row['id'] = $next;
        }
        self::$nextIds[$table] = max($next, (int) $row['id'] + 1);
        self::$tables[$table][] = $row;

        return (int) $row['id'];
    }

    /** @internal */
    public static function assertEnabled(): void
    {
        if (!self::$enabled) {
            throw new CapsuleUnavailableException();
        }
    }
}

final class CapsuleRawDouble implements \Stringable
{
    public function __construct(public readonly string $expression)
    {
    }

    public function __toString(): string
    {
        return $this->expression;
    }
}

/**
 * @extends \ArrayIterator<int, mixed>
 */
final class CapsuleCollectionDouble extends \ArrayIterator
{
    /** @return list<mixed> */
    public function all(): array
    {
        return array_values($this->getArrayCopy());
    }

    /** @return list<mixed> */
    public function toArray(): array
    {
        return $this->all();
    }

    public function first(): mixed
    {
        $all = $this->all();

        return $all[0] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }
}

final class CapsuleQueryDouble
{
    /** @var list<array{type: string, table: string, left: string, right: string}> */
    private array $joins = [];

    /** @var list<array{glue: string, test: \Closure(array<string, mixed>): bool}> */
    private array $wheres = [];

    /** @var list<array{column: string, desc: bool}> */
    private array $orders = [];

    private ?int $limit = null;

    /** @var list<string> */
    private array $columns = ['*'];

    public function __construct(private readonly string $table)
    {
    }

    /** @param string|list<string> $columns */
    public function select(string|array ...$columns): static
    {
        $flat = [];
        foreach ($columns as $column) {
            foreach ((array) $column as $name) {
                $flat[] = (string) $name;
            }
        }
        $this->columns = $flat === [] ? ['*'] : $flat;

        return $this;
    }

    public function join(string $table, string $first, string $operator, string $second): static
    {
        $this->joins[] = ['type' => 'inner', 'table' => $table, 'left' => $first, 'right' => $second];

        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): static
    {
        $this->joins[] = ['type' => 'left', 'table' => $table, 'left' => $first, 'right' => $second];

        return $this;
    }

    public function where(string|\Closure $column, mixed $operatorOrValue = null, mixed $value = null): static
    {
        return $this->addWhere('and', $column, $operatorOrValue, $value, func_num_args());
    }

    public function orWhere(string|\Closure $column, mixed $operatorOrValue = null, mixed $value = null): static
    {
        return $this->addWhere('or', $column, $operatorOrValue, $value, func_num_args());
    }

    /** @param list<mixed> $values */
    public function whereIn(string $column, array $values): static
    {
        $this->wheres[] = ['glue' => 'and', 'test' => static function (array $row) use ($column, $values): bool {
            return in_array(self::valueOf($row, $column), $values, false);
        }];

        return $this;
    }

    /** @param list<mixed> $values */
    public function whereNotIn(string $column, array $values): static
    {
        $this->wheres[] = ['glue' => 'and', 'test' => static function (array $row) use ($column, $values): bool {
            return !in_array(self::valueOf($row, $column), $values, false);
        }];

        return $this;
    }

    public function whereNull(string $column): static
    {
        $this->wheres[] = ['glue' => 'and', 'test' => static fn (array $row): bool => self::valueOf($row, $column) === null];

        return $this;
    }

    public function whereNotNull(string $column): static
    {
        $this->wheres[] = ['glue' => 'and', 'test' => static fn (array $row): bool => self::valueOf($row, $column) !== null];

        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): static
    {
        $this->orders[] = ['column' => $column, 'desc' => strtolower($direction) === 'desc'];

        return $this;
    }

    public function orderByDesc(string $column): static
    {
        return $this->orderBy($column, 'desc');
    }

    public function limit(int $count): static
    {
        $this->limit = $count;

        return $this;
    }

    public function take(int $count): static
    {
        return $this->limit($count);
    }

    /**
     * Returns a collection of stdClass rows. Declared `mixed` to mirror the
     * real WHMCS Capsule builder, whose Illuminate return type this project
     * does not ship: production code therefore sees an untyped result and
     * guards it with is_iterable(), and the double must not make those guards
     * look redundant to static analysis.
     *
     * @param string|list<string> $columns
     */
    public function get(string|array $columns = ['*']): mixed
    {
        Capsule::assertEnabled();
        $columns = (array) $columns;
        $projection = $columns === ['*'] ? $this->columns : $columns;

        $result = [];
        foreach ($this->matchingRows() as $row) {
            $result[] = $this->project($row, $projection);
        }

        return new CapsuleCollectionDouble($result);
    }

    /** @param string|list<string> $columns */
    public function first(string|array $columns = ['*']): ?\stdClass
    {
        Capsule::assertEnabled();
        $columns = (array) $columns;
        $projection = $columns === ['*'] ? $this->columns : $columns;

        foreach ($this->matchingRows() as $row) {
            return $this->project($row, $projection);
        }

        return null;
    }

    public function value(string $column): mixed
    {
        Capsule::assertEnabled();
        foreach ($this->matchingRows() as $row) {
            return self::valueOf($row, $column);
        }

        return null;
    }

    /** Declared `mixed` for the same reason as get(): mirror the real, untyped WHMCS builder. */
    public function pluck(string $column, ?string $key = null): mixed
    {
        Capsule::assertEnabled();
        $values = [];
        foreach ($this->matchingRows() as $row) {
            if ($key === null) {
                $values[] = self::valueOf($row, $column);
            } else {
                $values[(string) self::valueOf($row, $key)] = self::valueOf($row, $column);
            }
        }

        return new CapsuleCollectionDouble($values);
    }

    public function exists(): bool
    {
        Capsule::assertEnabled();
        foreach ($this->matchingRows() as $row) {
            return true;
        }

        return false;
    }

    public function doesntExist(): bool
    {
        return !$this->exists();
    }

    public function count(): int
    {
        Capsule::assertEnabled();

        return count($this->matchingRows());
    }

    /**
     * @param array<string, mixed> $values
     * @return int affected rows
     */
    public function update(array $values): int
    {
        Capsule::assertEnabled();
        $resolved = [];
        foreach ($values as $column => $value) {
            $resolved[$column] = $value instanceof CapsuleRawDouble
                ? (strtolower(trim($value->expression)) === 'now()' ? Capsule::$now : $value->expression)
                : $value;
        }

        $affected = 0;
        foreach ($this->matchingRows() as $row) {
            $index = $row['__index'];
            foreach ($resolved as $column => $value) {
                Capsule::$tables[$this->table][$index][self::unqualified($column)] = $value;
            }
            $affected++;
        }
        Capsule::$writes[] = ['table' => $this->table, 'op' => 'update', 'values' => $resolved, 'affected' => $affected];

        return $affected;
    }

    /** @param array<string, mixed>|list<array<string, mixed>> $values */
    public function insert(array $values): bool
    {
        Capsule::assertEnabled();
        $rows = array_is_list($values) ? $values : [$values];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $row */
            Capsule::append($this->table, $row);
            Capsule::$writes[] = ['table' => $this->table, 'op' => 'insert', 'values' => $row, 'affected' => 1];
        }

        return true;
    }

    /** @param array<string, mixed> $values */
    public function insertGetId(array $values): int
    {
        Capsule::assertEnabled();
        $id = Capsule::append($this->table, $values);
        Capsule::$writes[] = ['table' => $this->table, 'op' => 'insert', 'values' => $values, 'affected' => 1];

        return $id;
    }

    public function delete(): int
    {
        Capsule::assertEnabled();
        $affected = 0;
        foreach ($this->matchingRows() as $row) {
            unset(Capsule::$tables[$this->table][$row['__index']]);
            $affected++;
        }
        Capsule::$writes[] = ['table' => $this->table, 'op' => 'delete', 'values' => [], 'affected' => $affected];

        return $affected;
    }

    // -----------------------------------------------------------------------

    private function addWhere(string $glue, string|\Closure $column, mixed $operatorOrValue, mixed $value, int $argCount): static
    {
        if ($column instanceof \Closure) {
            $nested = new self($this->table);
            $nested->joins = $this->joins;
            $column($nested);
            $this->wheres[] = ['glue' => $glue, 'test' => static fn (array $row): bool => $nested->passes($row)];

            return $this;
        }

        [$operator, $expected] = $argCount >= 3 ? [strtolower((string) $operatorOrValue), $value] : ['=', $operatorOrValue];

        $this->wheres[] = ['glue' => $glue, 'test' => static function (array $row) use ($column, $operator, $expected): bool {
            $actual = self::valueOf($row, $column);

            return match ($operator) {
                '=' => self::loosely($actual, $expected),
                '!=', '<>' => !self::loosely($actual, $expected),
                '<' => $actual < $expected,
                '<=' => $actual <= $expected,
                '>' => $actual > $expected,
                '>=' => $actual >= $expected,
                'like' => (bool) preg_match(
                    '/^' . str_replace(['%', '_'], ['.*', '.'], preg_quote((string) $expected, '/')) . '$/is',
                    (string) $actual
                ),
                default => throw new \InvalidArgumentException('CapsuleDouble: unsupported operator ' . $operator),
            };
        }];

        return $this;
    }

    /** MySQL compares '42' and 42 as equal; a string column against an int value must match here too. */
    private static function loosely(mixed $actual, mixed $expected): bool
    {
        if ($actual === null || $expected === null) {
            return $actual === $expected;
        }
        if (is_numeric($actual) && is_numeric($expected)) {
            return (string) (float) $actual === (string) (float) $expected;
        }

        return (string) $actual === (string) $expected;
    }

    /** @param array<string, mixed> $row */
    private function passes(array $row): bool
    {
        $result = null;
        foreach ($this->wheres as $where) {
            $outcome = ($where['test'])($row);
            $result = $result === null
                ? $outcome
                : ($where['glue'] === 'or' ? ($result || $outcome) : ($result && $outcome));
        }

        return $result ?? true;
    }

    /**
     * Every row of the base table joined per the join list, resolved into one
     * flat map of "table.column" and "column" keys, filtered, ordered, limited.
     * The base row's position travels along as __index so writes can find it.
     *
     * @return list<array<string, mixed>>
     */
    private function matchingRows(): array
    {
        $resolved = [];
        foreach (Capsule::$tables[$this->table] ?? [] as $index => $row) {
            $flat = ['__index' => $index];
            foreach ($row as $column => $value) {
                $flat[$this->table . '.' . $column] = $value;
                $flat[$column] = $value;
            }
            $resolved[] = $flat;
        }

        foreach ($this->joins as $join) {
            $next = [];
            foreach ($resolved as $flat) {
                $matched = false;
                foreach (Capsule::$tables[$join['table']] ?? [] as $joinRow) {
                    $candidate = $flat;
                    foreach ($joinRow as $column => $value) {
                        $candidate[$join['table'] . '.' . $column] = $value;
                        if (!array_key_exists($column, $candidate)) {
                            $candidate[$column] = $value;
                        }
                    }
                    if (self::loosely(self::valueOf($candidate, $join['left']), self::valueOf($candidate, $join['right']))) {
                        $next[] = $candidate;
                        $matched = true;
                    }
                }
                if (!$matched && $join['type'] === 'left') {
                    $next[] = $flat;
                }
            }
            $resolved = $next;
        }

        $resolved = array_values(array_filter($resolved, fn (array $row): bool => $this->passes($row)));

        if ($this->orders !== []) {
            usort($resolved, function (array $a, array $b): int {
                foreach ($this->orders as $order) {
                    $left = self::valueOf($a, $order['column']);
                    $right = self::valueOf($b, $order['column']);
                    $cmp = is_numeric($left) && is_numeric($right)
                        ? (float) $left <=> (float) $right
                        : strcmp((string) $left, (string) $right);
                    if ($cmp !== 0) {
                        return $order['desc'] ? -$cmp : $cmp;
                    }
                }

                return 0;
            });
        }

        if ($this->limit !== null) {
            $resolved = array_slice($resolved, 0, $this->limit);
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $columns
     */
    private function project(array $row, array $columns): \stdClass
    {
        $object = new \stdClass();
        foreach ($columns as $column) {
            if ($column === '*') {
                foreach ($row as $key => $value) {
                    if ($key !== '__index' && !str_contains((string) $key, '.')) {
                        $object->{$key} = $value;
                    }
                }

                continue;
            }
            if (preg_match('/^(.+?)\s+as\s+(\S+)$/i', trim($column), $m) === 1) {
                $object->{$m[2]} = self::valueOf($row, $m[1]);

                continue;
            }
            $object->{self::unqualified($column)} = self::valueOf($row, $column);
        }

        return $object;
    }

    /** @param array<string, mixed> $row */
    private static function valueOf(array $row, string $column): mixed
    {
        return $row[trim($column)] ?? null;
    }

    private static function unqualified(string $column): string
    {
        $parts = explode('.', $column);

        return end($parts);
    }
}
