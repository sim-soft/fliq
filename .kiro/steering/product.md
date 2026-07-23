# Product Overview

simsoft/fliq (FLIQ — **F**ast, **L**ightweight, **I**ndependent **Q**uery Builder) — high-performance PHP Active Record / ORM. Faster and simpler than Eloquent/Doctrine/Yii2 AR with zero framework dependencies. Inspired by Yii2 AR and Eloquent, optimized through lean internals and direct query compilation.

## Capabilities

- Fluent query builder (method chaining)
- Active Record CRUD on models
- PDO + MySQLi drivers (MySQL, PostgreSQL, SQLite)
- Named connections (single/multiple) with read/write splitting
- Relations (hasOne, hasMany) with nested eager loading
- Attribute casting, guarded/fillable
- Aggregations (count, sum, avg, min, max)
- Raw SQL with parameter binding
- Sub-queries, joins, unions
- Dirty attribute tracking
- Transactions
- Soft deletes
- Timestamps (created_at/updated_at)
- Global scopes
- Model observers/events
- Query result caching (pluggable driver)
- Offset pagination + cursor pagination
- N+1 query detection
- Index advisor (suggests missing indexes from logged queries)
- Code generators (model + observer via `bin/fliq`)

## Philosophy

- Performance first: minimal allocations, direct SQL compilation
- Simplicity over magic: explicit API, no hidden queries
- Standalone: no framework coupling
- SOLID + GRASP, trait composition
- Model encapsulation: attributes/query logic inside models only
- Prepared statements only — never interpolate user values
