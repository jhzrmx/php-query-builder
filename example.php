<?php

require __DIR__ . '/vendor/autoload.php';   // created by `composer install`
/* or require each file manually:
foreach (glob(__DIR__ . '/src/*.php') as $file) {
    require_once $file;
}
*/

use QueryBuilder\DB;
use QueryBuilder\JoinClause;

/* ---------- 1. Connect ---------- */

DB::connect(array(
    'driver'   => 'mysql',          // mysql | pgsql | sqlite
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'database' => 'testing',
    'username' => 'root',
    'password' => '',
    'charset'  => 'utf8mb4',
));
// SQLite:  DB::connect(array('driver' => 'sqlite', 'database' => __DIR__ . '/app.db'));
// Existing PDO:  DB::connect($pdo);
// Second DB:     DB::connect(array(...), 'analytics');  DB::connection('analytics')->table('events')->get();

/* ---------- 2. Create the tables (safe to re-run) ---------- */

DB::table('users')->createIfNotExists(array(
    'id'         => array('int', 'primary_key', 'auto_increment'),
    'name'       => array('varchar(100)', 'not_null'),
    'email'      => array('string', 'unique'),                  // 'string' = VARCHAR(255)
    'role'       => array('string', 'default:user'),
    'status'     => array('string', 'default:active'),
    'is_admin'   => array('boolean', 'default' => false),
    'last_login' => array('datetime', 'nullable'),
    'deleted_at' => array('timestamp', 'nullable'),
    'created_at' => array('timestamp', 'default' => DB::raw('CURRENT_TIMESTAMP')),
));

DB::table('orders')->createIfNotExists(array(
    'id'         => array('int', 'primary_key', 'auto_increment'),
    'user_id'    => array('int', 'not_null'),
    'total'      => array('decimal(10,2)', 'not_null', 'default:0'),
    'status'     => array('string', 'default:pending'),
    'created_at' => array('timestamp', 'default' => DB::raw('CURRENT_TIMESTAMP')),
));

DB::table('products')->createIfNotExists(array(
    'id'          => array('int', 'primary_key', 'auto_increment'),
    'name'        => array('varchar(150)', 'not_null'),
    'price'       => array('decimal(10,2)', 'not_null'),
    'stock'       => array('int', 'not_null', 'default:0'),
    'category_id' => array('int', 'not_null'),
));

// Composite primary key: mark several columns as primary_key
DB::table('order_items')->createIfNotExists(array(
    'order_id'   => array('int', 'primary_key'),
    'product_id' => array('int', 'primary_key'),
    'quantity'   => array('int', 'not_null', 'default:1'),
));

DB::table('accounts')->createIfNotExists(array(
    'id'      => array('int', 'primary_key', 'auto_increment'),
    'balance' => array('decimal(12,2)', 'not_null', 'default:0'),
));

DB::table('logs')->createIfNotExists(array(
    'id'         => array('int', 'primary_key', 'auto_increment'),
    'message'    => array('text'),
    'created_at' => array('timestamp', 'default' => DB::raw('CURRENT_TIMESTAMP')),
));

/* ---------- 3. Start from a clean slate, then seed some data ---------- */

foreach (array('users', 'orders', 'products', 'order_items', 'accounts', 'logs') as $table) {
    DB::table($table)->truncate();      // removes all rows and resets auto-increment
}

$annId = DB::table('users')->insertGetId(array(     // insertGetId() returns the new id
    'name'   => 'Ann',
    'email'  => 'ann@example.com',
    'role'   => 'admin',
    'status' => 'active',
));

DB::table('users')->insert(array(                   // bulk insert (every row needs the same columns)
    array('name' => 'Bob', 'email' => 'bob@example.com', 'role' => 'editor', 'status' => 'active'),
    array('name' => 'Cy',  'email' => 'cy@example.com',  'role' => 'user',   'status' => 'active'),
    array('name' => 'Dee', 'email' => 'dee@example.com', 'role' => 'user',   'status' => 'banned'),
));

DB::table('orders')->insert(array(
    array('user_id' => 1, 'total' => 320.00, 'status' => 'paid'),
    array('user_id' => 1, 'total' => 80.50,  'status' => 'paid'),
    array('user_id' => 2, 'total' => 199.99, 'status' => 'paid'),
    array('user_id' => 3, 'total' => 450.00, 'status' => 'pending'),
    array('user_id' => 4, 'total' => 999.00, 'status' => 'paid'),
));

DB::table('products')->insert(array(
    array('name' => 'Keyboard', 'price' => 49.90,  'stock' => 25, 'category_id' => 1),
    array('name' => 'Monitor',  'price' => 189.00, 'stock' => 10, 'category_id' => 1),
    array('name' => 'Desk',     'price' => 120.00, 'stock' => 5,  'category_id' => 2),
    array('name' => 'Lamp',     'price' => 24.50,  'stock' => 40, 'category_id' => 3),
));

DB::table('accounts')->insert(array(
    array('balance' => 500),
    array('balance' => 250),
));

/* ---------- 4. The query from the brief ---------- */

$results = DB::table('users')
    ->select('users.id', 'users.name', 'orders.total')
    ->join('orders', 'users.id', '=', 'orders.user_id')
    ->where('users.status', '=', 'active')
    ->where('orders.total', '>', 150)
    ->orderBy('orders.total', 'desc')
    ->limit(10)
    ->get();

foreach ($results as $row) {
    echo $row->name . ' spent ' . $row->total . PHP_EOL;
}

/* ---------- 5. More query-builder examples ---------- */

$ann   = DB::table('users')->where('email', 'ann@example.com')->first();   // 2-arg form means "="
$user  = DB::table('users')->find(2);
$names = DB::table('users')->orderBy('name')->pluck('name');
$total = DB::table('orders')->where('status', 'paid')->sum('total');
$count = DB::table('users')->whereNull('deleted_at')->count();

echo "First match: {$ann->name} | find(2): {$user->name} | paid total: $total | live users: $count" . PHP_EOL;
echo 'Names: ' . implode(', ', $names) . PHP_EOL;

// Grouped conditions:  status = 'active' AND (role = 'admin' OR role = 'editor')
$staff = DB::table('users')
    ->where('status', 'active')
    ->where(function ($q) {
        $q->where('role', 'admin')->orWhere('role', 'editor');
    })
    ->get();

// Left join with multiple ON conditions, grouping and having
$bigSpenders = DB::table('users')
    ->select('users.name', DB::raw('COUNT(orders.id) AS order_count'))
    ->leftJoin('orders', function (JoinClause $j) {
        $j->on('users.id', '=', 'orders.user_id')->where('orders.status', '=', 'paid');
    })
    ->groupBy('users.id', 'users.name')
    ->havingRaw('COUNT(orders.id) >= ?', array(1))
    ->get();

// Conditional filters (e.g. from a search form)
$search = isset($_GET['q']) ? $_GET['q'] : 'o';
$products = DB::table('products')
    ->when($search, function ($q, $s) {
        $q->where('name', 'like', "%$s%");
    })
    ->whereIn('category_id', array(1, 2, 3))
    ->whereBetween('price', array(10, 200))
    ->forPage(1, 25)
    ->get();

// Inspect a query without running it
echo DB::table('users')->where('id', 1)->toSql() . PHP_EOL;   // SELECT * FROM `users` WHERE `id` = ?

/* ---------- 6. Updating and deleting ---------- */

DB::table('users')->where('id', $annId)->update(array('status' => 'inactive'));
DB::table('products')->where('id', 1)->increment('stock', 5);
DB::table('users')->where('status', 'banned')->delete();   // throws LogicException if the where() is missing

/* ---------- 7. Raw SQL with parameters ---------- */

$rows     = DB::select('SELECT * FROM users WHERE status = ? AND id > ?', array('active', 1));
$affected = DB::execute('UPDATE users SET status = ? WHERE last_login < ?', array('inactive', '2024-01-01'));

/* ---------- 8. Transactions & query log ---------- */

DB::enableQueryLog();

DB::transaction(function () {
    DB::table('accounts')->where('id', 1)->decrement('balance', 100);
    DB::table('accounts')->where('id', 2)->increment('balance', 100);
});

print_r(DB::getQueryLog());

/* ---------- 9. Clean up (uncomment to remove the demo tables) ---------- */

// foreach (array('users', 'orders', 'products', 'order_items', 'accounts', 'logs') as $table) {
//     DB::table($table)->dropIfExists();
// }
