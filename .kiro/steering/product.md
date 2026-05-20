# Product Overview

simsoft/fliq (FLIQ — **F**ast, **L**ightweight, **I**ndependent **Q**uery Builder) — high-performance PHP Active Record / ORM. Faster and simpler than Eloquent/Doctrine/Yii2 AR with zero framework dependencies. Inspired by Yii2 AR and Eloquent, optimized through lean internals and direct query compilation.

## Capabilities

- Fluent query builder (method chaining)
- Active Record CRUD on models
- PDO + MySQLi drivers
- Named connections (single/multiple)
- Relations (hasOne, hasMany)
- Attribute casting, guarded/fillable
- Aggregations (count, sum, avg, min, max)
- Raw SQL with parameter binding
- Sub-queries, joins, unions
- Dirty attribute tracking
- Transactions

## Philosophy

- Performance first: minimal allocations, direct SQL compilation
- Simplicity over magic: explicit API, no hidden queries
- Standalone: no framework coupling
- SOLID + GRASP, trait composition
- Model encapsulation: attributes/query logic inside models only
- Prepared statements only — never interpolate user values
