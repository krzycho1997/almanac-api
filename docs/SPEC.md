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

These are phpat rules in `tests/Architecture/`. They run inside PHPStan and cannot be ignored or baselined. A rule that selects nothing passes silently, so every rule has fixtures that break it and fixtures that are allowed, and a test expects the exact list of violations. The contributor notes in `CLAUDE.md` list what phpat cannot see, for review.

## Briefing flow

1. The date must be `Y-m-d` and a real calendar day (else 400), from 1995-06-16 to today + 15 days in `APP_TIMEZONE` (else 422). `lat` and `lon` are validated as plain decimal strings, both or neither, and rounded to two decimals (about 1 km). Weather is stored per day and that cell. A coarser grid was tried and dropped: Open-Meteo fits its answer to the elevation of the exact point, so snapping Tromsø from 69.65° to 69.7° moved it about 690 m up and turned a sunset at 00:22 into polar day.
2. Each source says whether it covers the date: APOD from 1995-06-16 by New York's publication day; Open-Meteo from 1940-01-01 to today + 14 days, through the archive for days six or more days ago and the forecast API for the rest. Otherwise the source is `not_applicable`, which is computed per request and never stored.
3. The aggregator reads the stored snapshots, asks the refresh policy what to fetch, and asks each source's circuit for permission.
4. Permitted sources are fetched concurrently in one HttpClient `stream()` (curl). Each client has a 3-second `max_duration`, one retry on network errors and 500, 502, 503 or 504, no retry on 429 or with `Retry-After`, and no redirects. The whole fetch ends after 3.5 seconds.
5. Results are mapped into the internal model, discoveries are extracted, and snapshots and discoveries are saved in one transaction.
6. The response is built from storage: 200 if any source has data, otherwise 502.

A failed source is data, not an exception. Each failure has a kind: `unavailable` (timeout, network, 5xx), `rate_limited` (429), `rejected` (another 4xx), `misconfigured` (401, 403, 3xx) or `invalid_payload`. The kind sets the log level and whether the breaker counts it.

## Storage

- **`briefing_source_snapshot`**: one row per `(source, scope_key)`. The key is `2024-03-15` for APOD, `03-15` for On This Day, and `2024-03-15@52.23,21.01` for weather. `status` is the last attempt; `payload` (JSONB) is the last good data as our internal model, never the upstream body. One table is enough because a payload is always read whole.
- **`briefing_discovery`**: what can be bookmarked. The id is a UUIDv5 of a stable key (`apod:{date}`, `event:{year}-{mm-dd}:{text hash}`, `person:{Wikidata item}`), so a briefing knows every `discoveryId` without a query. Rows are inserted once and never changed.
- **`bookmark`**: unique `(user_id, discovery_id)`, an index on `(user_id, created_at DESC, id DESC)`, offset pagination.
- Users, tokens and bookmarks have UUIDv7 ids; the user, bookmark and discovery ids have their own Doctrine types. Instants are UTC. The schema comes only from Doctrine Migrations, which the `php` container runs on start. A new migration starts from `doctrine:migrations:diff`, or from `doctrine:migrations:generate` when the change is one the mapping cannot express, so every version number is the generator's timestamp and two branches cannot pick the same one.

**Rules in PHP, not in CHECKs.** The database holds the types, keys and unique indexes; the other rules live where the rows are built, so they fail in the code that broke them, with a test, and the schema stays what the mapping describes. A migration dropped the earlier CHECK constraints. `DiscoveryRecord` refuses an empty title, a key that does not name something after its type and a picture of the day without a date. `SnapshotScope` refuses a date for On This Day and requires one for the other sources. Enums limit sources, statuses, errors and types; the id classes fix the UUID versions. `SourceResult` can only be ok with a payload or failed with an error, `saveResult()` refuses a not-applicable result, and the two snapshot upserts write those columns together. A native write that skips these classes is not checked.

**Native upserts.** Doctrine handles all entities, reads and the schema, and every entity has its own `ServiceEntityRepository`. Three writes use `INSERT … ON CONFLICT` on the repository's connection: snapshots, discoveries and bookmarks. The API and the worker write the same keys at once. A read-then-write is not atomic, `MERGE` still fails on a concurrent insert, and a unique violation closes the EntityManager. The snapshot upsert is guarded. A success replaces the payload only if it was fetched later. A failure never touches the payload. `last_attempt_at` never moves back. Discoveries are inserted sorted by key, so parallel batches lock in the same order, and a batch carries each key once: when a day lists the same person or event twice, the first record wins instead of whichever row the database meets first. Snapshots are re-read with `Query::HINT_REFRESH`, because the identity map would return the state from before the native write.

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

The past-date rule comes first, so a stored forecast for a day whose last refresh failed is not retried forever. Weather says which data it was when fetched: `final` (the archive, six or more days ago), `provisional` (the forecast API's last five days) or `forecast`. The kind is fixed at fetch time: a stored past day is not fetched again, so a day stored as a forecast keeps `kind: forecast`. When a refresh fails and old data exists, it is served with `stale: true`, the original `fetchedAt`, the `error` and `lastAttemptAt`. Final data is never stale.

## Circuit breaker

The refresh policy protects one snapshot; the breaker protects an upstream, so that an outage or NASA's `DEMO_KEY` limit is not hit again by every new date and queued message.

- There is one circuit per source in Redis, shared by `php` and `worker`. One `open_until` key holds the state: missing means closed, a future instant means open, a past one means half-open.
- Only outages count (`unavailable`, `rate_limited`). Three of them, each within 30 minutes of the last and with no successful call in between, open the circuit for 60 seconds. A failure that is not an outage (`rejected`, `misconfigured`, `invalid_payload`) neither counts nor resets the count: it shows the upstream answered, not that it is healthy, so a 503, a 400 and a 503 still add up. A 429 opens it until `Retry-After`, or for 15 minutes without it.
- Half-open allows one trial, claimed with `SET NX PX`. Only the trial closes or reopens the circuit. Opening and claiming are single Lua calls, safe between processes.
- A refused source makes no request and saves nothing. It serves stored data as stale, or is `failed` with `error: unavailable`.
- If Redis fails, the breaker fails open and logs one warning per request or message. Lost Redis data only closes circuits. `/api/health` shows each circuit and always answers 200.

## Rate limiting

`#[RateLimit]` on the briefing allows 60 requests a minute in a sliding window. The key is the client network (IPv4 address, IPv6 /64), not network plus path, so all dates share one limit. The limit is checked after the date resolver, so a malformed date is not counted. Counters are in Redis with no lock. A burst of parallel clients can therefore pass up to about 2.5 times the limit, and a Redis outage lets traffic through. No trusted proxies are set, so `X-Forwarded-For` cannot change the key. Above the limit the API answers 429 with `Retry-After`. Bookmarks rely on the token.

## Prefetch and Messenger

`PrefetchCommand` validates its input with `#[MapInput]`: strict `Y-m-d`, the same window, `to >= from`, at most 366 days. It then lets `Prefetcher` call the same aggregator as the controller, one transaction per date. Any problem gives exit code 1. `--delay` sleeps through `ClockInterface`, so tests do not wait.

`--async` sends one scalar-only message per date to `doctrine://default`. The table comes from a migration, and LISTEN/NOTIFY wakes the worker. Upstream failures are stored as snapshots, as in the synchronous run. A date that waits for a 10-minute pause, a `Retry-After` or an open circuit is redelivered at that moment, plus up to 30 seconds of jitter, for up to a day. Failures that waiting cannot fix are acknowledged with the source missing. Bugs and database errors are retried after 1, 4 and 16 seconds, then go to `failed`.

## Errors and validation

Every error from the app is `application/problem+json` from Symfony's `ProblemNormalizer`, whatever the `Accept` header: 404 and 405 from routing, 401 from the firewall, and `violations[]` for validation. A decorator gives it `type: about:blank` with the status name as `title` (RFC 9457 §4.2.1) instead of the obsolete RFC 2616 link and "An error occurred". `detail` is the message of our own exceptions, written for the client; framework errors get one fixed sentence per status, and a 500 only asks to quote `X-Request-Id`, so no internal message reaches the client, in dev either (dev still adds `class` and `trace`). Input goes through the Validator: value resolvers and `#[MapQueryString]` for URLs, `#[MapRequestPayload]` DTOs for bodies (unknown fields rejected), `#[MapInput]` for the console. Someone else's bookmark is a 404, so the API does not reveal that it exists. Client errors are logged at `notice`. nginx answers its own 400, 413 and 503 with a Problem Details body from named locations; only a URL it cannot parse, such as `/api/%`, still gets its HTML 400.

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

## Metrics

The metrics answer questions that lead to a decision; a metric with no such question is left out.

| Question | Decision |
|---|---|
| How many upstream calls do snapshots save, per source, for past days and for today and later? | Whether the weather `refreshAfter` (1 h) fits and whether prefetch pays off. Past days should be 100% from storage. |
| How fast do we use the providers' limits? | When to move from `DEMO_KEY` to an own NASA key, and which `--delay` to give prefetch. |
| How often do we serve `stale` or `missing`? | Whether the 10-minute pause and the breaker thresholds fit. |
| Does the breaker open again and again, or not at all? | The 60 s and 15 min openings; how reliable a provider is. |
| How long does each source take against the 3.5 s deadline? | Whether `max_duration: 3` and the 3.5 s deadline fit. |

**Catalogue.** Prefix `almanac_`, the unit in the name, `_total` for counters. Every label has a fixed, short list of values, well under 300 series in all.

| Metric | Type | Labels | Recorded in |
|---|---|---|---|
| `http_requests_total` | counter | `route` (or `unmatched`), `method`, `code` (unknown ones as `other`) | a `kernel.terminate` listener, after the response is sent |
| `http_request_duration_seconds` | histogram | `route` | the same listener, from `REQUEST_TIME_FLOAT` |
| `snapshot_decisions_total` | counter | `source`, `decision` (`final`, `fresh`, `wait`, `fetch`), `day` (`past`, `today`, `future`) | the aggregator, for each refresh decision |
| `upstream_fetches_total` | counter | `source`, `result` (`ok` or the failure kind) | the aggregator, for each settled fetch |
| `upstream_fetch_duration_seconds` | histogram | `source` | the same place, retries included |
| `briefing_source_status_total` | counter | `source`, `status` (`ok`, `stale`, `missing`, `not_applicable`) | the briefing controller, also before a 502 |
| `circuit_transitions_total` | counter | `source`, `to` (`open`, `half_open`, `closed`) | `CircuitLog`, next to the log line |
| `messenger_failed_messages` | gauge | none | read from the `failed` transport at scrape time |

Upstream buckets are `0.1, 0.25, 0.5, 1, 1.5, 2, 3, 3.5` seconds: 1 s is the connect limit, 3 s `max_duration`, 3.5 s the deadline, so a fetch above 3.5 s means the deadline did not work. HTTP buckets run from 25 ms to 30 s, with 1 s and 4 s for the SLO and 30 s for `request_terminate_timeout`. There are no summaries, because they cannot be added up across processes.

**No unbounded labels.** Dates, coordinates, `scope_key`, user and token ids, IP addresses, request ids, bookmark ids, raw paths, the upstream HTTP status and exception messages never become labels: each would add a series per value, and coordinates and addresses are personal data the logs already mask. Those details are in the JSON logs. An e2e test sends requests with dates, coordinates, UUIDs and a request id, then checks that every label on `/metrics` is a catalogue name with a bounded value.

**Storage and failures.** Modules record through the `App\Shared\Metrics\Metrics` port (`increment`, `observe`); a phpat rule keeps `promphp/prometheus_client_php` and its adapter inside `Shared\Metrics`. The values live in Redis, which `php` and the worker share; APCu would not be shared, because the worker runs in its own container. The store uses the app's connection and database under the library's `PROMETHEUS_` prefix: a second persistent connection that selected another database came back from phpredis's pool to the app on a later request, and the breaker then wrote into the metrics database. Any failure of the store drops the write and logs one warning; metrics then rest for 30 seconds, which under php-fpm means the rest of the request and in the worker half a minute, as the breaker does. Redis evicts only keys with a TTL (`volatile-lru`), so a full Redis drops cache entries and rate-limit windows, never an open circuit or a metric.

**`/metrics`** is served by a second nginx server on port 9100, which compose does not publish and which allows only private networks. It passes the FastCGI parameter `ALMANAC_METRICS_SCRAPE`, which no HTTP header can set; without it the controller answers 404, and the public port answers 404 before reaching PHP. The route is not part of the public API, so `openapi.yaml` leaves it out.

**SLOs, over 28 days.**
- Availability of briefings: responses other than 5xx among all except 4xx, target 99.5% (an error budget of about 3.4 hours). The three providers have no SLA, and stale data and partial 200s hide outages, so 99.9% would be fiction.
- Response time: 95% under 1 s, the stored path, and 99% under 4 s, which shows whether the 3.5 s deadline really cuts the wait.
- Completeness: `ok` and `stale` among `ok`, `stale` and `missing`, target 99%. It is the only SLI that sees one source failing behind a 200.

**Alerts** (`docker/prometheus/alerts.yml`, each with `promtool` tests for when it fires and when it stays quiet):
1. The scrape of `nginx:9100` fails for 2 minutes: nginx or php-fpm is down, and then the app counts nothing, so no other alert could fire.
2. The briefing error budget burns 14.4 times too fast in both the last hour and the last 5 minutes, with at least 20 requests an hour that are not 4xx.
3. Any `misconfigured` or `invalid_payload` answer within 15 minutes: a bad key or User-Agent, or a changed format.
4. A circuit opens at least three times in an hour.
5. Failed prefetch messages wait for 15 minutes.

The library creates a series at its first count, with no zero before it, and Redis restarts with none. So the counter alerts compare with the value 15 minutes or an hour ago, or with 0 when the series did not exist then, instead of `increase()`, which would miss the first event. Status codes outside the known list are labelled by class (`5xx`), so they still count as errors. Prometheus keeps 30 days, which covers the 28-day SLO window. Refresh decisions and fetches include prefetch, which runs the same aggregator; the served status counts only briefing responses.

There is no alert on an open circuit: without traffic a circuit stays half-open until the next request, so it would ring all night.

**Not chosen:** a Prometheus bundle (a second dependency with its own names), the OpenTelemetry SDK (about ten packages, a PECL extension and a collector; the way forward once there is tracing), statsd (an exporter container).

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
- Behind a proxy, `SYMFONY_TRUSTED_PROXIES` must be set, or all clients share one limit.
