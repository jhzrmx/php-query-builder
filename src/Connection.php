<?php

namespace QueryBuilder;

use Closure;
use InvalidArgumentException;
use PDO;

/**
 * Thin wrapper around PDO: builds the DSN from a config array, runs
 * prepared statements, handles transactions and keeps an optional query log.
 *
 * Config keys:
 *   driver    mysql | pgsql | sqlite            (default: mysql)
 *   host      default 127.0.0.1
 *   port
 *   database  database name, or file path / ":memory:" for sqlite
 *   username, password
 *   charset   default utf8mb4 (mysql)
 *   unix_socket (mysql)
 *   dsn       full DSN override
 *   options   extra PDO options
 */
class Connection
{
    /** @var PDO */
    private $pdo;
    /** @var string */
    private $driver;
    private $logging = false;
    private $queryLog = array();
    private $transactionLevel = 0;

    /**
     * @param array $config
     */
    public function __construct(array $config)
    {
        $this->driver = strtolower(self::cfg($config, 'driver', 'mysql'));

        $options = self::cfg($config, 'options', array()) + array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES   => false,
        );

        $dsn = isset($config['dsn']) ? $config['dsn'] : $this->buildDsn($config);

        $this->pdo = new PDO(
            $dsn,
            self::cfg($config, 'username', null),
            self::cfg($config, 'password', null),
            $options
        );
    }

    /**
     * Wrap an existing PDO instance.
     *
     * @return Connection
     */
    public static function fromPdo(PDO $pdo)
    {
        $reflection = new \ReflectionClass(get_called_class());
        $instance = $reflection->newInstanceWithoutConstructor();
        $instance->pdo = $pdo;
        $instance->driver = strtolower($pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        return $instance;
    }

    private static function cfg(array $config, $key, $default)
    {
        return isset($config[$key]) ? $config[$key] : $default;
    }

    private function buildDsn(array $c)
    {
        $database = self::cfg($c, 'database', '');

        switch ($this->driver) {
            case 'sqlite':
                return 'sqlite:' . self::cfg($c, 'database', ':memory:');

            case 'pgsql':
                return sprintf(
                    'pgsql:host=%s;port=%s;dbname=%s',
                    self::cfg($c, 'host', '127.0.0.1'),
                    self::cfg($c, 'port', 5432),
                    $database
                );

            case 'mysql':
                $charset = self::cfg($c, 'charset', 'utf8mb4');
                if (!empty($c['unix_socket'])) {
                    return sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $c['unix_socket'], $database, $charset);
                }
                return sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                    self::cfg($c, 'host', '127.0.0.1'),
                    self::cfg($c, 'port', 3306),
                    $database,
                    $charset
                );
        }

        throw new InvalidArgumentException("Unsupported driver [{$this->driver}]. Use mysql, pgsql or sqlite.");
    }

    /**
     * @return string
     */
    public function getDriver()
    {
        return $this->driver;
    }

    /**
     * @return PDO
     */
    public function getPdo()
    {
        return $this->pdo;
    }

    /**
     * Start a query against a table.
     *
     * @param string|Expression $table
     * @return QueryBuilder
     */
    public function table($table)
    {
        $builder = new QueryBuilder($this);
        return $builder->from($table);
    }

    /**
     * Run a SELECT and return all rows as objects.
     *
     * @return array
     */
    public function select($sql, array $bindings = array())
    {
        $stmt = $this->run($sql, $bindings);
        return $stmt->fetchAll();
    }

    /**
     * Run INSERT/UPDATE/DELETE/DDL and return the number of affected rows.
     *
     * @return int
     */
    public function execute($sql, array $bindings = array())
    {
        $stmt = $this->run($sql, $bindings);
        return $stmt->rowCount();
    }

    public function lastInsertId($sequence = null)
    {
        return $this->pdo->lastInsertId($sequence);
    }

    /**
     * Run a callback in a transaction; commits on success, rolls back on any exception.
     *
     * @return mixed whatever the callback returns
     */
    public function transaction(Closure $callback)
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
        } catch (\Exception $e) {
            $this->rollBack();
            throw $e;
        } catch (\Throwable $e) {   // PHP 7+ errors; this class simply never matches on PHP 5
            $this->rollBack();
            throw $e;
        }

        $this->commit();
        return $result;
    }

    public function beginTransaction()
    {
        if ($this->transactionLevel === 0) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT trans' . $this->transactionLevel);
        }
        $this->transactionLevel++;
    }

    public function commit()
    {
        $this->transactionLevel = max(0, $this->transactionLevel - 1);
        if ($this->transactionLevel === 0) {
            $this->pdo->commit();
        }
    }

    public function rollBack()
    {
        $this->transactionLevel = max(0, $this->transactionLevel - 1);
        if ($this->transactionLevel === 0) {
            $this->pdo->rollBack();
        } else {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT trans' . $this->transactionLevel);
        }
    }

    public function enableQueryLog($on = true)
    {
        $this->logging = (bool) $on;
    }

    public function getQueryLog()
    {
        return $this->queryLog;
    }

    /**
     * @return \PDOStatement
     */
    private function run($sql, array $bindings)
    {
        $start = microtime(true);
        $stmt = $this->pdo->prepare($sql);

        foreach (array_values($bindings) as $i => $value) {
            $stmt->bindValue($i + 1, $value, $this->paramType($value));
        }

        $stmt->execute();

        if ($this->logging) {
            $this->queryLog[] = array(
                'sql'      => $sql,
                'bindings' => $bindings,
                'time_ms'  => round((microtime(true) - $start) * 1000, 3),
            );
        }

        return $stmt;
    }

    private function paramType($value)
    {
        if (is_int($value)) {
            return PDO::PARAM_INT;
        }
        if (is_bool($value)) {
            return PDO::PARAM_BOOL;
        }
        if ($value === null) {
            return PDO::PARAM_NULL;
        }
        return PDO::PARAM_STR;
    }
}
