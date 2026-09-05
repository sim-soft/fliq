<?php

namespace Simsoft\DB\Builder\Clauses;

/**
 * Class OrderByClause
 *
 */
class OrderByClause extends Clause
{
    /** @var string Default direction. Default ASC */
    protected string $defaultDirection = 'ASC';

    /** @var array<int, string> Allowed directions */
    protected array $allowedDirections = ['ASC', 'DESC'];

    /**
     * {@inheritdoc}
     */
    protected function buildSQL(): string
    {
        if (is_array($this->attribute)) {
            $sql = [];
            foreach ($this->attribute as $attribute => $direction) {
                $sql[] = $this->queryAttribute($attribute) . ' ' . $this->normaliseDirection($direction);
            }
            return implode(', ', $sql);
        }

        if (strtoupper($this->attribute) === 'RAND()') {
            return 'RAND()';
        }

        return "{$this->queryAttribute($this->attribute)} {$this->normaliseDirection($this->value ?? '')}";
    }

    /**
     * Normalise a sort direction to a permitted keyword.
     *
     * A direction is interpolated rather than bound, so it must be whitelisted.
     * The array branch only uppercased its input, which let a direction taken
     * from user input (`?sort=`) append arbitrary SQL — the same defect already
     * fixed in ActiveQuery::orderBy(). Anything unrecognised falls back to ASC.
     *
     * @param string $direction The requested sort direction.
     * @return string Either 'ASC' or 'DESC'.
     */
    private function normaliseDirection(string $direction): string
    {
        $direction = strtoupper(trim($direction));

        return in_array($direction, $this->allowedDirections, true)
            ? $direction
            : $this->defaultDirection;
    }
}
