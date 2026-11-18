<?php

namespace QueryBuilder;

use InvalidArgumentException;

/**
 * Compiles CREATE TABLE statements from a simple column-definition array and
 * translates the generic types to MySQL / PostgreSQL / SQLite dialects.
 *
 * Column definition:   'column_name' => array('type', 'modifier', 'modifier', ...)
 *
 * Types (optionally with a size, e.g. 'varchar(100)', 'decimal(8,2)'):
 *   int, integer, bigint, smallint, tinyint, string (=varchar, default 255), varchar, char,
 *   text, mediumtext, longtext, boolean, float, double, decimal, date, time, datetime,
 *   timestamp, json, uuid, blob.  Other plain SQL type names are passed through.
 *
 * Modifiers:
 *   primary_key           (several columns marked => composite primary key)
 *   auto_increment        (integer types only; implies primary_key)
 *   not_null | required   /  null | nullable
 *   unique
 *   unsigned              (MySQL only, ignored elsewhere)
 *   default:VALUE         e.g. 'default:active', 'default:0'
 *   'default' => VALUE    array form; accepts null, bool, numbers, strings or DB::raw('...')
 */
class TableBuilder
{
    /** @var Connection */
    private $connection;
    private $driver;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
        $this->driver = $connection->getDriver();
    }

    /**
     * @param string $table       already-quoted table name
     * @param array  $columns     name => definition
     * @param bool   $ifNotExists
     * @return string
     */
    public function compileCreate($table, array $columns, $ifNotExists = false)
    {
        if (!$columns) {
            throw new InvalidArgumentException('create() needs at least one column definition.');
        }

        $parsed = array();
        $primary = array();

        foreach ($columns as $name => $spec) {
            if (!is_string($name) || $name === '') {
                throw new InvalidArgumentException('create() expects column names as array keys, e.g. array(\'id\' => array(\'int\', \'primary_key\')).');
            }
            $parsed[$name] = $this->parseSpec($name, $spec);
            if ($parsed[$name]['primary']) {
                $primary[] = $name;
            }
        }

        $inlinePrimary = count($primary) === 1;
        $definitions = array();

        foreach ($parsed as $name => $p) {
            $definitions[] = $this->compileColumn($name, $p, $inlinePrimary && $p['primary']);
        }

        if (count($primary) > 1) {
            foreach ($primary as $name) {
                if ($parsed[$name]['auto']) {
                    throw new InvalidArgumentException("Column [$name]: auto_increment cannot be used with a composite primary key.");
                }
            }
            $quoted = array();
            foreach ($primary as $name) {
                $quoted[] = $this->quote($name);
            }
            $definitions[] = 'PRIMARY KEY (' . implode(', ', $quoted) . ')';
        }

        return 'CREATE TABLE ' . ($ifNotExists ? 'IF NOT EXISTS ' : '') . $table
            . " (\n    " . implode(",\n    ", $definitions) . "\n)";
    }

    /* ------------------------------------------------------------------ */

    private function parseSpec($column, $spec)
    {
        if (is_string($spec)) {
            $spec = array($spec);
        }
        if (!is_array($spec) || !isset($spec[0]) || !is_string($spec[0])) {
            throw new InvalidArgumentException("Column [$column]: the first element of the definition must be a type, e.g. 'int' or 'varchar(255)'.");
        }

        $p = array(
            'type'       => $spec[0],
            'primary'    => false,
            'auto'       => false,
            'null'       => null,
            'unique'     => false,
            'unsigned'   => false,
            'hasDefault' => false,
            'default'    => null,
        );

        foreach ($spec as $key => $item) {
            if ($key === 0) {
                continue;
            }

            if (is_string($key)) {                       // 'default' => value
                if (strtolower($key) !== 'default') {
                    throw new InvalidArgumentException("Column [$column]: unknown option [$key].");
                }
                $p['hasDefault'] = true;
                $p['default'] = $item;
                continue;
            }

            if (!is_string($item)) {
                throw new InvalidArgumentException("Column [$column]: modifiers must be strings.");
            }

            if (preg_match('/^\s*default\s*:(.*)$/is', $item, $m)) {   // 'default:value'
                $p['hasDefault'] = true;
                $p['default'] = is_numeric($m[1]) ? $m[1] + 0 : $m[1];
                continue;
            }

            $token = strtolower(str_replace(array(' ', '-'), '_', trim($item)));

            if (in_array($token, array('primary_key', 'primary'), true)) {
                $p['primary'] = true;
            } elseif (in_array($token, array('auto_increment', 'autoincrement', 'increment'), true)) {
                $p['auto'] = true;
                $p['primary'] = true;
            } elseif (in_array($token, array('not_null', 'required'), true)) {
                $p['null'] = false;
            } elseif (in_array($token, array('null', 'nullable'), true)) {
                $p['null'] = true;
            } elseif ($token === 'unique') {
                $p['unique'] = true;
            } elseif ($token === 'unsigned') {
                $p['unsigned'] = true;
            } else {
                throw new InvalidArgumentException("Column [$column]: unknown modifier [$item].");
            }
        }

        return $p;
    }

    private function compileColumn($name, array $p, $inlinePrimary)
    {
        $mysql  = $this->driver === 'mysql';
        $sqlite = $this->driver === 'sqlite';

        $sql = $this->quote($name) . ' ' . $this->mapType($name, $p['type'], $p['auto']);

        if ($mysql && $p['unsigned']) {
            $sql .= ' UNSIGNED';
        }
        if ($p['null'] === false) {
            $sql .= ' NOT NULL';
        } elseif ($p['null'] === true) {
            $sql .= ' NULL';
        }
        if ($mysql && $p['auto']) {
            $sql .= ' AUTO_INCREMENT';
        }
        if ($p['hasDefault']) {
            $sql .= ' DEFAULT ' . $this->formatDefault($p['default']);
        }
        if ($p['unique'] && !$p['primary']) {
            $sql .= ' UNIQUE';
        }
        if ($inlinePrimary) {
            $sql .= ' PRIMARY KEY' . (($sqlite && $p['auto']) ? ' AUTOINCREMENT' : '');
        }

        return $sql;
    }

    private function mapType($column, $type, $auto)
    {
        $mysql  = $this->driver === 'mysql';
        $pgsql  = $this->driver === 'pgsql';
        $sqlite = $this->driver === 'sqlite';

        // Only letters/spaces for the name and digits/commas for the size: nothing else can reach the SQL.
        if (!preg_match('/^\s*([a-z][a-z ]*?)\s*(?:\(\s*([0-9]+(?:\s*,\s*[0-9]+)?)\s*\))?\s*$/i', $type, $m)) {
            throw new InvalidArgumentException("Column [$column]: invalid type [$type].");
        }

        $name = strtolower(preg_replace('/\s+/', ' ', $m[1]));
        $args = isset($m[2]) ? preg_replace('/\s+/', '', $m[2]) : '';

        if ($auto && !in_array($name, array('int', 'integer', 'bigint', 'smallint', 'tinyint'), true)) {
            throw new InvalidArgumentException("Column [$column]: auto_increment needs an integer type, got [$type].");
        }

        switch ($name) {
            case 'int':
            case 'integer':
                return $mysql ? 'INT' : (($pgsql && $auto) ? 'SERIAL' : 'INTEGER');

            case 'bigint':
                if ($pgsql) {
                    return $auto ? 'BIGSERIAL' : 'BIGINT';
                }
                return ($sqlite && $auto) ? 'INTEGER' : 'BIGINT';

            case 'smallint':
            case 'tinyint':
                if ($pgsql) {
                    return $auto ? 'SMALLSERIAL' : 'SMALLINT';
                }
                if ($sqlite && $auto) {
                    return 'INTEGER';
                }
                return strtoupper($name === 'tinyint' && !$mysql ? 'smallint' : $name);

            case 'string':
            case 'varchar':
                if (strpos($args, ',') !== false) {
                    throw new InvalidArgumentException("Column [$column]: [$type] takes a single length.");
                }
                return 'VARCHAR(' . ($args !== '' ? $args : '255') . ')';

            case 'char':
                return 'CHAR(' . ($args !== '' ? $args : '1') . ')';

            case 'text':
                return 'TEXT';
            case 'mediumtext':
                return $mysql ? 'MEDIUMTEXT' : 'TEXT';
            case 'longtext':
                return $mysql ? 'LONGTEXT' : 'TEXT';

            case 'boolean':
            case 'bool':
                return $mysql ? 'TINYINT(1)' : 'BOOLEAN';

            case 'float':
                return $pgsql ? 'REAL' : 'FLOAT';
            case 'double':
                return $mysql ? 'DOUBLE' : ($pgsql ? 'DOUBLE PRECISION' : 'REAL');

            case 'decimal':
            case 'numeric':
                return 'DECIMAL(' . ($args !== '' ? $args : '10,2') . ')';

            case 'date':
                return 'DATE';
            case 'time':
                return 'TIME';
            case 'datetime':
                return $pgsql ? 'TIMESTAMP' : 'DATETIME';
            case 'timestamp':
                return 'TIMESTAMP';

            case 'json':
                return $mysql ? 'JSON' : ($pgsql ? 'JSONB' : 'TEXT');
            case 'uuid':
                return $pgsql ? 'UUID' : 'CHAR(36)';
            case 'blob':
            case 'binary':
                return $pgsql ? 'BYTEA' : 'BLOB';
        }

        // Unknown but syntactically safe type name: pass it through.
        return strtoupper($name) . ($args !== '' ? '(' . $args . ')' : '');
    }

    private function formatDefault($value)
    {
        if ($value instanceof Expression) {
            return $value->getValue();
        }
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $this->driver === 'pgsql' ? ($value ? 'TRUE' : 'FALSE') : ($value ? '1' : '0');
        }
        if (is_int($value) || is_float($value)) {
            return str_replace(',', '.', (string) $value);   // locale-proof decimal point
        }
        if (is_string($value)) {
            if (preg_match('/^(CURRENT_TIMESTAMP|CURRENT_DATE|CURRENT_TIME)$/i', $value)) {
                return strtoupper($value);
            }
            return $this->connection->getPdo()->quote($value);
        }

        throw new InvalidArgumentException('Unsupported default value type.');
    }

    private function quote($name)
    {
        $q = ($this->driver === 'mysql') ? '`' : '"';
        return $q . str_replace($q, $q . $q, $name) . $q;
    }
}
