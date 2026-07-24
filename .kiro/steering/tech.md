# Tech Stack

## Runtime

- PHP ^8.4 | Extensions: ext-pdo, ext-mysqli (optional)
- Suggested: `simsoft/validator` ^3.0 (optional, for model validation)

## Dev Tools

- PHPUnit ^11.0 (`#[Test]`, `#[DataProvider]`)
- PHPStan ^2.0 (level 8)
- PHPMD ^2.15 (ruleset: `phpmd.xml`)
- php_codesniffer ^3.13 (PSR-12)

## Namespaces (PSR-4)

- `Simsoft\DB\` → `src/`
- `Models\` → `tests/Models/`
- `Integration\` → `tests/Integration/`

## Commands

```bash
composer install
composer test       # phpunit tests
composer qc         # phpstan + phpmd (zero errors required)
composer qc-fix     # phpcbf (PSR-12)
```

## Formatting

4-space indent, LF endings, UTF-8, final newline, trim trailing whitespace.

## Documentation Code Examples

- Multi-line SQL comments use `/* */` block style, not `//` per line
- No single-character variables (`$q`, `$u`) — use descriptive names (`$query`,
  `$user`)
- Avoid `!attribute` pattern in examples — use dot notation (`table.column`)
- Show actual SQL output above the code when possible

## Security

- Never interpolate config values into SQL — sanitize with `/[^a-zA-Z0-9_]/` or
  use prepared statements
- Never use `str_replace("'", "''", ...)` for SQL escaping — use parameter
  binding
- All user-facing queries must use prepared statements with `?` placeholders
- `Raw` class is the only escape hatch — document security responsibility at
  call site
- Identifier names validated via `Qualifier::validateIdentifier()` — do not
  bypass

## Quality Gates

All code must pass before presenting: `composer qc` + `composer test`.
