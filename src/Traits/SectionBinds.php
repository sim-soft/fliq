<?php

namespace Simsoft\DB\Traits;

/**
 * Bind values held per SQL section.
 *
 * Placeholders are positional, so the driver must receive the values in the
 * order the placeholders appear in the statement. A builder collects them in
 * the order its methods are called, which is not the same thing: a query built
 * with groupByRaw() before its where() emits the WHERE placeholder first but
 * collected the GROUP BY value first, so the two were handed over swapped. The
 * statement still ran, and returned the wrong rows without a word.
 *
 * Each section keeps its own list, and getBinds() joins them in the order
 * getSQL() emits the sections.
 *
 * The WHERE section is not held here: joins and conditions are built through
 * the helpers in Binds and append to that trait's list. This trait reaches it
 * through whereSectionBinds(), which the using class aliases.
 *
 * @see Binds
 */
trait SectionBinds
{
    /** @var array<int, mixed> Binds for placeholders in the SELECT list. */
    private array $selectBinds = [];

    /** @var array<int, mixed> Binds for placeholders in the FROM clause. */
    private array $fromBinds = [];

    /** @var array<int, mixed> Binds for placeholders in joined sub-queries. */
    private array $joinBinds = [];

    /** @var array<int, mixed> Binds for placeholders in GROUP BY. */
    private array $groupBinds = [];

    /** @var array<int, mixed> Binds for placeholders in HAVING. */
    private array $havingBinds = [];

    /** @var array<int, mixed> Binds for placeholders in UNION branches. */
    private array $unionBinds = [];

    /**
     * Get bound values, in the order their placeholders are emitted.
     *
     * @return array<int, mixed>|null Null when no binds exist.
     */
    public function getBinds(): ?array
    {
        $binds = array_merge(
            $this->selectBinds,
            $this->fromBinds,
            $this->joinBinds,
            $this->whereSectionBinds() ?? [],
            $this->groupBinds,
            $this->havingBinds,
            $this->unionBinds
        );

        return $binds === [] ? null : $binds;
    }

    /**
     * Get the bind values belonging to the FROM clause alone.
     *
     * A caller that re-emits this query's FROM sub-query needs its values, and
     * needs them apart from the rest so they can be placed ahead of the
     * conditions, matching the order the placeholders appear in.
     *
     * @return array<int, mixed>|null Null when no binds exist.
     */
    public function getFromBinds(): ?array
    {
        return $this->fromBinds === [] ? null : $this->fromBinds;
    }

    /**
     * Get the bind values for the filtering sections alone.
     *
     * These are the sections a caller re-emits when it borrows a query's
     * conditions but writes its own SELECT and FROM — an aggregate taking a
     * query's JOIN, WHERE, GROUP BY and HAVING to count against. Handing it
     * getBinds() gives values whose placeholders it did not emit, leaving the
     * statement over-supplied and refused by the driver.
     *
     * @return array<int, mixed>|null Null when no binds exist.
     */
    public function getConditionBinds(): ?array
    {
        $binds = array_merge(
            $this->joinBinds,
            $this->whereSectionBinds() ?? [],
            $this->groupBinds,
            $this->havingBinds
        );

        return $binds === [] ? null : $binds;
    }

    /**
     * Take another builder's bind values, section by section.
     *
     * Appending them wholesale to one list would put the incoming values after
     * every one of this builder's own, regardless of which section they belong
     * to, so a merged query's HAVING value landed behind a WHERE placeholder.
     * Each list is taken into its counterpart instead.
     *
     * @param self $source The builder whose binds are being taken.
     * @return void
     */
    protected function mergeSectionBinds(self $source): void
    {
        $this->appendSectionBinds($this->selectBinds, $source->selectBinds);
        $this->appendSectionBinds($this->fromBinds, $source->fromBinds);
        $this->appendSectionBinds($this->joinBinds, $source->joinBinds);
        $this->absorbBinds($source->whereSectionBinds());
        $this->appendSectionBinds($this->groupBinds, $source->groupBinds);
        $this->appendSectionBinds($this->havingBinds, $source->havingBinds);
        $this->appendSectionBinds($this->unionBinds, $source->unionBinds);
    }

    /**
     * Discard every collected bind value.
     *
     * @return void
     */
    public function clearBinds(): void
    {
        $this->clearWhereSectionBinds();

        $this->selectBinds = [];
        $this->fromBinds = [];
        $this->joinBinds = [];
        $this->groupBinds = [];
        $this->havingBinds = [];
        $this->unionBinds = [];
    }

    /**
     * Append values to the bind list held for one section.
     *
     * @param array<int, mixed> $target The section's bind list, by reference.
     * @param array<int, mixed>|null $values The values to append, if any.
     * @return void
     */
    protected function appendSectionBinds(array &$target, ?array $values): void
    {
        if ($values === null) {
            return;
        }

        foreach ($values as $value) {
            $target[] = $value;
        }
    }
}
