<?php

namespace Simsoft\DB\MySQL\Builder\Clauses;

/**
 * Class OrderByClause
 *
 */
class OrderByClause extends Clause
{
    /** @var string Default direction. Default ASC */
    protected string $defaultDirection = 'ASC';

    /** @var array Allowed directions */
    protected array $allowedDirections = ['ASC', 'DESC'];

    /**
     * {@inheritdoc}
     */
    protected function buildSQL(): string
    {
        if (is_array($this->attribute)) {
            $sql = [];
            foreach ($this->attribute as $attribute => $direction) {
                $sql[] = $this->queryAttribute($attribute) . ' ' . strtoupper($direction);
            }
            return implode(', ', $sql);
        }

        if (strtoupper($this->attribute) === 'RAND()') {
            return 'RAND()';
        }

        $direction = strtoupper($this->value ?? '');
        if (!in_array($direction, $this->allowedDirections)) {
            $direction = $this->defaultDirection;
        }

        return "{$this->queryAttribute($this->attribute)} $direction";
    }
}
