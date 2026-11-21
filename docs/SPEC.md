# Almanac API: design notes

How the API is built and why. Setup, endpoints and commands are in the [README](../README.md); the HTTP contract is [openapi.yaml](openapi.yaml).

## Modules

A modular monolith with three modules and a shared kernel:

| Module | Owns | Tables | Contract |
|---|---|---|---|
| `Briefing` | sources, snapshots, refresh policy, circuit breaker, discoveries, prefetch | `briefing_source_snapshot`, `briefing_discovery` | `DiscoveryCatalog`, `DiscoveryView`, `DiscoveryId`, `DiscoveryType` |
| `Identity` | users, tokens, Bearer authentication | `identity_user`, `identity_access_token` | `AuthenticatedUser`, `UserId` |
| `Bookmark` | bookmarks | `bookmark` | none |
| `Shared` | Problem Details, request id, logging, health, API document | none | `HealthCheck` port, uid base type |

A module uses only its own classes, `Shared`, the `Contract` namespace of any module, and code outside `App`; `Shared` uses no module. `config/` is the composition root. Foreign keys exist only inside a module. `bookmark.user_id` and `bookmark.discovery_id` are plain `uuid` columns: `DiscoveryCatalog::find()` runs before the insert, and users and discoveries are never deleted. That is the cost of the boundaries. Account deletion would need an event from Identity that Bookmark handles. In return, `Briefing` could become a separate service by turning `DiscoveryCatalog` into an HTTP client, with no change in `Bookmark`.

Inside a module the layout is plain Symfony, with no separate domain layer:

- **Edge** (controllers, commands, message handlers, value resolvers, security, health checks, HTTP listeners): input and output only, with no repositories, Doctrine or upstream clients. `AccessTokenHandler` is the one exception.
- **Services** in the module root (`BriefingAggregator`, `Prefetcher`, `AccessTokenIssuer`, `BookmarkService`) know nothing about HTTP. Status codes come from `framework.exceptions`, bodies from mappers.
- **Model and contracts** are readonly data, free of Doctrine, HTTP and entities. Contracts depend only on contracts.
- **Sources**: each `Source/<Name>/` has a client, a typed upstream response, a mapper and a definition, and is private to itself. Response DTOs use their own enums, so a domain enum cannot leak into the API.

These are phpat rules in `tests/Architecture/`. They run inside PHPStan and cannot be ignored or baselined. A rule that selects nothing passes silently, so every rule has fixtures that break it and fixtures that are allowed, and a test expects the exact list of violations. `CLAUDE.md` lists what phpat cannot see, for review.

## Briefing flow

1. The date must be `Y-m-d` and a real calendar day (else 400), from 1995-06-16 to today + 15 days in `APP_TIMEZONE` (else 422). `lat` and `lon` are validated as plain decimal strings, both or neither, and rounded to two decimals (about 1 km).
2. Each source says whether it covers the date: APOD from 1995-06-16 by New York's publication day; Open-Meteo archive for past days and forecast from today. Otherwise the source is `not_applicable`, which is computed per request and never stored.
3. The aggregator reads the stored snapshots, asks the refresh policy what to fetch, and asks each source's circuit for permission.
4. Permitted sources are fetched concurrently in one HttpClient `stream()` (curl). Each client has a 3-second `max_duration`, one retry on network errors and 5xx, no retry on 429 or with `Retry-After`, and no redirects.
5. Results are mapped into the internal model, discoveries are extracted, and snapshots and discoveries are saved in one transaction.
6. The response is built from storage: 200 if any source has data, otherwise 502.

A failed source is data, not an exception. Each failure has a kind: `unavailable` (timeout, network, 5xx), `rate_limited` (429), `rejected` (another 4xx), `misconfigured` (401, 403, 3xx) or `invalid_payload`. The kind sets the log level and whether the breaker counts it.

## Storage

- **`briefing_source_snapshot`**: one row per `(source, scope_key)`. The key is `2024-03-15` for APOD, `03-15` for On This Day, and `2024-03-15@52.23,21.01` for weather. `status` is the last attempt; `payload` (JSONB) is the last good data as our internal model, never the upstream body. One table is enough because a payload is always read whole. Named `CHECK` constraints keep status, error, payload and dates consistent.
- **`briefing_discovery`**: what can be bookmarked. The id is a UUIDv5 of a stable key (`apod:{date}`, `event:{year}-{mm-dd}:{text hash}`, `person:{Wikidata item}`), so a briefing knows every `discoveryId` without a query. Rows are inserted once and never changed.
- **`bookmark`**: unique `(user_id, discovery_id)`, an index on `(user_id, created_at DESC, id DESC)`, offset pagination.
- Entity ids are typed UUIDv7s with their own Doctrine types. Instants are UTC. The schema comes only from Doctrine Migrations, which the `php` container runs on start.

**Native upserts.** Doctrine handles all entities, reads and the schema. Three writes use `INSERT … ON CONFLICT` on the same connection: snapshots, discoveries and bookmarks. The API and the worker write the same keys at once. A read-then-write is not atomic, `MERGE` still fails on a concurrent insert, and a unique violation closes the EntityManager. The snapshot upsert is guarded. A success replaces the payload only if it was fetched later. A failure never touches the payload. `last_attempt_at` never moves back. Discoveries are inserted sorted by key, so parallel batches lock in the same order. Snapshots are re-read with `Query::HINT_REFRESH`, because the identity map would return the state from before the native write.

## Refresh policy

`RefreshPolicy` is a pure function of the snapshot state, the source's `refreshAfter`, the date and now:

| Snapshot | Date | Decision |
|---|---|---|
| none | any | fetch |
| has data | before today | final, never fetched again |
| last attempt failed under 10 minutes ago | today or later, or past without data | wait |
| last attempt failed 10 or more minutes ago | same | fetch |
| ok, no `refreshAfter` (APOD, On This Day) | today or later | final |
| ok, `refreshAfter` 1 hour (weather) | today or later | fetch when older than an hour |

The past-date rule comes first, so a stored forecast for a day whose last refresh failed is not retried forever. Weather says which data it is: `forecast`, `provisional` (the last five archive days) or `final`. When a refresh fails and old data exists, it is served with `stale: true`, the original `fetchedAt`, the `error` and `lastAttemptAt`. Final data is never stale.

## Circuit breaker

The refresh policy protects one snapshot; the breaker protects an upstream, so that an outage or NASA's `DEMO_KEY` limit is not hit again by every new date and queued message.

- There is one circuit per source in Redis, shared by `php` and `worker`. One `open_until` key holds the state: missing means closed, a future instant means open, a past one means half-open.
- Only outages count (`unavailable`, `rate_limited`). Three within a sliding 30-minute window open the circuit for 60 seconds. A 429 opens it until `Retry-After`, or for 15 minutes without it.
- Half-open allows one trial, claimed with `SET NX PX`. Only the trial closes or reopens the circuit. Opening and claiming are single Lua calls, safe between processes.
- A refused source makes no request and saves nothing. It serves stored data as stale, or is `failed` with `error: unavailable`.
- If Redis fails, the breaker fails open and logs one warning. Lost Redis data only closes circuits. `/api/health` shows each circuit and always answers 200.

## Rate limiting

`#[RateLimit]` on the briefing allows 60 requests a minute in a sliding window. The key is the client network (IPv4 address, IPv6 /64), not network plus path, so all dates share one limit. The limit is checked after the date resolver, so a malformed date is not counted. Counters are in Redis with no lock. A burst of parallel clients can therefore pass up to about 2.5 times the limit, and a Redis outage lets traffic through. No trusted proxies are set, so `X-Forwarded-For` cannot change the key. Above the limit the API answers 429 with `Retry-After`. Bookmarks rely on the token.

## Prefetch and Messenger

`PrefetchCommand` validates its input with `#[MapInput]`: strict `Y-m-d`, the same window, `to >= from`, at most 366 days. It then lets `Prefetcher` call the same aggregator as the controller, one transaction per date. Any problem gives exit code 1. `--delay` sleeps through `ClockInterface`, so tests do not wait.

`--async` sends one scalar-only message per date to `doctrine://default`. The table comes from a migration, and LISTEN/NOTIFY wakes the worker. Upstream failures are stored as snapshots, as in the synchronous run. A date that waits for a 10-minute pause, a `Retry-After` or an open circuit is redelivered at that moment, plus up to 30 seconds of jitter, for up to a day. Failures that waiting cannot fix are acknowledged with the source missing. Bugs and database errors are retried after 1, 4 and 16 seconds, then go to `failed`.

## Errors and validation

Every error is `application/problem+json` from Symfony's `ProblemNormalizer`, whatever the `Accept` header: 404 and 405 from routing, 401 from the firewall, and `violations[]` for validation. Input goes through the Validator: value resolvers and `#[MapQueryString]` for URLs, `#[MapRequestPayload]` DTOs for bodies (unknown fields rejected), `#[MapInput]` for the console. Someone else's bookmark is a 404, so the API does not reveal that it exists. Client errors are logged at `notice`.

## OpenAPI

`docs/openapi.yaml` is hand-written OpenAPI 3.0.3, because the validator does not support 3.1. `/api/docs.json` serves it as JSON. The document is checked, not only written:

- `OpenApiValidatingBrowser` is the test client. Every functional response must match the document, and so must the request of every 2xx. An undocumented route must answer Problem Details.
- Schemas set `additionalProperties: false` and require every key, and there are no `default` responses, so an extra field or an undocumented status fails.
- `OpenApiCoverageTest` matches routes and paths both ways. Redocly lints the file in CI, and `OpenApiDocumentTest` checks the examples after PHP has parsed the YAML.

## Logging

- One JSON line per entry on stderr. A request id is assigned or validated by nginx, re-checked in PHP, returned as `X-Request-Id` and added to every entry.
- `warning` means degraded but coping (source outage, open circuit, Redis down). `error` means something on our side must change (a bad key, a changed upstream format). Each failed source is logged once.
- Our channels accept only the context keys in `LogContextKey`. Monolog's `RedactingFormatter` on every handler masks tokens, keys, emails and coordinates. The NASA key travels in a header, and the security identifier is the user's UUID.
- `LogLeakTest` replays the listed scenarios at prod log levels and finds no coordinates, email or token. It is a regression check for those paths, not a proof.

## Code quality and security

- PHPStan level 9 with strict and deprecation rules, phpat, and a rule that requires `declare(strict_types=1)`. PHP-CS-Fixer with the Symfony rule sets.
- `composer audit` runs in every check. CI adds gitleaks on the full history, hadolint and a commit-subject check.
- Tokens are 32 random bytes, stored only as sha256. Unknown, revoked and expired tokens get the same 401.
- Only nginx is published, on `127.0.0.1`, with security headers and a 16 KB body limit. TLS belongs to the proxy in front of it.
- The prod image has no dev tools, its files are owned by root, and it runs as uid 82. It is checked in CI. A version tag publishes it with an SBOM after Trivy and Grype scans.

## Known limits

- Days stored before discoveries existed have no discovery rows; past days are not fetched again.
- Two parallel first requests for one scope both call upstream; the guarded upsert keeps the newer data.
- Forecast and archive share the weather circuit. The breaker honours `Retry-After` only on 429.
- Behind a proxy or NAT, `SYMFONY_TRUSTED_PROXIES` must be set, or all clients share one limit.
