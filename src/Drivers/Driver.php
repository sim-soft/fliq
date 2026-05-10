<?php

namespace Simsoft\DB\MySQL\Drivers;

use Simsoft\DB\MySQL\Interfaces\Executable;
use Simsoft\DB\MySQL\Traits\Error;

abstract class Driver
{
    use Error;

    /**
     * @var array Required config keys
     */
    protected array $required = [];

    /**
     * @var array Default config keys and values
     */
    protected array $default = [];

    /**
     * Connect to database.
     *
     * @return void
     */
    abstract protected function connect(): void;

    /**
     * Execute SQL statement. Return bool only indicate whether operation is success.
     *
     * @param Executable $query The executable object.
     * @return bool
     */
    abstract public function execute(Executable $query): bool;

    /**
     * Execute SQL statement to get query result.
     *
     * @param Executable $query The executable object.
     * @return array
     */
    abstract public function query(Executable $query): array;

    /**
     * Perform query transaction.
     *
     * If callback MUST return a bool value.
     * If callback returned TRUE, transaction will be committed, else will be rollback.
     *
     * @param callable $callback The callback to be executed.
     * @return bool
     */
    abstract public function transaction(callable $callback): bool;

    /**
     * Get last insert id.
     *
     * @return false|string
     */
    abstract public function lastInsertId(): false|string;

    /**
     * Constructor
     *
     * @param array $config Database config use for establish connection
     * @throws \Exception
     */
    public function __construct(
        protected array $config
    )
    {
        $this->config = [...$this->default, ...$this->config];
        $this->validate();
        $this->connect();
    }

    /**
     * Validate config
     *
     * @return void
     * @throws \Exception
     */
    private function validate(): void
    {
        $missing = [];
        foreach ($this->required as $key) {
            if (!isset($this->config[$key])) {
                $missing[] = $key;
            }
        }

        if ($missing) {
            throw new \Exception("Database: Missing required config keys: " . implode(', ', $missing));
        }
    }

    public function __wakeup()
    {
        $this->connect();
    }
}
