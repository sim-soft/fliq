<?php

namespace Simsoft\DB\Exceptions;

use RuntimeException;

/**
 * MassAssignmentException class.
 *
 * Thrown when a model is mass assigned but has declared no rules for it, and
 * `Model::requireAssignmentRules()` is in force.
 */
class MassAssignmentException extends RuntimeException
{
}
