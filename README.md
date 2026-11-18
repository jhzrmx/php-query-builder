# PHP Query Builder

A small fluent, PDO-based query builder for **MySQL, PostgreSQL and SQLite** (PHP 5.6+, including 7.x and 8.x).

* Every value is a **bound parameter**; identifiers are quoted per driver.
* Results are arrays of `stdClass` rows (`$row->name`).
* No dependencies besides `ext-pdo`.

## Install

```bash
composer install        # generates vendor/autoload.php (PSR-4: QueryBuilder\)
```

## Quick start

```php
use QueryBuilder\DB;

DB::connect(array('driver' => 'sqlite', 'database' => 'app.db'));

$results = DB::table('users')
    ->select('users.id', 'users.name', 'orders.total')
    ->join('orders', 'users.id', '=', 'orders.user_id')
    ->where('users.status', '=', 'active')
    ->where('orders.total', '>', 150)
    ->orderBy('orders.total', 'desc')
    ->limit(10)
    ->get();
```

See `example.php` for the full tour.

## API cheat-sheet

| Area | Methods |
|------|---------|
| Connect | `DB::connect($config\|$pdo, $name)`, `DB::connection($name)`, `DB::setDefaultConnection()` |
| Select | `select`, `addSelect`, `selectRaw`, `distinct`, `DB::raw()` |
| Join | `join`, `leftJoin`, `rightJoin`, `crossJoin` (closure form gets a `JoinClause`) |
| Where | `where`, `orWhere`, `whereIn`, `whereNotIn`, `whereNull`, `whereNotNull`, `whereBetween`, `whereColumn`, `whereRaw`, grouped closures, array form |
| Shape | `groupBy`, `having`, `havingRaw`, `orderBy`, `latest`, `oldest`, `limit`, `offset`, `forPage`, `when` |
| Read | `get`, `first`, `find`, `value`, `pluck`, `count`, `sum`, `avg`, `min`, `max`, `exists` |
| Write | `insert` (single/bulk), `insertGetId`, `update`, `increment`, `decrement`, `delete` |
| Schema | `create`, `createIfNotExists`, `drop`, `dropIfExists`, `truncate` |
| Raw SQL | `DB::select($sql, $params)`, `DB::execute($sql, $params)` |
| Other | `DB::transaction(fn)`, `toSql()`, `getBindings()`, `DB::enableQueryLog()` / `getQueryLog()` |

## Creating tables

```php
DB::table('users')->createIfNotExists(array(
    'id'         => array('int', 'primary_key', 'auto_increment'),
    'name'       => array('varchar(100)', 'not_null'),
    'email'      => array('string', 'unique'),
    'status'     => array('string', 'default:active'),
    'is_admin'   => array('boolean', 'default' => false),
    'created_at' => array('timestamp', 'default' => DB::raw('CURRENT_TIMESTAMP')),
));

DB::table('users')->truncate();      // delete all rows, reset auto-increment
DB::table('users')->dropIfExists();  // ->drop() throws if the table doesn't exist
```

The first element of each definition is the type, the rest are modifiers.

* **Types:** `int`, `bigint`, `smallint`, `tinyint`, `string` (VARCHAR, default 255), `varchar(n)`, `char(n)`,
  `text`, `mediumtext`, `longtext`, `boolean`, `float`, `double`, `decimal(p,s)`, `date`, `time`, `datetime`,
  `timestamp`, `json`, `uuid`, `blob`. They are translated per driver (e.g. `auto_increment` becomes
  `AUTO_INCREMENT` on MySQL, `SERIAL` on PostgreSQL and `AUTOINCREMENT` on SQLite). Other plain SQL type names pass through.
* **Modifiers:** `primary_key` (several columns = composite key), `auto_increment` (integers only, implies primary key),
  `not_null`, `nullable`, `unique`, `unsigned` (MySQL only), `default:VALUE` or `'default' => VALUE`
  (null, bool, number, string, or `DB::raw()` for expressions).
* Unknown types or modifiers throw an `InvalidArgumentException` instead of being ignored.
* `truncate()`, `drop()` and `dropIfExists()` act on the whole table, so they throw if combined with `where()`/`join()`/`limit()`.
  On MySQL, `TRUNCATE`/`DROP`/`CREATE` cause an implicit commit, so they can't be rolled back inside a transaction.
  Column names in `create()` are quoted, but default values and types come from your code, so don't build them from user input.

## Security notes

* Values are always bound. Column names, tables and `DB::raw()` / `*Raw()` fragments are **not** values:
  never pass user input into them. Operators and order directions are whitelisted.
* `update()`, `delete()` (and so `increment()` / `decrement()`) throw a `LogicException` when there is no `where()`, so you can't wipe a table by accident. If you truly mean every row, say so explicitly with `->whereRaw('1 = 1')`.

## PHP compatibility

The code uses no scalar/return type declarations, `??`, `match`, arrow functions or other
PHP 7+/8+ syntax, so it runs on 5.6 through 8.x. Types are documented in docblocks, and
`array()` syntax is used in examples (the short `[]` form also works on 5.6).
