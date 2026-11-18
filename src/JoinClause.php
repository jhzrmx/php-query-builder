<?php

namespace QueryBuilder;

/**
 * Builds the ON clause for joins that need more than one condition:
 *
 *   ->join('orders', function (JoinClause $j) {
 *       $j->on('users.id', '=', 'orders.user_id')
 *         ->where('orders.status', '=', 'paid');
 *   })
 */
class JoinClause
{
    /** @var string inner|left|right|cross */
    public $type;

    /** @var string|Expression */
    public $table;

    /** @var array each: boolean, type (column|value), first, operator, second */
    public $clauses = array();

    /**
     * @param string            $type
     * @param string|Expression $table
     */
    public function __construct($type, $table)
    {
        $this->type  = $type;
        $this->table = $table;
    }

    /**
     * Compare two columns.
     *
     * @return $this
     */
    public function on($first, $operator, $second, $boolean = 'and')
    {
        $this->clauses[] = array('boolean' => $boolean, 'type' => 'column', 'first' => $first, 'operator' => $operator, 'second' => $second);
        return $this;
    }

    /**
     * @return $this
     */
    public function orOn($first, $operator, $second)
    {
        return $this->on($first, $operator, $second, 'or');
    }

    /**
     * Compare a column to a bound value.
     *
     * @return $this
     */
    public function where($first, $operator, $value, $boolean = 'and')
    {
        $this->clauses[] = array('boolean' => $boolean, 'type' => 'value', 'first' => $first, 'operator' => $operator, 'second' => $value);
        return $this;
    }

    /**
     * @return $this
     */
    public function orWhere($first, $operator, $value)
    {
        return $this->where($first, $operator, $value, 'or');
    }
}
