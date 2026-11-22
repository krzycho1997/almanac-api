# Almanac API

A REST API that builds a briefing for any date from 1995-06-16 to 15 days after today. A briefing combines three public sources:

- NASA's Astronomy Picture of the Day (APOD),
- Wikipedia's On This Day (events, births, deaths),
- Open-Meteo weather for a place: the archive for days six or more days ago, the forecast API for the last five days and up to 14 days ahead.

Every source is normalised into our own JSON format and stored in PostgreSQL. If a source fails, the briefing still answers with the others and says which one is missing. Users are created from the console, get a Bearer token, and keep bookmarks of single discoveries (a picture, an event, a person).

Stack: PHP 8.5, Symfony 8.1, Doctrine ORM 3 with Migrations, PostgreSQL 18, Redis 8, nginx and php-fpm, Docker Compose. The design notes are in [docs/SPEC.md](docs/SPEC.md); the API contract is [docs/openapi.yaml](docs/openapi.yaml).

## Setup

Requirements: Docker with Compose v2. Nothing else on the host.

```sh
git clone https://github.com/krzycho1997/almanac-api.git
cd almanac-api
docker compose up --wait
curl http://127.0.0.1:8080/api/health
```

Get a free NASA key (by email, at https://api.nasa.gov) before the first briefing and put it in `.env.dev.local`, as described below. Without it the dev stack uses `DEMO_KEY`, whose quota is shared by everyone on your IP and is often used up already.

The first start builds the image, runs `composer install`, waits for PostgreSQL and runs the migrations. It then starts these services:

| Service | What it does |
|---|---|
| `nginx` | the only published port, `127.0.0.1:8080` (change it with `HTTP_PORT`) |
| `php` | php-fpm with the app; runs the migrations on start |
| `worker` | `messenger:consume async`, the same image; waits for the migrations, never runs them |
| `database` | PostgreSQL 18 |
| `redis` | Redis 8: circuit breaker state, rate-limit counters, `cache.app` and the metrics; no persistence, and only keys with a TTL are evicted |

`compose.yaml` uses full image names and SELinux volume labels, so it also runs with `podman compose`. CI starts it from a clean checkout with rootless Podman 5 (5.7 at the time of writing) and podman-compose 1.6 on Ubuntu 26.04 and runs `bin/smoke-test` (health, a briefing, a new user's token, and a bookmark created, listed and deleted). Podman 4.x, as on Ubuntu 24.04, ships an aardvark-dns that loses DNS between containers at random ([containers/aardvark-dns#389](https://github.com/containers/aardvark-dns/issues/389)), so use Podman 5.

### Environment and the NASA key

`.env` is committed and holds only non-secret defaults; `.env.example` lists the same keys (`bin/check-env-keys` keeps them in sync). Local overrides go in files that git ignores.

`NASA_API_KEY` is empty in `.env`. The dev environment uses `DEMO_KEY` from `.env.dev`. `DEMO_KEY` allows about 30 requests an hour and 50 a day per IP. When NASA answers 429, the APOD circuit opens until `Retry-After`, or for 15 minutes without it. Until then the briefing shows APOD as missing (or serves stored data as stale), and `/api/health` shows `"apod": "open"`.

As of 22.11.2026, `api.nasa.gov/planetary/apod` answers every date, with a valid key too, with a stand-in entry titled "NASA Science" with the NASA logo instead of the picture of the day. The app recognises it as a placeholder, so the briefing shows APOD as `failed` with `error: invalid_payload` while the other sources answer. That is the upstream's answer, not an app error.

To use your own key, put it in `.env.dev.local`. It must be this file and not `.env.local`, because `.env.dev` is loaded after `.env.local` and would override it.

```sh
echo 'NASA_API_KEY=your-key' > .env.dev.local
docker compose restart redis worker
```

php-fpm reads the `.env` files on every request in dev, so only the long-running worker needs the restart. Redis keeps no data on disk, so restarting it also closes an APOD circuit that `DEMO_KEY` opened.

In dev, error bodies also carry `class` and `trace`, because debug is on; add `| jq 'del(.trace,.class)'` to a curl to read them. The prod image and the tests send Problem Details without them.

## Endpoints

| Method and path | Auth | What it does |
|---|---|---|
| `GET /api/briefings/{date}?lat=&lon=` | public, 60 requests a minute per client | briefing for a date; `lat` and `lon` are optional and go together |
| `GET /api/bookmarks?page=&limit=` | Bearer | the user's bookmarks, newest first (`limit` up to 100, default 20) |
| `POST /api/bookmarks` | Bearer | bookmark a discovery: `{"discoveryId": "…"}` |
| `DELETE /api/bookmarks/{id}` | Bearer | remove one of the user's bookmarks |
| `GET /api/health` | public | liveness and the state of each source's circuit |
| `GET /api/docs.json` | public | the OpenAPI 3.0.3 document as JSON |

Status codes: 400 for a date that is not `YYYY-MM-DD` or a malformed JSON body, 401 without a valid token, 404 for an unknown discovery or someone else's bookmark, 409 for a discovery already bookmarked, 415 for a body that is not JSON, 422 for a date outside the window, bad coordinates, a missing or invalid `discoveryId` or a body field the request does not define, 429 over the rate limit (with `Retry-After`), 502 only when no source has data. Every error from the app is `application/problem+json` (RFC 9457) with a `detail` that says what to change. A validation error (400 or 422 with `violations[]`, one per invalid field) has Symfony's `type: https://symfony.com/errors/validation` and `title: Validation Failed`; every other error has `type: about:blank` and the status name as `title`, and every response has an `X-Request-Id` header. nginx answers its own errors in the same format: 413 for a body over 16 KB, 400 for headers it cannot read, 503 when php-fpm does not answer. A URL nginx itself cannot parse, such as `/api/%`, still gets nginx's HTML 400.

### curl examples

Briefing for the default place (Warsaw, set in `.env`) and for given coordinates (rounded to 2 decimal places):

```sh
curl -s http://127.0.0.1:8080/api/briefings/2024-03-15
curl -s 'http://127.0.0.1:8080/api/briefings/2024-03-15?lat=48.86&lon=2.35'
```

In the answer, `sources` has the status of each source (`ok`, `failed` or `not_applicable`, with `stale: true` when old data is served after a failed refresh), and `missing` lists the sources without data. Wikipedia is slow for a day its CDN has not cached yet (up to about 5.5 s for one list), so it gets 8 seconds; if it takes longer still, On This Day is `failed` with `error: unavailable` and is fetched again 10 minutes later. That is the intended behaviour, not a bug.

Health and the API document:

```sh
curl -s http://127.0.0.1:8080/api/health
curl -s http://127.0.0.1:8080/api/docs.json
```

Create a user and print its token. With `-q` the command prints only the token. Running it again for the same address revokes the old tokens and prints a new one. `--ttl` sets the lifetime (`12h`, `30d`; default `30d`).

```sh
TOKEN=$(docker compose exec -T php bin/console almanac:user:create you@example.com -q)
```

Bookmarks. Each item in a briefing (`apod`, `events[]`, `births[]`, `deaths[]`) has a `discoveryId`. The example uses `jq`; you can also copy an id by hand.

```sh
DISCOVERY=$(curl -s http://127.0.0.1:8080/api/briefings/2024-03-15 | jq -r '.deaths[0].discoveryId')

BOOKMARK=$(curl -s -X POST http://127.0.0.1:8080/api/bookmarks \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d "{\"discoveryId\": \"$DISCOVERY\"}" | jq -r .id)

curl -s 'http://127.0.0.1:8080/api/bookmarks?page=1&limit=20' -H "Authorization: Bearer $TOKEN"

curl -s -i -X DELETE "http://127.0.0.1:8080/api/bookmarks/$BOOKMARK" -H "Authorization: Bearer $TOKEN"
```

The `POST` answers 201 with the bookmark, the delete answers 204.

### Prefetch

Synchronous, with a progress bar and a table of problems at the end. The exit code is 1 if any date had a problem.

```sh
docker compose exec php bin/console almanac:prefetch 2024-03-01 2024-03-07
docker compose exec php bin/console almanac:prefetch 2024-03-01 2024-03-07 --lat=48.86 --lon=2.35
```

`--delay=120000` (milliseconds) waits after each date that called upstream, which keeps `DEMO_KEY` under its hourly limit. With `--async` it spaces the queued messages instead: date number n becomes available n × delay after queueing. A range is at most 366 days.

Asynchronous, through Symfony Messenger with the Doctrine transport: the command queues one message per date and returns. The `worker` service fetches them.

```sh
docker compose exec php bin/console almanac:prefetch 2024-03-01 2024-03-31 --async
docker compose logs -f worker
docker compose exec php bin/console messenger:failed:show
```

A date whose source is down, rate limited or behind an open circuit goes back to the queue and is retried when that source can be called again, for up to a day. Messages that fail because of a bug or the database are retried 3 times and then moved to the `failed` queue.

## Monitoring (optional)

A bonus beyond the brief, off by default:

```sh
docker compose --profile monitoring up -d
```

Grafana opens at http://127.0.0.1:3000 with one dashboard and no login (viewer only), and Prometheus at http://127.0.0.1:9090 with five alerts. The app serves its metrics on `nginx:9100/metrics`, inside the compose network only; the public port answers 404. The metrics, their labels, the SLOs and the alerts are described in [docs/SPEC.md](docs/SPEC.md#metrics).

## Checks and tests

Everything runs in the `php` container, against PostgreSQL. The `e2e` suite sends real requests to nginx, so the stack must be running.

```sh
docker compose exec php composer check:fast   # composer validate, env keys, YAML lint, CS-Fixer, container lint, schema mapping, PHPStan (level 9, with the phpat rules), composer audit, unit tests
docker compose exec php composer check        # check:fast, then a fresh test database and every suite except live
docker compose exec php composer test         # unit, integration, functional and e2e
docker compose exec php composer test:unit    # also test:integration, test:functional (with e2e)
docker compose exec php composer test:live    # calls the real APIs; run by hand, not part of the other suites or CI
docker compose exec php vendor/bin/phpunit --testsuite briefing   # one module: briefing, identity, bookmark, shared
docker compose exec php vendor/bin/phpunit --testsuite phpstan    # the architecture rules against their fixtures
docker compose run --rm --no-deps redocly lint docs/openapi.yaml  # OpenAPI lint, as in CI
bin/smoke-test http://127.0.0.1:8080                               # on the host, needs curl and jq; COMPOSE='podman compose' for Podman; leaves a smoke-test user and discovery
```

`composer cs-fix` fixes the style. After the first start has installed `vendor/`, `composer test:unit` also runs without the database: `docker compose run --rm --no-deps php composer test:unit`.

Git hooks, once per clone: `git config core.hooksPath .githooks`. `commit-msg` checks the Conventional Commits format and length; `pre-push` runs `check:fast` in the container (Docker only).

CI (`.github/workflows/ci.yaml`) runs `check:fast`, the Redocly lint, a build and check of the prod image, a clean `docker compose up --wait` with all tests, `promtool` checks and alert tests for the monitoring profile, the same stack under Podman with `bin/smoke-test`, a check of the commit subjects, gitleaks on the history and hadolint. A tag such as `v1.0.0` (matching `v[0-9]+.[0-9]+.[0-9]+`) runs `release.yaml`, which publishes the prod image to GHCR with an SBOM.

## Core and bonus

The brief asks for three sources normalised into one format, stored data, a briefing that survives a failing source, users with bookmarks, Docker (and Podman), tests and this README. That core is the sources, the snapshot table with its refresh rule, the briefing and bookmark endpoints, the console user command and the test suite.

Everything else goes beyond the brief, and each piece can go on its own:

| Bonus | What it adds | Cost of removing it |
|---|---|---|
| Circuit breaker in Redis (Lua) | stops calling a provider that is down or out of quota, across dates and processes | each date and place still waits 10 minutes after a failure, but a provider that is down or out of quota is called again for every new date or cell, and `Retry-After` is ignored |
| Async prefetch (Messenger) | warms many dates in the background | prefetch stays synchronous |
| phpat architecture rules | module boundaries and layers checked in PHPStan | boundaries kept by review only |
| OpenAPI validation in every functional test | the contract cannot drift | the document can go stale |
| Monitoring profile (Prometheus, Grafana) | metrics, SLOs, five alerts | nothing at runtime; it is off by default |
| Release pipeline (SBOM, Trivy, Grype) | a scanned image on GHCR | images built by hand |

If I had to cut, the release pipeline and the monitoring profile go first, then phpat; the breaker is the last bonus I would drop.

## Code map

- **How is a briefing built?** `Briefing/Controller/BriefingController` calls `BriefingAggregator::briefingFor()`, which reads stored snapshots through `SnapshotRefresher` and `Repository/SourceSnapshotRepository`, fetches what is due in one stream with `Source/ConcurrentFetcher`, saves the results through `SnapshotRefresher::saveAndReread()`, and `Mapper/BriefingResponseMapper` builds the response.
- **When is a source fetched again?** `Briefing/RefreshPolicy::decide()` with each source's `refreshAfter()` (`Source/Source.php`); the upserts in `SourceSnapshotRepository` change a row only when the new data or attempt is newer, so a slower parallel request cannot overwrite it.
- **How do I add a fourth source?** Implement `Source/Source.php` in a new `Source/<Name>/` directory, following `Source/OpenMeteo/` (client, response, mapper, definition); its scoped HTTP client goes into `config/packages/framework.yaml`, and the other places are listed under "With more time". Provider knowledge stays in that directory (PHPStan's `ProviderLiteralRule` rejects provider hosts elsewhere); where shared code needs it, a port lies outside, as `Discovery/PersonIdentity` with `Source/OnThisDay/WikipediaPersonIdentity`, and `Discovery/PersonReference` owns the person key formats.
- **Who may see or delete a bookmark?** `Bookmark/BookmarkService` with `Bookmark/Repository/BookmarkRepository`, whose reads and deletes always filter by the owner, so someone else's bookmark is a 404.
- **Where is a circuit's state?** `Briefing/CircuitBreaker/RedisCircuitBreakerStore`: per source, the instant until which it is open and the count of recent failures.
- **Model, Contract, Dto, Entity?** `Model` is the internal data of a source as stored in a snapshot, `Contract` is what other modules may use, `Dto` is the shape of API requests and responses, and `Entity` is what Doctrine stores.

## Decisions and trade-offs

- **Sources.** Each source has its own client that maps the answer into our internal model; raw payloads are never proxied. On This Day is three calls (`selected`, `births`, `deaths`) instead of `/all/`: for 15 March `/all/` is 1.4 MB, a third of it events and holidays we never show. The calls run in parallel, 3 seconds each (8 for Wikipedia, whose uncached answers take up to about 5.5 s) and 8.5 seconds in all; a network error or a 500, 502, 503 or 504 is retried once (for Wikipedia only if the first attempt failed fast enough to leave time before the deadline); a 429, or any answer with `Retry-After`, never is.
- **Modules.** `Briefing`, `Identity` and `Bookmark` talk only through `Contract` namespaces, checked by phpat. The cost is no foreign keys between modules: a bookmark keeps the user and discovery ids as plain `uuid` columns.
- **Snapshots.** One row per source and scope, the internal model as JSONB. A stored past day never calls upstream again, even when its weather was a forecast (`kind: forecast`); APOD and On This Day are final once fetched, and weather for today and later is refreshed after an hour. A failure waits 10 minutes, and the last good data is served meanwhile as `stale`, or the source is listed in `missing`.
- **Location.** APOD and On This Day are one row per day for everyone; weather one per day and 0.01° cell (about 1 km). A coarser grid would store less but describe another place: Open-Meteo fits its data to the elevation of the exact point, and 0.1° put Tromsø 690 m higher, in polar day, on a night the sun set at 00:22. The monitoring profile counts the upstream calls that new places cause.
- **Native upserts.** Doctrine maps everything, but three writes use `INSERT … ON CONFLICT`, because the API and the worker write the same rows at once and neither ORM nor DBAL has an atomic upsert. A `WHERE` guard keeps newer data, and a duplicate bookmark is a clean 409.
- **Circuit breaker.** Three outages within 30 minutes of each other, with no success between, open a source for 60 seconds; a 429 until `Retry-After`. A 400 or an invalid payload neither counts nor resets the count, and one trial call closes the circuit. It fails open when Redis is down.
- **Errors.** Problem Details with a `detail` that says what to change: validation errors keep Symfony's validation type with `violations[]`, every other error has `about:blank` and the status name. OpenAPI is written by hand and every functional test is validated against it.
- **Rate limit.** 60 briefings a minute per client network (IPv4 address or IPv6 /64).
- **Logs.** One-line JSON; listed context keys only; tokens, keys, emails and coordinates masked.

The reasoning behind each part is in [docs/SPEC.md](docs/SPEC.md).

## Schema

Five tables, each owned by one module and created only by Doctrine Migrations (details in [docs/SPEC.md](docs/SPEC.md#storage)):

- `briefing_source_snapshot`: one row per source and scope (`2024-03-15`, `03-15`, `2024-03-15@52.23,21.01`), the internal model in a JSONB `payload`, the last attempt's status and error.
- `briefing_discovery`: everything that can be bookmarked. The id is a UUIDv5 of a stable key such as `apod:2024-03-15` or `person:Q1048`, so a briefing knows every `discoveryId` without a query.
- `identity_user` and `identity_access_token`: users with a unique email, and tokens stored only as sha256 with an expiry; revoking a token deletes its row.
- `bookmark`: a unique constraint on `(user_id, discovery_id)` and an index on `(user_id, created_at DESC, id DESC)` for the newest-first pages.

Users, tokens and bookmarks have UUIDv7 ids. Instants are stored in UTC.

## Security

Tokens are 32 random bytes; only their sha256 is stored. They expire (30 days by default), and running the user command again deletes the old ones. Unknown, expired and revoked tokens get the same 401. The token is a Bearer secret, so outside localhost the API must sit behind a reverse proxy that terminates TLS and sends HSTS; only nginx is published, on `127.0.0.1`, with security headers and a 16 KB body limit. `composer audit` runs in every check, CI scans the full history with gitleaks, and a release is published only after Trivy and Grype pass on the image.

## Notes

- **Two parallel first requests for the same date and place both call upstream.** The guarded upsert keeps the newer data; a lock per scope would need `symfony/lock`, a new dependency.
- **Requests and responses.** Bodies and query strings are mapped into readonly DTOs with `#[MapRequestPayload]` and `#[MapQueryString]` and checked by the Validator; a body field the DTO does not define is a 422. Responses are separate DTOs built by a mapper per module and serialized by Symfony's `#[Serialize]` attribute, so an entity never reaches the serializer.
- **Rate limit behind a proxy.** The key is `REMOTE_ADDR`; `X-Forwarded-For` is ignored. Locally every request through the published port comes from the compose gateway, so the whole host shares one limit. Behind a load balancer, set `SYMFONY_TRUSTED_PROXIES`.
- **`LogLeakTest`** checks the scenarios it lists: a briefing with coordinates whose source fails, a 404 whose `Referer` is a briefing address with coordinates, the user command, and requests with a valid and a wrong token. It shows that no coordinates, email, token or token hash reach the logs, as prod configures them, in those cases. It is not a proof that nothing can ever leak.
- **Framework.** Symfony is used as a set of components: only what the code calls is required, one package at a time, without packs, so there is no Twig, forms, profiler or maker. `composer show --direct` lists the whole dependency surface. Inside each module the layout stays plain Symfony (controllers, services, entities, repositories). The phpat rules in `tests/Architecture/` keep module boundaries and layers, and each rule is tested against fixtures that break it.
- **Commits and branches.** In day-to-day team work I would squash-merge feature branches. Here the history is the deliverable, so each slice has its own branch (`type/NN-description`) and PR, merged with a merge commit, and each commit is one green step. Commits follow Conventional Commits with a fixed list of scopes; the `commit-msg` hook and CI check them.

## With more time

- Wikipedia Page Summary as a fourth source, if a briefing should carry more than On This Day's summaries. It touches about a dozen places: a `Source/` directory (client, response DTO, mapper, definition), a payload model, cases in `SourceName`, `SourceKey` and `SourcePayloadCodec`, the briefing's response DTOs and mappers, the discovery extractor if its items can be bookmarked, `openapi.yaml`, and fixtures with tests. The schema does not change, and the breaker, health check and metrics pick the source up from `SourceName`.
- A lock per scope, so parallel first requests call upstream once (it needs `symfony/lock`), and `Cache-Control` derived from the refresh policy, so clients can cache final days.
- A separate circuit for each Open-Meteo address, because an archive outage now also stops forecasts, and a manual reset command instead of restarting Redis.
- A one-off fetch of observed weather for a past day that has only a forecast. Stored past days must not call upstream again, so this needs an agreed exception to that rule.
- Clean-up of old weather rows for coordinates other than the default place (a forecast stays forever once its day has passed), and one call budget for Open-Meteo shared by the API and the worker for places not yet stored, so many new places and a prefetch cannot use up its daily limit together.
- Short-lived tokens with refresh, or an OIDC provider through Symfony's `access_token` handlers, because a 30-day Bearer token is a long-lived secret.
- Redis and PostgreSQL probes in `/api/health` and the request id passed into Messenger messages, so an outage shows up before users report it and a queued date can be traced to its command.
- A stable event key (year and linked pages instead of a text hash), because an edit on Wikipedia now creates a new discovery and old bookmarks keep pointing at the old text.
- Splitting the root of `src/Briefing` (27 files) into `Snapshot/`, `Failure/` and `Messenger/`, because `MessageHandler/` also holds a stamp, a listener and a retry strategy that are not handlers.
- A PostgreSQL schema per module with its own database user, so the boundaries hold in the database too; it is the step before splitting `Briefing` into a service behind `DiscoveryCatalog`.

## Use of AI

I used Claude Code as the main coding tool. I designed the architecture, wrote the plan, made every decision and reviewed every change, and I am responsible for the code. Mistakes I caught along the way include a snapshot query that matched sources and keys as a cross product instead of exact pairs, a 3-second limit that cut off Wikipedia's uncached answers (2.5 to 5.4 seconds), and a coarser weather grid that would have reported another place's elevation and daylight (Tromsø in polar day on a night with a sunset). I'm happy to go through any line of it, or change it, live.
