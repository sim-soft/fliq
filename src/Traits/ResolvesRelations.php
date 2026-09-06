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
 * narrowed to methods that declare they return a Relation and can actually be
 * called with no arguments. Everything else reads as the absent attribute it is.
 */
trait ResolvesRelations
{
    /**
     * Determine whether a name is a lazy-loadable relation method.
     *
     * @param string $name The property name being read.
     * @return bool
     */
    private function isRelationMethod(string $name): bool
    {
        if ($name === '' || !method_exists($this, $name)) {
            return false;
        }

        $method = new ReflectionMethod($this, $name);

        if (!$method->isPublic() || $method->isStatic() || $method->getNumberOfRequiredParameters() > 0) {
            return false;
        }

        $returnType = $method->getReturnType();

        return $returnType instanceof ReflectionNamedType && $returnType->getName() === Relation::class;
    }
}
