<?php

namespace Simsoft\DB;

use Simsoft\DB\Builder\ActiveQuery;
use Simsoft\DB\Builder\Raw;

/**
 * EagerLoader class.
 *
 * Batch-loads relations for a collection of models to prevent N+1 queries.
 * Supports nested relations via dot notation: 'posts.comments.author'
 */
class EagerLoader
{
    /**
     * Column alias the junction's parent-side key is fetched under.
     *
     * Grouping a many-to-many batch needs the parent id, which lives on the
     * junction and not on the related table, so it is selected under a name of
     * our own. The name has to be one no real column will have: aliasing onto
     * an existing column would overwrite it — a junction key selected as `id`
     * replaces the related model's own id — and the grouping would then be
     * right about the wrong rows.
     */
    private const string VIA_KEY = '__fliq_via_key';

    /**
     * Load relations for a set of models.
     *
     * Supports dot notation for nested relations and callable constraints.
     *
     * @param array<Model> $models The parent models.
     * @param array<string> $relations The relation names to load (supports dot notation).
     * @param array<string, callable> $constraints Optional constraints keyed by full dot-notation relation path.
     * @return array<Model> The models with relations populated.
     */
    public static function loadRelations(array $models, array $relations, array $constraints = []): array
    {
        if (empty($models) || empty($relations)) {
            return $models;
        }

        // Parse relations into a nested tree structure
        $tree = self::parseRelationTree($relations);

        // Load the tree recursively with path tracking for constraint matching
        self::loadTree($models, $tree, $constraints, '');

        return $models;
    }

    /**
     * Parse flat dot-notation relations into a nested tree.
     *
     * Input:  ['posts', 'posts.comments', 'posts.comments.author', 'profile']
     * Output: ['posts' => ['comments' => ['author' => []]], 'profile' => []]
     *
     * @param array<string> $relations Flat relation list.
     * @return array<string, mixed> Nested tree structure.
     */
    private static function parseRelationTree(array $relations): array
    {
        /** @var array<string, mixed> $tree */
        $tree = [];

        foreach ($relations as $relation) {
            $parts = explode('.', $relation);
            $current = &$tree;

            foreach ($parts as $part) {
                if (!isset($current[$part]) || !is_array($current[$part])) {
                    $current[$part] = [];
                }
                /** @var array<string, mixed> $next */
                $next = &$current[$part];
                $current = &$next;
                unset($next);
            }

            unset($current);
        }

        return $tree;
    }

    /**
     * Recursively load a relation tree.
     *
     * @param array<Model> $models The parent models at this level.
     * @param array<string, mixed> $tree The remaining relation tree to load.
     * @param array<string, callable> $constraints Constraints keyed by full dot-notation path.
     * @param string $prefix The current path prefix for constraint matching.
     * @return void
     */
    private static function loadTree(array $models, array $tree, array $constraints = [], string $prefix = ''): void
    {
        foreach ($tree as $relationName => $children) {
            $fullPath = $prefix === '' ? $relationName : $prefix . '.' . $relationName;
            $constraint = $constraints[$fullPath] ?? null;
            self::loadRelation($models, $relationName, $constraint);

            // If there are nested relations, collect the loaded related models and recurse
            if (!empty($children)) {
                $relatedModels = self::collectLoadedRelation($models, $relationName);
                if (!empty($relatedModels)) {
                    self::loadTree($relatedModels, $children, $constraints, $fullPath);
                }
            }
        }
    }

    /**
     * Collect all loaded related models from a set of parent models.
     *
     * @param array<Model> $models The parent models.
     * @param string $relationName The relation that was loaded.
     * @return array<Model> All related models (flattened).
     */
    private static function collectLoadedRelation(array $models, string $relationName): array
    {
        $collected = [];

        foreach ($models as $model) {
            if (!$model->relationLoaded($relationName)) {
                continue;
            }

            $related = $model->{$relationName};

            if ($related instanceof Model) {
                $collected[] = $related;
                continue;
            }

            if (is_iterable($related)) {
                foreach ($related as $item) {
                    if ($item instanceof Model) {
                        $collected[] = $item;
                    }
                }
            }
        }

        return $collected;
    }

    /**
     * Load a single relation for all models at one level.
     *
     * @param array<Model> $models The parent models.
     * @param string $relationName The relation method name.
     * @param callable|null $constraint Optional constraint callback.
     * @return void
     */
    private static function loadRelation(array $models, string $relationName, ?callable $constraint = null): void
    {
        // Not $models[0]: an indexBy()-keyed set has no key 0, and reading one
        // gave "Undefined array key 0" followed by a TypeError out of
        // method_exists(). `with('posts')->indexBy('username')` is a documented
        // pair of features and every combination of them was fatal.
        $firstModel = reset($models);

        if ($firstModel === false) {
            return;
        }

        // method_exists() was the whole guard, and then the name was called.
        // That is the hazard ResolvesRelations was written to close in __get(),
        // where reading `$user->delete` as a property ran the delete; the same
        // hole was left open here, so `with('delete')` deleted every row it had
        // just selected. A name is eligible only if it declares that it returns
        // a Relation and can be called with no arguments — reading must never
        // write.
        if (!$firstModel->isRelationMethod($relationName)) {
            return;
        }

        $relation = $firstModel->{$relationName}();
        if (!$relation instanceof Relation) {
            return;
        }

        $foreignKey = $relation->getForeignKey();
        $localKey = $relation->getLocalKey();
        $isMultiple = $relation->isMultiple();
        $relatedClass = $relation->getRelatedClass();

        $localValues = self::collectLocalKeyValues($models, $localKey);

        if (empty($localValues)) {
            self::assignEmptyRelation($models, $relationName, $isMultiple);
            return;
        }

        $uniqueValues = array_values(array_unique($localValues));

        // Build a batch query using the related model class
        $batchQuery = self::buildBatchQuery($relatedClass, $relation, $foreignKey, $uniqueValues, $constraint);
        $relatedRecords = iterator_to_array($batchQuery->all());

        // Which column on the fetched rows says which parent each belongs to.
        //
        // For a direct relation that is the foreign key, which is a real column
        // on the related table. Through a junction it is not: the batch selects
        // `tag.*`, and the parent's id lives on `post_tag`, so grouping by
        // `tag_id` read an attribute no fetched row had. Every row grouped under
        // '' and every parent was assigned the empty list — eager loading a
        // many-to-many relation returned nothing at all, for every model, while
        // the lazy path returned the right rows. buildBatchQuery() selects the
        // junction column under a reserved alias for exactly this.
        $groupKey = $relation->getViaTable() === null ? $foreignKey : self::VIA_KEY;

        $grouped = self::groupByForeignKey($relatedRecords, $groupKey);

        // Assign to each parent model
        self::assignRelatedModels($models, $grouped, $localKey, $relationName, $isMultiple);

        // The alias is machinery, not data. Left in place it would show up in
        // toArray() and toJson() as a column the related table does not have.
        if ($groupKey === self::VIA_KEY) {
            self::discardViaKeys($relatedRecords);
        }
    }

    /**
     * Drop the junction-key carrier from records that were grouped by it.
     *
     * @param array<Model> $records The fetched related records.
     * @return void
     */
    private static function discardViaKeys(array $records): void
    {
        foreach ($records as $record) {
            unset($record->{self::VIA_KEY});
        }
    }

    /**
     * Collect non-null local key values from a set of models.
     *
     * @param array<Model> $models The parent models.
     * @param string $localKey The local key attribute name.
     * @return array<mixed>
     */
    private static function collectLocalKeyValues(array $models, string $localKey): array
    {
        $values = [];
        foreach ($models as $model) {
            $value = $model->{$localKey};
            if ($value !== null) {
                $values[] = $value;
            }
        }
        return $values;
    }

    /**
     * Assign an empty relation value to all models.
     *
     * @param array<Model> $models The parent models.
     * @param string $relationName The relation name.
     * @param bool $isMultiple Whether the relation returns multiple records.
     * @return void
     */
    private static function assignEmptyRelation(array $models, string $relationName, bool $isMultiple): void
    {
        foreach ($models as $model) {
            $model->setRelation($relationName, $isMultiple ? [] : null);
        }
    }

    /**
     * Assign loaded related models to each parent model.
     *
     * @param array<Model> $models The parent models.
     * @param array<mixed, array<Model>> $grouped Related models grouped by foreign key.
     * @param string $localKey The local key attribute name.
     * @param string $relationName The relation name.
     * @param bool $isMultiple Whether the relation returns multiple records.
     * @return void
     */
    private static function assignRelatedModels(
        array $models,
        array $grouped,
        string $localKey,
        string $relationName,
        bool $isMultiple
    ): void
    {
        foreach ($models as $model) {
            $key = $model->{$localKey};
            $related = $grouped[$key] ?? [];
            $model->setRelation($relationName, $isMultiple ? $related : ($related[0] ?? null));
        }
    }

    /**
     * Build a batch query for all related records.
     *
     * @param string $relatedClass The related model class name.
     * @param Relation $relation The relation instance.
     * @param string $foreignKey The foreign key on the related table.
     * @param array<int, mixed> $values The local key values to match.
     * @param callable|null $constraint Optional constraint callback.
     * @return ActiveQuery
     */
    private static function buildBatchQuery(
        string $relatedClass,
        Relation $relation,
        string $foreignKey,
        array $values,
        ?callable $constraint = null
    ): ActiveQuery
    {
        $query = $relatedClass::find();

        // Handle viaTable (many-to-many through junction)
        $viaTable = $relation->getViaTable();
        if ($viaTable !== null) {
            $viaLink = $relation->getViaLink();
            $junctionFk = $viaLink !== null ? (string)key($viaLink) : '';
            $localKey = $relation->getLocalKey();
            $query->join($viaTable, [$foreignKey => $localKey]);
            $query->in("!$viaTable.$junctionFk", $values);

            if ($constraint !== null) {
                $constraint($query);
            }

            self::selectGroupingKey($query, $viaTable, $junctionFk);

            return $query;
        }

        // Direct relation: WHERE foreign_key IN (...)
        $query->in($foreignKey, $values);

        // Apply user constraint
        if ($constraint !== null) {
            $constraint($query);
        }

        self::selectGroupingKey($query, null, $foreignKey);

        return $query;
    }

    /**
     * Make sure the batch fetches the column the rows will be grouped by.
     *
     * One batch fetches the related rows for every parent at once, so each row
     * has to say which parent it came back for. Nothing guaranteed it did.
     *
     * Through a junction the answer is not on the related table at all: the
     * batch selected `tag.*` while the parent's id sat on `post_tag`, so every
     * row grouped under '' and every parent got the empty list — eager loading
     * a many-to-many relation returned nothing, for every model, while the lazy
     * path returned the right rows.
     *
     * For a direct relation the column exists but a constraint could drop it.
     * `with(['posts' => fn($q) => $q->select('title')])` fetched the right rows
     * and then discarded all of them, reporting zero posts for users who have
     * them. The documented example includes the foreign key in its select,
     * which is what kept it working and what made the omission look like the
     * caller's mistake — but a projection is a statement about what the caller
     * wants back, not a licence to lose the rows.
     *
     * Appending after the constraint is what makes it not the caller's problem.
     * The column may end up named twice; every engine collapses duplicates in
     * an associative fetch, verified on MySQL, PostgreSQL and SQLite.
     *
     * @param ActiveQuery $query The batch query, already constrained.
     * @param string|null $viaTable The junction table, or null for a direct relation.
     * @param string $column The column the rows will be grouped by.
     * @return void
     */
    private static function selectGroupingKey(ActiveQuery $query, ?string $viaTable, string $column): void
    {
        // An untouched query still means SELECT *, which already includes the
        // grouping column for a direct relation. Naming it would turn the
        // wildcard off and narrow the result to that one column.
        if ($viaTable === null && $query->getSelects() === []) {
            return;
        }

        $grammar = Connection::grammar($query->getConnectionName());
        $table = $viaTable ?? (string)$query->getTable();

        // Whatever the caller asked for stays; the wildcard is added only when
        // they asked for nothing, so that a junction batch does not hydrate
        // models holding the carrier and no columns of their own.
        if ($query->getSelects() === []) {
            $query->select(new Raw($grammar->quoteIdentifier((string)$query->getTable()) . '.*'));
        }

        $query->select(new Raw(sprintf(
            '%s.%s AS %s',
            $grammar->quoteIdentifier($table),
            $grammar->quoteIdentifier($column),
            $grammar->quoteIdentifier($viaTable === null ? $column : self::VIA_KEY)
        )));
    }

    /**
     * Group models by their foreign key value.
     *
     * @param array<Model> $records The related records.
     * @param string $foreignKey The foreign key attribute name.
     * @return array<mixed, array<Model>>
     */
    private static function groupByForeignKey(array $records, string $foreignKey): array
    {
        $grouped = [];
        foreach ($records as $record) {
            $key = $record->{$foreignKey};
            $grouped[$key][] = $record;
        }
        return $grouped;
    }
}
