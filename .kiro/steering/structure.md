# Project Structure

```
src/                        # Simsoft\DB\ namespace
├── Builder/                # SQL builders
│   ├── ActiveQuery.php     # Fluent SELECT builder (conditions, joins)
│   ├── Builder.php         # Abstract base (Insert/Update/Delete/Select)
│   ├── Select/Insert/Update/Delete/Raw.php
│   ├── Aggregations/       # Count, Sum, Avg, Min, Max
│   ├── Clauses/            # Select, OrderBy, Having
│   └── Conditions/         # Between, In, Like, Exists, MatchAgainst
├── Drivers/                # Driver.php (abstract), PDODriver, MySQLiDriver
├── Exceptions/             # ConnectionException, QueryException
├── Grammar/                # Grammar.php (abstract), MySQL, Postgres
├── Interfaces/             # Executable, Updatable, Deletable
├── Traits/                 # Binds, Condition, Execute, Debug, etc.
├── Collection.php          # Query result collection
├── Connection.php          # Static connection registry
├── DB.php                  # Facade/helper
├── Model.php               # Abstract Active Record base
├── Query.php               # Query helper
└── Relation.php            # hasOne/hasMany

tests/                      # PHPUnit suite
├── Models/                 # Test fixtures (Models\ namespace)
└── Query/                  # Builder unit tests (no DB)

docs/                 # User documentation
resources/                  # SQL schemas and sample data
```

## Architecture

- Active Record: Models extend `Model`, CRUD via `save()`/`delete()`/`update()`
- Query Builder: `ActiveQuery` compiles fluent calls to SQL + bound params
- Builder pattern: subclasses implement `buildSQL()`
- Trait composition for cross-cutting concerns
- Driver abstraction: PDO + MySQLi via `Driver` base
- Static connection registry: `Connection::add()`/`Connection::get()`
- Prepared statements only — never interpolate user values

## New Code Placement

| Type | Location | Namespace |
|------|----------|-----------|
| Condition | `src/Builder/Conditions/` | `Simsoft\DB\Builder\Conditions` |
| Aggregation | `src/Builder/Aggregations/` | `Simsoft\DB\Builder\Aggregations` |
| Clause | `src/Builder/Clauses/` | `Simsoft\DB\Builder\Clauses` |
| Trait | `src/Traits/` | `Simsoft\DB\Traits` |
| Interface | `src/Interfaces/` | `Simsoft\DB\Interfaces` |
| Driver | `src/Drivers/` | `Simsoft\DB\Drivers` |
| Docs | `docs/` | — |
| Test models | `tests/Models/` | `Models` |
| Query tests | `tests/Query/` | `Query` |

New classes/helpers MUST have a usage guide in `docs/` with real method names/signatures.
