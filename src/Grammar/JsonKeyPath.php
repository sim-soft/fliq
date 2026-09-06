<?php

namespace Simsoft\DB\Grammar;

use InvalidArgumentException;

/**
 * Path guard shared by the grammars' JSON key-existence builders.
 *
 * ActiveQuery's JSON methods take the path inside the column string, so
 * jsonHas('meta->size') asks for the key 'size' while jsonHas('meta') gives no
 * key at all and yields an empty path. The document root is not a key and
 * cannot be tested for existence, but each grammar built an expression from
 * the empty path anyway. MySQL produced the path '$.', which the server
 * rejects outright — "Invalid JSON path expression" — while PostgreSQL built
 * jsonb_exists(metadata, '') and ran it, asking whether a key named '' exists.
 * No document has one, so jsonHas('metadata') matched no rows and
 * jsonMissing('metadata') matched all of them, both exactly backwards and
 * neither reported as an error.
 *
 * Rejecting the empty path turns the silent PostgreSQL answer into the same
 * failure MySQL already gave, raised where the mistake is rather than at the
 * server.
 */
trait JsonKeyPath
{
    /**
     * Get the driver name identifier.
     *
     * @return string
     */
    abstract public function getDriverName(): string;

    /**
     * Reject a key-existence check with no key to check.
     *
     * @param string $path The JSON path.
     * @return void
     * @throws InvalidArgumentException If the path is empty.
     */
    protected function assertJsonKeyPath(string $path): void
    {
        if (trim($path) === '') {
            throw new InvalidArgumentException(sprintf(
                'A JSON key path is required for %s: the document root is not a key.'
                . " Write the path in the column, as 'column->key'."
                . " To test that the document itself is present, use notNull('column').",
                $this->getDriverName()
            ));
        }
    }
}
