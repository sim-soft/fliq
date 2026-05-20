---
inclusion: fileMatch
fileMatchPattern: "**/*.php,**/*.md"
---

# Coding Conventions

## PSR Compliance

PSR-1, PSR-12 (mandatory). PSR-3/11/13 when relevant.

## Design Principles

- SOLID + GRASP throughout
- Framework-independent — no framework coupling

## Naming

- Variables: ≥2 chars (PHPMD ShortVariable)
- Methods: ≥3 chars (exception: `in()`)
- Classes: PascalCase | Methods/props/vars: camelCase | Constants: UPPER_SNAKE_CASE
- No excessively long variable names (PHPMD LongVariable)

## PHPDoc

- Class-level docblock required on all classes
- Public/protected methods: full `@param`, `@return`, `@throws`
- Use `@var` for complex typed properties

## Code Quality

- Pass PHPMD (`phpmd.xml`) and PHPStan level 8
- No `else` — use early returns/guard clauses
- No boolean argument flags (except: `__construct`, `parse`, `formal`, `make`, `lookup`)
- No `exit`/`eval`/`goto`
- Max coupling: 14 (CouplingBetweenObjects)
- No unused/dead code — no "for future use" params/methods/imports. Do not suppress with `@SuppressWarnings(PHPMD.UnusedFormalParameter)`
- No unnecessary pass-by-reference — only when mutation occurs
- No backslash-prefixed global calls (`\is_array()`) — PHP 8.1+ optimizes automatically
- No `use function` imports for global PHP functions
- No unnecessary curly braces in interpolation (`"$this->prop"` not `"{$this->prop}"` unless arrays/complex expressions)
- No inline FQCNs — always `use` import + short name
- No generic `\Exception` — use specific or custom exception from `src/Exceptions/`
- PHP ^8.1 compat only — no features from later versions. Guard version-specific constants with `defined()` + fallback.

## Encapsulation

- ORM Model attributes accessed only within the model
- Check for existing accessors before reading properties
- Query builders live inside the ORM model
- Fat model, thin controller (in MVC context)

## Documentation

- New classes/helpers MUST have a usage guide in `docs/` with real method names/signatures
- Verify methods exist before writing examples

## Testing

- Ask permission before creating tests
- PHPUnit 11: `#[Test]`, `#[DataProvider]` (public static providers)
- Test class mirrors source path (`src/Foo.php` → `tests/FooTest.php`)
- `@SuppressWarnings` allowed in test classes when PHPMD limits intentionally exceeded
