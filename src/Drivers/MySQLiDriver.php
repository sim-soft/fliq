<?php

namespace Simsoft\DB\MySQL\Drivers;

use mysqli;

class MySQLiDriver extends Driver
{
    protected mysqli $db;
    protected function connect(): void
    {
        //$this->db = new mysqli();
    }

    public function execute(string $sql, array $params = []): bool
    {

    }

    public function query(string $sql, array $params = []): array
    {

    }
}
