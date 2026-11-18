<?php

namespace QueryBuilder;

/**
 * A raw SQL fragment that is inserted into a query verbatim (never quoted or escaped).
 * Only ever build these from trusted strings, never from user input.
 */
final class Expression
{
    private $value;

    /**
     * @param string $value
     */
    public function __construct($value)
    {
        $this->value = (string) $value;
    }

    /**
     * @return string
     */
    public function getValue()
    {
        return $this->value;
    }

    public function __toString()
    {
        return $this->value;
    }
}
