<?php

/**
 * FLIQ Benchmark Suite
 *
 * Run: php benchmarks/run.php
 *
 * Requires a running MySQL connection with sample_db loaded.
 */

require __DIR__ . '/../vendor/autoload.php';

use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Connection;
use Simsoft\DB\Model;

// Setup connection
Connection::add('mysql', [
    'driver' => 'mysql',
    'host' => '127.0.0.1',
    'database' => 'sample_db',
    'username' => 'root',
    'password' => '',
    'charset' => 'utf8mb4',
]);

// Simple test model
class BenchUser extends Model
{
    protected string $table = 'user';
    protected array $fillable = ['username', 'email', 'password', 'role', 'score', 'department_id', 'status_code'];
}

$iterations = 10000;

echo "FLIQ Benchmark\n";
echo "====================\n";
echo "PHP " . PHP_VERSION . " | Iterations: $iterations\n\n";

// --- Query Building (no DB) ---
echo "Query Building (no DB execution):\n";

$start = microtime(true);
$memStart = memory_get_usage();
for ($i = 0; $i < $iterations; $i++) {
    (new ActiveQuery())->from('user')->where('status', 1)->getSQL();
}
$time = (microtime(true) - $start) * 1000;
$mem = (memory_get_usage() - $memStart) / 1024;
printf("  Simple SELECT .............. %.2fms total (%.4fms/query) | %.1fKB\n", $time, $time / $iterations, $mem);

$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    (new ActiveQuery())
        ->from('user')
        ->where('status', 1)
        ->where('age', '>', 18)
        ->like('name', '%john%')
        ->in('role', ['admin', 'editor', 'member'])
        ->between('score', 0, 100)
        ->getSQL();
}
$time = (microtime(true) - $start) * 1000;
printf("  Complex WHERE (5 conds) .... %.2fms total (%.4fms/query)\n", $time, $time / $iterations);

$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    (new ActiveQuery())
        ->from('user u')
        ->select('first_name', 'last_name')
        ->join('profile p', ['user_id' => 'id'])
        ->where('status', 1)
        ->groupBy('department_id')
        ->havingRaw('COUNT(*) > ?', [5])
        ->orderBy('name')
        ->limit(10)
        ->getSQL();
}
$time = (microtime(true) - $start) * 1000;
printf("  JOIN+GROUP+HAVING+ORDER .... %.2fms total (%.4fms/query)\n", $time, $time / $iterations);

// --- Database Execution ---
echo "\nDatabase Execution:\n";

$start = microtime(true);
for ($i = 0; $i < 1000; $i++) {
    BenchUser::findByPk(1);
}
$time = (microtime(true) - $start) * 1000;
printf("  findByPk() x1000 .......... %.2fms total (%.3fms/query)\n", $time, $time / 1000);

$start = microtime(true);
for ($i = 0; $i < 1000; $i++) {
    BenchUser::find()->where('status_code', 1)->first();
}
$time = (microtime(true) - $start) * 1000;
printf("  find()->where()->first() .. %.2fms total (%.3fms/query)\n", $time, $time / 1000);

$start = microtime(true);
for ($i = 0; $i < 100; $i++) {
    BenchUser::find()->where('status_code', 1)->get()->all();
}
$time = (microtime(true) - $start) * 1000;
printf("  find()->get()->all() x100 . %.2fms total (%.3fms/query)\n", $time, $time / 100);

// --- Model Hydration ---
echo "\nModel Hydration:\n";

$rawRows = [];
for ($i = 0; $i < 1000; $i++) {
    $rawRows[] = [
        'id' => $i,
        'username' => "user$i",
        'email' => "user$i@test.com",
        'score' => $i,
        'status_code' => 1,
        'role' => 'member',
        'department_id' => 1,
        'password' => 'x',
        'deleted_at' => null,
    ];
}

$start = microtime(true);
$memStart = memory_get_usage();
$models = [];
foreach ($rawRows as $row) {
    $models[] = BenchUser::hydrate($row);
}
$time = (microtime(true) - $start) * 1000;
$mem = (memory_get_usage() - $memStart) / 1024;
printf("  1000 models hydrated ....... %.2fms | %.1fKB peak\n", $time, $mem);

// --- Memory ---
echo "\nMemory:\n";
$before = memory_get_usage();
$q = new ActiveQuery();
$after = memory_get_usage();
printf("  ActiveQuery object ......... %d bytes\n", $after - $before);

$before = memory_get_usage();
$m = new BenchUser();
$after = memory_get_usage();
printf("  Model instance ............. %d bytes\n", $after - $before);

echo "\nPeak memory: " . number_format(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB\n";
echo "Done.\n";
