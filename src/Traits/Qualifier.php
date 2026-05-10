<?php

namespace Simsoft\DB\MySQL\Traits;

/**
 * Trait Qualifier
 *
 */
trait Qualifier
{
    /** @var null|string The table alias */
    protected ?string $alias = null;

    /**
     * Set alias
     *
     * @param string|null $alias The alias name.
     */
    public function alias(?string $alias): self
    {
        $this->alias = $alias;
        return $this;
    }

    /**
     * Get alias
     *
     * @return string|null
     */
    public function getAlias(): ?string
    {
        return $this->alias;
    }

    /**
     * Convert to qualified field format.
     *
     * @param string $attribute The attribute name.
     */
    protected function queryAttribute(string $attribute): string
    {
        if ($attribute[0] === '!') {
            return '`' . ltrim($attribute, '!') . '`';
        }

        return $attribute[0] === '{' ? $attribute : '{' . $attribute . '}';
    }

    /**
     * Get qualified sub-query
     *
     * @param string $sql The sub query SQL.
     * @param string|null $alias The alias name.
     * @return string
     */
    public function getQualifiedSubQuery(string $sql, ?string $alias = null): string
    {
        $this->alias($alias);
        return "($sql) `{$this->getAlias()}`";
    }

    /**
     * Get qualified table name.
     *
     * @param string $table The table name.
     * @param string|null $alias The table alias.
     * @return string
     */
    public function getQualifiedTable(string $table, ?string $alias = null): string
    {
        if ($alias === null) {
            $this->alias($table);
            return "`$table`";
        } else {
            $this->alias($alias);
            return "`$table` `{$this->getAlias()}`";
        }
    }

    /**
     * Get qualifier attribute name.
     *
     * @param string $attribute The attribute name.
     *
     * @return string The qualified name
     */
    public function getQualifiedAttribute(string $attribute): string
    {
        if ($attribute !== '*') {
            $attribute = "`$attribute`";
        }
        return $this->getAlias() === null ? $attribute : "`{$this->getAlias()}`.$attribute";
    }

    /**
     * Qualifier attributes from raw SQL statement.
     *
     * @param string $sql The SQL to be qualified.
     */
    public function getQualifiedSQL(string $sql): string
    {
        preg_match_all('/\{(.*?)}/', $sql, $matches);
        if ($matches[1]) {
            $attributes = [];
            foreach ($matches[1] as $key => $attribute) {
                $attr = explode('.', $attribute);
                if (empty($attr[1])) {
                    $attributes[$key] = $this->getQualifiedAttribute($attr[0]);
                } else {
                    $attributes[$key] = $attr[1] === '*' ? "`$attr[0]`.*" : "`$attr[0]`.`$attr[1]`";
                }
            }

            return str_replace($matches[0], $attributes, $sql);
        }

        return $sql;
    }

    /**
     * Replace a given value in the string sequentially with an array.
     *
     * @param string $sql The SQL statement.
     * @param array $values Values to replaces
     * @param string $placeHolder String to be replaced. Default: '?'.
     * @return string
     */
    public function getReadableSQL(string $sql, array $values, string $placeHolder = '?'): string
    {
        $segments = explode($placeHolder, $sql);
        $result = array_shift($segments);
        foreach ($segments as $segment) {
            $value = (array_shift($values) ?? $placeHolder);
            if (!is_numeric($value)) {
                $value = "'$value'";
            }
            $result .= $value . $segment;
        }

        return $result;
    }
}
