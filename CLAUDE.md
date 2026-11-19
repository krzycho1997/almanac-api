# Almanac API

Symfony 8.1 API on PHP 8.5, PostgreSQL 18 and Redis 8, run with Docker Compose (Podman works too).

## Commands

Everything runs in the `php` container.

- Start: `docker compose up --wait`, then `curl http://127.0.0.1:8080/api/health`
- Before every commit: `docker compose exec php composer check:fast`
- Everything: `docker compose exec php composer check` (`check:fast`, then the test database and all suites)
- Tests: `composer test`, or one level with `test:unit`, `test:integration`, `test:functional`, `test:live`
- One file: `docker compose exec php vendor/bin/phpunit tests/Path/To/SomeTest.php`
- One module: `docker compose exec php vendor/bin/phpunit --testsuite briefing` (also `identity`, `bookmark`, `shared`); a module with no tests yet fails with "No tests executed!" unless you add `--do-not-fail-on-empty-test-suite`
- PHPStan rules and the architecture check: `docker compose exec php vendor/bin/phpunit --testsuite phpstan`
- Static analysis and style: `composer phpstan`, `composer cs-check`, `composer cs-fix`
- New migration: `docker compose exec php bin/console doctrine:migrations:diff`
- Git hooks, once per clone: `git config core.hooksPath .githooks`

## Modules

- `src/Briefing`, `src/Bookmark`, `src/Identity` are modules; `src/Shared` is shared by all of them.
- A module may use only its own classes, `Shared`, the `Contract` namespace of any module, and code outside `App`. `Shared` uses no module.
- No Doctrine relations or foreign keys between modules; store the other module's id as a `uuid` column.
- `config/` is the composition root and may reference module internals.
- A new top-level directory in `src/` becomes a module for the architecture rules automatically; add its test suite to `phpunit.dist.xml` and its commit scope to `.githooks/commit-msg` by hand.

## Layers

- Edge (`Controller`, `Command`, `MessageHandler`, `ValueResolver`, `Security`, `ErrorHandler`, `RequestId`): input and output only, no repositories, Doctrine or upstream clients. Exception: `AccessTokenHandler`.
- Services in the module root, `Briefing\CircuitBreaker`, `Briefing\Discovery` and every module's `Exception` know nothing about HTTP or the edge; status codes come from `framework.exceptions`, response bodies from `Mapper`.
- Contracts depend only on contracts. `Briefing\Model` and contracts stay free of Doctrine (including `Symfony\Bridge\Doctrine`), HTTP, entities and sources; Doctrine `*IdType` classes in `Contract` are the exception.
- Every class in `App` is `final`, except entities, abstract classes, interfaces, enums, traits and `Kernel`.
- The rules cover `src/`; `App\Tests` and `App\PHPStan` are outside them.

These rules live in `tests/Architecture/` as phpat rules and run inside PHPStan. They cannot be ignored or baselined. `tools/phpstan/tests/Architecture` runs them against fixtures and expects an exact list of violations: when you change a rule, add a fixture that breaks it and one that is allowed. The rule classes end in `Test`, so `vendor/bin/phpunit tests` warns about them; run suites instead. phpat counts a `@final` docblock as `final`, and does not see property `@var` docblocks, `Foo::bar(...)` callables, static properties, function calls, closure return types, class-level `@extends`/`@implements`, typed class constants, anonymous classes, class names in strings, or SQL on another module's tables: check those in review.

Custom PHPStan rules and loaders live in `tools/phpstan/` (`App\PHPStan`).

## Tests

- Every PHPUnit test has `// Given`, `// When`, `// Then` sections. Merge them where a split would be artificial (`// Given / When`, `// When / Then`, `// Then / When` for `expectException()`); drop `// Given` when there is nothing to prepare. phpat classes in `tests/Architecture/` are rule declarations, not PHPUnit tests, so they have no sections.
- Write the functional test for an endpoint before the endpoint.
- Upstream HTTP is always mocked (`MockHttpClient`); tests never hit the network, except the `live` suite.
- The `e2e` suite sends real HTTP requests from the `php` container to nginx (`E2E_BASE_URL`, default `http://nginx`), so it needs the running stack; nginx behaviour (headers, limits) is tested there, not in CI scripts.
- Use `ClockInterface` instead of `new \DateTimeImmutable()` in application code; functional tests freeze the clock in `FunctionalTestCase`.
- Never delete, skip or weaken a test to make it pass. If a test looks wrong, stop and ask.

## Code

- `declare(strict_types=1);` in every PHP file, including `bin/`, `public/`, `config/` and migrations.
- No comments in code, not even for facts the code cannot express: put reasons, upgrade notes and links in the commit message, the PR description or `docs/`. Allowed: PHPDoc types for static analysis, `// Given` / `// When` / `// Then` in tests, and generated files.
- Classes follow SOLID (one reason to change, depend on interfaces where one exists, extend by decorating or tagging rather than editing) and SLAP: a public method reads as a list of steps at one level of abstraction, and details such as header arrays, JSON decoding or loops go into named private methods.
- Name repeated or meaningful literals with constants, and use the framework's own first (`Request::METHOD_*`, `Response::HTTP_*`, `JsonEncoder::FORMAT`, `ProblemNormalizer::TYPE`); define `private const` only where none exists. Prefer early returns to compound conditions and nested `if`/`else`, and write conditions positively: no `!` or `!==` in an `if`; invert the check (`if ($x instanceof Foo) { return …; } return …;`, `$level->includes(…)` instead of `!…->isLowerThan(…)`) or name the helper for the case it detects (`isOutsideClientErrors()`).
- Logs: our channels (`briefing`, `identity`) accept only context keys from `App\Shared\Logging\LogContextKey` (others are masked); add a case there with the plan's log catalogue before logging a new field. Every handler formats through `app.monolog.formatter.redacting_json`. Never put coordinates, emails, tokens or keys into message templates; pass IDs (UUID), not personal data.
- No new dependencies without asking. No API Platform, no MySQL or SQLite.

## Commits and branches

- Conventional Commits: `type(scope): description`, imperative, lower case after the colon, no trailing period, at most 72 characters. Types: `build`, `chore`, `ci`, `docs`, `feat`, `fix`, `perf`, `refactor`, `revert`, `style`, `test`. Scopes: `briefing`, `bookmark`, `identity`, `shared`, `docker`, `deps`, `deps-dev`.
- No `Co-Authored-By` trailer; the README describes the use of AI.
- Every commit is green: `composer check:fast` passes before it is made.
- One branch per slice (`type/NN-description`, from a fresh `main`), one PR, merged with a merge commit (`--no-ff`). Bring a branch up to date with `git rebase main`, never with a merge from `main`. Force-push only your own branch before merge; `main` is never rewritten.
- A PR that introduces or changes a decision lists it under "Decisions and trade-offs".
