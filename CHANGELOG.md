# Changelog

All notable changes to `simsoft/fliq` are documented here.

## [Unreleased]

### Added

- **`QueryMonitor::clearHandler()` and `QueryLogger::clearHandler()`** — there
  was no way to undo `setHandler()`. Neither `disable()` nor `reset()` removes a
  handler, so one registered for a profiling block kept receiving queries for
  the rest of the process: writing to a file the caller had finished with, or
  into a closure whose request had ended.

### Fixed

**LIKE case sensitivity**

- **`orLike()`, `notLike()` and `orNotLike()` documented the wrong default** —
  all three docblocks said `caseSensitive` defaults to `false` against a
  signature reading `bool $caseSensitive = true`. The reverse of the behaviour,
  and the more dangerous way round to be wrong: a reader trusting it expects a
  case-insensitive search and writes a strict one, which on PostgreSQL silently
  returns fewer rows rather than failing.
- **`whereLike()` and its three siblings claimed to be aliases and are not** —
  each said "Alias for like()", which is true of the implementation and false of
  the behaviour: they default `caseSensitive` to `false` where the base family
  defaults it to `true`. The same call through each builds different SQL, and on
  PostgreSQL — where `LIKE` and `ILIKE` genuinely differ rather than deferring
  to the column collation — matches different rows. The docblocks now say so and
  point at each other, and the trait docblock records the split.
- `orNotLike()` had no caller anywhere in the package or its tests, and the
  PostgreSQL `ILIKE` branch was never exercised, which is how the above went
  unnoticed. Both are now covered, including against a live server.
- The four `whereLike()` wrappers moved from `ActiveQuery` to the `Likeable`
  trait, alongside the family they delegate to. No signature or behaviour
  changed — they were the only part of the LIKE family living apart from the
  rest of it, which is part of why the two defaults drifted without anyone
  noticing.

**Connection configuration**

- **One malformed entry in a config file silently discarded every connection
  after it** — `Connection::configure()` passed each entry straight to `add()`,
  which is typed `array`, and its `try` sat outside the loop. A scalar entry
  therefore raised a `TypeError` that broke out of the `foreach`, and the
  connections declared below it were never registered. The application then
  booted half-configured with no indication which half: the first `get()` on a
  missing connection threw "connection not found" for a name that was plainly
  there in the file. Malformed entries are now skipped individually, each
  reported by name and type, and the connections around them still load.
- **A config file that could not be read failed in total silence** — a missing
  path returned with no message at all, as did a file returning a non-array, so
  a typo in `configure('config/db.php')` registered nothing and said nothing.
  The mistake surfaced later as a "connection not found" thrown from somewhere
  with no bearing on it. Every failure now raises an `E_USER_WARNING` naming the
  file and what was wrong with it. `configure()` still does not throw, so
  bootstrap behaviour is unchanged for anyone already handling this correctly.
- **A directory passed as the config path produced a raw stream error** —
  the guard used `file_exists()`, which accepts directories, so the path reached
  `require` and reported "failed to open stream: Permission denied" instead of
  naming the actual problem. It now checks `is_file()`.
- **An integer-keyed entry was dropped rather than registered** — a config
  written as a list instead of a map handed `add()` an `int` where it declares
  `string`, which was the same escaping `TypeError` as above. Keys are now cast,
  so such an entry registers under its string form.
- **A config key present but set to `null` was reported as missing** — the
  driver's validation tested `isset()`, which cannot tell the two apart, so
  `'password' => null` produced "Missing required config keys: password" about a
  key sitting on the line in front of the reader. The usual next step — adding
  the key that is said to be absent — changes nothing, because it is already
  there. The two cases are now reported separately and both are named when both
  occur, so a config with one key absent and another nulled says so in one
  message rather than mentioning only the first problem found.

  The rejection itself is unchanged and deliberately so: a `null` host connects
  to localhost and a `null` database selects none, both without complaint, so a
  typo that nulls either reaches a server — just not the intended one. That was
  confirmed against MySQL and PostgreSQL with the check bypassed. Only the
  wording was wrong. One case is newly caught as a consequence: `SQLiteDriver`
  defaults `database` to `:memory:` before validation runs, so an explicit
  `['database' => null]` used to pass and now does not.

**Query result caching**

- **`CacheInterface` claimed to be "PSR-16 compatible"** — it is not, in either
  direction. It declares four of PSR-16's eight methods (no `clear()`,
  `getMultiple()`, `setMultiple()` or `deleteMultiple()`) and does not throw on
  invalid keys, so a class written against it is not a PSR-16 cache, and a
  PSR-16 cache does not satisfy it without an adapter. Anyone who took the
  docblock at its word and passed a PSR-16 implementation straight to
  `QueryCache::setDriver()` got a `TypeError`. The docblock now states what the
  interface actually is, and `docs/05` carries the adapter — including the `$ttl`
  translation, since this interface spells "no expiry" as `0` and PSR-16 spells
  it `null`.

**Error collection**

- **`addErrors()` overwrote messages instead of adding them** — it merged with
  the spread operator, which preserves string keys, so a batch keyed by field
  name (`['email' => 'is required']`, the shape a caller naturally reaches for)
  replaced any earlier message sharing that key. Two calls about one field left
  one message where there should have been two, and the loss was silent:
  `getErrors()` simply returned fewer messages than were handed to it. The
  errors array also ended up holding string keys that its own
  `array<int, string>` type forbids, which `getErrors()` then returned. Both it
  and `addValidationErrors()` now append, matching what `addError()` has always
  done one at a time. The bundled `simsoft/validator` was unaffected in
  practice — it appends within each field, so its inner arrays are
  integer-keyed and spreading them renumbered — but any other source keying
  messages by rule name lost every field after the first to share one.

**RETURNING**

- **`returning()` with no arguments emitted no clause at all** — on `Update` and
  `Delete` the requested columns were held in an array defaulting to `[]`, and
  `buildSQL()` decided whether to emit the clause with `empty()`. A bare
  `returning()` stores `[]` as well, so "asked for every column" and "never
  asked" were the same value and the request was dropped: the statement ran,
  reported nothing back, and `getReturningResult()` answered `null` — exactly
  what it answers for a statement that never asked — with no indication why. The
  docblock on both methods promised `Empty = RETURNING *`, and every grammar
  implements `returningColumnsSQL([])` as `RETURNING *` for precisely this case,
  so the one line written to serve it was unreachable from any caller. The
  columns are now `null` until asked for, which is the shape `Insert` already
  used, and `returning()` renders `RETURNING *` on PostgreSQL and SQLite 3.35+.
  `getReturningResult()` accordingly separates `[]` (asked, matched no row) from
  `null` (never asked). MySQL, which has no such clause, still omits it.

**Cursor and cache**

- **`cursor()` combined with `cache()` did different things on different
  drivers** — caching stores the whole result set, which is the one thing a
  cursor exists not to do, so the two cannot both be honoured and which one won
  came down to the driver underneath. On PDO the cache was never consulted and
  the rows streamed; on MySQLi, which falls back to `all()`, the set was
  materialised, written to the cache, and served from it on the next call. The
  same code therefore read the database on one driver and returned rows from
  before the last `UPDATE` on the other — with nothing said either way — and
  over 20k rows cost 17.9 MB against PDO's 0.5 MB, 4.4 MB of it pushed into the
  cache. Asking for both now raises a `QueryException` naming the conflict,
  identically on every driver, exactly as `cursor()` with `with()` already did.
  A TTL of zero or less is not a caching request and still streams.

**Statement building**

`getSQL()` memoises, and nothing ever invalidated what it cached. The
memoisation is load-bearing — `buildSQL()` appends a bind for every placeholder
it emits, so running it twice over the same binds produces two values for one
placeholder — but caching a statement that can still be mutated meant the first
read fixed it for good. Everything below follows from a value being computed at
one moment and used at another.

- **Reading a builder's SQL froze it** — every mutation made after the read was
  dropped in silence: `Select::distinct()`, `Insert::ignore()`,
  `Update::ignore()`, `Update::lowPriority()`, `Update::returning()`,
  `Delete::quick()`, `Delete::lowPriority()` and `condition()` on any of them.
  Reading early is not an unusual thing to do — `dump()`, `dd()`, `explain()`
  and string interpolation all read the SQL, and `DB::sqlOnly()` exists to hand
  back a builder for the express purpose of being inspected — so the documented
  inspect-then-run flow ran a statement that was not the one it had shown.
  `$b->getSQL(); $b->returning('id'); $b->execute();` executed without the
  `RETURNING` clause. Every mutator now discards the cache, and `getSQL()`
  clears the binds before rebuilding, which is what makes the rebuild safe.
- **`Update::setCounter()` bound its value ahead of the values it is emitted
  after** — the counter was rendered and bound the moment it was named, before
  the attribute assignments that precede it in the statement. `new Update('user',
  ['username' => 'ALPHA'], 'id = 1')` with `setCounter('status_code', 7)` sent
  `[7, 'ALPHA']` for placeholders running `username, status_code`, so each value
  arrived at the other's column and MySQL refused the statement outright:
  `Truncated incorrect INTEGER value: 'ALPHA'`. Nothing was written.
- **`Update::setCounter()` quoted the column for the wrong engine** — it quoted
  at call time, and `Model::updateCounter()` calls it before `withConnection()`.
  On PostgreSQL the statement was built with MySQL backticks
  (`UPDATE "user" SET \`status_code\` = ...`) and the server rejected it with
  `SQLSTATE[42601]: Syntax error`; `updateCounter()` did not work on PostgreSQL
  at all. Counters are now rendered during the build, in the same pass and the
  same order as everything else, so they are quoted for whichever connection the
  statement actually runs on. An invalid column name is still refused by the
  call that named it.
- **`Select::condition()` called twice kept the first condition's binds** — it
  absorbed the source's values as it rendered, and the second call replaced the
  SQL while leaving the first call's values in place. The statement then held
  one placeholder and two values and the driver refused it. The condition source
  is now held and rendered once, during the build.
- **`getBinds()` answered `null` before the statement had been built** on
  `Insert`, `Update`, `Delete` and `Upsert` — the values are produced by the same
  pass that emits the placeholders they fill, so a statement that plainly had
  values reported none until its SQL had been read. `Select` answered, because it
  bound in `condition()` instead, so the two disagreed. Both reads now agree with
  each other in either order, on every builder. (`Traits\Condition` imported
  `Traits\Binds`, which flattened the plain accessor into the using class where
  it took precedence over the builder's override; the import is gone.)
- **`withConnection()` did not requote an already-built statement** — the
  statement is quoted for a particular grammar, so it is no more valid across a
  connection change than the cached grammar that `withConnection()` has always
  discarded.

`Builder\Clauses` types are unaffected: their subclasses are constructed
complete and have no mutators, and `CaseExpression` still collects its binds as
it renders — so for those, binds are still read *after* the cast to string, as
`docs/02-QUERY-BUILDER.md` documents. `ActiveQuery` has its own `getSQL()` that
rebuilds on every call and was never affected.

**Query monitoring**

`QueryMonitor` exists to notice a lazy-loading loop and name the line that wrote
it. It was failing at both halves, and failing quietly — a monitor that groups
nothing reports nothing, which is indistinguishable from a codebase that has no
N+1 in it.

- **Every warning blamed the library instead of the caller** — `getOrigin()`
  walked the backtrace looking for the first frame outside the library, testing
  each against six hardcoded fragments (`'src/Drivers/'`, `'src/Model.php'` and
  so on) written with forward slashes. On Windows `__FILE__` uses backslashes,
  so no frame ever matched, no frame was ever skipped, and the very first one
  was returned — which is the `getOrigin()` call inside `recordQuery()` itself.
  Every `origin` in every warning read `.../src/QueryMonitor.php:94`, a file the
  caller cannot change, where the documentation promised
  `'app/Controller.php:42'`. The fragment list was also incomplete on any
  platform: it named six paths, so a query arriving through any other file in
  the package — `Collection`'s lazy pager among them, which is the path a
  relation load actually takes — would have been credited to the library even
  where the fragments did match. Origin is now determined by testing each frame
  against the package directory derived from `__DIR__`, which covers every file
  on either separator with nothing to keep in sync.
- **The trailing literal, which is the one that varies, was never normalised** —
  the numeric pattern required a delimiter after the digits and end-of-string is
  not one, so `WHERE id = 1` and `WHERE id = 2` normalised to themselves and
  stayed distinct. That is exactly where an N+1 puts its varying value, so the
  loop the class is built to catch counted as one occurrence per id, never
  reached the threshold, and produced no warning. Six queries differing only in
  the id yielded six patterns and zero detections. The same omission left
  `LIMIT 10` unnormalised while `LIMIT 5 OFFSET 10` normalised only the first of
  its two numbers.
- **An escaped quote split one string literal into two** — `'[^']*'` stops at
  the first quote of an escaped pair, so `'o''brien'` normalised to `??` while
  `'smith'` normalised to `?`, and the same query grouped differently depending
  on whether the value contained an apostrophe. The literal pattern now
  understands the doubled-quote escape, written so it cannot backtrack.
- **The backtrace depth was too shallow for the case that matters** — a lazy
  relation load reaches `recordQuery()` eight frames down, through `Execute`,
  `Fetchable` and `Collection`'s pager, leaving almost nothing of the ten-frame
  budget for the caller. Raised to a documented constant with room to clear the
  library.

**Documentation**

- The N+1 example in `docs/01-GETTING-STARTED.md` did not run: `echo
  $user->posts;` raises `Object of class Simsoft\DB\Collection could not be
  converted to string`, and never materialised the relation it was demonstrating.
- The query-logging examples in `docs/01-GETTING-STARTED.md` and
  `docs/05-ADVANCED-FEATURES.md` called `get()` and then read the log, showing a
  count of 2 and a list of suggested indexes. `get()` returns a lazy
  `Collection`, so at that point the log was empty and the advisor had nothing
  to suggest. Both now consume the results first, and the advisor's documented
  output no longer names a table its own example does not produce.
- `docs/05-ADVANCED-FEATURES.md` did not say what `cache()` covers. It now names
  the reads that are cached, records that aggregates (`count()`, `sum()`,
  `avg()`, `min()`, `max()` and their `*Distinct()` variants) build a separate
  query carrying no TTL and so run every time, and documents that `cursor()` is
  refused rather than silently ignoring the TTL.
- The SQL-only section of `docs/01-GETTING-STARTED.md` showed a builder being
  handed back and inspected without saying what may be done with it afterwards.
  It now states that reading the SQL does not finalise the builder, that
  `getSQL()` and `getBinds()` agree in either order, and that
  `Builder\Clauses` types are not builders and still require reading their binds
  after the cast.
- `docs/08-CHEATSHEET.md` omitted `clearErrors()` from the Error trait table and
  said nothing about how errors combine. It now lists the method and records
  that errors are appended, never displaced, and that keys on the array passed
  in are discarded.
- `docs/10-POSTGRESQL.md` and `docs/08-CHEATSHEET.md` showed `returning()` only
  with explicit column names. Both now document the no-argument form as
  `RETURNING *`, and docs/10 records that `getReturningResult()` answers `[]`
  when the clause matched no row and `null` when none was asked for.

**Drivers**

The four drivers agreed on the happy path and had drifted apart on every failure
path. Each of these turned a plain fact — the server is unreachable, nothing was
inserted, that statement has no rows — into an exception of the wrong type
thrown from somewhere other than where it happened.

- **A failed MySQLi connection reported itself as connected** — `connect()`
  assigned `new mysqli()` to the handle before `real_connect()` ran, so when the
  connect failed the handle stayed behind and satisfied `isConnected()`. Every
  later call then raised a PHP `Error` rather than an exception: `mysqli object
  is not fully initialized` from a query, a statement or a transaction, and
  `Property access is not allowed yet` from `lastInsertId()`. An `Error` is not
  an `Exception`, so a caller catching `Exception` around its query did not
  catch it at all. The handle is now built locally and published only once
  `real_connect()` has returned, matching what the PDO drivers already did.
- **A failed reconnect returned normally** — `connect()` records its failure as
  an error string rather than throwing, because the constructor path collects it
  (`Connection::get()` reads `hasError()` and raises `ConnectionException`
  itself). Nothing read it on the reconnect path, so `forceReconnect()` returned
  having established nothing and the caller carried on with a driver holding no
  connection. The failure surfaced two frames later from whatever statement came
  next, as `RuntimeException('Database connection failed')` on PDO and a PHP
  `Error` on MySQLi — neither naming the server as the cause. All four drivers
  now raise the `ConnectionException` the documentation already promised, with
  the underlying reason in the message.
- **A driver that recovered still reported the failure it recovered from** —
  errors only ever accumulated and nothing could retract one, so a connection
  that dropped and came back answered `hasError()` true forever after, naming a
  failure that no longer applied. This is not cosmetic:
  `Connection::createDriver()` reads exactly that to decide whether a connection
  is usable. Every `connect()` now clears the state a new connection does not
  inherit — the recorded errors, the transaction depth and the last activity.
- **`MySQLiDriver::lastInsertId()` threw where the other three answered
  `false`** — with no connection it raised `RuntimeException`, and on a handle
  whose connect had failed a PHP `Error`, while the three PDO-backed drivers all
  returned `false` through `normalizeInsertId()`. `Execute::getLastInsertId()`
  is typed `?string`, maps only `false` to `null` and does not catch, so "nothing
  was inserted" escaped as an exception from a method that had a value for it.
  MySQLi now shares the same normaliser.
- **`MySQLiDriver::query()` treated a statement with no result set as a
  failure** — `get_result()` answers `false` both for a statement that failed
  and for one that ran fine and has no rows to give, so `query('SET @x = 1')`
  threw `Failed to get result: ` with an empty reason, because there was no
  error to name. The other three drivers return `[]` for the same statement.
  Column count now distinguishes the two, and a genuine failure is still
  reported — with the statement's own error rather than the connection's, which
  is empty in exactly that case.
- **`PostgresDriver::query()` returned one empty row per affected row for a
  write** — PDO's pgsql driver reports an `UPDATE` touching one row as a result
  set of one row with no columns, so `query()` answered `[[]]`, which every
  caller reads as "a row was found". A column-less result is now `[]`, matching
  the other three drivers. `RETURNING` is unaffected: those rows have columns.
- **A driver advertised reconnect-on-deserialize and could not deliver it** —
  `__wakeup()` called `connect()`, but PDO refuses to be serialized, so three
  drivers in four died with `Serialization of 'PDO' is not allowed`: an
  exception naming a class the caller never mentioned, thrown from a driver
  advertising the opposite. The one that did round trip, MySQLi, wrote its
  config out with it — host, username and password in plaintext, into whatever
  the payload was stored in. A driver is a live connection plus the credentials
  that opened it, and neither survives that boundary usefully. All four now
  refuse with a `LogicException` that says so and points at `Connection::add()`.
- **`options` could disarm the PDO drivers** — the key is documented as
  overriding the defaults, and `PDO::ATTR_ERRMODE` was among them. The drivers
  are written for the exception form throughout, so setting `PDO::ERRMODE_SILENT`
  did not make the library quieter: `prepare()` began returning `false` and the
  caller got `TypeError: prepareStatement(): Return value must be of type
  PDOStatement, false returned` in place of the database error. The error mode is
  now pinned across all three PDO drivers; every other option applies as written.

**Model generation**

As with the observer generator, these defects land in the generated file rather
than in this library. A table name comes from a database rather than from a
developer typing it, so the generator meets names it did not choose.

- **On PostgreSQL, a column in more than one constraint was introspected more
  than once** — the column query joined `key_column_usage` and filtered for the
  primary key in the `SELECT` list rather than in the join, so it returned one
  row per constraint the column belonged to. A column that was both `UNIQUE`
  and a foreign key came back three times, and each copy became another
  `@property` line and another relation method: `Cannot redeclare
  Model::user()`, a fatal error in a file the reader had not written. The
  primary key is now looked up as a pre-filtered subquery, so the column list
  is one row per column whatever constraints it carries. The fixture's
  `user_profile.user_id` is exactly this shape and could not be generated
  before. MySQL and SQLite were unaffected.
- **A relation was generated for a column whose method name PHP would not
  accept** — the `*_id` rule fired on the column name alone. Three cases each
  produced a file that failed at load rather than at generation: `save_id` gave
  `save(): Relation`, which cannot override `Model::save(bool): bool`;
  `class_id` gave `Class::class`, a reserved word used as a class reference, so
  a parse error; and two columns resolving to one name gave `Cannot redeclare`.
  Each of the three is now skipped — the rest of the model is generated
  normally, and the one relation can be written by hand under a name of the
  reader's choosing.
- **A table PHP has no legal class name for was generated anyway** — MySQL
  accepts a table named `2fa_token`, PHP does not accept a class named
  `2faToken`. The file was written without complaint and was a parse error on
  its class line; the same applied to a table named after a reserved word
  (`class`, `list`, `match`). Both are now refused up front, naming the table
  and pointing at `className()`, which is the documented way through and is
  verified to produce a loadable file.
- **The table name reached SQL uninspected** — `SHOW COLUMNS FROM \`$table\``
  and `PRAGMA table_info('$table')` name the table inline, because an
  identifier cannot be parameter-bound. The name is now checked against a plain
  identifier grammar before either query is built.

**Observer generation**

Every defect below lands in the generated file rather than in this library, so
none of them could fail a test here — they fail in the reader's project, at the
moment they load the file the tool told them to write.

- **A generated "before" method could not do what its own docblock told it to**
  — `creating`, `updating`, `saving` and `deleting` were emitted as `: void`
  while the docblock printed directly above them said "Return false to cancel
  the operation". Following that instruction is `Fatal error: A void method
  must not return a value`, so the one documented way to use the stub broke the
  file. The four now emit `?bool`, and the stub body carries an explicit
  `return null;` — a `?bool` method that falls off its end raises a `TypeError`,
  so the return has to be there for the untouched stub to work at all. The
  "after" events keep `void`, since they genuinely cannot cancel. The same
  correction was applied to the observer examples in the Active Record and
  Observer Generator guides, which printed the broken shape too.
- **`--append` re-added a custom event on every run** — `detectExistingEvents()`
  scanned only the eight built-in event names, so a method it had itself
  written for any other name was invisible to it. A second append wrote a second
  copy, and the file became `Fatal error: Cannot redeclare`. It now also looks
  for the events actually being requested.
- **An event name that is not a legal method name was written out anyway** —
  `--events=save-point` produced `public function save-point(...)`, a parse
  error the reader met when they loaded the file rather than when they ran the
  command. Names are now validated against PHP's method-name grammar up front,
  in both `generate()` and `append()`.
- **`--events=creating, created` silently produced a broken method** — the CLI
  splits on the comma, so a space after it became part of the name. Names are
  now trimmed in `events()`, which fixes all three `bin/fliq` call sites at
  once.
- **An appended method's type hint pointed at a class that does not exist** —
  `append()` added `public function deleting(User $user)` without ensuring the
  file imports `User`, so the hint resolved against the observer's own
  namespace. The file still parsed; it failed later, at call time, with a
  `TypeError` naming `App\Observers\User`. The model import is now added when
  it is missing, placed below the last existing `use` (or the namespace, or the
  opening tag). A file that already binds that short name — importing the model
  from elsewhere, or aliasing something else to it — is left alone, since a
  second import of the same name is itself a fatal error.
- **Appending to a file with no closing brace wrote a parse error over it** —
  the fallback pasted the methods onto the end and added a brace of its own,
  leaving them outside any class. Damaging the reader's file is worse than
  refusing, so it now throws and leaves the file untouched.

Internally, the class-generating and append-generating paths had two copies of
the method-emitting code and were free to drift. Both now build methods through
one routine, so a fix cannot be applied to one path and forgotten on the other.

**Set membership (IN / NOT IN)**

- **An empty list matched every row instead of none** — `in()` returned early
  when given `[]`, dropping the condition entirely, so
  `->in('id', $allowedIds)` with an allow-list that filtered down to nothing
  handed back the whole table rather than nothing. No error was raised: the
  query simply answered a different question. Membership of a set with nothing
  in it is false for every row and non-membership is true for every row —
  confirmed against MySQL, PostgreSQL and SQLite, including for a NULL column —
  so an empty list now builds `1 = 0` for `in()` and `1 = 1` for `notIn()`.
  Skipping was also wrong in the other direction beside an `OR`, where
  `orNotIn('col', [])` is constant-true and must widen the clause to every row;
  dropped, it left the preceding condition to answer alone.
  **Breaking:** code relying on an empty list meaning "no filter" must now
  guard the call itself. Note `like()` keeps skipping an empty pattern array —
  a search with no terms is an absent filter, whereas an empty set has a
  defined answer.
- **`IN` with a subquery or `Raw` expression that had nothing to bind could not
  execute** — `getBinds()` reports "no binds" as `null`, and `appendBinds()`
  reads a `null` as a value and bound one SQL NULL for it. The statement then
  carried one bind with no placeholder to put it in, and the driver refused it
  with `SQLSTATE[HY093]: Invalid parameter number` before reaching the server.
  `WHERE id IN (SELECT user_id FROM post)` — a subquery with no `WHERE` of its
  own — therefore never ran. The two meanings now have separate names:
  `appendBinds()` still binds a value (including a deliberate NULL, as
  `update(['token' => null])` needs), and a new `absorbBinds()` takes a nested
  expression's binds and treats `null` as none. `Select` had the same defect
  from the same cause and is fixed with it. `ExistsCondition`, `Aggregate`,
  `Condition` and `SectionBinds` had each guarded by hand, three of them
  spelling the guard differently; all now call `absorbBinds()`, so the one
  place that can get this wrong is the one place it is written.
- **A value that could not form a set built `IN ()`** — `InCondition`'s three
  branches were consecutive `if`s with no `else` and no final `throw`, so a
  scalar, a string or a `null` fell through all three and left an empty
  parenthesis, which every engine rejects as a syntax error reported from the
  server with nothing pointing back at the call that built it. It is now
  refused when the condition is built, with the same message the `where()`
  route already gave: `IN on "id" needs an array, subquery or Raw expression;
  got int.`
- **`in()` and `notIn()` built the list form twice** — `ActiveQuery` inlined a
  "fast path" that assembled the placeholders itself and only delegated to
  `InCondition` for subqueries and `Raw`, so one statement had two
  implementations free to drift and `InCondition`'s list branch was unreachable
  from the public API. Both now go through `InCondition`, which is where the
  empty-list and bind defects above could be fixed once rather than in each
  copy. Generated SQL for a non-empty list is unchanged.

**Ordering**

- **A list of column names was sorted by position, not by name** —
  `orderBy()` reads an array as `column => direction`, but a plain list arrives
  as `position => column`. The positions were taken as the column names and the
  names as the directions, so `orderBy(['score', 'id'])` emitted
  ``ORDER BY `user`.`0` ASC, `user`.`1` ASC`` and the server rejected it with
  "Unknown column 'user.0' in 'order clause'". A list entry now takes its name
  from the value and its direction from the `$direction` argument, and the two
  shapes may be mixed in one array (`orderBy(['role', 'score' => 'DESC'])`).
- **`orderByDesc()` sorted ascending whenever given an array** — it forwards to
  `orderBy($attribute, 'DESC')`, but the array branch never consulted
  `$direction`, so the `DESC` was dropped and the rows came back in exactly the
  reverse of the requested order, without an error. `orderByDesc(['score'])`
  now sorts descending, and the direction whitelist and the `RAND()` special
  case apply to every shape rather than only to the single-column one.
- **An empty attribute name raised a PHP warning and produced invalid SQL** —
  `queryAttribute()` indexes the first character of the name in every branch, so
  `''` emitted "Uninitialized string offset 0" and then a bare `{}` placeholder
  that failed at the server as a syntax error. It is now rejected with an
  `InvalidArgumentException` naming the problem, at the call that made it, for
  every attribute entry point (`select`, `where`, `groupBy`, `orderBy`, `in`,
  `isNull`, `between`, and the rest).

**Expressions carrying bind values**

- **A `Raw` expression outside `WHERE` lost its values and the statement would
  not run** — placeholders are positional, and every value has to reach the
  driver in the order its placeholder is emitted. `SectionBinds` kept a list per
  section for that, but had none for `ORDER BY`, so an order expression had
  nowhere to put its values at all. That single gap is why `orderByRaw()` took
  no values to begin with, why `orderBy(new Raw(...))` dropped them, and why
  anything re-emitting a borrowed `ORDER BY` came up short. There is now an
  `orderBinds` list, merged between `HAVING` and `UNION` to match emission
  order, and it propagates through `merge()`, `union()` and `clearBinds()` with
  the rest.
- **`selectRaw()` and `orderByRaw()` could not take values** — neither had a
  `$binds` parameter, so `selectRaw('IF(score > ?, 1, 0) AS passed')` emitted a
  placeholder with nothing to fill it and the driver refused the statement
  before it reached the server. Writing the value into the string instead was
  the only way through, which is exactly the injection route the builder exists
  to close. Both now take `?array $binds = null` as a second argument, matching
  `whereRaw()`, `groupByRaw()` and `havingRaw()`.
- **`orderBy()` quoted an expression as though it were a column name** —
  `orderBy(new Raw('FIELD(status, ?)'))` asked the server for a column literally
  called `FIELD(status, ?)`, and dropped the bind besides. `orderBy()` and
  `addOrderBy()` now accept a `Raw` or a `Clause` and emit it as written,
  collecting its values. A direction is not appended: an expression that wants
  one says so itself, and one built around `CASE` or `FIELD` usually does not.
  A `Clause` is given the query's alias and placeholder first, and its SQL is
  read before its binds, since a clause collects them while it builds.
- **Sorting by a `CaseExpression` produced structurally invalid SQL** — the
  documented way to sort by one was to cast it to a string, which left its WHEN
  and THEN values behind; passing the expression itself instead had it
  stringified and then quoted, emitting
  ``ORDER BY `p`.`CASE WHEN {status_code` = ? THEN ? ... END}` `` with the
  deferred-quoting markers leaking into the statement. Neither form could
  execute. `->orderBy(CaseExpression::when(...)->then(1)->else(2))` now works
  directly; the cast form still works if you hand over `getBinds()` yourself,
  read after the cast.
- **`SelectClause` and `HavingClause` dropped a `Raw` entry's values** — a
  clause wrapping `IF(score > ?, 1, 0) AS grade`, or a `HAVING` as ordinary as
  `COUNT(*) > ?`, emitted the placeholder and discarded what filled it. Both now
  absorb the expression's binds, as `select()` already did for a bare `Raw`.
- **`Select` over-supplied the driver when borrowing a query's conditions** — it
  re-emits the source query's JOIN, WHERE, GROUP BY, HAVING, ORDER BY and LIMIT,
  but took `getBinds()`, which also hands over the source's SELECT, FROM and
  UNION values whose placeholders are not in the new statement. Borrowing the
  conditions of a query that selected a `Raw` column left the driver holding
  more values than it had positions for and it refused to run the statement. It
  now takes `getConditionBinds()` followed by the new `getOrderBinds()`, in the
  order those sections are emitted.

**Collections and query reuse**

- **The documented row-existence check always threw** — `ActiveQuery` declares
  its own `exists(ActiveQuery|Raw $query)` for the SQL `EXISTS` sub-query
  condition, and PHP resolves a class/trait method-name collision silently in
  the class's favour. `Fetchable::exists(): bool` was therefore unreachable, and
  the example printed in the cheatsheet —
  `User::find()->where('email', $e)->exists()` — raised `ArgumentCountError`
  rather than answering. The trait method is now `hasRecords()`, so both the
  row check and the sub-query condition are callable. **Breaking:** callers of
  the (never working) no-argument `exists()` must use `hasRecords()`; the
  sub-query form is unchanged.
- **`first()` narrowed the query it was called on, permanently** — the `LIMIT 1`
  was written onto the receiver rather than a copy, so it outlived the call.
  After `$query->first()`, that same `$query` answered every later `all()`,
  `getArray()`, `get()`, `each()` and `cursor()` with a single row and reported
  no error while doing it. It now limits a clone, as `paginate()` and
  `chunkById()` already did. `hasRecords()` does the same.
- **`Collection::all()` silently returned one page of records** — `lazy()`
  fetches each page as its own query, so without `indexBy()` every page was
  keyed from zero again and the keys repeated across pages. Iterating with
  `foreach` hid this, but anything that materialised the generator —
  `iterator_to_array()`, and so `all()` — let each page overwrite the one before
  it. A ten-row table read in pages of four returned four records. Keys are now
  numbered continuously across the whole iteration; keys chosen by `indexBy()`
  are still preserved.
- **Chaining `filter()` or `map()` discarded all but the last callback** — each
  had a single storage slot, so `->filter($a)->filter($b)` applied only `$b`,
  and `->map($a)->map($b)` handed `$b` the unmapped record. Filters also always
  ran on the raw record whatever the call order, so a `filter()` after a `map()`
  never saw the mapped value. Both now append to one ordered pipeline and run in
  the order they were added, each stage seeing what the stage before it
  produced. **Breaking for subclasses:** the protected `Collection::$filterCallback`
  and `$mapCallback` properties are replaced by a single ordered `$stages` list.
- **`batch()` ignored `filter()` and `map()`** — it resolved raw records itself
  instead of reading through the shared pipeline, so batching a filtered
  collection yielded every row while iterating the very same collection yielded
  only the matches. It now applies both. A batch can be shorter than the
  requested size, which is how many rows are fetched per query rather than how
  many survive, and a page where nothing survives is skipped rather than yielded
  as an empty array.
- **The four drivers disagreed on "no insert id", three different ways** — for
  the same fact, MySQLi answered `false`, the PDO and SQLite drivers answered
  the string `'0'`, and PostgreSQL threw an uncaught `PDOException` (`lastval is
  not yet defined in this session`) out of `getLastInsertId()`, a method typed
  `?string`. Since `Execute::getLastInsertId()` maps only `false` to `null`,
  `'0'` reached callers as the string id `"0"` — an id no auto-increment column
  or sequence ever produces. A shared `Driver::normalizeInsertId()` now maps the
  throw and both empty forms to `false`, so all four agree and
  `getLastInsertId()` answers `null`.

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
- **Logical operator injection** — the operator joining a condition to the one
  before it is interpolated rather than bound, and was accepted unvalidated as
  the trailing argument of some twenty condition methods. It reached the WHERE
  clause as a bare token, so
  `where('id', '=', 999)->isNull('deleted_at', 'OR 1=1 -- ')` produced
  ``WHERE `id` = ? OR 1=1 --  `deleted_at` IS NULL``: the `OR` made the
  restriction irrelevant and the comment marker removed what followed, returning
  every row of a table a caller had narrowed to one. Nine methods each inlined
  their own copy of the join, so they now share one `pushCondition()` helper
  and one validation point; `AND` and `OR` are accepted in any case and anything
  else is rejected with `InvalidArgumentException`. Affects `where`, `not`,
  `isNull`, `notNull`, `in`, `notIn`, `regex`, `notRegex`, `containsWords`,
  `whereAny`, `whereAll`, `whereNone`, `whereColumn`, `whereDate`,
  `whereFulltext`, `whereHas`, `whereDoesntHave`, `jsonContains`,
  `jsonNotContains`, `jsonHas`, `jsonMissing`, `jsonLength` and
  `whereJsonValue`, and every `or`-prefixed variant of them.

**Query Builder — JSON**

- **A JSON column without a `->key` built invalid or silently wrong SQL** — the
  JSON methods take the path inside the column string, so `jsonHas('meta')`
  leaves the path empty. That empty path means the document root, and the three
  grammars disagreed about it. `jsonHas('metadata')` built
  `JSON_CONTAINS_PATH(metadata, 'one', '$.')` on MySQL, which the server rejects
  outright, but on PostgreSQL it built `jsonb_exists(metadata, '')` and ran:
  the query asked whether a key named `''` existed, which is never true, so
  `jsonHas('metadata')` reported that none of the 10 fixture rows had a metadata
  document and `jsonMissing('metadata')` reported that all 10 lacked one — both
  exactly backwards, with no error. The document root is not a key, so both
  methods now reject an empty path on all three grammars with a message naming
  the fix (`'column->key'`, or `notNull('column')` to test that the document is
  present).
- **PostgreSQL answered root-path containment, extraction and length wrongly** —
  where MySQL and SQLite already read an empty path as the document root
  (`'$'`), `PostgresGrammar` ran `explode('.', '')`, which yields `['']`, and
  navigated to a key named `''` instead. `jsonContains('metadata', ...)`
  returned 0 rows against a fixture where MySQL returned 3. The root now emits
  `column @> ?::jsonb`, `column` / `column #>> '{}'` and
  `jsonb_array_length(column)`, each verified against the MySQL answer for the
  same question. Note that `jsonb_array_length` requires an array at the path,
  at the root as anywhere else, so a length query against a JSON *object*
  answers on MySQL and errors on PostgreSQL — that divergence is not specific to
  the root and is now documented.

**Destructive operations and nested saves**

- **`deleteAll()` emptied the table when its condition came back empty** — the
  method's own docblock says it "requires an explicit condition to prevent
  accidental full-table deletes", but the only thing enforcing that was the
  parameter type, which rules out `null` and nothing else. `deleteAll('')`,
  `deleteAll(new Raw(''))` and `deleteAll($queryWithNoWhere)` each built a bare
  `DELETE FROM table`, removed every row, and returned `true`. This arrives
  through the most ordinary route there is — a filter that assembled to nothing
  — and cascading foreign keys carry it into the child tables. Every shape that
  contributes no `WHERE` is now refused with a `QueryException` naming
  `deleteAllUnchecked()` as the deliberate alternative; a condition that
  narrows, including a purposeful `1 = 1`, is unaffected.
- **A key in a `saveTogether()` payload could invoke any zero-argument method**
  — deciding whether an array-valued key named a relation was done by *calling*
  the method, guarded only by `method_exists()`. So
  `saveTogether(['delete' => [...]])` ran `delete()`, removed the row, then
  carried on saving it, because the call returned a `bool` and the key was
  reclassified as a column. Payload keys typically come from a request body, so
  the caller does not choose these names: 33 methods were reachable this way on
  a plain model. This is the same hole `ResolvesRelations` closed for
  `$model->foo` reads, and it is now closed the same way — only methods
  declaring a `Relation` return type and no required arguments are eligible.
- **A related model that refused to save reported success** —
  `Relation::save()` discarded the result of `$model->save()` and returned the
  model regardless, so a refusal from `validate()` or a `beforeSave()` hook was
  indistinguishable from a write: same return type, no exception, and for an
  update `exists()` was still true. It now raises a `QueryException` carrying
  the model's own error messages.
- **`saveTogether()` committed the parent and none of its children, and
  returned `true`** — it sits directly on top of the above, and its own
  documentation promises that if any part fails the whole thing rolls back. The
  transaction machinery was correct; it was simply never told anything had gone
  wrong. Worse at depth: the parent record silently failed, so its child was
  written with a foreign key of `NULL`, which surfaced only because that column
  happened to be `NOT NULL`. A nullable one would have stored the orphan
  silently. Failures now propagate and the transaction rolls back, for hasOne
  and hasMany alike — the two near-identical private methods that handled them
  are also now one, so a fix to either can no longer miss the other.

**Query Builder — conditions**

- **A `Raw` condition on `DELETE`, `UPDATE` or an aggregate was emitted without
  `WHERE`** — the keyword was prefixed for string conditions only. An
  `ActiveQuery` is right to be emitted verbatim, since its sections carry their
  own keywords, but a `Raw` is written the way every documented `Raw` fragment
  is written, and `deleteAll(new Raw('`n` > ?', [2]))` produced
  ``DELETE FROM `t` `n` > ?``. The mirror case — a string that did include the
  keyword — came out as `WHERE WHERE ...`, and a whitespace-only condition left
  a dangling `WHERE`. All three were syntax errors rather than silent damage, so
  the server rejected them, but the condition itself was well-formed and nothing
  told the caller which of the three shapes wanted the keyword. Every shape now
  produces the same clause. A fragment that legitimately opens with `WHERE`,
  `ORDER BY`, `GROUP BY`, `HAVING`, `LIMIT`, `OFFSET` or a `JOIN` is left as
  written, while `LEFT(...)` and `RIGHT(...)` are read as the string functions
  they are rather than as joins.

**Data Integrity**

- **A cast attribute could not hold or be cleared to NULL** — casts were applied
  to `null`, so `$model->score = null` stored `0`, `->name = null` stored `''`
  and a nullable JSON column stored the string `"null"`. A nullable column could
  never be cleared through a cast attribute, and reading one back reported `0`
  or `false` where the row held `NULL`, leaving no way to tell a real zero from
  a missing value. A cast now describes the column's type, not whether it has a
  value: `NULL` passes through untouched in both directions.
- **Assigning `null` on a new record was dropped from the INSERT** — a `null`
  was not recorded as dirty, so the column was omitted from the statement and
  the table `DEFAULT` was applied instead. A row written with an explicit
  `null` came back holding the default, with nothing reporting the
  substitution.
- **Writes between loosely equal values never reached the database** — dirty
  tracking compared with `==`, which treats `null`, `0`, `false` and `''` as the
  same value. Clearing a populated column to `null`, setting a `NULL` column to
  `0`, or emptying a string was recorded as "no change" and silently dropped
  from the `UPDATE`. The comparison now distinguishes `null` from every other
  value while still tolerating the type differences drivers introduce, so
  re-saving an untouched row remains a no-op.
- **Reading an attribute rewrote the model** — the cast result was assigned back
  into the attributes, so merely looking at a property changed what
  `toArray()`, `toJson()` and `getAttributes()` subsequently reported, and
  created attributes for columns that had never been set. Reading is now a read;
  `toArray()` presents cast values deliberately, and `getAttributes()` returns
  the raw stored values.
- **An `array` cast never round-tripped** — it kept a live PHP array in the
  attributes, which the mysqli driver bound as the literal string `Array`, and
  decoded a stored JSON list into a one-element array holding the JSON text.
  `array` and `json` now both store encoded and decode on read; `array` still
  always answers with an array.
- **An unencodable value was written as an empty string** — `json_encode()`
  returns `false` for a resource, `NAN` or invalid UTF-8, and that `false` was
  stored as-is and reached the column as `''`, destroying its contents without
  a word. It now throws `InvalidArgumentException`. Malformed JSON already in a
  column is handed back as the raw string rather than as an empty array, so the
  corruption stays visible.
- **An unrecognised cast type was ignored** — a typo such as `'interger'` fell
  through to a `default` arm that stored the value untouched, so the cast the
  model declared was never applied and nothing said so. Unknown names now throw
  `InvalidArgumentException` listing the supported types.
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

- **An unrecognised full-text search mode was answered in plain mode** — only
  `plain`, `phrase` and `websearch` were recognised and everything else fell
  through to the plain branch, which is not a wording difference. On MySQL a
  caller asking for `'boolean'` — MySQL's own name for what this library calls
  `websearch` — was answered `IN NATURAL LANGUAGE MODE`, where `+` and `-` are
  stripped as punctuation: searching `'+PHP -Docker'` over the `post` fixture
  returned five rows where `websearch` returns four, including the very row the
  `-` was written to exclude. The required term was not required and the excluded
  term was not excluded, and nothing in the result said the mode had been
  ignored. PostgreSQL had the same fallthrough (`plainto_tsquery` in place of
  `websearch_to_tsquery`), as did SQLite. All three grammars now raise
  `InvalidArgumentException` naming the supported modes and pointing at
  `websearch` for boolean operators, via the shared `Grammar\FulltextMode` trait.
  Case and surrounding space are normalised rather than refused, as they already
  were for `EXPLAIN` formats.
- **`orWhere()` refused array forms `where()` accepts, and mis-built a `Clause`**
  — its signature omitted `array` and `Clause` from the attribute and narrowed
  the operator from `mixed` to `?string`, though it does nothing but call
  `where()` with `'OR'`. `orWhere([['id', '>=', 1], ['id', '<=', 3]])` and
  `orWhere(['id' => 1])` raised a `TypeError` for shapes documented on `where()`,
  and `orWhere('score', 0)` failed on the non-string shorthand value. Worse,
  `orWhere($clause)` was *accepted* — `Clause` is stringable, so it slipped
  through the `callable|Raw` union — and the clause was flattened into the
  attribute slot and then read as a null comparison, producing
  ``... OR `user`.`created` >= ? AND `user`.`created` <= ? IS NULL`` with one
  bind for three placeholders. That failed at execute with mysqli complaining
  about argument counts rather than at the call that was wrong. The signature now
  matches `where()` exactly. A sweep of all 32 base/or-variant pairs found this
  to be the only mismatched one; a test now holds that line for the rest.
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
- **`transaction()` could not recover a dropped connection, while `execute()`
  and `query()` both could** — a statement got two recovery routes, the idle
  ping and the statement-level retry, and opening a transaction got neither. A
  worker whose connection had been closed by `wait_timeout` therefore recovered
  when its next statement happened to be a query and failed when it happened to
  be a transaction, which is the case where losing the write matters most, and
  it failed with the `ConnectionException` documented as meaning "nothing was
  written" — true, but the block had never started. Neither existing route
  could have covered it: `mysqli::begin_transaction()` does not round trip, so
  there is no failure for a retry to catch and the drop surfaces on the first
  statement *inside* the block, by which point reconnecting is correctly
  refused. `transaction()` now checks liveness before opening the outermost
  block, regardless of `ping_idle_seconds`. Nested calls, which become
  savepoints, do not re-check. The mid-transaction guard is unchanged: a
  connection lost after the block opens still throws and still writes nothing.
- **`reconnectIfNeeded()` did not exist on `SQLiteDriver`** — three drivers
  carried a byte-identical copy and the fourth had none, so calling the
  documented name on a SQLite connection was a fatal error. It is also *why*
  the defect above existed: a method defined only on subclasses cannot be
  called from the base class that needs it. Both it and `ping()` now live on
  `Driver`, with the part that genuinely differs per driver — the liveness
  probe, and whether a handle is held — named as `probeLiveness()` and
  `isConnected()`.
- **`PDODriver::ping()` broke the connection it had just vouched for** — the
  probe ran `SELECT 1` and never read the result. MySQL does not buffer by
  default, so the unread rows kept the connection busy and the next prepared
  statement failed with "2014 Cannot execute queries while other unbuffered
  queries are active" — the liveness check being the thing that killed the
  connection. The probe now reads its result set to completion, and that
  requirement is written into the abstract method rather than left to each
  driver to remember.
- **`PDODriver::execute()` left an open cursor on any row-returning
  statement** — the unbound branch used `PDO::exec()`, which hands back no
  statement to close, so `execute(new Raw('SELECT 1'))` left rows unread and
  the failure surfaced on whichever innocent statement came next rather than on
  the one that caused it. It now prepares in both branches and closes the
  cursor, as the three other drivers already did.
- **`SQLiteDriver::ping()` never recorded the activity it had just proved** —
  every other driver calls `markActivity()` on a successful ping. Without it
  the connection looked permanently idle, so the idle window never applied and
  a full round trip was paid before every statement no matter how
  `ping_idle_seconds` was set. Fixed by hoisting; `connect()` also clears the
  timestamp now, so a fresh connection is not vouched for by its predecessor's.

**Statement cache**

- **`SQLiteDriver` accepted `statement_cache` and `statement_cache_size` and
  obeyed neither** — it declared neither key and read neither, while keeping
  exactly the same cache and the same eviction as the other two PDO drivers. A
  config disabling the cache for a bulk import was accepted in full and applied
  in none of it: it cached three statements while being told to cache none, and
  held eight against a ceiling of three. Both keys are now read at connect.
- **Four of the five documented cache controls did not exist on
  `SQLiteDriver`** — it offered `clearStatementCache()` and none of the others,
  so the runtime control block in the getting-started guide was a fatal error
  on a SQLite connection. All five are now declared by the new
  `Simsoft\DB\Interfaces\CachesStatements`, which `PDODriver`,
  `PostgresDriver` and `SQLiteDriver` implement. `Connection::get()` returns a
  `Driver`, on which the methods were previously named by nothing, so calling
  one was checked by nothing until it ran; `instanceof CachesStatements` now
  answers the question statically, and `MySQLiDriver` — which prepares nothing
  — correctly does not implement it.
- **A reconnecting SQLite driver kept statements prepared on the old
  connection** — `forceReconnect()` replaced the handle and left the cache
  intact. It now clears it, as the other drivers do.

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

**Model — property access**

- **Reading any method name as a property invoked the method** — lazy loading a
  relation means `__get()` calls a method, and the only guard was
  `method_exists()`. So `$user->delete` — a typo for `$user->delete()`, or a
  template printing a key that turned out not to be a column — ran the delete,
  removed the row, and then failed with `Call to a member function fetch() on
  true`; the row was already gone by the time the error surfaced. The same held
  for `save`, `insert`, `refresh` and `validate`. Eligibility is now limited to
  methods that declare `Relation` as their return type and take no required
  arguments, via the new `Traits\ResolvesRelations`. Reading a property no
  longer writes to the database.
- **`unset()` left the pending change behind** — `__unset()` cleared
  `$attributes` but not `$dirtyAttributes`, so the removed column stayed in
  `isDirty()` and `getDirtyAttributes()`. Since `save()` writes
  `array_intersect_key($attributes, $dirtyAttributes)`, the column then dropped
  out of the `UPDATE` while `save()` still returned `true` — a write that
  reported success having sent nothing. It also left `$relations` untouched, so
  `unset($user->posts)` left the posts loaded and still serialized by
  `toArray()`. Both are now cleared.
- **A null primary key produced a statement that matched no row and reported
  success** — `getPKs()` built `WHERE id = ?` bound to `null`, which matches
  nothing in SQL, while the driver reported the statement as successful. So
  after `unset($user->id)` — or an unsaved key assignment — `delete()` returned
  `true` with the row still in the table, and `update()`, `updateAttributes()`
  and `updateCounter()` returned `true` having written nothing. A model that
  exists but has no key value now raises `QueryException` naming the model and
  the missing attribute. New records are unaffected.
- **`isset()` disagreed with reading for relations** — `__isset()` checked
  `$attributes` alone, so `isset($user->profile)` was `false` while
  `$user->profile` answered with a `UserProfile`, and `$user->profile ??
  $default` therefore discarded the loaded object and took the default.
  `isset()` now defers to `__get()`. `ArrayAccess` inherits the fix, since
  `offsetExists()` forwards to `__isset()`.
- **A lazy-loaded to-many relation serialized as `{}`** — `toArray()` handled a
  relation held as an array but not as a `Collection`, and lazy loading yields a
  `Collection` where eager loading yields an array. The `Collection` fell
  through to "return it unchanged", so a user with three posts encoded as
  `"posts":{}` — `Collection` exposes no public properties for `json_encode` to
  find — while the same model loaded with `with('posts')` produced the full
  list. Both shapes now serialize identically.

**Eager loading**

- **`with()` on a many-to-many relation returned nothing, for every parent** —
  the batch selects `tag.*` and groups the rows by the relation's foreign key,
  but through a junction the parent's id is on `post_tag` and not on `tag`. The
  grouping key was read from rows that never carried it, so every row grouped
  under `''`, every parent was assigned the empty list, and no error was raised
  anywhere. Reading the same relation lazily returned the correct rows the whole
  time, which is what made it look like a fixture problem rather than a defect.
  The batch now selects the junction's parent-side key under a reserved alias,
  groups by that, and strips the alias from the models before handing them back
  — so `toArray()` and `toJson()` still show only the related table's own
  columns.
- **`with('delete')` deleted every row it had just selected** — the guard was
  `method_exists()`, and then the name was called. This is the same hazard
  `Traits\ResolvesRelations` was written to close in `__get()`, left open one
  call site away: the fix's own regression test passed while the identical bug
  stayed live in `EagerLoader`. `isRelationMethod()` is now public and is the
  single answer to "may a read resolve this name?", used by property access,
  eager loading and relation filters alike. `save`, `refresh` and any other
  non-relation method are likewise no longer invoked.
- **`has()`, `whereHas()` and `doesntHave()` ran the method they were deciding
  about** — `ActiveQuery::resolveRelation()` was the third place answering the
  same question, and it too called the name before checking what came back. A
  name declaring a required argument escaped as a bare `ArgumentCountError`
  naming neither the filter nor the relation. All three now reject an ineligible
  name with an `InvalidArgumentException` that says which name was passed and
  what a relation has to be, without calling anything.
- **A relation method declaring `?Relation` broke each of its callers a
  different way** — `isRelationMethod()` tested the return type's *name* and not
  its nullability, and `?Relation` and `Relation|null` both reflect as a named
  type called `Relation`. So a nullable declaration passed eligibility, and the
  guarantee eligibility exists to establish — that PHP itself enforces what comes
  back — did not hold. The four callers then disagreed about the same method:
  reading the property and `isset()` died on `Call to a member function fetch()
  on null`, `has()` raised a `TypeError` naming an internal method rather than
  the relation, eager loading silently left the relation unloaded, and
  `saveTogether()` returned `true` having saved nothing. Eligibility now requires
  the declaration to exclude null, so all four give the one answer. The
  `has()` rejection message also said a relation "declares Relation as its
  return type" — precisely what the author of a `?Relation` believes they did —
  and now rules out the nullable spelling by name. `docs/04-RELATION.md` records
  the rule, which was not written down anywhere.
- **`with()` combined with `indexBy()` was fatal** — the loader read
  `$models[0]`, and a keyed set has no key `0`. Every combination of two
  documented features raised "Undefined array key 0" followed by a `TypeError`.
  The first model is now taken by position rather than by key, and an empty set
  returns without work.
- **A constrained `select()` that omitted the foreign key silently returned zero
  rows** — the fetched rows had no column to group by, so they were all
  discarded and the parents reported having no related records at all. The
  documented example includes the foreign key in its projection, which is
  exactly what kept it working and made the omission look like the caller's
  mistake. The grouping key is now appended after the constraint runs, and
  removed again before the models are returned: a projection says what the
  caller gets back, not how many records exist. On a junction batch the
  caller's projection was ignored outright; it is now respected.
- **`find()->with(...)->findByPk(1)` was a `TypeError`** — the model-level
  `findByPk()` accepted a scalar and the query-level one did not, and every
  route to eager loading goes through the query. The documented chain in
  `docs/04-RELATION.md` could not run. `ActiveQuery::findByPk()` now takes the
  same two shapes as `Model::findByPk()`, and a scalar given for a composite key
  raises a `QueryException` naming the columns instead of returning `null` —
  which was indistinguishable from "no such row".

**Cursor**

- **`cursor()` buffered the entire result set, which is the one thing it exists
  not to do** — `PDO::MYSQL_ATTR_USE_BUFFERED_QUERY` is an attribute of the
  connection, and it was being passed to `prepare()` as a statement option,
  where PDO accepts it and does nothing with it. Every row was loaded into
  memory before the first one was yielded. Measured over 20,000 rows, `cursor()`
  held 5.15 MB against `getArray()`'s 4.46 MB — the method documented for
  "processing millions of rows with constant memory" cost more than the method
  that says it loads everything. Buffering is now switched off on the connection
  for the duration of the cursor and restored afterwards: the same 20,000 rows
  now cost 0.74 MB, and draining them all does not raise peak memory at all.
  **This is a behaviour change**: a genuinely streaming cursor means MySQL will
  not run another query on that connection until it is drained, so a lazy
  relation read or a write from inside the loop now fails with "Cannot execute
  queries while other unbuffered queries are active" instead of silently
  wasting memory. Collect inside the loop and act after it, or use `each()`,
  which buffers a chunk at a time and has no such restriction.
- **A cursor left its statement open when the caller stopped early** — the
  `closeCursor()` was a trailing statement in the generator body, which a caller
  who `break`s out of the loop, or throws inside it, never reaches. With
  buffering genuinely off that leaves the result set open and the connection
  unable to run anything else. Both the close and the attribute restore now
  happen in a `finally`, which PHP runs on `break`, on exception, and when an
  abandoned generator is collected.
- **`indexBy()` was silently dropped by `cursor()` on PDO drivers** — and
  honoured on MySQLi, which has no `getPdo()` and so falls back to `all()`. The
  same query returned the configured keys or `0, 1, 2` depending on which driver
  was behind the connection, with no error either way. The cursor now keys its
  rows the way `getArray()` does, string or closure, on every driver.
- **`with()` was silently dropped by `cursor()` on PDO drivers**, and applied on
  MySQLi for the same reason — so the same code handed back models with their
  relations loaded or without, depending on the driver, and a relation that was
  never loaded is indistinguishable from a parent that has no related rows.
  Eager loading batches the related rows in a second query once every parent is
  known, which a cursor can neither know nor run while it streams; combining the
  two now raises a `QueryException` naming the relations and pointing at
  `each()`. **Breaking** only for code that was already not getting the eager
  loading it asked for.

**Upsert**

- **An explicit update value was honoured on MySQL and silently dropped on
  PostgreSQL and SQLite** — `Upsert` built MySQL's statement itself and handed
  every other engine to the grammar, and only its own branch understood a string
  key. `['value' => 'x']` therefore set the column to `'x'` on MySQL and to the
  value the INSERT carried everywhere else, from the same call, with no error
  and no bind for the value that was dropped. Verified against MySQL 8.0.30,
  PostgreSQL 14.5 and SQLite 3.49.2: the same call now leaves the same row on
  all three, and hand-written `DO UPDATE SET v = ?` confirmed both dialects
  supported this all along. The two branches are gone — the assignments are
  built once, and the grammar contributes only the wrapper its engine spells
  differently (`Grammar::onConflictSQL()`, `excludedColumnSQL()`,
  `requiresConflictTarget()`).
- **`DB::upsert()` could not name the conflict target** — it took no such
  argument, so the grammars fell back to the first inserted column. On MySQL,
  which reacts to any unique key and takes no target, that worked; on PostgreSQL
  and SQLite it produced `ON CONFLICT ("first_column")`, which those engines
  reject outright unless that column happens to be a unique constraint
  ("there is no unique or exclusion constraint matching the ON CONFLICT
  specification"). An upsert against a composite key was unusable through the
  facade on those engines. `DB::upsert()` now takes `$conflictColumns` as its
  fifth argument; MySQL accepts and ignores it, so naming the target makes the
  same call portable.
- **An upsert with no columns crashed inside the grammar** — `INSERT INTO t ()
  VALUES ()` is not a statement any engine accepts. It reached MySQL and was
  rejected there; on the engines that need a conflict target, `$columns[0]` was
  read off an empty array and raised "Undefined array key 0" followed by a
  `TypeError` naming a line in `PostgresGrammar` rather than the argument at
  fault. It is now refused with an `InvalidArgumentException`, as `Insert`
  already refuses the same shape.

**Test suite**

- **`DBTest`'s data provider left `DB::sqlOnly()` enabled for the whole run** —
  PHPUnit resolves every data provider before it runs any test, so a flag set
  inside one is set globally from that point on, and nothing turned it off.
  Every later test calling `DB::insert()`, `DB::update()`, `DB::delete()` or
  `DB::upsert()` therefore got a builder back and wrote nothing, while the
  facade's own tests passed because they set and cleared the flag themselves.
  The provider now clears it before returning. This masked nothing that shipped,
  but it made any new integration test of a `DB::` write silently inert.

### Changed

- `Traits\Debug` removed. Nothing used it: no class in `src/` applied the trait
  (checked by reflection across every type, following nested traits and parent
  classes, not by name search), nothing read its `$debugMode` property, the
  model generator never emitted it, and no documentation mentioned it. It had
  been unreferenced since the initial commit, and its `enableDebug()` /
  `disableDebug()` names collided with the real, live switch on
  `QueryException`, which is what the docs describe. The debug helpers callers
  actually use — `dump()`, `dd()` and `explain()` on `Traits\Execute`,
  `getFullSQL()` and `tap()` on `ActiveQuery` — never consulted the flag and are
  unaffected; they print when called, which is why a mode flag had nothing to
  gate. **This is breaking** only for code applying `Simsoft\DB\Traits\Debug`
  directly; it was undocumented, so no caller was told it existed.
- `Grammar::upsertSQL()` removed. It built the whole statement, which is what
  forced the two-branch structure above: to honour an explicit update value it
  would have needed the values as well as the column names, and it took only the
  names. `Upsert` now assembles the statement and asks the grammar for the three
  parts that actually differ by engine. **This is breaking** for a custom
  `Grammar` implementation, which must replace `upsertSQL()` with
  `onConflictSQL()`, `excludedColumnSQL()` and `requiresConflictTarget()`; no
  caller outside the grammars used it.
- `whereFulltext()` and `orWhereFulltext()` now throw `InvalidArgumentException`
  for a mode other than `plain`, `phrase` or `websearch`. **This is breaking**
  for code passing any other name — but that code was not getting the search it
  asked for: the mode was silently replaced with `plain`, which on MySQL changes
  which rows come back. `'boolean'` is the common case and the message names
  `websearch` as its replacement. Case and surrounding whitespace are normalised,
  so `'WebSearch'` and `' phrase '` continue to work.
- `orWhere()`'s signature widened to match `where()`: `string|array|callable|Raw|
  Clause` for the attribute and `mixed` for the operator. This only accepts more
  than before, so no working call changes meaning.
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
- A cast is no longer applied to `NULL`. A cast attribute over a nullable column
  now reads back as `null` instead of `0`, `false`, `''` or `[]`, and assigning
  `null` clears the column instead of writing the cast's zero value. **This is
  breaking** for code that relied on a cast attribute never being `null` — a
  `null` check is now required where the column is nullable, which is what the
  database was reporting all along.
- An unrecognised cast type now throws `InvalidArgumentException` instead of
  being ignored. **This is breaking** for a model carrying a typo in `$casts`,
  which will now fail on first assignment rather than silently skipping the
  cast. Supported names: `int`, `integer`, `bool`, `boolean`, `float`, `double`,
  `real`, `string`, `binary`, `array`, `json`.
- A value that cannot be encoded as JSON — a resource, `NAN`, invalid UTF-8 —
  now throws `InvalidArgumentException` on assignment to an `array` or `json`
  attribute instead of being written to the column as an empty string.
- The `array` cast's storage format changed from a live PHP array in
  `$attributes` to JSON text, matching `json`. Reading the property is
  unaffected and still gives an array; `getAttributes()` and anything else
  reading raw storage now sees the encoded string. **This is breaking** for code
  reading an `array` attribute out of `getAttributes()` rather than off the
  model.
- `toArray()` and `toJson()` now present `array` and `json` attributes as the
  decoded document rather than the raw stored string, so they agree with what
  reading the property gives. `getAttributes()` returns raw storage as before.
  **This is breaking** for code that expected `toArray()` to carry the encoded
  string.
- Condition methods now accept only `AND` or `OR` as the logical operator
  joining a condition to the one before it, in any case and with surrounding
  whitespace ignored. **This is breaking** for code passing anything else — but
  that code was producing a WHERE clause containing its argument as a bare SQL
  token, not the query it asked for.
- `jsonHas()` and `jsonMissing()` now throw `InvalidArgumentException` when the
  column carries no `->key`. **This is breaking** for code calling them with a
  bare column — on MySQL that call already failed at the server, and on
  PostgreSQL it returned the opposite of the right answer. Use
  `notNull('column')` to test that the document is present.
- Reading a property named after a non-relation method now answers `null`
  instead of calling the method. **This is breaking** for code relying on
  `$model->someMethod` as a call — but that idiom ran the method as a side
  effect of a read and then failed on `->fetch()`, so it could not have been
  working. Add the parentheses. Relation methods are unaffected.
- `update()`, `delete()`, `updateAttributes()` and `updateCounter()` now throw
  `QueryException` when the model exists but its primary key is `null`. **This
  is breaking** for code that reached those calls without a key — but the
  statement matched no row and returned `true` regardless, so the previous
  behaviour was a silent no-op reported as success.
- `isset($model->relation)` on an unloaded relation now loads it, as reading it
  does, and answers on the value. Use `relationLoaded()` for the previous
  in-memory-only check.
- `toArray()` and `toJson()` now serialize a lazy-loaded to-many relation as a
  list rather than `{}`, matching the eager-loaded shape. **This changes output**
  for any consumer that had adapted to the empty object.

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
- `DB::upsert()` takes `$conflictColumns` as a fifth argument, naming the
  columns the conflict is detected on. Required by PostgreSQL and SQLite for
  any key that is not the first inserted column; accepted and ignored by MySQL,
  so passing it makes one call portable.
- `Grammar::onConflictSQL()`, `Grammar::excludedColumnSQL()` and
  `Grammar::requiresConflictTarget()` split an upsert into the assignments
  (which do not vary by engine) and the wrapper around them (which does). A
  custom grammar implementing `Grammar` must add these three.

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
- 62 attribute casting tests — 49 unit, 13 integration — covering `NULL` in both
  directions, that reading a property leaves the model unchanged and clean, the
  rejection of unknown cast types and unencodable values, every boolean
  representation the three engines produce, `array` / `json` round-trips,
  malformed stored JSON, the `toArray()` / `getAttributes()` split, and the
  dirty-tracking boundary between a real change and a driver type difference.
  The integration tests read every column back with a raw query rather than
  trusting the model that wrote it, since a cast defect is invisible from inside
  the model — the model is the thing that is wrong. One writes into a column
  declared `DEFAULT 99` specifically to prove an explicit `null` reaches the
  database rather than being dropped from the `INSERT`. 35 of the 62 fail
  against the unfixed code.
- 57 tests — 41 unit, 16 integration — covering the full-text mode and
  `orWhere()`'s signature. The mode tests assert on the rows the server returns
  for a term where the modes genuinely disagree (`'+PHP -Docker'` over the `post`
  fixture, where the excluded row is present in `plain` and absent in
  `websearch`) rather than on the SQL text, since the whole defect was that
  plausible-looking SQL answered a different question. The `orWhere` tests pair
  every accepted shape against the same shape through `where(..., 'OR')` and
  require identical SQL and binds, and one asserts that placeholder count equals
  bind count — the specific breakage the `Clause` form produced. A reflection
  test compares all 32 base/or-variant signatures so a future or-variant cannot
  drift from its base. 41 of the 57 fail against the unfixed code.
- 121 tests covering logical operator validation and JSON root paths (78 unit,
  43 integration). The operator tests sweep every condition method that takes
  one, asserting each rejects an injected value, still accepts `AND` and `OR`,
  and normalises case; one drives the reported payload
  (`'OR 1=1 -- '`) through the builder and counts the rows the server returns,
  so it measures the leak rather than the SQL text. The JSON tests check both
  halves of the empty-path defect: that key-existence is refused where there is
  no key, and that the root forms which do have a meaning give the same answer
  on PostgreSQL as on MySQL against the same fixture — the PostgreSQL answers
  were wrong rather than absent, so a SQL-text assertion would have passed. 67
  of the 121 fail against the unfixed code.
- 61 tests covering model property access (47 unit, 14 integration). Each defect
  here reported success while doing the wrong thing, so the integration tests
  ask the server what happened rather than asking the model: whether the row is
  still present after reading `$user->delete`, what the `score` column holds
  after a `save()` that followed an `unset()`, and whether the row survived a
  `delete()` with a null key. A provider drives ten method names — ordinary,
  untyped, argument-taking, static, protected and inherited — through both
  reading and `isset()`, and one test asserts eager- and lazy-loaded relations
  serialize byte-identically. 37 of the 61 fail against the unfixed code.
- 61 collection tests covering chunked iteration and the filter/map pipeline,
  run against in-memory SQLite so the row set is exact. Six chunk sizes — under,
  over and exactly dividing the row count — each drive materialisation, `all()`,
  key continuity, per-record identity and `foreach`, so a fix that merely
  renumbers keys without keeping records is caught. The pipeline tests assert
  that two and three chained filters narrow rather than replace, that a second
  `map()` receives the first's output, that a `filter()` after a `map()` sees
  the mapped value, that deriving a chain leaves the collection it came from
  untouched, and that `batch()` agrees with iterating the same collection.
  31 of the 61 fail against the unfixed code.
- 44 integration tests for the same behaviour against live MySQL, checked
  against counts the server computes rather than hand-written lists. A provider
  drives all six whole-table reads (`all()`, `getArray()`, `get()->all()`,
  `each()`, `cursor()`, `batch()`) after both `first()` and `hasRecords()`, and
  the chained-filter tests first assert that either condition alone matches more
  rows than the pair, so they cannot pass while the callbacks overwrite one
  another. Also covers the sub-query `exists()` condition against
  `COUNT(DISTINCT user_id)`, and insert ids for a statement that inserted
  nothing, a connection that has inserted nothing, and a real insert.
  23 of the 44 fail against the unfixed code.
- 18 unit tests pinning the method-resolution and driver contracts that the
  fixes depend on: that `hasRecords()` resolves to the trait file rather than
  being shadowed, that no other `Fetchable` method is shadowed by `ActiveQuery`,
  that `first()` and `hasRecords()` leave a query's SQL and bindings unchanged,
  that all three PDO-backed drivers route through `normalizeInsertId()`, and
  that all four drivers declare the same `false|string` return.

### Documentation

- New "Case sensitivity" section in the query builder guide. The Like Clauses
  section listed the four base methods and said nothing about case at all, and
  the `whereLike()` family appeared nowhere in it. The section tabulates both
  families against their opposite defaults, shows the SQL each produces, and
  notes that the difference is usually invisible on MySQL's case-insensitive
  collations but changes the result set on PostgreSQL. It also records that the
  `LOWER(col)` form cannot use a plain index on the column.
- The PostgreSQL guide's ILIKE section now warns that `like()` and `whereLike()`
  disagree by default. It previously showed only `whereLike()`, so a reader
  following it and then reaching for `like()` — the name the query builder guide
  documents — would get case-sensitive matching without anything having said so.
- New "Loading connections from a file" section in the getting started guide.
  `Connection::configure()` was named once, in the observer generator guide,
  with no statement anywhere of what the file it loads should contain. The
  section shows the expected `return [...]` shape, documents that `configure()`
  adds to what is already registered rather than replacing it and that a later
  entry overwrites an existing name, and tabulates every failure mode against
  what survives it — including that a malformed entry costs you that entry only.
  It also warns that an error handler discarding warnings during bootstrap will
  hide the first failure and leave you looking at the second.
- New "When entries go away" section in the advanced features guide. The cache
  documentation described how to switch caching on but never what it costs: the
  TTL is the only thing that ends an entry, nothing invalidates on write, and a
  query cached for 300 seconds keeps serving pre-`UPDATE` rows for up to 300
  seconds — including after an update issued by your own code, on the same
  connection, in the same request. The section shows that sequence, says which
  queries to keep `cache()` off, and covers eviction: expired entries are
  dropped on the next read that finds them, but there is no background sweep, so
  keys never read again stay resident and a long-lived worker caching a wide
  spread of one-off queries wants a driver with its own eviction. `clear()` is
  documented, along with the fact that it is not on the interface and needs the
  concrete `ArrayCache`.
- The custom cache driver section now states that only `get()` and `set()` are
  ever called by the query cache, so implementers know `delete()` and `has()`
  are contract obligations no framework path will reach.
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
- The Attribute Casting section of the Active Record guide previously listed the
  cast names and stopped. It now covers the three things that decide what
  actually reaches a column: that a cast never applies to `NULL` in either
  direction, how `array` and `json` differ (`array` always answers with an
  array, `json` stays faithful to the document) and that both store encoded, and
  that reading a property does not alter the model — with the `toArray()` /
  `getAttributes()` split spelled out. Every example was run before being
  written down.
- The PostgreSQL guide's boolean list now says the recognised false values are
  matched case-insensitively, and that a nullable boolean column reads back as
  `null` rather than `false`.
- The model generator's type casting table now points at the same `NULL` rule,
  since a generated model's casts behave exactly like a hand-written one's.
- The full-text sections of the PostgreSQL guide and the cheatsheet now state
  that `plain`, `phrase` and `websearch` are the whole set and that any other
  name raises, with `'boolean'` called out by name — it is MySQL's own word for
  the mode and the one most likely to be typed. The mode table was already
  correct about what the three modes do; what was missing was what happens to a
  fourth.
- The query builder guide's array-shapes subsection now shows `orWhere()` taking
  both array forms, and says why the triplet list is not parenthesised while the
  map is. Every example was run before being written down — the first draft of
  this one claimed parentheses the builder does not emit.
- New "Joining a condition with OR" subsection in the Where Clauses guide. The
  trailing logical operator is a parameter of some twenty public methods and
  appeared in no documentation at all, which is part of why nothing checked it:
  it now covers the `AND` default, the equivalence with the `or`-prefixed
  variants, that only `AND` and `OR` are accepted, and that the argument must
  never be built from request data.
- The `jsonHas()` / `jsonMissing()` sections of the query builder guide and the
  cheatsheet now state that a `->key` is required and point at `notNull()` for
  testing that the document itself is present.
- New "The Document Root" subsection in the PostgreSQL guide, covering the
  forms a bare JSON column produces and the `jsonb_array_length` divergence from
  MySQL's `JSON_LENGTH` on objects.
- Two JSON examples in the query builder guide stated SQL the builder does not
  produce: `jsonHas()` was documented as building
  `"user"."meta" -> 'address' IS NOT NULL` on PostgreSQL when it builds
  `jsonb_exists("user"."meta", 'address')`, and the SQLite `jsonContains()`
  comment was truncated mid-expression. Every JSON example in the section was
  run against all three grammars and the comments corrected to match.
- New "Property Access" section in the Active Record guide, and a matching table
  in the cheatsheet. Reading, testing and unsetting a model property are the
  most-used surface in the library and were documented nowhere, which is part of
  why four defects lived there undetected. It covers the resolution order, which
  method names are eligible for lazy loading and which read as `null`, that
  `isset()` agrees with reading and loads an unloaded relation, that `unset()`
  discards the pending change, and the `QueryException` raised when an existing
  model has no primary key value. Every example was run against the fixture
  before being written down.
- The cheatsheet's exists-check row called the method that always threw. It now
  shows `hasRecords()`, and the query builder guide's Exists Clauses section
  distinguishes the two: `hasRecords()` asks whether the query matches anything,
  `exists()` adds the SQL `EXISTS` sub-query condition and always takes a query.
- The collections guide documented a doubled `filter()` chain, which silently
  applied only the second callback. That section now states that filters and
  maps compose and run in the order they were added, with an example of a
  `filter()` after a `map()` receiving the mapped value rather than the model.
- The collections guide claimed `count()` on a filtered collection returns the
  filtered count in the very example whose parenthetical says it does not. The
  number now matches the note above it.
- The collections guide's `batch()` section now says that filters and maps
  apply, that the size is how many rows are fetched per query rather than how
  many come back, and that a fully filtered-out page is skipped. Every example
  in the guide was run against the fixture before being written down.
- The getting-started guide carried the statement-cache section twice, verbatim,
  and both copies said "PDO only" and "the PDO driver". Three drivers cache, and
  SQLite is one of them. There is now one section, it names all three and says
  that `mysqli` caches nothing, it documents the `CachesStatements` check for
  code whose driver comes from configuration, and it states that eviction is by
  insertion order rather than least-recently-used. The PostgreSQL and SQLite
  config examples list both cache keys, which they support and did not mention.
- The getting-started guide's "Dropped Connections" section said recovery
  happens in two ways. There are three: it now documents the check that
  `transaction()` makes before opening a block, why neither other route can
  cover that case, and that nested calls do not repeat it. Every claim in the
  section was run against a killed MySQL connection on both drivers.
- The relation guide's constrained eager-loading example passed
  `limit(5)`, which reads as five posts per user and is not what it does: eager
  loading fetches every parent's rows in one query, so the limit caps that one
  result set and the parents past the cap get an empty list — indistinguishable
  from having no related rows. The section now states this for `limit()` and
  `offset()`, shows the per-parent alternative, and says that `orderBy()` inside
  a constraint is safe. The same example selected `avatar` from a projection
  purely to keep the foreign key in it; that is no longer necessary and the
  section says so.
- The advanced features guide's cursor section promised constant memory and
  stopped there. Now that a cursor genuinely streams, what that costs has to be
  documented beside it: a new "You cannot query the same connection while a
  cursor is open" subsection covers the MySQL restriction, shows the collect-
  then-act shape that replaces a relation read inside the loop, states that
  `with()` raises rather than being ignored, and gives `each()` as the answer
  for callers who need to query as they iterate — with the trade-off between the
  two spelled out. The section's first example filtered on a `status` column the
  sample schema does not have; it is `status_code`. Every example, including the
  one showing what not to do, was run before being written down.
- Upsert had no section of its own — one cheatsheet row, and a PostgreSQL-guide
  example that showed the conflict target the facade had no way to pass. The
  advanced features guide now has an "Upsert (Insert or Update on Conflict)"
  section covering the update columns, the string-key form for setting a column
  to a value other than the one inserted, mixing the two, and why the conflict
  target must be named for PostgreSQL and SQLite (with the error those engines
  give when it is wrong) while MySQL ignores it. The cheatsheet's row showed a
  call that could not work on those engines and now names the target. Every
  example was run against MySQL and PostgreSQL, on `setting`, whose composite
  unique key is what the examples are about.

### Tests

- 12 tests covering `Builder::__toString()`, which no test had ever called
  despite four classes declaring it and the query-builder guide documenting a
  cast. Casting is not merely an alias for `getSQL()`: it triggers the same
  build, so it inherits the memoisation and the bind accumulation that build
  carries. The tests run one builder of every kind — `Select`, `Insert`,
  `Update`, `Delete`, `Upsert` and an aggregate — through a cast-first read, a
  repeated cast (the values must not accumulate), a mutation after a cast, and a
  connection change after a cast, plus the four string contexts PHP reaches
  `__toString()` from. No defect was found; the method was correct on both
  engines and is now held that way.
- 17 tests covering `CursorPaginator`, which had none. Its offset counterpart
  has been covered since it was written, and the two are easy to mistake for
  each other, but they are separate classes and this one carries cursors rather
  than page numbers. `isEmpty()` had no caller at all. The tests pin it against
  `count()` for empty, single and multi-row pages, and include a page whose only
  row is itself falsy — `0`, `''`, `[]`, `null`, `false` — since that is the
  reading `empty()` invites and would be wrong. Also covers iterating twice
  (the iterator yields from an array, not a spent generator), string cursors,
  and `hasMore` being about the query where `isEmpty()` is about the page.
- Both sets were checked by mutation rather than by line count, since covering a
  correct line proves nothing on its own: inverting `isEmpty()`, rewriting it to
  the falsy-element reading, and emptying `__toString()` each fail the new tests
  (2, 5 and 9 failures).
- 15 tests covering the three error paths in `Driver` that nothing reached: the
  two ways `ping()` reports failure, the rollback that cannot be sent, and
  config validation. Each was confirmed reachable against a live server before
  being written, which mattered — the first attempt at the `ping()` tests passed
  against a deliberately broken `ping()`, because the `false` they observed came
  from the `catch` rather than from either branch being aimed at. The paths now
  covered are: no connection left to probe (a failed reconnect leaves the driver
  holding nothing); a probe that reports failure by return value rather than by
  throwing, which is reachable because `mysqli_report()` is process-global and
  the driver sets it only inside `connect()`; and a savepoint rollback lost with
  its connection, the nested counterpart of the outer-rollback case. A companion
  test pins the suppression's boundary — a rollback that fails for any other
  reason is still raised — since a driver that swallowed that would report a
  rollback it never performed. Verified by mutation: making `ping()` ignore both
  failures, and widening the suppression to every rollback error, produce 5
  failures between them.
- 21 tests covering the paths through `EagerLoader` that nothing reached, taking
  the file to 100% line coverage. 12 concern the nullable declaration above,
  each run against both spellings, and they produce 12 failures and errors
  against the unfixed source — including the two fatals, which is what the
  divergence looked like in production.

  The other 9 cover states the batch loader short-circuits, and what each one
  pins is the work *avoided* rather than the value returned. An unsaved parent
  gets an empty relation, and gets it without sending the `WHERE 1 = 0` whose
  answer is known before it leaves. A set whose first model does not have the
  relation is passed over without any of the later ones being read — reading a
  relation lazy-loads it, so the collector that gathers models for a nested
  level would otherwise reintroduce, one query per parent, the N+1 eager loading
  exists to remove. Both are asserted on the query count for a reason: both
  lines were correct-but-uncovered, where covering the line proves nothing on
  its own, and the first attempt at each passed against source with the guard
  deleted. Deleting each of the three guards now fails the suite.

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
