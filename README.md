# Almanac API

A REST API that builds a briefing for any date from 1995-06-16 to 15 days after today. A briefing combines three public sources:

- NASA's Astronomy Picture of the Day (APOD),
- Wikipedia's On This Day (events, births, deaths),
- Open-Meteo weather (archive for past days, forecast for today and later) for a place.

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

The first start builds the image, runs `composer install`, waits for PostgreSQL and runs the migrations. Then it starts these services:

| Service | What it does |
|---|---|
| `nginx` | the only published port, `127.0.0.1:8080` (change it with `HTTP_PORT`) |
| `php` | php-fpm with the app; runs the migrations on start |
| `worker` | `messenger:consume async`, the same image; waits for the migrations, never runs them |
| `database` | PostgreSQL 18 |
| `redis` | Redis 8: circuit breaker state, rate-limit counters, `cache.app`; no persistence |

`compose.yaml` uses full image names and SELinux volume labels, so it is meant to run with `podman compose` too. The Podman run on a clean machine is still to be done.

### Environment and the NASA key

`.env` is committed and holds only non-secret defaults; `.env.example` lists the same keys (`bin/check-env-keys` keeps them in sync). Local overrides go in files that git ignores.

`NASA_API_KEY` is empty in `.env`. The dev environment uses `DEMO_KEY` from `.env.dev`. `DEMO_KEY` allows about 30 requests an hour and 50 a day per IP. When NASA answers 429, the APOD circuit opens until `Retry-After`, or for 15 minutes without it. Until then the briefing shows APOD as missing (or serves stored data as stale), and `/api/health` shows `"apod": "open"`.

To use your own key (free, by email, at https://api.nasa.gov), put it in `.env.dev.local`. It must be this file and not `.env.local`, because `.env.dev` is loaded after `.env.local` and would override it.

```sh
echo 'NASA_API_KEY=your-key' > .env.dev.local
docker compose restart worker
```

php-fpm reads the `.env` files on every request in dev, so only the long-running worker needs the restart.

In dev, error bodies also carry `class` and `trace`, because debug is on. The prod image and the tests send Problem Details without them.

## Endpoints

| Method and path | Auth | What it does |
|---|---|---|
| `GET /api/briefings/{date}?lat=&lon=` | public, 60 requests a minute per client | briefing for a date; `lat` and `lon` are optional and go together |
| `GET /api/bookmarks?page=&limit=` | Bearer | the user's bookmarks, newest first (`limit` up to 100, default 20) |
| `POST /api/bookmarks` | Bearer | bookmark a discovery: `{"discoveryId": "…"}` |
| `DELETE /api/bookmarks/{id}` | Bearer | remove one of the user's bookmarks |
| `GET /api/health` | public | liveness and the state of each source's circuit |
| `GET /api/docs.json` | public | the OpenAPI 3.0.3 document as JSON |

Status codes: 400 for a date that is not `YYYY-MM-DD` or a malformed JSON body, 401 without a valid token, 404 for an unknown discovery or someone else's bookmark, 409 for a discovery already bookmarked, 415 for a body that is not JSON, 422 for a date outside the window, bad coordinates or bad input, 429 over the rate limit (with `Retry-After`), 502 only when no source has data. Every error is `application/problem+json` (RFC 9457), and every response has an `X-Request-Id` header.

### curl examples

Briefing for the default place (Warsaw, set in `.env`) and for given coordinates (rounded to 2 decimal places):

```sh
curl -s http://127.0.0.1:8080/api/briefings/2024-03-15
curl -s 'http://127.0.0.1:8080/api/briefings/2024-03-15?lat=48.86&lon=2.35'
```

In the answer, `sources` has the status of each source (`ok`, `failed` or `not_applicable`, with `stale: true` when old data is served after a failed refresh), and `missing` lists the sources without data.

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

curl -s -X POST http://127.0.0.1:8080/api/bookmarks \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d "{\"discoveryId\": \"$DISCOVERY\"}"

curl -s 'http://127.0.0.1:8080/api/bookmarks?page=1&limit=20' -H "Authorization: Bearer $TOKEN"

curl -s -i -X DELETE http://127.0.0.1:8080/api/bookmarks/BOOKMARK_ID -H "Authorization: Bearer $TOKEN"
```

`BOOKMARK_ID` is the `id` from the `POST` answer or from the list. The delete answers 204.

### Prefetch

Synchronous, with a progress bar and a table of problems at the end. The exit code is 1 if any date had a problem.

```sh
docker compose exec php bin/console almanac:prefetch 2024-03-01 2024-03-07
docker compose exec php bin/console almanac:prefetch 2024-03-01 2024-03-07 --lat=48.86 --lon=2.35
```

`--delay=120000` (milliseconds) waits after each date that called upstream, which keeps `DEMO_KEY` under its hourly limit. A range is at most 366 days.

Asynchronous, through Symfony Messenger with the Doctrine transport: the command queues one message per date and returns. The `worker` service fetches them.

```sh
docker compose exec php bin/console almanac:prefetch 2024-03-01 2024-03-31 --async
docker compose logs -f worker
docker compose exec php bin/console messenger:failed:show
```

A date whose source is down, rate limited or behind an open circuit goes back to the queue and is retried when that source can be called again, for up to a day. Messages that fail because of a bug or the database are retried 3 times and then moved to the `failed` queue.

## Checks and tests

Everything runs in the `php` container, against PostgreSQL. The `e2e` suite sends real requests to nginx, so the stack must be running.

```sh
docker compose exec php composer check:fast   # composer validate, env keys, YAML lint, CS-Fixer, container lint, schema mapping, PHPStan (level 9, with the phpat rules), composer audit, unit tests
docker compose exec php composer check        # check:fast, then a fresh test database and all suites
docker compose exec php composer test         # unit, integration, functional and e2e
docker compose exec php composer test:unit    # also test:integration, test:functional (with e2e)
docker compose exec php composer test:live    # calls the real APIs; not part of the other suites
docker compose exec php vendor/bin/phpunit --testsuite briefing   # one module: briefing, identity, bookmark, shared
docker compose exec php vendor/bin/phpunit --testsuite phpstan    # the architecture rules against their fixtures
docker compose run --rm --no-deps redocly lint docs/openapi.yaml  # OpenAPI lint, as in CI
```

`composer cs-fix` fixes the style. `composer test:unit` also runs without the database: `docker compose run --rm --no-deps php composer test:unit`.

Git hooks, once per clone: `git config core.hooksPath .githooks`. `commit-msg` checks the Conventional Commits format and length; `pre-push` runs `check:fast` in the container (Docker only).

CI (`.github/workflows/ci.yaml`) runs `check:fast`, the Redocly lint, a build and check of the prod image, a clean `docker compose up --wait` with all tests, a check of the commit subjects, gitleaks on the history and hadolint. A `v*` tag runs `release.yaml`, which publishes the prod image to GHCR with an SBOM.

## Decisions and trade-offs

- **Sources.** APOD, On This Day and Open-Meteo, each behind its own client that maps the upstream answer into an internal model; the API never proxies raw payloads. Page Summary was left out because On This Day already carries page summaries. The three calls run concurrently with a 3-second budget each, one retry on 5xx and none on 429.
- **Modules.** A modular monolith: `Briefing`, `Identity`, `Bookmark` and a small `Shared`. Modules talk only through `Contract` namespaces. Rules written with phpat run inside PHPStan, cannot be ignored, and also fix the layers (edge, services, model). The cost: no foreign keys or Doctrine relations between modules. A bookmark stores user and discovery ids as plain `uuid` columns, and the contract checks the discovery before the insert.
- **Snapshots and refresh.** One row per source and scope in `briefing_source_snapshot`, with the internal model as JSONB. A past date with stored data never calls upstream again, even when the stored weather was a forecast (the answer says `kind: forecast`). APOD and On This Day are final once fetched; weather for today and later is refreshed after an hour. A failed fetch is retried after 10 minutes. Until then the last good data is served with `stale: true`, or the source is listed in `missing`.
- **Native upserts.** The ORM maps every entity, all reads and the schema. Three writes use `INSERT … ON CONFLICT` on the Doctrine connection: snapshots, discoveries and the bookmark insert. The API and the worker write the same rows at once, and neither the ORM nor DBAL has an atomic upsert. A unique violation would also close the EntityManager. Guards in the `WHERE` clause stop an older result from overwriting a newer one. A duplicate bookmark is a clean 409.
- **Circuit breaker.** One circuit per source, kept in Redis and shared by `php` and `worker`. Three outages open it for 60 seconds; a 429 opens it until `Retry-After`. One trial call closes it again. If Redis is down, it fails open: calls go through and one warning is logged.
- **Messenger.** `--async` queues one message per date on the Doctrine transport. Upstream failures are stored as snapshots, not as failed messages. A date that is only waiting (pause, `Retry-After`, open circuit) is redelivered at that moment.
- **OpenAPI.** Written by hand. Every request and response in every functional test is validated against it, and a test checks that routes and paths match both ways. An undocumented status or field fails the build.
- **Rate limit.** A sliding window of 60 requests a minute per client network (IPv4 address or IPv6 /64), shared by all dates. Counters live in Redis and fail open.
- **Logs.** One-line JSON on stderr. Our channels accept only listed context keys, and the app's redacting JSON formatter masks tokens, keys, emails and coordinates on every handler. User ids in logs are UUIDs, never emails.

## Notes

- **Days stored before discoveries existed have no discovery ids.** Past days are never fetched again, so a database filled before that step needs a reset (`docker compose down -v`) or a one-off backfill.
- **Two parallel first requests for the same date and place both call upstream.** The guarded upsert keeps the newer data; a lock per scope would need `symfony/lock`, a new dependency.
- **Rate limit behind a proxy.** The key is `REMOTE_ADDR`; `X-Forwarded-For` is ignored. Locally every request through the published port comes from the compose gateway, so the whole host shares one limit. Behind a load balancer, set `SYMFONY_TRUSTED_PROXIES`.
- **`LogLeakTest`** checks the scenarios it lists: a briefing with coordinates whose source fails, a 404 whose `Referer` is a briefing address with coordinates, the user command, and requests with a valid and a wrong token. It shows that no coordinates, email, token or token hash reach the logs, as prod configures them, in those cases. It is not a proof that nothing can ever leak.
- **Framework.** Symfony is used as a set of components: only what the code calls is required, one package at a time, without packs, so there is no Twig, forms, profiler or maker. `composer show --direct` lists the whole dependency surface. Inside each module the layout stays plain Symfony (controllers, services, entities, repositories). The phpat rules in `tests/Architecture/` keep module boundaries and layers, and each rule is tested against fixtures that break it.
- **Commits and branches.** In day-to-day team work I would squash-merge feature branches. Here the history is the deliverable, so each slice has its own branch (`type/NN-description`) and PR, merged with a merge commit, and each commit is one green step. Commits follow Conventional Commits with a fixed list of scopes; the `commit-msg` hook and CI check them.

## With more time

- Wikipedia Page Summary as a fourth source: a new `Source/` directory, a case in `SourceName` and a migration of the `CHECK` constraint.
- A lock per scope, so parallel first requests call upstream once, and `Cache-Control` derived from the refresh policy.
- A separate circuit for each Open-Meteo address (forecast and archive share one today) and a manual reset command.
- A one-off fetch of observed weather for a past day that has only a forecast, after agreeing on it, since stored past days must not call upstream again.
- Short-lived tokens with refresh and revocation, or an OIDC provider through Symfony's `access_token` handlers.
- Redis and PostgreSQL probes in `/api/health`, metrics per source, and the request id passed into Messenger messages.
- A stable event key (year and linked pages instead of a text hash), so an edit on Wikipedia does not create a new discovery.
- A PostgreSQL schema per module with its own database user, which is the step before splitting `Briefing` into a service behind `DiscoveryCatalog`.

## Use of AI

I designed the architecture, wrote the plan and decided every trade-off. An AI coding assistant (Claude Code) wrote the code, tests and documentation under that direction, and AI reviewers audited each change before and after it was written. Every change also had to pass the test suite and static analysis before it was merged.
