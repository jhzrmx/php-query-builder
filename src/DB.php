<?php

namespace QueryBuilder;

use Closure;

/**
 * Static facade over one or more named connections.
 *
 *   DB::connect([...]);                    // default connection
 *   DB::connect([...], 'analytics');       // named connection
 *   DB::connection('analytics')->table('events')->get();
 */
final class DB
{
    /** @var Connection[] */
    private static $connections = array();
    private static $default = 'default';

    /**
     * @param array|Connection|\PDO $config
     * @param string                $name
     * @return Connection
     */
    public static function connect($config, $name = 'default')
    {
        if ($config instanceof Connection) {
            $connection = $config;
        } elseif ($config instanceof \PDO) {
            $connection = Connection::fromPdo($config);
        } elseif (is_array($config)) {
            $connection = new Connection($config);
        } else {
            throw new \InvalidArgumentException('DB::connect() expects a config array, a Connection or a PDO instance.');
        }

        self::$connections[$name] = $connection;
        if (count(self::$connections) === 1) {
            self::$default = $name;
        }
        return $connection;
    }

    public static function setDefaultConnection($name)
    {
        self::connection($name); // validate it exists
        self::$default = $name;
    }

    /**
     * @param string|null $name
     * @return Connection
     */
    public static function connection($name = null)
    {
        if ($name === null) {
            $name = self::$default;
        }
        if (!isset(self::$connections[$name])) {
            throw new \RuntimeException("Database connection [$name] is not configured. Call DB::connect() first.");
        }
        return self::$connections[$name];
    }

    public static function disconnect($name = null)
    {
        if ($name === null) {
            $name = self::$default;
        }
        unset(self::$connections[$name]);
    }

    /**
     * @param string|Expression $table
     * @return QueryBuilder
     */
    public static function table($table)
    {
        return self::connection()->table($table);
    }

    /**
     * Raw SELECT: DB::select('SELECT * FROM users WHERE id = ?', [1])
     *
     * @return array
     */
    public static function select($sql, array $bindings = array())
    {
        return self::connection()->select($sql, $bindings);
    }

    /**
     * Raw INSERT/UPDATE/DELETE/DDL; returns the affected row count.
     *
     * @return int
     */
    public static function execute($sql, array $bindings = array())
    {
        return self::connection()->execute($sql, $bindings);
    }

    public static function transaction(Closure $callback)
    {
        return self::connection()->transaction($callback);
    }

    /**
     * @return Expression
     */
    public static function raw($sql)
    {
        return new Expression($sql);
    }

    public static function enableQueryLog($on = true)
    {
        self::connection()->enableQueryLog($on);
    }

    public static function getQueryLog()
    {
        return self::connection()->getQueryLog();
    }
}
