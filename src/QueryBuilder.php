<?php

namespace QueryBuilder;

use Closure;
use InvalidArgumentException;

/**
 * Fluent SQL query builder. Every value is sent as a bound parameter;
 * identifiers (tables/columns) are quoted for the active driver.
 */
class QueryBuilder
{
    protected static $operators = array(
        '=', '<', '>', '<=', '>=', '<>', '!=',
        'like', 'not like', 'ilike', 'not ilike',
    );

    /** @var Connection */
    protected $connection;

    /** @var string|Expression|null */
    protected $table = null;
    protected $columns = array();
    protected $selectBindings = array();
    protected $distinct = false;
    /** @var JoinClause[] */
    protected $joins = array();
    protected $wheres = array();
    protected $groups = array();
    protected $havings = array();
    protected $orders = array();
    protected $limit = null;
    protected $offset = null;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /* ------------------------------------------------------------------
     |  Building: FROM / SELECT
     * ----------------------------------------------------------------*/

    /**
     * @param string|Expression $table
     * @return $this
     */
    public function from($table)
    {
        $this->table = $table;
        return $this;
    }

    /**
     * select('a', 'b.c', DB::raw('COUNT(*) AS n'))  or  select(['a', 'b'])
     *
     * @return $this
     */
    public function select()
    {
        $this->columns = $this->flatten(func_get_args());
        return $this;
    }

    /**
     * @return $this
     */
    public function addSelect()
    {
        $this->columns = array_merge($this->columns, $this->flatten(func_get_args()));
        return $this;
    }

    /**
     * @return $this
     */
    public function selectRaw($sql, array $bindings = array())
    {
        $this->columns[] = new Expression($sql);
        $this->selectBindings = array_merge($this->selectBindings, array_values($bindings));
        return $this;
    }

    /**
     * @return $this
     */
    public function distinct()
    {
        $this->distinct = true;
        return $this;
    }

    /* ------------------------------------------------------------------
     |  Building: JOIN
     * ----------------------------------------------------------------*/

    /**
     * join('orders', 'users.id', '=', 'orders.user_id')
     * join('orders', function (JoinClause $j) { $j->on(...)->where(...); })
     *
     * @param string|Expression $table
     * @param string|Closure    $first
     * @return $this
     */
    public function join($table, $first, $operator = null, $second = null, $type = 'inner')
    {
        $join = new JoinClause($type, $table);

        if ($first instanceof Closure) {
            call_user_func($first, $join);
        } else {
            if ($operator === null || $second === null) {
                throw new InvalidArgumentException('join() needs (table, first, operator, second) or (table, closure).');
            }
            $join->on($first, $this->validOperator($operator), $second);
        }

        $this->joins[] = $join;
        return $this;
    }

    /**
     * @return $this
     */
    public function leftJoin($table, $first, $operator = null, $second = null)
    {
        return $this->join($table, $first, $operator, $second, 'left');
    }

    /**
     * @return $this
     */
    public function rightJoin($table, $first, $operator = null, $second = null)
    {
        return $this->join($table, $first, $operator, $second, 'right');
    }

    /**
     * @return $this
     */
    public function crossJoin($table)
    {
        $this->joins[] = new JoinClause('cross', $table);
        return $this;
    }

    /* ------------------------------------------------------------------
     |  Building: WHERE
     * ----------------------------------------------------------------*/

    /**
     * where('status', 'active')                    // = shorthand
     * where('total', '>', 150)
     * where(['status' => 'active', 'age' => 30])
     * where(function ($q) { $q->where(...)->orWhere(...); })   // grouped (parentheses)
     *
     * @param string|Expression|Closure|array $column
     * @return $this
     */
    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        if (is_array($column)) {
            return $this->whereNested(function (QueryBuilder $q) use ($column) {
                foreach ($column as $key => $val) {
                    if (is_int($key) && is_array($val)) {
                        call_user_func_array(array($q, 'where'), $val);   // [col, op, val] or [col, val]
                    } else {
                        $q->where($key, '=', $val);
                    }
                }
            }, $boolean);
        }

        if ($column instanceof Closure) {
            return $this->whereNested($column, $boolean);
        }

        if (func_num_args() === 2) {                 // where('col', $value)
            $value = $operator;
            $operator = '=';
        }

        $operator = $this->validOperator($operator);

        if ($value === null) {                       // = null  =>  IS NULL
            if ($operator === '=') {
                return $this->whereNull($column, $boolean);
            }
            if ($operator === '<>' || $operator === '!=') {
                return $this->whereNull($column, $boolean, true);
            }
            throw new InvalidArgumentException("Operator [$operator] cannot be used with NULL.");
        }

        $this->wheres[] = array('type' => 'basic', 'column' => $column, 'operator' => $operator, 'value' => $value, 'boolean' => $boolean);
        return $this;
    }

    /**
     * @return $this
     */
    public function orWhere($column, $operator = null, $value = null)
    {
        if (func_num_args() === 2) {
            return $this->where($column, '=', $operator, 'or');
        }
        return $this->where($column, $operator, $value, 'or');
    }

    /**
     * @return $this
     */
    public function whereColumn($first, $operator, $second, $boolean = 'and')
    {
        $this->wheres[] = array('type' => 'column', 'first' => $first, 'operator' => $this->validOperator($operator), 'second' => $second, 'boolean' => $boolean);
        return $this;
    }

    /**
     * @return $this
     */
    public function whereIn($column, array $values, $boolean = 'and', $not = false)
    {
        $this->wheres[] = array('type' => 'in', 'column' => $column, 'values' => array_values($values), 'not' => $not, 'boolean' => $boolean);
        return $this;
    }

    /**
     * @return $this
     */
    public function whereNotIn($column, array $values)
    {
        return $this->whereIn($column, $values, 'and', true);
    }

    /**
     * @return $this
     */
    public function orWhereIn($column, array $values)
    {
        return $this->whereIn($column, $values, 'or');
    }

    /**
     * @return $this
     */
    public function whereNull($column, $boolean = 'and', $not = false)
    {
        $this->wheres[] = array('type' => 'null', 'column' => $column, 'not' => $not, 'boolean' => $boolean);
        return $this;
    }

    /**
     * @return $this
     */
    public function whereNotNull($column)
    {
        return $this->whereNull($column, 'and', true);
    }

    /**
     * @return $this
     */
    public function orWhereNull($column)
    {
        return $this->whereNull($column, 'or');
    }

    /**
     * @return $this
     */
    public function whereBetween($column, array $range, $boolean = 'and', $not = false)
    {
        if (count($range) !== 2) {
            throw new InvalidArgumentException('whereBetween() expects exactly two values.');
        }
        $this->wheres[] = array('type' => 'between', 'column' => $column, 'values' => array_values($range), 'not' => $not, 'boolean' => $boolean);
        return $this;
    }

    /**
     * @return $this
     */
    public function whereNotBetween($column, array $range)
    {
        return $this->whereBetween($column, $range, 'and', true);
    }

    /**
     * Raw condition with ? placeholders: whereRaw('YEAR(created_at) = ?', [2024])
     *
     * @return $this
     */
    public function whereRaw($sql, array $bindings = array(), $boolean = 'and')
    {
        $this->wheres[] = array('type' => 'raw', 'sql' => $sql, 'bindings' => array_values($bindings), 'boolean' => $boolean);
        return $this;
    }

    /**
     * @return $this
     */
    public function orWhereRaw($sql, array $bindings = array())
    {
        return $this->whereRaw($sql, $bindings, 'or');
    }

    /**
     * @return $this
     */
    protected function whereNested(Closure $callback, $boolean)
    {
        $nested = new static($this->connection);
        call_user_func($callback, $nested);

        if ($nested->wheres) {
            $this->wheres[] = array('type' => 'nested', 'query' => $nested, 'boolean' => $boolean);
        }
        return $this;
    }

    /**
     * Apply a callback only when $condition is truthy.
     *
     * @return $this
     */
    public function when($condition, callable $callback, callable $default = null)
    {
        if ($condition) {
            call_user_func($callback, $this, $condition);
        } elseif ($default !== null) {
            call_user_func($default, $this, $condition);
        }
        return $this;
    }

    /* ------------------------------------------------------------------
     |  Building: GROUP / HAVING / ORDER / LIMIT
     * ----------------------------------------------------------------*/

    /**
     * @return $this
     */
    public function groupBy()
    {
        $this->groups = array_merge($this->groups, $this->flatten(func_get_args()));
        return $this;
    }

    /**
     * @return $this
     */
    public function having($column, $operator, $value, $boolean = 'and')
    {
        $this->havings[] = array('type' => 'basic', 'column' => $column, 'operator' => $this->validOperator($operator), 'value' => $value, 'boolean' => $boolean);
        return $this;
    }

    /**
     * @return $this
     */
    public function orHaving($column, $operator, $value)
    {
        return $this->having($column, $operator, $value, 'or');
    }

    /**
     * @return $this
     */
    public function havingRaw($sql, array $bindings = array(), $boolean = 'and')
    {
        $this->havings[] = array('type' => 'raw', 'sql' => $sql, 'bindings' => array_values($bindings), 'boolean' => $boolean);
        return $this;
    }

    /**
     * @param string|Expression $column
     * @return $this
     */
    public function orderBy($column, $direction = 'asc')
    {
        $direction = strtolower($direction);
        if ($direction !== 'asc' && $direction !== 'desc') {
            throw new InvalidArgumentException("Order direction must be 'asc' or 'desc'.");
        }
        $this->orders[] = array('column' => $column, 'direction' => $direction);
        return $this;
    }

    /**
     * @return $this
     */
    public function orderByDesc($column)
    {
        return $this->orderBy($column, 'desc');
    }

    /**
     * @return $this
     */
    public function latest($column = 'created_at')
    {
        return $this->orderBy($column, 'desc');
    }

    /**
     * @return $this
     */
    public function oldest($column = 'created_at')
    {
        return $this->orderBy($column, 'asc');
    }

    /**
     * @return $this
     */
    public function limit($limit)
    {
        $this->limit = max(0, (int) $limit);
        return $this;
    }

    /**
     * @return $this
     */
    public function offset($offset)
    {
        $this->offset = max(0, (int) $offset);
        return $this;
    }

    /**
     * @return $this
     */
    public function forPage($page, $perPage = 15)
    {
        return $this->offset(max(0, ((int) $page - 1) * (int) $perPage))->limit($perPage);
    }

    /* ------------------------------------------------------------------
     |  Reading
     * ----------------------------------------------------------------*/

    /**
     * Run the query; returns an array of stdClass rows.
     *
     * @return array
     */
    public function get()
    {
        return $this->connection->select($this->toSql(), $this->getBindings());
    }

    /**
     * @return object|null
     */
    public function first()
    {
        $query = clone $this;
        $rows = $query->limit(1)->get();
        return isset($rows[0]) ? $rows[0] : null;
    }

    /**
     * @return object|null
     */
    public function find($id, $column = 'id')
    {
        $query = clone $this;
        return $query->where($column, '=', $id)->first();
    }

    public function value($column)
    {
        $query = clone $this;
        $row = $query->select($column)->first();

        if ($row === null) {
            return null;
        }
        $name = $this->stripAlias($column);
        return $row->$name;
    }

    /**
     * pluck('name')  or  pluck('name', 'id')  (id => name)
     *
     * @return array
     */
    public function pluck($column, $key = null)
    {
        $query = clone $this;
        $rows = ($key === null) ? $query->select($column)->get() : $query->select($column, $key)->get();

        $name = $this->stripAlias($column);
        $keyName = ($key === null) ? null : $this->stripAlias($key);
        $result = array();

        foreach ($rows as $row) {
            if ($keyName === null) {
                $result[] = $row->$name;
            } else {
                $result[$row->$keyName] = $row->$name;
            }
        }
        return $result;
    }

    /**
     * @return int
     */
    public function count($column = '*')
    {
        return (int) $this->aggregate('count', $column);
    }

    /**
     * @return int|float|null
     */
    public function sum($column)
    {
        return $this->aggregate('sum', $column);
    }

    /**
     * @return int|float|null
     */
    public function avg($column)
    {
        return $this->aggregate('avg', $column);
    }

    public function min($column)
    {
        return $this->aggregate('min', $column);
    }

    public function max($column)
    {
        return $this->aggregate('max', $column);
    }

    /**
     * @return bool
     */
    public function exists()
    {
        return $this->count() > 0;
    }

    protected function aggregate($function, $column)
    {
        $query = clone $this;
        $query->orders = array();
        $query->limit = null;
        $query->offset = null;
        $query->selectBindings = array();
        $query->columns = array(new Expression(strtoupper($function) . '(' . $this->wrap($column) . ') AS aggregate'));

        $row = $query->first();
        $value = ($row !== null && isset($row->aggregate)) ? $row->aggregate : null;

        return is_numeric($value) ? $value + 0 : $value;
    }

    /* ------------------------------------------------------------------
     |  Writing
     * ----------------------------------------------------------------*/

    /**
     * insert(['name' => 'Ann'])  or  insert([['name' => 'Ann'], ['name' => 'Bob']])
     * Returns the number of inserted rows.
     *
     * @return int
     */
    public function insert(array $values)
    {
        list($sql, $bindings) = $this->compileInsert($values);
        return $sql === '' ? 0 : $this->connection->execute($sql, $bindings);
    }

    /**
     * Insert one row and return its auto-increment id.
     *
     * @return int|string
     */
    public function insertGetId(array $values, $idColumn = 'id', $sequence = null)
    {
        list($sql, $bindings) = $this->compileInsert($values);

        if ($this->connection->getDriver() === 'pgsql') {
            $rows = $this->connection->select($sql . ' RETURNING ' . $this->wrap($idColumn), $bindings);
            $id = $rows[0]->$idColumn;
        } else {
            $this->connection->execute($sql, $bindings);
            $id = $this->connection->lastInsertId($sequence);
        }

        return is_numeric($id) ? $id + 0 : $id;
    }

    /**
     * Returns the number of affected rows. Requires a where() clause.
     *
     * @return int
     */
    public function update(array $values)
    {
        if (!$values) {
            return 0;
        }

        $this->requireWhere('update');

        $bindings = array();
        $sets = array();
        foreach ($values as $column => $value) {
            $sets[] = $this->wrap((string) $column) . ' = ' . $this->placeholder($value, $bindings);
        }

        $sql = 'UPDATE ' . $this->wrap($this->table) . ' SET ' . implode(', ', $sets);
        if ($this->wheres) {
            $sql .= ' WHERE ' . $this->compileConditions($this->wheres, $bindings);
        }

        return $this->connection->execute($sql, $bindings);
    }

    /**
     * @return int
     */
    public function increment($column, $amount = 1, array $extra = array())
    {
        return $this->update(array($column => new Expression($this->wrap($column) . ' + ' . $this->numeric($amount))) + $extra);
    }

    /**
     * @return int
     */
    public function decrement($column, $amount = 1, array $extra = array())
    {
        return $this->update(array($column => new Expression($this->wrap($column) . ' - ' . $this->numeric($amount))) + $extra);
    }

    /**
     * Returns the number of deleted rows. Requires a where() clause.
     *
     * @return int
     */
    public function delete()
    {
        $this->requireWhere('delete');

        $bindings = array();
        $sql = 'DELETE FROM ' . $this->wrap($this->table);
        if ($this->wheres) {
            $sql .= ' WHERE ' . $this->compileConditions($this->wheres, $bindings);
        }
        return $this->connection->execute($sql, $bindings);
    }

    /**
     * Refuse to run a table-wide UPDATE/DELETE by accident.
     */
    private function requireWhere($action)
    {
        if (!$this->wheres) {
            throw new \LogicException(
                "Refusing to {$action}() without a where() clause: it would affect every row in the table. "
                . "Add a where() condition (use whereRaw('1 = 1') if you really mean every row)."
            );
        }
    }

    /* ------------------------------------------------------------------
     |  Schema: create / drop / truncate
     * ----------------------------------------------------------------*/

    /**
     * Create the table. Throws if it already exists.
     *
     *   DB::table('users')->create(array(
     *       'id'    => array('int', 'primary_key', 'auto_increment'),
     *       'name'  => array('varchar(100)', 'not_null'),
     *       'email' => array('string', 'unique'),
     *       'role'  => array('string', 'default:user'),
     *   ));
     *
     * See TableBuilder for the list of types and modifiers.
     *
     * @return bool
     */
    public function create(array $columns)
    {
        return $this->runCreate($columns, false);
    }

    /**
     * Same as create(), but does nothing if the table already exists.
     *
     * @return bool
     */
    public function createIfNotExists(array $columns)
    {
        return $this->runCreate($columns, true);
    }

    /**
     * Drop the table (and all its data). Throws if it does not exist.
     *
     * @return bool
     */
    public function drop()
    {
        $this->rejectConditions('drop');
        $this->connection->execute('DROP TABLE ' . $this->wrap($this->table));
        return true;
    }

    /**
     * @return bool
     */
    public function dropIfExists()
    {
        $this->rejectConditions('dropIfExists');
        $this->connection->execute('DROP TABLE IF EXISTS ' . $this->wrap($this->table));
        return true;
    }

    /**
     * Remove every row and reset auto-increment counters. Keeps the table itself.
     *
     * @return bool
     */
    public function truncate()
    {
        $this->rejectConditions('truncate');
        $table = $this->wrap($this->table);

        switch ($this->connection->getDriver()) {
            case 'mysql':
                $this->connection->execute('TRUNCATE TABLE ' . $table);
                break;

            case 'pgsql':
                $this->connection->execute('TRUNCATE TABLE ' . $table . ' RESTART IDENTITY');
                break;

            default: // sqlite has no TRUNCATE: delete all rows, then reset the AUTOINCREMENT counter
                $connection = $this->connection;
                $name = $this->tableName();
                $connection->transaction(function () use ($connection, $table, $name) {
                    $connection->execute('DELETE FROM ' . $table);
                    $found = $connection->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'sqlite_sequence'");
                    if ($found) {
                        $connection->execute('DELETE FROM sqlite_sequence WHERE name = ?', array($name));
                    }
                });
        }

        return true;
    }

    private function runCreate(array $columns, $ifNotExists)
    {
        $builder = new TableBuilder($this->connection);
        $sql = $builder->compileCreate($this->wrap($this->table), $columns, $ifNotExists);
        $this->connection->execute($sql);
        return true;
    }

    private function rejectConditions($method)
    {
        if ($this->wheres || $this->joins || $this->limit !== null) {
            throw new \LogicException("$method() affects the whole table and cannot be combined with where()/join()/limit(). Use delete() to remove specific rows.");
        }
    }

    private function tableName()
    {
        if ($this->table instanceof Expression) {
            throw new \LogicException('This operation needs a plain table name, not a raw expression.');
        }
        $parts = explode('.', (string) $this->table);
        return end($parts);
    }

    /* ------------------------------------------------------------------
     |  Inspecting
     * ----------------------------------------------------------------*/

    /**
     * The SELECT statement with ? placeholders.
     *
     * @return string
     */
    public function toSql()
    {
        $bindings = array();
        return $this->compileSelect($bindings);
    }

    /**
     * Bound values, in placeholder order.
     *
     * @return array
     */
    public function getBindings()
    {
        $bindings = array();
        $this->compileSelect($bindings);
        return $bindings;
    }

    /* ------------------------------------------------------------------
     |  Compiling
     * ----------------------------------------------------------------*/

    protected function compileSelect(array &$bindings)
    {
        if ($this->table === null) {
            throw new \LogicException('No table specified. Use DB::table(...) or ->from(...).');
        }

        $bindings = $this->selectBindings;
        $columns = $this->columns ? $this->columns : array('*');

        $wrapped = array();
        foreach ($columns as $column) {
            $wrapped[] = $this->wrap($column);
        }

        $sql = 'SELECT ' . ($this->distinct ? 'DISTINCT ' : '')
            . implode(', ', $wrapped)
            . ' FROM ' . $this->wrap($this->table);

        foreach ($this->joins as $join) {
            $sql .= ' ' . $this->compileJoin($join, $bindings);
        }

        if ($this->wheres) {
            $sql .= ' WHERE ' . $this->compileConditions($this->wheres, $bindings);
        }

        if ($this->groups) {
            $groups = array();
            foreach ($this->groups as $group) {
                $groups[] = $this->wrap($group);
            }
            $sql .= ' GROUP BY ' . implode(', ', $groups);
        }

        if ($this->havings) {
            $sql .= ' HAVING ' . $this->compileConditions($this->havings, $bindings);
        }

        if ($this->orders) {
            $orders = array();
            foreach ($this->orders as $order) {
                $orders[] = $this->wrap($order['column']) . ' ' . strtoupper($order['direction']);
            }
            $sql .= ' ORDER BY ' . implode(', ', $orders);
        }

        return $sql . $this->compileLimitOffset();
    }

    protected function compileLimitOffset()
    {
        if ($this->limit === null && $this->offset === null) {
            return '';
        }

        $driver = $this->connection->getDriver();
        $limit = $this->limit;

        if ($limit === null) {   // OFFSET without LIMIT
            if ($driver === 'pgsql') {
                return ' OFFSET ' . $this->offset;
            }
            $limit = ($driver === 'sqlite') ? '-1' : '18446744073709551615';
        }

        return ' LIMIT ' . $limit . ($this->offset !== null ? ' OFFSET ' . $this->offset : '');
    }

    protected function compileJoin(JoinClause $join, array &$bindings)
    {
        $sql = strtoupper($join->type) . ' JOIN ' . $this->wrap($join->table);

        if ($join->type === 'cross' || !$join->clauses) {
            return $sql;
        }

        $on = '';
        foreach ($join->clauses as $i => $c) {
            $on .= ($i === 0 ? '' : ' ' . strtoupper($c['boolean']) . ' ');
            $operator = $this->validOperator($c['operator']);

            if ($c['type'] === 'column') {
                $on .= $this->wrap($c['first']) . ' ' . $operator . ' ' . $this->wrap($c['second']);
            } else {
                $on .= $this->wrap($c['first']) . ' ' . $operator . ' ' . $this->placeholder($c['second'], $bindings);
            }
        }

        return $sql . ' ON ' . $on;
    }

    /**
     * Compiles a list of WHERE / HAVING items, collecting bindings in order.
     */
    protected function compileConditions(array $items, array &$bindings)
    {
        $sql = '';

        foreach ($items as $i => $w) {
            switch ($w['type']) {
                case 'basic':
                    $part = $this->wrap($w['column']) . ' ' . $w['operator'] . ' ' . $this->placeholder($w['value'], $bindings);
                    break;
                case 'column':
                    $part = $this->wrap($w['first']) . ' ' . $w['operator'] . ' ' . $this->wrap($w['second']);
                    break;
                case 'null':
                    $part = $this->wrap($w['column']) . ($w['not'] ? ' IS NOT NULL' : ' IS NULL');
                    break;
                case 'raw':
                    $bindings = array_merge($bindings, $w['bindings']);
                    $part = '(' . $w['sql'] . ')';
                    break;
                case 'nested':
                    $part = '(' . $this->compileConditions($w['query']->wheres, $bindings) . ')';
                    break;
                case 'in':
                    $part = $this->compileIn($w, $bindings);
                    break;
                case 'between':
                    $part = $this->compileBetween($w, $bindings);
                    break;
                default:
                    throw new \LogicException('Unknown condition type [' . $w['type'] . '].');
            }

            $sql .= ($i === 0 ? '' : ' ' . strtoupper($w['boolean']) . ' ') . $part;
        }

        return $sql;
    }

    private function compileIn(array $w, array &$bindings)
    {
        if (!$w['values']) {     // IN () is invalid SQL; an empty list matches nothing
            return $w['not'] ? '1 = 1' : '0 = 1';
        }

        $bindings = array_merge($bindings, $w['values']);
        $marks = implode(', ', array_fill(0, count($w['values']), '?'));

        return $this->wrap($w['column']) . ($w['not'] ? ' NOT IN (' : ' IN (') . $marks . ')';
    }

    private function compileBetween(array $w, array &$bindings)
    {
        $bindings = array_merge($bindings, $w['values']);
        return $this->wrap($w['column']) . ($w['not'] ? ' NOT BETWEEN ? AND ?' : ' BETWEEN ? AND ?');
    }

    protected function compileInsert(array $values)
    {
        if (!$values) {
            return array('', array());
        }

        $rows = (isset($values[0]) && is_array($values[0])) ? $values : array($values);
        $columns = array_keys($rows[0]);
        $bindings = array();
        $groups = array();

        foreach ($rows as $row) {
            $marks = array();
            foreach ($columns as $column) {
                if (!array_key_exists($column, $row)) {
                    throw new InvalidArgumentException("Every inserted row must contain the column [$column].");
                }
                $marks[] = $this->placeholder($row[$column], $bindings);
            }
            $groups[] = '(' . implode(', ', $marks) . ')';
        }

        $wrappedColumns = array();
        foreach ($columns as $column) {
            $wrappedColumns[] = $this->wrap((string) $column);
        }

        $sql = 'INSERT INTO ' . $this->wrap($this->table)
            . ' (' . implode(', ', $wrappedColumns) . ')'
            . ' VALUES ' . implode(', ', $groups);

        return array($sql, $bindings);
    }

    /* ------------------------------------------------------------------
     |  Helpers
     * ----------------------------------------------------------------*/

    /**
     * Quote an identifier: users.name, users.*, "total as t", or a raw Expression.
     */
    protected function wrap($value)
    {
        if ($value instanceof Expression) {
            return $value->getValue();
        }

        $value = (string) $value;

        if (preg_match('/^(.+?)\s+as\s+(.+)$/i', $value, $m)) {
            return $this->wrap($m[1]) . ' AS ' . $this->wrapSegment(trim($m[2]));
        }

        $segments = array();
        foreach (explode('.', $value) as $segment) {
            $segments[] = $this->wrapSegment($segment);
        }
        return implode('.', $segments);
    }

    private function wrapSegment($segment)
    {
        if ($segment === '*') {
            return '*';
        }
        $q = ($this->connection->getDriver() === 'mysql') ? '`' : '"';
        return $q . str_replace($q, $q . $q, $segment) . $q;
    }

    private function placeholder($value, array &$bindings)
    {
        if ($value instanceof Expression) {
            return $value->getValue();
        }
        $bindings[] = $value;
        return '?';
    }

    private function validOperator($operator)
    {
        $operator = strtolower(trim((string) $operator));
        if (!in_array($operator, self::$operators, true)) {
            throw new InvalidArgumentException("Invalid operator [$operator].");
        }
        return $operator;
    }

    private function numeric($amount)
    {
        if (!is_numeric($amount)) {
            throw new InvalidArgumentException('Increment/decrement amount must be numeric.');
        }
        return $amount + 0;
    }

    private function stripAlias($column)
    {
        if (preg_match('/\s+as\s+(.+)$/i', $column, $m)) {
            return trim($m[1]);
        }
        $parts = explode('.', $column);
        return end($parts);
    }

    private function flatten(array $columns)
    {
        $flat = array();
        array_walk_recursive($columns, function ($c) use (&$flat) {
            $flat[] = $c;
        });
        return $flat;
    }
}
