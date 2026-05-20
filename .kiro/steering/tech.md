# Tech Stack

## Runtime

- PHP ^8.1 | Extensions: ext-pdo, ext-mysqli (optional)
- Suggested: `simsoft/validator` ^1.0 (optional, for model validation)

## Dev Tools

- PHPUnit ^11.0 (`#[Test]`, `#[DataProvider]`)
- PHPStan ^2.0 (level 8)
- PHPMD ^2.15 (ruleset: `phpmd.xml`)
- php_codesniffer ^3.13 (PSR-12)

## Namespaces (PSR-4)

- `Simsoft\DB\` → `src/`
- `Models\` → `tests/Models/`

## Commands

```bash
composer install
composer test       # phpunit tests
composer qc         # phpstan + phpmd (zero errors required)
composer qc-fix     # phpcbf (PSR-12)
```

## Formatting

4-space indent, LF endings, UTF-8, final newline, trim trailing whitespace.

## Quality Gates

All code must pass before presenting: `composer qc` + `composer test`.
