<?php

namespace Simsoft\DB\Traits;

use ReflectionMethod;
use ReflectionNamedType;
use Simsoft\DB\Relation;

/**
 * ResolvesRelations trait.
 *
 * Decides which method names a property read is allowed to call.
 *
 * Reading `$model->posts` lazy-loads a relation, which means __get() has to
 * invoke a method. The guard for that was method_exists() alone, so reading
 * ANY method name as a property called it: `$user->delete` — a typo for
 * `$user->delete()`, or a template printing a key that turned out not to be a
 * column — ran the delete, removed the row, and then failed on ->fetch() with
 * "Call to a member function fetch() on true".
 *
 * Reading a property must never write to the database, so eligibility is
 * narrowed to methods that declare they return a Relation — not merely a
 * Relation or null — and can actually be called with no arguments. Everything
 * else reads as the absent attribute it is.
 *
 * The check is public because eager loading asks the same question of the same
 * names. EagerLoader had its own answer — method_exists() alone, the very guard
 * this replaced — so `with('delete')` deleted every row it had just selected.
 * One question, one answer.
 */
trait ResolvesRelations
{
    /**
     * Determine whether a name may be resolved as a relation.
     *
     * The return type must not be nullable. `?Relation` and `Relation|null` both
     * reflect as a ReflectionNamedType naming Relation, so testing the name
     * alone accepted them — and the whole point of testing the declaration is
     * that PHP then guarantees what comes back. It does not guarantee that for a
     * nullable type, so an eligible method could still answer null, and the four
     * callers of this each met that differently: reading the property and
     * isset() both died on "Call to a member function fetch() on null", has()
     * raised a TypeError naming an internal method, eager loading quietly left
     * the relation unloaded, and saveTogether() returned true having saved
     * nothing. Requiring the declaration to exclude null is what makes the one
     * answer usable by all four.
     *
     * @param string $name The property or relation name.
     * @return bool
     */
    public function isRelationMethod(string $name): bool
    {
        if ($name === '' || !method_exists($this, $name)) {
            return false;
        }

        $method = new ReflectionMethod($this, $name);

        if (!$method->isPublic() || $method->isStatic() || $method->getNumberOfRequiredParameters() > 0) {
            return false;
        }

        $returnType = $method->getReturnType();

        return $returnType instanceof ReflectionNamedType
            && $returnType->getName() === Relation::class
            && !$returnType->allowsNull();
    }
}
