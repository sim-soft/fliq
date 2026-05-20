<?php
return [
    'mysql' => [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'database' => 'sample_db',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
    'pgsql' => [
        'driver' => 'pgsql',
        'host' => '127.0.0.1',
        'port' => 5432,
        'database' => 'sample_db',
        'username' => 'postgres',
        'password' => '',
        'charset' => 'utf8',
        'schema' => 'public',
    ],
    /* 'sqlite' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
    ], */
    /* 'job_db' => [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'database' => 'job_db',
        'username' => 'root',
        'password' => 'root',
        'charset' => 'utf8mb4',
    ], */
];
