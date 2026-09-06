# Changelog

All notable changes to `simsoft/fliq` are documented here.

## [Unreleased]

### Fixed

**Security**

- **Identifier quoting could be escaped** — `quoteIdentifier()` wrapped names in
  backticks (MySQL) or double quotes (PostgreSQL, SQLite) without escaping those
  characters in the input, so a column name containing one closed the quoting
  early and the rest was parsed as SQL. Passing
  ``id` FROM `user` UNION SELECT username FROM `user` --`` as a column returned
  every username. The quote character is now doubled, which is the standard
  escape for all three engines.
- **JSON paths could escape their string literal** — JSON paths cannot be bound
  as parameters, so they are interpolated into a quoted literal; a quote in the
  path closed it and the remainder became SQL. All 17 interpolation sites across
  the three grammars now escape the path. MySQL also doubles backslashes, which
  it treats as an escape character inside string literals.
- **PostgreSQL text search config could escape its string literal** — the
  `$language` argument to `whereFulltext()` reached `to_tsvector('...')` and
  `plainto_tsquery('...')` unescaped, so a quote in it closed the literal. It is
  now escaped like any other interpolated literal.
- **PostgreSQL array cast type was interpolated unchecked** — the `$type`
  argument to `arrayContains()` / `arrayOverlaps()` becomes a bare SQL keyword
  (`::text[]`), so it can be neither quoted nor bound. It is now restricted to a
  simple identifier and rejected with `InvalidArgumentException` otherwise;
  multi-word types such as `double precision` remain valid.

- **Mass assignment bypass in `Model::update()`** — attributes passed to
  `update()` were written straight to the database without consulting
  `$fillable` / `$guarded`, so `$user->update($_POST)` could set guarded columns
  (including the primary key). The rules are now applied to the supplied
  attributes, matching the constructor and `fill()`.
- **`ORDER BY` direction injection** — the array form of `orderBy()` only
  uppercased the direction instead of whitelisting it, so
  `orderBy(['id' => $_GET['sort']])` could append arbitrary SQL. Both the array
  and scalar forms now normalise to `ASC` / `DESC`, with anything else falling
  back to `ASC`.
- **Comparison operator injection** — operators are interpolated into SQL rather
  than bound, and were accepted unvalidated, so an operator taken from request
  data (`where('id', $_GET['op'], 1)`) could replace the comparison outright:
  `'UNION SELECT'` turned the comparison into `WHERE id UNION SELECT ?`. Operators are now
  checked against a whitelist and rejected with `InvalidArgumentException`.
  This affected every condition method, not just `where()`: `orWhere()`,
  `having()`, `whereColumn()`, `whereAny()` / `whereAll()` / `whereNone()`,
  `whereJson()`, `whereJsonLength()`, `whereDate()` / `whereMonth()` /
  `whereYear()` / `whereTime()`, and `CaseExpression::when()` / `whenColumn()`.
- **Table aliases were interpolated unvalidated** — an alias names the table and
  prefixes every unqualified column, so it can be neither quoted away nor bound,
  yet three paths assigned it straight to the property instead of going through
  `validateIdentifier()`: `from(['t' => $sub])`, `withAlias()` and `alias()`
  itself. Quoting alone does not make one safe, since the grammars double an
  embedded quote character rather than reject it — ``alias('t`;--')`` reached the
  statement as a usable identifier with its punctuation intact. Validation now
  happens in `alias()`, which every one of those paths goes through.
- **The write builders validated no identifier at all** — `Insert`, `Update`,
  `Delete` and `Upsert` quoted their table and column names by calling `quote()`
  directly, bypassing the `validateIdentifier()` check every read path gets. As
  above, quoting is not a defence on its own: `` new Insert('user` (id) VALUES
  (1) -- ', [...]) `` produced a statement with a second, attacker-chosen value
  list, and `ActiveQuery` had been refusing the same input all along. Both names
  now go through `quoteTable()` / `quoteColumn()`, which validate first — on
  every write builder, on the bulk column list, and on `setCounter()`, which
  writes its column on both sides of the assignment.
- **A quote in a phrase search term reopened the query as operators** — MySQL
  phrase mode wraps the term in double quotes inside `BOOLEAN MODE`, where a
  quote in the term itself closed the phrase early and the remainder was read
  as query syntax. `whereFulltext(['title', 'body'], 'data"base systems',
  'phrase')` matched rows containing neither phrase. The term stays bound, and
  the quote is now replaced with a space before the phrase is assembled; the
  same applies to SQLite's FTS5 phrase mode.

**Data Integrity**

- **Relation filters silently returned every row for an unrecognised name** —
  `has()`, `doesntHave()`, `whereHas()` and `whereDoesntHave()` returned the
  query untouched when the name was not a relation, so `has('psots')` dropped
  the filter and answered with the whole table. A filter that cannot be applied
  widens the result set, which is the one wrong answer a caller has no way to
  see. All four now throw `InvalidArgumentException`, naming the model and
  distinguishing a missing method from one that does not return a `Relation`.
- **Relation filters were unusable together with `alias()`** — the EXISTS
  sub-query correlated to the model's table name rather than the name the parent
  has in the query, so `User::find()->alias('u')->has('posts')` emitted
  ``` `post`.`user_id` = `user`.`id` ``` under ``` FROM `user` `u` ``` and raised
  `Unknown column 'user.id' in 'where clause'`. The correlation now uses the
  query's alias when one is set.
- **Self-referencing relations selected the wrong rows in silence** — the inner
  and outer queries used the same table name, so the correlation resolved
  against the sub-query's own `FROM` and compared each row to itself.
  `Category::find()->has('children')` returned 0 parents where the database has
  3, and `doesntHave('children')` returned every row instead of the 6 leaves.
  The inner table is now aliased `<table>_exists` when — and only when — it
  would otherwise collide, so callbacks that qualify by the related table name
  keep working everywhere else.
- **Many-to-many relation filters raised `Unknown column`** — `viaTable()` was
  ignored, so the junction's foreign key was looked for on the related table:
  `Post::find()->has('tags')` emitted ``` `tag`.`tag_id` = `post`.`id` ```, a
  column that exists nowhere. Existence is now decided on the junction table,
  which is all that is needed and leaves the callback constraining the junction.
- **The sub-query was built with the default connection's grammar** — a query on
  any other connection mixed quoting styles in a single statement:
  `SELECT "user".* ... EXISTS (SELECT 1 FROM ``post`` ...)`, which parses on
  neither engine. The sub-query now inherits its parent's connection.
- **`arrayContains()` never worked for a string on MySQL** — the candidate value
  was bound straight into `JSON_CONTAINS(col, ?, '$')`, which requires JSON
  text rather than a bare value, so every string raised `Invalid JSON text in
  argument 1 to function json_contains: "Invalid value." at position 0`.
  Integers passed only by accident, `2` being valid JSON on its own. The
  candidate is now built in SQL with `JSON_ARRAY(?)`, so the value stays bound
  and the same call selects the same rows on MySQL, PostgreSQL and SQLite.
- **Array elements were compared as strings on MySQL, so integers never
  matched** — PDO binds every value as a string, so `JSON_ARRAY(?)` given `2`
  built `["2"]`, which does not match a stored `[1,2]`.
  `arrayContains('role_ids', 5, 'int')` and `arrayOverlaps('nums', [2, 9],
  'int')` both returned nothing where PostgreSQL returned the row. The element
  type the caller already passes for PostgreSQL's `::int[]` cast now also
  selects a MySQL cast for the bound value (`SIGNED`, `DECIMAL`, `DATE`,
  `DATETIME`, `CHAR`), chosen from a fixed map so the type never reaches the
  statement as SQL.
- **`arrayOverlaps($col, [])` emitted `IN ()` on SQLite** — a syntax error on
  every other engine, accepted only because SQLite is lenient. It now emits
  `0 = 1` and binds nothing, on both SQLite and MySQL; PostgreSQL's native
  `ARRAY[]::text[]` was already valid. This matches how `like($col, [])`
  already treats an empty value list.
- **Every MySQL search mode ran in `BOOLEAN MODE`, which changed results and
  crashed on ordinary terms** — `fulltextSearch()` ignored its `$mode` argument
  entirely, so `plain` and `phrase` emitted the same SQL as `websearch`. In
  boolean mode punctuation in the term is query syntax: searching
  `'database -systems'` returned nothing, because the hyphen was read as an
  exclusion the caller never wrote, and `'C++ database'` raised
  `QueryException: syntax error, unexpected '+'` — an ordinary search term
  crashed the query. `plain` now uses `NATURAL LANGUAGE MODE`, `phrase` quotes
  the bound term, and `websearch` keeps `BOOLEAN MODE`, where the operators are
  the caller's intent. The three modes now agree with their PostgreSQL
  counterparts on the same data.
- **SQLite full-text search dropped every column after the first** —
  `whereFulltext(['title', 'body'], ...)` searched `title` alone and said
  nothing about `body`, so the result was quietly incomplete. FTS5 scopes
  `MATCH` to one column and a search expression carries one bound term, so
  several columns cannot be honoured in a single expression; passing more than
  one now raises `InvalidArgumentException` naming the alternative. An empty
  column list, which previously fell back to a column named `content` that the
  caller never mentioned, is refused for the same reason.
- **SQLite reported that it had no full-text support while still emitting
  `MATCH`** — `supportsFulltext()` returned `false`, but nothing consults it, so
  callers got a statement that failed at execution with `unable to use function
  MATCH in the requested context`. FTS5 is compiled into SQLite by default; the
  requirement is a table created with `CREATE VIRTUAL TABLE ... USING fts5`,
  which is a property of the table rather than the driver. The flag now reports
  what the driver actually has.

- **MySQL statement modifiers were emitted to every engine, and on PostgreSQL
  wrote to the wrong table** — `LOW_PRIORITY`, `IGNORE` and `QUICK` are MySQL
  keywords, but `Update`, `Delete` and `Model::updateAll()` emitted them
  regardless of the connection. On SQLite that is a syntax error and on
  PostgreSQL `UPDATE LOW_PRIORITY t` fails with `relation "low_priority" does not
  exist` — noisy, but safe. `UPDATE IGNORE "user" SET ...` is worse: PostgreSQL
  parses it as an update of a table named `ignore` aliased `"user"`, so where a
  table of that name exists the statement **succeeds, reports rows affected, and
  writes to the wrong table** while the intended row is untouched. Verified
  end to end against PostgreSQL 14.5. The modifiers are now gated on
  `Grammar::supportsStatementModifiers()` and omitted where they mean nothing;
  dropping a MySQL-only scheduling hint changes nothing about what the statement
  does.
- **Bulk `insertOrIgnore()` had never worked on PostgreSQL** —
  `PostgresGrammar::insertIgnoreFullSQL()` wrapped the placeholders it was given
  in parentheses, which suits a single row and not a bulk set that already
  brings its own. The result was `VALUES ((?,?),(?,?))`, read as one row holding
  two row-constructors and rejected with `SQLSTATE[42601] INSERT has more target
  columns than expressions`. The grammar no longer wraps, and the single-row
  caller supplies its own parentheses.
- **`RETURNING` was silently dropped from an ignored INSERT** — on PostgreSQL and
  SQLite the grammar supplies the whole statement for `insertOrIgnore()`, and
  that override ends at `DO NOTHING`; the clause was only appended on the path
  the override replaced. A caller asking for one got no rows back and no
  indication why. It is now appended after the conflict action, where it returns
  a row when the insert happened and none when it was skipped.
- **`getLastInsertId()` reported an id for a row that does not exist** — when
  `ON CONFLICT DO NOTHING` skipped an insert, `RETURNING` named no row and the
  code fell through to the driver, which answered with the sequence's current
  value. That id belongs to no row the statement wrote, and on PostgreSQL to no
  row at all, since the conflicting attempt still consumes a sequence number.
  Nothing was inserted, so it now returns `null`.
- **SQLite never captured `RETURNING` results** — SQLite has supported the clause
  since 3.35 and `SQLiteGrammar` advertises it, so the clause was emitted and the
  rows it produced were left in the statement and discarded.
  `getReturningResult()` answered `null` — the same answer it gives for a
  statement that returned nothing. `SQLiteDriver::execute()` now fetches them,
  for INSERT, UPDATE and DELETE alike.
- **A bulk INSERT silently dropped values** — every row is written against the
  first row's columns, so a key only a later row carried was never sent. The
  insert reported success, the column kept its default, and nothing anywhere
  said so; against a nullable column there was no symptom at all. Such a row is
  now refused with a message naming it and the columns at fault. A row *short* of
  a column is still accepted and supplies `NULL` for it — a value the caller did
  not give, rather than one they gave and the database never saw.
- **Schema-qualified table names were broken on every write builder** — the
  whole string was quoted as a single identifier, so `public.user` became
  `"public"."user"` on a read and `"public.user"` on a write, naming a table no
  server has. `INSERT INTO public.user` failed with `relation "public.user" does
  not exist`. Writes now use the same schema-aware quoting reads do.
- **`Model::insertBatch()` assembled its own statement from a `Raw`** — the same
  multi-row INSERT `Insert` already builds, minus the identifier validation and
  minus the ragged-row check above. It now delegates to `Insert`.
- **Degenerate `Insert` arguments crashed inside the builder** — `new Insert('t',
  [])` emitted `INSERT INTO \`t\` () VALUES ()`, which no engine accepts, after
  raising `Undefined array key 0` and a `TypeError` out of `array_keys()`; a list
  of bare scalars and a bulk set whose first row is empty failed the same way.
  All three now raise `InvalidArgumentException` naming what the caller passed
  rather than a line inside the builder.

- **Nested transactions silently lost atomicity** — no driver tracked whether a
  transaction was already open, so a nested `transaction()` call issued a second
  `BEGIN`. On the default MySQLi driver that **implicitly commits** the outer
  transaction, so the inner call permanently wrote the outer's work and a later
  outer rollback had nothing left to undo — both rows survived a rollback that
  should have discarded them. The PDO drivers raised
  `There is already an active transaction` instead. Nesting is now supported on
  all four drivers: the outermost call opens the real transaction and inner
  calls use savepoints, so an inner rollback undoes only its own work while an
  outer rollback still undoes everything. `getTransactionLevel()` reports the
  current depth.
- **Reconnecting during a transaction silently discarded it** —
  `reconnectIfNeeded()` runs before every query and would transparently
  re-establish a dropped connection mid-transaction, abandoning the statements
  written so far while letting the remaining ones commit on their own. It now
  throws `ConnectionException` instead of turning an atomic block into a partial
  write.
- **Cached query results leaked between connections** — the cache key was built
  from the SQL and bind values only, so the same statement run against two
  different databases shared one entry and the second connection was served the
  first one's rows. Verified end to end: a query against database B returned
  database A's data. The connection name is now part of the key, which matters
  most for multi-tenant setups that keep tenants apart by connection.
- **`update()` left `updated_at` stale** — `update()` wrote its columns without
  running `beforeSave()`, so the `Timestamps` trait never fired and the row's
  other columns changed while `updated_at` kept its old value. `save()` was
  unaffected, which made the two paths disagree about the same record.
  `beforeSave()` now runs on both, exactly once per write.

**Query Builder**

- **`FROM` and `JOIN` were quoted for whichever connection was current when they
  were named, not the one the query ran on** — both were rendered eagerly by
  `from()` and `join()`, freezing that grammar into the stored string. A
  connection chosen afterwards produced a statement quoted for one engine and
  run against another, so `DB::table('user', 'pg')` — which calls `from()` before
  `withConnection()` internally — sent MySQL backticks to PostgreSQL and failed
  outright. Both sources are now held unquoted and rendered at `getSQL()` time,
  which is also how `where()`, `select()` and `orderBy()` already worked.
- **`getTable()` returned a rendered clause rather than a table name** — on an
  aliased query it gave ``​`user` `u`​``, and every caller tried to recover the
  name with `trim($t, '`"')`, which cannot remove the interior quotes. `count()`,
  `sum()`, `min()`, `max()` and `updateAll()` on an aliased query therefore asked
  the server for a table named ``user` `u`` and failed. It now returns the raw
  name, and callers quote it themselves for their own grammar.
- **Aggregates over a sub-query source emitted an empty `FROM`** — a query built
  with `from(['t' => $sub])` has no table name, so `count()` and the other
  aggregates produced ``FROM ``​``. They now re-emit the sub-query, placing its
  bind values ahead of the outer condition's to match the order the placeholders
  appear in.
- **`updateAll()` on a sub-query source built an `UPDATE` against a `SELECT`** —
  the sub-query SQL was passed to `Update` as though it were a table name. It now
  raises a `QueryException`, since there is no table to write back to.
- **A sub-query source could end up with no name at all** — a derived table must
  be named, and both MySQL and PostgreSQL refuse one that is not, but the alias
  was quoted without being checked. `from(['SELECT ...'])` written as a list took
  the integer key `0` and produced a table named `` `0` ``; `from([])`, and
  `alias(null)` after a good alias, produced the empty identifier `` `` `` —
  which MySQL and SQLite happen to accept and PostgreSQL rejects outright, so the
  same builder emitted SQL that ran on two engines and would not parse on the
  third. All three now raise `InvalidArgumentException` naming the argument at
  fault. `Qualifier::getQualifiedSubQuery()` had the same gap and also did not
  validate an alias it was given.
- **An alias equal to the table name was emitted twice** — `from('user u')
  ->alias('user')` produced ``FROM `user` `user```, because the check compared the
  new alias against the already-rendered clause and never matched.
- **A negative `limit()` silently returned every row** — `hasLimit()` tested
  `$limit > 0`, so a negative limit reported that no limit was set and
  `Collection` fell back to paginating the full result set. `limit(-5)` returned
  the entire table while `getSQL()` displayed `LIMIT -5`. Negative values are now
  rejected by `limit()` and `offset()`, and `page()` requires a page of 1 or
  greater (`page(0)` previously computed a negative offset).
- **`getTotalPages()` divided by an unchecked page size** — `getTotalPages(0)`
  raised an uncaught `DivisionByZeroError` and a negative size returned a
  negative page count (`getTotalPages(-5)` gave `-2`), which a paginator would
  then loop over. Both are now rejected, matching `limit()` and `page()`.
- `mysqli::ping()` is deprecated in PHP 8.4 and emitted a deprecation notice on
  every reconnect check; `MySQLiDriver::ping()` now issues `SELECT 1`, matching
  the PDO, PostgreSQL and SQLite drivers.

**Reliability**

- **MySQLi never recovered a dropped connection** — `reconnectIfNeeded()` was
  called by the PDO and PostgreSQL drivers only. `MySQLiDriver::execute()` and
  `query()` never called it, so the default driver had no reconnect path at all
  and a connection dropped by `wait_timeout` failed every subsequent query for
  the life of the process. All four drivers now recover.
- **A dropped connection was only noticed by the pre-query ping** — recovery
  depended entirely on the liveness check happening to run first. A connection
  that died between the check and the statement failed outright. Recovery is now
  driven by the statement: a failure whose error names a lost connection is
  reconnected and retried once. Errors that are not connection losses are
  re-thrown untouched and never retried, and a loss inside a transaction still
  throws `ConnectionException` rather than retrying, since retrying one
  statement of an atomic block would commit it alone.
- **A connection lost during rollback masked the real exception** — tearing down
  a transaction on a dead connection threw the driver's own connection error
  from inside the rollback, replacing the `ConnectionException` that explains
  what happened. The rollback now swallows connection-loss errors only; any
  other rollback failure still propagates.

**Performance**

- **Every query paid for a `SELECT 1` liveness check** — the ping ran before
  each statement, costing a full round trip. Measured against a local MySQL over
  `pdo_mysql`, the ping was **77%** of the cost of a small bound query (1.001 ms
  of 1.291 ms). Connections used within the last 30 seconds are no longer
  re-checked, which more than halved the measured per-query time (1.291 ms →
  0.507 ms). Idle connections — the ones that actually get dropped — are still
  checked. The window is configurable per connection with `ping_idle_seconds`;
  `0` restores a ping before every query.

**Query Plans (EXPLAIN)**

- **`explain(format: ...)` was assembled from one template for every driver** —
  each engine names its own formats and spells the request differently, and the
  argument was neither translated nor checked. MySQL ignored `$format` entirely
  and returned a twelve-column traditional plan for `format: 'json'`, which
  callers then parsed as JSON. PostgreSQL emitted
  `EXPLAIN ANALYZE (FORMAT JSON)`, which the server rejects with a syntax error
  at `FORMAT` — the correct spelling puts every option in one list,
  `EXPLAIN (ANALYZE, FORMAT JSON)`. Both were the documented calls. The prefix is
  now built by the grammar for the connection the query runs on, and a format the
  engine cannot produce raises `InvalidArgumentException` instead of silently
  returning a plan in a different shape. MySQL's `EXPLAIN ANALYZE` is restricted
  further, since it rejects `FORMAT=JSON` beside it, and SQLite rejects `analyze`
  outright because `EXPLAIN QUERY PLAN` does not execute the statement.

**Debug Output (`dump()`, `dd()`, `getFullSQL()`)**

These render a statement with its bind values interpolated so it can be read and
pasted into a client. They were assembled in `Qualifier` with one rule for every
driver and no knowledge of how a value would actually be bound, which got the
rendering wrong in both directions — showing differences that were not there and
hiding ones that were. Rendering now belongs to the grammar, beside the escaping
that already lived there, as `Grammar::literal()` and `Grammar::readableSQL()`.

- **A value containing a backslash produced unparseable SQL on MySQL** — only the
  apostrophe was escaped, but MySQL treats a backslash inside a literal as an
  escape character. `'back\'` never closed the literal, so the rest of the
  statement was swallowed into it and the server reported a syntax error for a
  query that had executed without complaint. MySQL now doubles backslashes, which
  it already did for JSON paths; PostgreSQL and SQLite correctly leave them
  alone, since doubling there would show a value with two where the bound one had
  one.
- **Numeric-looking strings were rendered bare and changed the comparison** — an
  `is_numeric()` check emitted `007` and `1e3` unquoted. Except on SQLite,
  `PDOStatement::execute()` binds every value as a string, so the executed
  statement compared `'007'` while the rendering compared the number — and
  against a text column those differ: `'007' = 7` holds where `'007' = '7'` does
  not. The rendering found rows the query did not. Values are now rendered as the
  driver sends them, and SQLite — whose driver binds by type — overrides this.
- **A genuine `null` bind was rendered as the string `'?'`** — a bound null was
  substituted with the placeholder itself, so `deleted_at = '?'` was rejected by
  MySQL with `Incorrect TIMESTAMP value: '?'`. It renders as `NULL`.
- **A statement short of binds had its placeholder rendered as a value** — the
  remaining `?` came back quoted as `b = '?'`, which reads as a value the caller
  passed and never did. Unfilled placeholders are now left as written.
- **A `DateTime` or array bind crashed the debug helper** — casting one raised
  `Object of class DateTime could not be converted to string`, an `Error` thrown
  out of `dump()` from inside the debugging that was meant to find the original
  problem. Values with no string form now render as `'[DateTime]'` / `'[array]'`.
- **`Raw::dump()` printed a different shape from every other builder** — `Raw`
  has no `Qualifier`, so it fell through to a two-line `SQL` + `Binds: [...]`
  form: not the output the documentation shows, and not something that can be
  pasted into a client. Every builder now dumps the same interpolated statement.

**Robustness**

- **`execute()` and `query()` discarded what the driver wrote back** — both
  cloned their target before running it, so a `RETURNING` result — which
  PostgreSQL and SQLite write onto the builder — landed on the copy and was
  thrown away with it. `execute($insert)` reported no returned row and no last
  insert id for a statement that had run and returned one.
- **Running a cached query through another builder killed the process** — the TTL
  was read as `$target->cacheTtl` behind a `property_exists()` check.
  `property_exists()` reports a protected property as present, but reading one
  from outside the declaring class is a fatal `Error` rather than a catchable
  exception. The public accessor is used instead.
- **A cache backend failure was reported as a query failure** — the cache read
  and write sat inside the `try` wrapping the query, so a full Redis or an
  unreachable memcached surfaced as a `QueryException` naming the `SELECT`. On
  the write side the rows had already been fetched and were then discarded; on
  the read side they were always obtainable from the database. The cache is now
  best-effort on both sides: a failed read is a miss, and a failed write leaves
  the result untouched.
- **`QueryLogger` grew without bound** — every executed query was retained for
  the life of the process, so a long-running worker or queue consumer
  accumulated the SQL and bind values of every query it had ever run until it
  exhausted memory. The log now keeps the most recent 1000 entries and discards
  older ones, while `getQueryCount()` and `getTotalTime()` continue to describe
  every query, not just the retained ones. Configurable with
  `QueryLogger::setLimit()`; `0` restores unlimited retention.
- **A mistyped `Query::` method silently matched every row** — `__callStatic()`
  checked whether the method existed and, when it did not, returned a bare query
  with no conditions instead of failing. `Query::wheer('id', 5)` therefore
  matched the whole table, so a typo in a `DELETE` or `UPDATE` would apply to
  every row. Unknown methods now throw `BadMethodCallException`.
- **`DB::table()` ignored the connection argument for models** — the `Model`
  overload returned `$model::find()` and dropped `$connection` on the floor,
  while `resolveTable()` — used by every write method — honoured it. So
  `DB::insert($model, [...], 'reporting')` wrote to `reporting` but
  `DB::table($model, 'reporting')` read from the default connection: the same
  argument in the same position, silently routed to a different database. The
  override is now applied; passing no connection still leaves the model's own
  connection intact.
- **A relation with a `NULL` key returned the entire related table** —
  `Relation::applyConstraints()` returned early when the local key value was
  `null`, leaving the query with no condition at all. Fetching such a relation
  therefore returned every row of the related table rather than none:
  `$post->getComments()->fetch()` on an unsaved `Post` returned all comments in
  the database, and a *saved* row with a nullable foreign key was worse —
  `$post->getCategory()->fetch()` returned an arbitrary unrelated `Category`
  instead of `null`, so the record appeared to belong to something it has no
  link to. A relation with no key value now matches nothing. Note this is not
  `WHERE fk IS NULL`, which would wrongly match other rows whose key is also
  null.
- **Aggregates returned `0` when the result alias was `null`** — every
  aggregate method advertises `?string $alias`, but passing `null` made all
  eleven of them return `0` regardless of the data. The SQL was correct; without
  an alias no `AS` clause is emitted, so the driver names the column after the
  expression (`COUNT(*)`), while `queryScalar()` looked up `$row[null]`, which
  PHP coerces to the absent key `''` and falls through to the `?? 0` default. So
  `$query->count('*', null)` reported an empty table and `avg('score', null)`
  reported `0.0` — a wrong answer rather than an error. The aggregate is the
  only selected column, so it is now read positionally when no alias was given.
  Empty result sets still yield `0`.
- **PostgreSQL notifications were silently lost or cross-delivered** —
  `listen()`, `unlisten()` and `notify()` stripped every character outside
  `[a-zA-Z0-9_]` from the channel name, which changed *which channel was
  addressed* rather than rejecting a bad name. `listen('order-created')`
  subscribed to `ordercreated`, so a notification a trigger published under the
  real name never arrived and nothing reported an error. Worse, distinct names
  collapsed onto one another: a subscriber to `tenant-1` received messages
  addressed to `tenant1`. Channel names are now quoted as identifiers, matching
  `PostgresGrammar::quoteIdentifier()`, so they are used exactly as given.
- **`notify()` published to a different channel depending on the payload** — an
  empty payload took a `NOTIFY <channel>` branch, where the unquoted identifier
  was folded to lowercase, while a non-empty payload used a bound
  `pg_notify(?, ?)`, which is case-sensitive. `notify('MixedCase')` and
  `notify('MixedCase', 'data')` therefore reached two different channels, so a
  subscriber received some of a publisher's messages and not others. Both now
  route through `pg_notify(?, ?)`.
- **Over-long PostgreSQL channel names were truncated by the server** — a name
  longer than 63 bytes (`NAMEDATALEN - 1`) was silently truncated by `LISTEN`
  while `pg_notify()` rejected the same string, so the two could never agree and
  names differing only past byte 63 collapsed. Channel names are now validated
  in the driver and an empty or over-long one raises `InvalidArgumentException`
  from all three methods; 63 bytes remains valid.
- **`notBetweenDate()` matched nothing at all** — the negated range was built as
  `col < start AND col > end`, which asks for a date that is both before the
  range and after it. No row can satisfy that, so every call returned an empty
  result with no error. A row outside a range is before the start *or* after the
  end, so the two comparisons are now joined with `OR`. The same fault, and the
  same fix, applied to the negated interval form
  (`notBetweenDateInterval()` and `betweenDateInterval(..., is: false)`).
- **Negated date ranges were not parenthesised** — now that they build an `OR`,
  an adjacent condition would have bound to only one half of the range, because
  `AND` binds tighter than `OR`. Both negated forms are now wrapped, so
  `where(...)->notBetweenDate(...)` restricts the whole range rather than one
  end of it.
- **`betweenDate()` with neither date produced broken SQL** — the clause
  returned an empty string, which the builder still joined to its neighbours:
  alone it emitted a bare `WHERE`, and alongside another condition a dangling
  operator (`WHERE id = ? AND`). Either way the query failed with a syntax error
  pointing far from the call responsible. Omitting both dates now raises
  `InvalidArgumentException` naming the attribute.
- **MySQL silently dropped `NOWAIT` and `SKIP LOCKED`** — `MySQLGrammar` only
  recognised the shared-lock mode, so both modifiers fell through to a plain
  `FOR UPDATE`. A caller asking to skip locked rows got the blocking behaviour
  instead: the documented job-queue pattern
  (`forUpdateSkipLocked()`) waited on rows another worker held rather than
  passing over them, and `forUpdateNoWait()` blocked where it was meant to fail
  fast — up to `innodb_lock_wait_timeout`, 50 seconds by default. Both clauses
  have been available since MySQL 8.0 and are now emitted.
- **`crossJoin()` built an `ON` clause out of nothing** — the `$on` parameter
  defaults to an empty array, which is how a `CROSS JOIN` is normally written
  since it pairs every row and has nothing to match on. The builder took the
  first key and value of that empty array anyway, emitting
  ``ON `post`.`` = {}`` plus PHP warnings, which the server rejects outright —
  so the documented call could not run at all. A join given no keys now omits
  the clause entirely.
- **`withAlias()` never applied its alias** — an unqualified column is stored
  deferred as `{name}` and resolved in `getSQL()` against whatever alias is
  current *then*, which is the query's own, since `withAlias()` restores it
  before returning. Every column named inside the callback came out qualified
  with the `FROM` table instead of the temporary alias. Nothing failed: the
  query ran and constrained the wrong table. Entries appended by the callback
  are now resolved while the temporary alias still holds.
- **`like()` with no patterns broke the query** — an empty terms array is an
  ordinary runtime state (a search box submitted blank, a filter list nobody
  ticked), but the compound form was assembled regardless and emitted an empty
  group: `WHERE ()` on its own, or a dangling `AND ()` beside another
  condition. Both are syntax errors, so the page died rather than showing
  unfiltered results. The condition is now skipped, as `in()` already does for
  an empty value list. `LikeCondition` had the same fault.
- **An empty clause left a dangling operator** — `onCondition()` appended the
  logical operator *before* building the clause, so a clause that built to
  nothing left `AND` with no right-hand side. The operator is now added only
  once the clause is known to have produced SQL, which covers every clause
  rather than each one separately.
- **`ORDER BY` direction injection in `OrderByClause`** — the array branch only
  uppercased its direction instead of whitelisting it, so a direction taken
  from request data could append arbitrary SQL. This is the same defect already
  fixed in `ActiveQuery::orderBy()`; the class even carried an
  `$allowedDirections` list, but only its scalar branch consulted it. Both
  branches now normalise to `ASC` / `DESC`.
- **`BetweenCondition` accepted any number of bounds** — it always emitted two
  placeholders but bound whatever it was given, so one, three or zero values
  left a placeholder/bind mismatch. The driver reported it as "must consist of
  ... elements" without naming the attribute. Anything other than exactly two
  bounds now raises `InvalidArgumentException` naming it.
- **`HavingClause` skipped the bind for a null value** — the placeholder was
  emitted unconditionally, so a null left the statement one bind short and the
  driver rejected it outright. A null now binds like any other value.
- **`SelectClause` with no columns emitted `SELECT  FROM`** — the empty string
  was still added to the column list, producing a syntax error alone and
  `a, , b` beside other columns. An empty clause is now dropped, and a query
  left with no columns falls back to `*` as it already does when `select()` is
  never called.
- **`withAlias()` discarded most callables** — the parameter accepts any
  `callable`, but only a `Closure` was acted on. An invokable object or a
  `[$object, 'method']` pair fell past the check and was dropped without a
  word, so the call added nothing and returned `$this` as though it had worked.
  A callable that cannot be rebound is now invoked as-is, receiving the query as
  its argument.
- **`IS` and `IS NOT` compared against the operator text** — both are on the
  documented operator whitelist, so `where('deleted_at', 'IS', null)` passed
  validation, but nothing handled them: the two-argument shorthand saw a null
  value, took the operator as the value, and built ``deleted_at` = 'IS'``. That
  is valid SQL, so there was no error to notice — it simply matched the wrong
  rows, silently. Both now build `IS [NOT] NULL`, as does `<>` against null,
  which had the same fate while `!=` was already handled.
- **`IN`, `NOT IN`, `BETWEEN` and `NOT BETWEEN` emitted one placeholder** —
  these are on the same whitelist, so `where('id', 'IN', [1, 2])` looks
  supported, but both the fast path and `Condition` built `id IN ?` and the
  server rejected the statement. They are now routed to `in()`, `notIn()` and
  `between()`, which already build the correct shape. A range needs exactly two
  bounds and a set needs an array, subquery or `Raw`; anything else raises
  `InvalidArgumentException` naming the attribute.
- **An empty value list built `IN ()`** — both array forms of `where()` emitted
  an empty group for an entry whose values were empty, which is a syntax error,
  so a filter narrowed to nothing took the whole query down. The entry is now
  skipped and the rest of the group still applies, matching `in()`.
- **A short triplet bound null and warned** — the list form destructured each
  `[attribute, operator, value]` entry without checking its shape, so a
  two-element entry raised "Undefined array key 2" and then built a condition
  matching nothing. Entries are now validated, with the error naming the
  position at fault.
- **A comparison against several values left the binds short** — `Condition`
  emits one placeholder, but bound every value it was given, so
  `where('username', 'LIKE', ['%a%', '%b%'])` failed with the driver's "must
  consist of exactly 1 elements" rather than anything naming the attribute.
  It now raises `InvalidArgumentException` when the shapes disagree.
- **`Condition` discarded its operator for a null value** — a null value bound
  the *operator* in its place and reset the operator to `=`, so a clause built
  as `score > null` became ``score` = '>'`` — the column compared against the
  literal operator text, which a column whose value happened to be `>` would
  have matched. The shorthand predates `operator()` validating its input. A
  null now binds like any other value.
- **Bind values were handed over in call order, not placeholder order** —
  placeholders are positional, but every value went into one list as the builder
  methods were called, while `getSQL()` emits the sections in a fixed order.
  Building a query in any other order therefore paired the values with the wrong
  placeholders: `groupByRaw('... > ?', [50])->where('status_code', '=', 1)`
  filtered on `status_code = 50` and grouped on `score > 1`. Both are valid SQL,
  so there was no error and no log line — the query simply returned the wrong
  rows. Each section now keeps its own list, joined in emission order.
- **`HAVING` entries were joined with a comma** — `GROUP BY` takes a list but
  `HAVING` takes one boolean expression, so a second `having()` or `havingRaw()`
  call produced `HAVING a, b`, a syntax error that took the whole query down.
  Entries are now joined with `AND`, or with `OR` through the new `orHaving()`
  and `orHavingRaw()`. `orMerge()` had the same fate and is fixed with it.
- **A `Raw` attribute discarded the operator and value beside it** —
  `where(new Raw('score'), '>', 90)` built a bare `WHERE score`, a truthiness
  test keeping every non-zero row. Valid SQL, so nothing reported the missing
  comparison; it returned all ten sample users where two match. A `Raw` given
  alone still stands as the whole condition. This affected `having()` equally.
- **A `Raw` select expression dropped its binds** —
  `select(new Raw('IF(score > ?, 1, 0) AS grade', [50]))` left the statement one
  value short of its placeholders and the driver refused to execute it.
- **`having()` never handled `IS` / `IS NOT`** — the routing added to `where()`
  did not reach `having()`, so its own value shorthand took the operator as the
  value and built `HAVING col = 'IS'` — matching nothing, with no error. Both
  now build `IS [NOT] NULL`.
- **`IS` and `IS NOT` accepted a value they cannot compare against** — given a
  non-null value they built `col IS ?`, which the server rejects with a message
  naming only the position in the statement. They now raise
  `InvalidArgumentException` naming the operator and the value's type.
- **Joining a sub-query could not run in any form** — the documented
  `join(['alias' => $query], ...)` array form folded the sub-query into the table
  string and quoted the whole `SELECT` as one identifier, so the server rejected
  it as an over-long identifier name. It was also aliased twice, and the
  sub-query's bind values were dropped. The alias is now quoted alone, the
  sub-query parenthesised, and its binds kept for the `JOIN` section.
- **`merge()` moved the incoming binds into the `WHERE` list** — every value was
  appended wholesale regardless of the section it belonged to, so a merged
  query's `HAVING` value landed behind a `WHERE` placeholder. Join binds were
  dropped entirely. Each list is now taken into its counterpart.
- **Aggregates were given binds for placeholders they had not emitted** —
  `Count` and the other aggregates re-emit a source query's `JOIN`, `WHERE`,
  `GROUP BY` and `HAVING` but write their own `SELECT`, yet took all of its
  binds, so a source query with a `Raw` select expression left the statement
  over-supplied and the driver refused it. They now take the condition sections
  alone.

### Changed

- `Model::update()` now filters its argument through the mass assignment rules.
  Attributes set directly (`$model->column = $value`) remain trusted and are
  still written, as are attributes set by lifecycle hooks such as the
  `Timestamps` trait. **This is breaking** for code that relied on `update()`
  writing columns outside `$fillable` — use `updateAttributes()` for those.
- `updateAttributes()`, `updateAll()`, `insertBatch()` and `updateBatch()`
  documented as bypassing mass assignment protection, alongside the lifecycle
  hooks they already skipped. Never pass unvalidated external input to them.
- Condition methods now accept only whitelisted comparison operators: `=`, `!=`,
  `<>`, `>`, `>=`, `<`, `<=`, `<=>`, `LIKE`, `NOT LIKE`, `ILIKE`, `NOT ILIKE`,
  `IN`, `NOT IN`, `IS`, `IS NOT`, `BETWEEN`, `NOT BETWEEN`, `REGEXP`,
  `NOT REGEXP` and `RLIKE`. Word operators are case-insensitive, and the
  `where('col', 'value')` shorthand is unaffected. **This is breaking** for code
  passing any other operator — use `Raw` for expressions outside this set.
- `limit()`, `offset()` and `page()` now throw `InvalidArgumentException` on
  negative or out-of-range values instead of generating invalid SQL or silently
  ignoring the limit. `limit(0)` still means "no limit" and is unchanged.
- `getTotalPages()` now throws `InvalidArgumentException` when the page size is
  below 1. **This is breaking** for callers passing `0` or a negative size,
  though the previous behaviour was a fatal error or a negative page count.
  Unlike `limit()`, `0` is not "no limit" here — a page has to hold something.
- `Model::update()` now runs `beforeSave()`, so lifecycle traits apply to it as
  they do to `save()`. **This is breaking** for a `beforeSave()` override with
  side effects that assumed `update()` would skip it. Model events are still not
  fired by `update()`; call `save()` when you need those.
- `QueryException` no longer appends the failing SQL to `getMessage()`. That
  message is what reaches logs, error pages and third-party error trackers, and
  SQL text names tables and columns and often embeds literals. The statement is
  still carried on the exception — call `getSql()` and `getBinds()` to inspect
  it deliberately. **This is breaking** for anything parsing the message for
  SQL. `QueryException::enableDebug()` restores the old message format for local
  development. Note the driver's own error text is passed through unchanged and
  may still name the table it failed on: withholding the statement narrows what
  leaks, it does not make the message safe to show a user.
- `Query::__callStatic()` now throws `BadMethodCallException` for an unknown
  method instead of returning an unconditioned query. **This is breaking** only
  for code relying on the previous behaviour, which had no legitimate use.
- `QueryLogger` now retains at most 1000 queries by default. **This is breaking**
  for code reading `getQueries()` expecting the complete history of a long run;
  call `QueryLogger::setLimit(0)` for the old unlimited behaviour. Aggregates
  (`getQueryCount()`, `getTotalTime()`) are unaffected and still cover every
  query. `getDroppedCount()` reports how many were discarded.
- Connections are no longer pinged before every query, only after
  `ping_idle_seconds` (default 30) of inactivity. A lost connection is now
  recovered when the statement fails rather than by the pre-query check, so
  recovery is stronger than before rather than weaker. Set `ping_idle_seconds`
  to `0` in a connection's config to restore a ping before every query.

### Added

- `Model::requireAssignmentRules()` makes mass assignment on a model that
  declares neither `$fillable` nor `$guarded` throw `MassAssignmentException`
  instead of accepting every column but the primary key. Opt-in rather than the
  default, deliberately: switching it on for everyone would break existing
  undeclared models silently, at runtime, in exactly the write paths that
  matter. Call it once during bootstrap to find the omissions on the first
  request instead of after a bad write. `Model::allowUndeclaredAssignment()`
  reverses it. Direct assignment and `updateAttributes()` are unaffected.
- `ping_idle_seconds` connection config controls how long a connection may sit
  idle before its next use re-checks it.
- `QueryLogger::setLimit()`, `getLimit()` and `getDroppedCount()` for bounding
  and inspecting the query log.
- `QueryException::enableDebug()` / `disableDebug()` / `isDebug()` control
  whether the failing SQL is appended to the exception message.
- `orHaving()` and `orHavingRaw()` join a `HAVING` condition to the previous one
  with `OR`. `having()` and `havingRaw()` take a trailing logical operator too.
- `ActiveQuery::getConditionBinds()` returns the binds for the filtering
  sections alone — `JOIN`, `WHERE`, `GROUP BY`, `HAVING` — for callers that
  re-emit a query's conditions under their own `SELECT`.

### Tests

- 7 security tests covering `ORDER BY` direction whitelisting (rejection and
  valid directions), `update()` mass assignment (guarded attributes stripped,
  directly-assigned attributes preserved), and operator whitelisting (rejection
  across every condition method, and valid comparisons still accepted)
- 3 query builder tests covering negative `limit()` / `offset()`, invalid
  `page()` numbers, and valid limits still applying (including `limit(0)`)
- 7 security tests covering identifier-quote escaping, JSON path escaping, the
  PostgreSQL text search config and the array cast type — each paired with a
  test that the valid forms (`*`, `user.*`, `!user.id`, nested JSON paths,
  `double precision`) are unchanged
- 7 transaction tests covering nesting: an outer rollback discarding work an
  inner call committed, an inner rollback leaving the outer intact, nesting
  beyond two levels, exception propagation from a nested call, and that the
  depth counter unwinds to zero after both success and failure
- 27 date range tests covering all eight `betweenDate` / `betweenDateInterval`
  methods, which previously had none (0.00% coverage on the clause, now 100%).
  Results are checked against the answer the database computes for the
  equivalent plain SQL rather than a hand-written row list, so an expectation
  cannot be wrong in the same direction as the code. Includes each range and its
  negation partitioning the table, grouping against a surrounding `AND` on
  either side, inclusive bounds, the half-open interval end, and the binding of
  dates. 11 of the 27 fail against the unfixed clause.
- 10 row-locking tests covering all four lock clauses, which previously had
  none. The behavioural ones create real contention from a second connection:
  `SKIP LOCKED` is checked to return exactly the rows another transaction does
  *not* hold, and `NOWAIT` to fail within seconds rather than waiting out the
  lock timeout. Against the unfixed grammar the contention test does not merely
  fail — it blocks until "Lock wait timeout exceeded", which is the production
  symptom itself.
- 8 join tests covering joins written without an `ON` clause: `crossJoin()` with
  and without an alias, two of them side by side, every join type reaching the
  same path, and that joins given keys are unaffected. 4 fail against the
  unfixed builder.
- 13 `withAlias()` tests covering the joined-table case the method exists for,
  the alias applying only inside the callback, `select` / `groupBy` / `orderBy`
  as well as conditions, nesting, explicit prefixes left alone, each callable
  form, and the alias being restored when the callback throws. 10 fail against
  the unfixed builder.
- 23 clause-object tests covering `LikeCondition`, `BetweenCondition`,
  `SelectClause`, `HavingClause` and `OrderByClause`, none of which had a
  single test — every one sat at 0.00% coverage despite `select()` and
  `where()` both accepting a `Clause`. They were `ActiveQuery`'s own
  implementation until the builder inlined its clause construction, which left
  them exported and unexercised. 10 of the 23 fail against the unfixed code.
- 8 integration tests for `like()` with no patterns, checked against the answer
  the database computes for the equivalent plain SQL. Covers each `like`
  variant, an empty clause between two live conditions (which must still be
  joined to each other), and that a list with patterns in it is not swallowed
  by the guard. 6 of the 8 fail against the unfixed builder — as errors, since
  the query did not run at all.
- 16 operator-shape tests covering every whitelisted operator whose right-hand
  side is not a single placeholder — `IN`, `NOT IN`, `BETWEEN`, `NOT BETWEEN`,
  `IS`, `IS NOT` and `<>` against null — plus the shape errors each now raises.
  `Condition` was the lowest-covered file in `src` at 47.22%.
- 14 tests for the two array forms of `where()`, covering an empty value list
  in each, a dropped entry leaving the conditions on either side joined to each
  other, malformed triplets, and the ordinary shapes both forms still build.
- 10 integration tests for the same operators, checked against the answer the
  database computes for the equivalent plain SQL. These run live because the
  `IS` defect produced *valid* SQL: a string comparison would have called the
  broken form correct, since only the rows it returned were wrong. Includes the
  two null operators selecting disjoint sets that together cover the table —
  previously both returned nothing, which would have looked consistent had each
  been checked only against the other.
- 2 cache tests covering key separation by connection and an end-to-end check
  that two databases holding the same table never serve each other's rows
- 2 timestamp tests covering `update()` advancing `updated_at` while leaving
  `created_at` alone, and an explicitly assigned `updated_at` surviving it
- 2 aggregation tests covering the rejected page sizes and that valid ones still
  count correctly, including a query matching no rows
- 7 connection resilience tests run against a live server, severing the
  connection with MySQL's `KILL` rather than simulating it: transparent recovery
  on both reads and writes, a loss inside a transaction throwing instead of
  retrying (asserting no partial write survives and the depth counter unwinds),
  a genuine SQL error being neither retried nor swallowed, and the idle window
  itself — that a live connection is not pinged, that `ping_idle_seconds => 0`
  pings every time, and that an idle connection is still checked. The ping
  assertions count server-side `Com_select` rather than measuring elapsed time,
  so a slow CI machine cannot make them flap.
- 4 query logger tests covering the retention limit, that a limit of `0` keeps
  everything, that lowering the limit trims immediately, and that `reset()`
  clears the dropped totals
- 7 security tests covering the mass assignment opt-in (permissive by default,
  rejecting undeclared models when required, leaving declared models and direct
  assignment alone), `QueryException` keeping SQL out of its message and debug
  mode restoring it, and `Query::` rejecting an unknown method while valid ones
  still work
- 34 relation tests covering the M:N pivot write API, which had no coverage at
  all: `attach()` (including that a duplicate pair throws rather than being
  ignored, and that one bad pair rolls back the whole batch), `detach()` (a
  given subset, `null` for all, that an empty array is not treated as "all", and
  that unattaching a shared tag leaves other rows alone), `sync()` (attach-only,
  detach-only, both at once, idempotence, and string IDs from request data
  comparing equal to integer IDs), `saveMany()`, the `viaTable` guard on
  non-pivot relations, and the accessors. Also covers the `NULL` key fix from
  both directions — an unsaved parent and a saved row with a nullable foreign
  key — and asserts writes on a keyless relation cannot touch the table. This
  takes `Relation` from 34.58% to 100% line coverage.
- 40 `DB` facade tests covering the execution path. The existing suite only
  exercised `sqlOnly` mode, so every method was tested for the SQL it builds
  and none for what it does to a database — the branch that runs in
  production. Covers all four write families including the `IGNORE`, `QUICK`
  and `LOW_PRIORITY` variants, `upsert()` inserting and updating, `raw()` and
  `query()` (including that binds stay data and that both throw on bad SQL),
  `transaction()` committing, rolling back on `false`, rolling back on an
  exception, and preserving the original exception as `getPrevious()`, and the
  `sqlOnly` flag applying to every write method while `raw()` / `query()`
  deliberately bypass it. This takes `DB` from 5.45% to 100% line coverage.
- 40 aggregation tests. The six `Distinct` variants had no tests at all, and
  neither did the alias parameter every aggregate accepts. Covers
  `countDistinct` (collapsing repeated values, the `'*'` branch that skips the
  `DISTINCT` keyword, and a unique column where it changes nothing),
  `sumDistinct`, `maxDistinct`, `minDistinct` and `avgDistinct` against both
  repeated and unique columns, the `0` contract on an empty result set,
  `getTotalPages` (rounding up, exact division, no matches, and the
  `InvalidArgumentException` below 1), the null-alias fix across all eleven
  methods, and that aggregating leaves the query reusable — `avg()` passes
  `$this` where the others pass a clone, so repeated calls and later `where()`
  additions are asserted to still behave. This takes `Traits\Aggregation` from
  49.12% to 100% line coverage.
- 41 PostgreSQL driver tests run against a live server. The existing suite only
  exercised query building, leaving the driver's own surface untested. Covers
  the five advisory lock methods (a second session refused, reentrant session
  locks needing one release each, transaction-level locks freed on both commit
  and rollback, and a lock released when its session ends), LISTEN/NOTIFY (the
  bugs above, plus payload fidelity for quotes, newlines and JSON, the sender's
  backend pid, and the 63-byte name boundary), the statement cache (eviction at
  the configured size, reuse across repeated queries, `clear`, and queries still
  working while disabled), and connection loss — severed with
  `pg_terminate_backend()` from a third session, asserting `ping()` reports it,
  that `query()` and `execute()` recover transparently, that the statement cache
  is dropped on reconnect, and that a loss inside a transaction throws with no
  partial write surviving. Negative assertions publish a sentinel afterwards
  rather than polling once, since `NOTIFY` is asynchronous and an immediate poll
  cannot distinguish "not sent" from "not yet arrived". This takes
  `PostgresDriver` from 55.90% to 94.90% line coverage.
- 30 tests covering grouping, `HAVING` and bind ordering — 18 asserting the
  generated SQL and the order values reach the driver, 12 running against a live
  database. Covers `HAVING` joined with `AND` and `OR` across `having()`,
  `orHaving()`, `havingRaw()` and `orHavingRaw()`; a `Raw` attribute compared
  against a value and standing alone; bind order when a section is built out of
  emission order, for `GROUP BY`, `HAVING`, `SELECT` and `UNION`; `IS` / `IS NOT`
  routed to a `NULL` check in `having()` and rejected when given a value; a
  joined sub-query in both `ActiveQuery` and `Raw` form; `merge()` keeping each
  list in its own section; `clearBinds()`; and an aggregate taking only the binds
  for the sections it re-emits. Two of these defects produced valid SQL that
  returned the wrong rows with no error, so the integration tests compare every
  result against the answer the database computes for the equivalent plain SQL
  rather than against an expected SQL string — which would have called the
  broken forms correct. 26 of the 30 fail against the unfixed code. This takes
  `Traits\Groupable` from 61.54% to 88.10% and `Conditions\Condition` to 100%.
- 50 tests covering the debug renderings and alias validation — 34 unit and 16
  run against MySQL, PostgreSQL and SQLite. The rendering tests never assert an
  expected SQL string: they execute both the bound statement and the rendered
  one and compare the rows the server returns, because a rendering that quietly
  disagrees with the query is precisely the defect, and a string comparison
  would have called every broken form correct. Seeded with values that render
  one way and compare another — `007`, `1e3`, `a\b`, a trailing backslash, an
  apostrophe — since the difference only shows against rows that hold them.
  Covers escaping per engine (MySQL doubling backslashes, PostgreSQL and SQLite
  not), `NULL`, bools and ints rendered as the driver sends them, SQLite's
  by-type binding, unfilled placeholders, a `?` inside a value, values with no
  string form, and `Raw` dumping the same shape as every other builder. The
  alias tests cover the three unvalidated paths, a rejected alias leaving the
  query untouched, and the sub-query forms that produced a table named `0` or
  nothing at all. 61 of the 81 fail against the unfixed code.
- Two integration tests inserted a user without removing it. `DatabaseTestCase`
  reloads the fixture once per class, but several classes read the sample rows
  without reloading anything, so a leaked row surfaced as an off-by-one count
  failure in an unrelated class depending on execution order — visible here as
  one failure in roughly fifteen full runs, and not reproducible from the seed
  that produced it. Both now delete their row in a `finally`.

### Documentation

- New "Security: how your values are protected" section in the query builder
  guide, covering value binding, which parts of a query are validated instead of
  bound, validating column names that come from user input, and the
  responsibility that comes with `Raw`.
- The operator whitelist, the `ASC` / `DESC` fallback and the `limit()` /
  `offset()` / `page()` constraints are now documented where they are used, in
  the query builder guide and the cheatsheet.
- New "Nesting transactions" section in the Active Record guide, covering
  savepoint semantics, what an inner versus an outer rollback undoes, and the
  connection-loss behaviour.
- New "What makes a cache entry unique" section in the advanced features guide,
  explaining that the cache key includes the connection name and why that
  matters for multi-tenant setups.
- The aggregation section of the query builder guide now documents the `0`
  returned for an empty result set, why `countDistinct('*')` is a plain count,
  the result alias argument (including `null`), and `getTotalPages()`.
- New "Channel Names" subsection in the PostgreSQL guide, covering names being
  used exactly as given (so a trigger's `pg_notify()` reaches the subscriber
  that asked for it), case sensitivity, the 63-byte limit and why it is checked
  in the driver, and that `NOTIFY` is asynchronous — so a single non-blocking
  poll is not proof that nothing was sent.
- The `dd()` section of the query builder guide now shows the output for its
  "works on any builder" example, and explains why numbers come back quoted —
  PDO binds every value as a string except on SQLite, so the rendering shows
  what ran rather than what was typed. Also states that the output is escaped
  for the connection's own engine, and that it remains a debugging aid rather
  than a way to build SQL.
- The Between Date sections of the query builder guide now document the negated
  forms, which were previously absent: the SQL they build, the parentheses and
  why they are needed, that at least one of the two dates is required, and that
  an interval window is half-open so consecutive windows tile without overlap.
- New "Joins Without an ON Clause" subsection in the query builder guide.
  `crossJoin()` previously appeared only in a bare method list, with no example
  of the form it is normally written in.
- New "Scoping Conditions to a Joined Table" subsection documenting
  `withAlias()`, which was previously undocumented: what the temporary alias
  covers, that it applies only inside the callback, and that explicitly
  prefixed names are left alone.
- The Like Clauses section now documents what an empty pattern array does, that
  it matches `in()`, and how to get the opposite behaviour if an empty search
  should return nothing.
- New "Operators that take more than one value" section in the Where Clauses
  guide. The operator table listed `IN`, `BETWEEN`, `IS` and their negations,
  but nothing said what to pass them — the array shape the set and range
  operators need, that `IS` takes no value, which shorthands are equivalent,
  and that the dedicated methods read better when the operator is not itself a
  variable.
- New "Passing several conditions at once" section covering both array forms of
  `where()`. Only the list of triplets appeared in an example; the map form was
  built but undocumented, including that it is parenthesised, that an array
  value means `IN`, that an empty value list is skipped, and that a `null`
  value binds rather than becoming an `IS NULL` check.
- The grouping example filtered on a `SELECT` alias — `having('count', '>', 1)`.
  A plain string attribute is qualified with the table alias, so it built
  `` `t`.`count` > ? `` and the server answered "Unknown column 't.count' in
  'having clause'". The example as written could not run. It now uses the `Raw`
  aggregate form, and a new "`HAVING`: one expression, several conditions"
  section documents the joining rule, `orHaving()` / `orHavingRaw()`, why a
  select alias needs `havingRaw()` (and that resolving one there is a MySQL
  extension), and comparing against a `Raw` expression in both `where()` and
  `having()`.
- New "`IS` and `IS NOT` compare against `NULL`" subsection, covering both
  methods and the exception raised when given a value.
- New "Joining a Sub-query" subsection. The `[alias => query]` array form is in
  the `join()` signature and its docblock but appeared in no example, and never
  worked; it is now documented with the bind ordering it implies.

---

## [2.0.6] - 2026-08-13

### Fixed

- **`join()` with a table alias produced a wrong ON clause** — a qualified
  foreign key matching the join table or its alias (`['s.supp_idx' =>
  'supp_idx']` with alias `s`) was quoted whole, generating an invalid column
  reference; the prefix is now stripped before quoting
- **`make:model <ClassName>` ignored the supplied class name** — the generator
  always derived the class name from the table, so the file was written under
  the derived name; `ModelGenerator::className()` now overrides it in both
  `generate()` and `preview()`

---

## [2.0.5] - 2026-07-22

### Fixed

- **`SoftDeletes` trait silently overrode `Model::find()`** — a model defining
  its own `find()` (or using a custom query class) lost the soft delete scope
  entirely, returning trashed records. The scope is now applied by the
  `ActiveQuery` constructor instead of by a trait-level `find()` override, so it
  survives any custom `find()`.
- **`withoutGlobalScope()` dropped the soft delete scope** — excluding one named
  global scope also discarded soft delete filtering; the soft delete scope is
  now re-applied independently.

**Security**

- **Connection charset/collation interpolated into SQL** — `charset`,
  `collation` and `schema` config values were interpolated into `SET NAMES` /
  `SET search_path` unsanitised; now stripped to `[a-zA-Z0-9_]` in the PDO and
  PostgreSQL drivers
- **`NOTIFY` payload escaped by hand** — `str_replace("'", "''", ...)` replaced
  with a prepared `SELECT pg_notify(?, ?)`

### Changed

- `ActiveQuery::__construct()` accepts `withScopes` to opt out of automatic
  scope application
- `Model::applyGlobalScopes()` visibility raised from `protected` to `public`
  so `ActiveQuery` can invoke it

---

## [2.0.4] - 2026-07-16

### Added

**PostgreSQL — Production Grade**

- `RETURNING` clause on INSERT (reliable `lastInsertId` without sequence names)
- `RETURNING` clause on UPDATE/DELETE (`returning()` method +
  `getReturningResult()`)
- `INSERT ... ON CONFLICT DO NOTHING` for `insertOrIgnore()` /
  `DB::insertOrIgnore()`
- `FOR UPDATE`, `FOR SHARE`, `FOR UPDATE NOWAIT`, `FOR UPDATE SKIP LOCKED`
  row-level locking
- `whereFulltext()` / `orWhereFulltext()` — PostgreSQL full-text search via
  `to_tsvector`/`to_tsquery`
- `arrayContains()` / `arrayOverlaps()` — native PG array column queries (`@>`,
  `&&`)
- `jsonHas()` / `jsonMissing()` now uses `jsonb_exists()` on PostgreSQL
- Advisory locks: `advisoryLock()`, `advisoryLockTry()`, `advisoryUnlock()`,
  `advisoryLockTransaction()`, `advisoryLockTransactionTry()`
- LISTEN/NOTIFY: `listen()`, `unlisten()`, `notify()`, `getNotification()`
- Statement cache configurability: `statement_cache`, `statement_cache_size`
  config + `enableStatementCache()`, `disableStatementCache()`
- Schema-qualified table references (`"schema"."table"` quoting)
- Boolean casting handles PostgreSQL `'t'`/`'f'`/`'true'`/`'false'` strings

**Query Builder**

- `explain()` / `explain(analyze: true, format: 'json')` — query execution plan
  for all drivers
- `forUpdate()`, `forShare()`, `forUpdateNoWait()`, `forUpdateSkipLocked()` —
  row-level locking
- `whereFulltext(columns, term, mode, language)` — full-text search (PG:
  tsvector, MySQL: MATCH AGAINST)
- `arrayContains(column, value, type)` / `arrayOverlaps(column, values, type)` —
  array column queries
- `CaseExpression` — fluent CASE WHEN THEN ELSE END builder with `when()`,
  `whenColumn()`, `whenRaw()`, alias support via `->as()`

**Code Generators (CLI)**

- `vendor/bin/fliq make:model <ClassName>` — generate Model from database table
- `vendor/bin/fliq make:model --all` — generate models for all tables
- `vendor/bin/fliq make:observer <ModelName>` — generate Observer class
- `vendor/bin/fliq make:observer --all` — generate observers for all models
- Auto-detection: relations (`*_id` → `hasOne`), traits (SoftDeletes,
  Timestamps), casts, `$guarded`, composite PKs, enum comments
- Config auto-discovery (`config/db.php`, `config/database.php`)
- `--exclude`, `--dry-run`, `--preview`, `--force`, `--verbose`, colored output
- Namespace auto-detection from `composer.json` PSR-4 mapping

**Documentation**

- `docs/10-POSTGRESQL.md` — full PostgreSQL production guide
- `docs/11-MODEL-GENERATOR.md` — Model generator tutorial
- `docs/12-OBSERVER-GENERATOR.md` — Observer generator tutorial

### Changed

- PostgreSQL driver no longer labeled "beta"
- `Model::insert()` uses `RETURNING` on PostgreSQL for reliable auto-increment
  ID retrieval
- `jsonHas()` / `jsonMissing()` now use Grammar interface (`jsonKeyExists()`)
  instead of hardcoded MySQL SQL
- LIKE methods auto-use `ILIKE` / `NOT ILIKE` on PostgreSQL when
  case-insensitive (verified, no change needed)

### Fixed

- `INSERT IGNORE` on PostgreSQL now generates `ON CONFLICT DO NOTHING` (was
  producing bare `INSERT`)
- `PDO::lastInsertId()` on PostgreSQL now works via `RETURNING` (was unreliable
  without sequence name)
- Boolean cast `(bool)'f'` no longer incorrectly returns `true` on PostgreSQL
- Schema-qualified tables now quote correctly (`"public"."users"` not
  `"public.users"`)

---

## [1.0.0] - 2026-05-20

Initial release.

- Fluent query builder (SELECT, INSERT, UPDATE, DELETE, UPSERT)
- Active Record pattern with hasOne/hasMany/viaTable relations
- PDO driver (MySQL/MariaDB) and MySQLi driver
- PostgreSQL driver
- Eager loading with dot notation and constraints
- Soft deletes and timestamps traits
- Collection with lazy/chunked iteration
- JSON column queries
- Fulltext search (MATCH AGAINST)
- Query logging and N+1 detection
- PHPStan level 8, PHPMD, PSR-12

### Added

**Query Builder**
- `whereAny(array $columns, string $operator, mixed $value)` — WHERE (col1 op ? OR col2 op ?)
- `whereAll(array $columns, string $operator, mixed $value)` — WHERE (col1 op ? AND col2 op ?)
- `whereNone(array $columns, string $operator, mixed $value)` — WHERE NOT (col1 op ? OR col2 op ?)
- `orWhereAny()`, `orWhereAll()`, `orWhereNone()` — OR variants

**JSON Queries**
- Auto JSON extraction via `->` notation in `where()`, `in()`, `orderBy()`, `groupBy()`, etc.
  - `->where('meta->age', '>', 25)` now works without `whereJson()`
- `whereJsonDoesntContain(string $column, mixed $value)` — NOT JSON_CONTAINS
- `whereJsonContainsKey(string $column)` — JSON_CONTAINS_PATH key exists
- `whereJsonDoesntContainKey(string $column)` — NOT JSON_CONTAINS_PATH
- `orWhereJson()`, `orWhereJsonContains()`, `orWhereJsonDoesntContain()`
- `orWhereJsonContainsKey()`, `orWhereJsonDoesntContainKey()`
- `orWhereJsonLength()`
- Short aliases: `jsonContains()`, `jsonNotContains()`, `jsonHas()`, `jsonMissing()`

### Fixed

- **PDODriver statement cache HY093** — `execute(null)` on a cached prepared statement caused parameter count mismatch; fixed by passing `[]` instead of `null`
- **`has()`/`whereHas()`/`doesntHave()`/`whereDoesntHave()` wrong table correlation** — EXISTS subquery referenced the wrong table; fixed by using explicit `parent_table.local_key` reference
- **`viaTable()` M:N wrong JOIN column** — `JOIN post_tag ON post_tag.id = tag.id` was generated instead of `post_tag.tag_id = tag.id`; fixed in both `Relation::applyConstraints()` and `EagerLoader::buildBatchQuery()`
- **`Condition::buildSQL()` null bind injection** — `appendBinds(null)` was called when a `Raw` object had no binds, adding a phantom null bind value

### Improved

- PHPStan level 8 — 0 errors across `src/` and `tests/`
- PHPMD — 0 violations
- Null safety in all 3 drivers (PDO, MySQLi, PostgreSQL) — connection guards throw `RuntimeException` instead of calling methods on null
- Full iterable value type annotations on all array properties and parameters

### Tests

- 321 integration tests covering: CRUD, relationships, eager loading, soft deletes, timestamps, collections, JSON queries, fulltext search, GROUP BY/HAVING/JOIN, query monitoring/logging, security (SQL injection resistance), upsert execution, MySQLi driver
