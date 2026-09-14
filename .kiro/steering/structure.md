# Project Structure

```
src/                        # Simsoft\DB\ namespace
├── Builder/                # SQL builders
│   ├── ActiveQuery.php     # Fluent SELECT builder (conditions, joins)
│   ├── Builder.php         # Abstract base (Insert/Update/Delete/Select)
│   ├── Select.php / Insert.php / Update.php / Delete.php / Raw.php / Upsert.php
│   ├── Aggregations/       # Count, Sum, Avg, Min, Max
│   ├── Clauses/            # Select, OrderBy, Having, CaseExpression
│   └── Conditions/         # Between, BetweenDate, In, Like, Exists, MatchAgainst
├── Cache/                  # ArrayCache, CacheInterface, QueryCache
├── Drivers/                # Driver.php (abstract), PDODriver, MySQLiDriver, PostgresDriver, SQLiteDriver
├── Exceptions/             # ConnectionException, QueryException
├── Generator/              # ModelGenerator, ObserverGenerator
├── Grammar/                # Grammar.php (abstract), MySQLGrammar, PostgresGrammar, SQLiteGrammar
├── Interfaces/             # Executable, Updatable, Deletable
├── Traits/                 # Aggregation, Binds, Condition, Error, Execute, Fetchable,
│                           # HasEvents, Ignore, LowPriority, PlaceHolder, Qualifier,
│                           # Scenario, SoftDeletes, Timestamps
├── Collection.php          # Query result collection
├── Connection.php          # Static connection registry (read/write splitting)
├── CursorPaginator.php     # Cursor-based pagination
├── DB.php                  # Facade/helper
├── EagerLoader.php         # Nested eager loading (with())
├── IndexAdvisor.php        # Query-based index suggestions
├── Model.php               # Abstract Active Record base
├── Paginator.php           # Offset-based pagination
├── Query.php               # @deprecated — use DB::table()
├── QueryLogger.php         # Query logging for debugging
├── QueryMonitor.php        # N+1 detection
└── Relation.php            # hasOne/hasMany

tests/                      # PHPUnit suite
├── config/                 # Test configuration files
├── Integration/            # Integration tests (Integration\ namespace)
├── Models/                 # Test fixtures (Models\ namespace)
├── Query/                  # Builder unit tests (no DB)
├── DBTest.php              # DB facade tests
└── ModelTest.php           # Model unit tests

docs/                       # User documentation
resources/                  # SQL schemas and sample data
```

## Architecture

- Active Record: Models extend `Model`, CRUD via `save()`/`delete()`/`update()`
- Query Builder: `ActiveQuery` compiles fluent calls to SQL + bound params
- Builder pattern: subclasses implement `buildSQL()`
- Trait composition for cross-cutting concerns
- Driver abstraction: PDO (MySQL, PostgreSQL, SQLite) + MySQLi via `Driver` base
- Grammar abstraction: dialect-specific SQL generation (MySQL, PostgreSQL,
  SQLite)
- Static connection registry: `Connection::add()`/`Connection::get()` with
  read/write splitting
- Prepared statements only — never interpolate user values

## New Code Placement

| Type              | Location                    | Namespace                         |
|-------------------|-----------------------------|-----------------------------------|
| Condition         | `src/Builder/Conditions/`   | `Simsoft\DB\Builder\Conditions`   |
| Aggregation       | `src/Builder/Aggregations/` | `Simsoft\DB\Builder\Aggregations` |
| Clause            | `src/Builder/Clauses/`      | `Simsoft\DB\Builder\Clauses`      |
| Trait             | `src/Traits/`               | `Simsoft\DB\Traits`               |
| Interface         | `src/Interfaces/`           | `Simsoft\DB\Interfaces`           |
| Driver            | `src/Drivers/`              | `Simsoft\DB\Drivers`              |
| Grammar           | `src/Grammar/`              | `Simsoft\DB\Grammar`              |
| Generator         | `src/Generator/`            | `Simsoft\DB\Generator`            |
| Cache             | `src/Cache/`                | `Simsoft\DB\Cache`                |
| Docs              | `docs/`                     | —                                 |
| Test models       | `tests/Models/`             | `Models`                          |
| Query tests       | `tests/Query/`              | `Query`                           |
| Integration tests | `tests/Integration/`        | `Integration`                     |

New classes/helpers MUST have a usage guide in `docs/` with real method names/signatures.
