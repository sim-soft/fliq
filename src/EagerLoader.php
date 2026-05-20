<?php

namespace Simsoft\DB;

use Simsoft\DB\Builder\ActiveQuery;

/**
 * EagerLoader class.
 *
 * Batch-loads relations for a collection of models to prevent N+1 queries.
 * Supports nested relations via dot notation: 'posts.comments.author'
 */
class EagerLoader
{
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
        $firstModel = $models[0];

        if (!method_exists($firstModel, $relationName)) {
            return;
        }

        // Get relation metadata from the first model
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

        // Group related records by foreign key value
        $grouped = self::groupByForeignKey($relatedRecords, $foreignKey);

        // Assign to each parent model
        self::assignRelatedModels($models, $grouped, $localKey, $relationName, $isMultiple);
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

            return $query;
        }

        // Direct relation: WHERE foreign_key IN (...)
        $query->in($foreignKey, $values);

        // Apply user constraint
        if ($constraint !== null) {
            $constraint($query);
        }

        return $query;
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
