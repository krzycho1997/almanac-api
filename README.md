# Almanac API

[![CI](https://github.com/krzycho1997/almanac-api/actions/workflows/ci.yaml/badge.svg)](https://github.com/krzycho1997/almanac-api/actions/workflows/ci.yaml)

Almanac API builds a briefing for any date from 1995-06-16 to 15 days after today. It draws on three public sources: NASA's Astronomy Picture of the Day (APOD), Wikipedia's On This Day and Open-Meteo weather. I normalise each one into a single JSON format and store it in PostgreSQL, and when a source fails, the briefing still answers and says what is missing. Users get a Bearer token from a console command and can bookmark single discoveries: a picture, an event or a person.

It runs on PHP 8.5, Symfony 8.1, Doctrine ORM 3, PostgreSQL 18, Redis 8 and nginx with php-fpm, all started by Docker Compose.

## Features

- A briefing for any date from three sources, each normalised into one JSON format, with a status per source and a list of what is missing when a source fails.
- Snapshots in PostgreSQL: a stored past day never calls upstream again, and today and later follow a refresh policy.
- Users with Bearer tokens created from the console, and bookmarks of single discoveries, paginated, private to their owner and never duplicated.
- A prefetch command for a range of dates, run directly or queued for a worker through Symfony Messenger.
- Problem Details errors, rate limiting, and an OpenAPI document that every functional test is checked against.
- A circuit breaker in Redis that stops calling a provider that is down or out of quota.
- Optional extras that stay out of the way: a monitoring profile with Prometheus and Grafana (off unless started with `--profile monitoring`), architecture rules that run inside PHPStan, and release and deploy workflows that run only in GitHub Actions.

## Quick start

You need Docker with Compose v2, or Podman 5 with podman-compose, and `curl` and `jq` on the host for the examples.

```sh
git clone https://github.com/krzycho1997/almanac-api.git && cd almanac-api
docker compose up --wait
curl http://127.0.0.1:8080/api/health
```

With Podman 5, run `podman compose up --build --detach` instead and wait until the health URL answers. The first start builds the image, installs the dependencies and runs the migrations; on my laptop that took about a minute with the base images already pulled. Health should answer:

```json
{"status":"ok","checks":{"circuits":{"apod":"closed","onThisDay":"closed","weather":"closed"}}}
```

The dev stack uses NASA's shared `DEMO_KEY`, which is often used up. A free key needs only an email address at [api.nasa.gov](https://api.nasa.gov). Put it in `.env.dev.local` (git ignores it; this command overwrites the file) and restart Redis and the worker, so a circuit that `DEMO_KEY` opened is closed too:

```sh
echo 'NASA_API_KEY=your-key' > .env.dev.local
docker compose restart redis worker
```

The API contract is [docs/openapi.yaml](docs/openapi.yaml), also served at `http://127.0.0.1:8080/api/docs.json`. For PhpStorm or httpYac there are ready-made requests in [http/](http/).

## Try it

Ask for a briefing at the default place (Warsaw, from `ALMANAC_DEFAULT_*` in `.env`) or at given coordinates, which are rounded to two decimals:

```sh
curl -s http://127.0.0.1:8080/api/briefings/2024-03-15
curl -s 'http://127.0.0.1:8080/api/briefings/2024-03-15?lat=48.86&lon=2.35'
```

A shortened answer looks like this. Every item carries the `discoveryId` you bookmark, and `sources` and `missing` say what each source did:

```json
{
  "date": "2024-03-15",
  "location": {"latitude": 52.23, "longitude": 21.01, "name": "Warsaw", "isDefault": true},
  "apod": null,
  "events": [{"discoveryId": "e9b46a81-debf-5313-adf4-562da5ece32d", "year": 1990,
              "text": "Iraqi authorities hanged Iranian freelance reporter Farzad Bazoft on charges of spying for Israel.",
              "pages": [{"title": "Farzad Bazoft", "url": "https://en.wikipedia.org/wiki/Farzad_Bazoft", "thumbnail": "…"}]}],
  "births": [{"discoveryId": "071bcebd-7021-5cb1-b2a8-c571ae75aa8f", "year": 2005, "name": "Rodri Mendoza", "description": "Spanish footballer", "…": "…"}],
  "weather": {"kind": "final", "temperatureMin": 1.7, "temperatureMax": 10.0, "description": "Overcast", "…": "…"},
  "sources": {
    "apod": {"status": "failed", "error": "invalid_payload", "lastAttemptAt": "2026-11-22T17:09:04Z"},
    "onThisDay": {"status": "ok", "fetchedAt": "2026-11-21T16:26:50Z", "stale": false},
    "weather": {"status": "ok", "fetchedAt": "2026-11-21T16:26:50Z", "stale": false}
  },
  "missing": ["apod"]
}
```

APOD is missing on purpose here. As of 22.11.2026, NASA's APOD API answers every date, with a valid key too, with a "NASA Science" stand-in instead of the picture. The app recognises it as an invalid payload, so every briefing currently shows the partial-data path at work.

Create a user (with `-q` the command prints only the token), then add, list and delete a bookmark. The add answers 201 and the delete 204:

```sh
TOKEN=$(docker compose exec -T php bin/console almanac:user:create you@example.com -q)
DISCOVERY=$(curl -s http://127.0.0.1:8080/api/briefings/2024-03-15 | jq -r '.events[0].discoveryId')
BOOKMARK=$(curl -s -X POST http://127.0.0.1:8080/api/bookmarks -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d "{\"discoveryId\": \"$DISCOVERY\"}" | jq -r .id)
curl -s 'http://127.0.0.1:8080/api/bookmarks?page=1&limit=20' -H "Authorization: Bearer $TOKEN"
curl -s -i -X DELETE "http://127.0.0.1:8080/api/bookmarks/$BOOKMARK" -H "Authorization: Bearer $TOKEN"
```

To break a source yourself, add `OPEN_METEO_ARCHIVE_BASE_URI=http://127.0.0.1:9` to `.env.dev.local` and ask for a date and place that are not stored yet. It has to be a new one, because a stored past day never calls upstream again. Remove the line afterwards.

```sh
curl -s 'http://127.0.0.1:8080/api/briefings/2023-07-05?lat=12.35&lon=56.79' | jq -c '.sources.weather, .missing'
```

```text
{"status":"failed","error":"unavailable","lastAttemptAt":"…"}
["apod","weather"]
```

To click through the same requests in PhpStorm or httpYac, copy `http/http-client.private.env.json.dist` to `http/http-client.private.env.json`, paste a token, pick the `dev` environment, and run `01-public.http`, then `02-bookmarks.http`.

## Endpoints

| Method and path | Auth | What it does |
|---|---|---|
| `GET /api/briefings/{date}?lat=&lon=` | public, 60 requests a minute per client | the briefing for a date; `lat` and `lon` go together |
| `GET /api/bookmarks?page=&limit=` | Bearer | the user's bookmarks, newest first (`limit` up to 100, default 20) |
| `POST /api/bookmarks` | Bearer | bookmark a discovery: `{"discoveryId": "…"}`; answers 201 |
| `DELETE /api/bookmarks/{id}` | Bearer | remove one of the user's bookmarks; answers 204 |
| `GET /api/health` | public | liveness and the state of each source's circuit |
| `GET /api/docs.json` | public | the OpenAPI 3.0.3 document |

Every error comes back as `application/problem+json` with a `detail` that tells the client what to change, and validation errors list each invalid field in `violations[]`.

| Status | When |
|---|---|
| 400 | a date that is not `YYYY-MM-DD`, a malformed JSON body, or headers nginx cannot read |
| 401 | no valid token |
| 404 | an unknown discovery or bookmark, or someone else's bookmark |
| 409 | a discovery already bookmarked |
| 413 | a body over 16 KB |
| 415 | a body that is not JSON |
| 422 | a date outside the window, bad coordinates, a bad `page` or `limit`, a missing or invalid `discoveryId`, or a body field the request does not define |
| 429 | over the rate limit, with `Retry-After` |
| 502 | no source has data |
| 503 | php-fpm does not answer |

## Tests

Everything runs in the `php` container against a real PostgreSQL, and the e2e suite calls nginx, so the stack must be running.

| Command | What it runs |
|---|---|
| `docker compose exec php composer check` | `check:fast` (validation, CS-Fixer, PHPStan level 9, `composer audit`, unit tests), then a fresh test database and the integration, functional and e2e suites |
| `docker compose exec php composer test` | the unit tests, then a fresh test database and the integration, functional and e2e suites |
| `docker compose exec php composer test:unit` | the unit tests only; no database needed |
| `docker compose exec php composer test:db` | recreates and migrates the test database; run it once before a single suite or module |
| `docker compose exec php vendor/bin/phpunit --testsuite bookmark` | one module: `briefing`, `identity`, `bookmark` or `shared` |
| `bin/smoke-test http://127.0.0.1:8080` | on the host: health, a briefing, a new user's token, a bookmark added, listed and deleted |

If you want one place to start, the briefing tests with a failing, mocked source are in [tests/Briefing/Functional/BriefingControllerTest.php](tests/Briefing/Functional/BriefingControllerTest.php), and the bookmark endpoint tests are in [tests/Bookmark/Functional/](tests/Bookmark/Functional/).

## Decisions

**Which APIs.** I picked APOD, On This Day and Open-Meteo because together they give a picture, a piece of history and the weather for any date. For On This Day I make three calls (`selected`, `births`, `deaths`) instead of one to `/all/`: for 15 March `/all/` is 1.4 MB, and a third of it is data the briefing never shows. I left out Page Summary, because On This Day already carries each page's summary.

**Structure.** It is a modular monolith. `Briefing`, `Identity` and `Bookmark` talk to each other only through their `Contract` namespaces, and phpat checks that inside PHPStan. Each provider has its own directory, `src/Briefing/Source/<Provider>/`, with its client, response, mapper and definition, so swapping a provider touches one directory. DTOs only carry fields, and mappers turn them into domain objects and back. The price is that there are no foreign keys between modules: a bookmark keeps the user and discovery ids as plain `uuid` columns.

**Schema.** There is one snapshot row per source and scope, holding the internal model as JSONB, since a payload is always read whole. The scope is `2024-03-15` for APOD, `03-15` for On This Day and `2024-03-15@52.23,21.01` for weather. A discovery id is a UUIDv5 of a stable key, so a briefing knows every bookmarkable id without a query, and bookmarks are unique on `(user_id, discovery_id)`.

**Caching and refresh.** A stored past day is final and never calls upstream again. For today and later, APOD and On This Day are final once fetched, and weather is refreshed after an hour. I store weather per 0.01° cell, about 1 km. A coarser grid would store less, but it would describe another place, because Open-Meteo fits its data to the point's elevation: at 0.1°, Tromsø ended up 690 m higher and in polar day, on a night the sun set at 00:22.

**Upstream errors.** The sources are fetched in parallel, with a hard deadline of 8.5 seconds. A timeout, network error or 5xx is retried once; a 429 never is. A failure is stored and the source waits 10 minutes before the next try, while the last good data is served as `stale`. On top of that, a circuit breaker stops calling a provider after repeated outages, or until its `Retry-After`.

**Trade-offs I accepted.** Three writes use native `INSERT … ON CONFLICT`, because the API and the worker write the same rows and Doctrine has no atomic upsert. Two parallel first requests for one date still both call upstream, since a lock would mean a new dependency. I write the OpenAPI document by hand and let every functional test enforce it, rather than generating it from annotations.

## Assumptions

- Warsaw is the default place; `lat` and `lon` pick another one.
- Dates run from the first APOD picture (1995-06-16) to 15 days after today. Weather only reaches 14 days ahead, so beyond that it is `not_applicable`.
- The paths have no version prefix. A breaking change would go under `/api/v2`, with `Deprecation` and `Sunset` headers on the old paths, and `info.version` in the OpenAPI document follows SemVer.

## With more time

- A global budget for cold fetches, shared by the API and the worker, so many new dates and places cannot use up a provider's daily quota; and clean-up of weather rows for places other than the default.
- A lock per scope, so parallel first requests call upstream once, and `Cache-Control` derived from the refresh policy, so clients can cache final days.
- Splitting the root of `src/Briefing` (28 files) into `Snapshot/`, `Failure/` and `Messenger/`, because `MessageHandler/` also holds a stamp, a listener and a retry strategy that are not handlers.
- A stable event key (the year and linked pages instead of a text hash), because a Wikipedia edit now creates a new discovery and old bookmarks keep pointing at the old text.
- Short-lived tokens with refresh, or an OIDC provider through Symfony's `access_token` handlers, because a 30-day Bearer token is a long-lived secret.
- Redis and PostgreSQL probes in `/api/health`, so an outage shows up before users report it.

## Code map

If you want to read the code, these are good places to start:

- A briefing request goes `BriefingController` → `BriefingAggregator` → `SnapshotRefresher` and `SourceSnapshotRepository` → `Source/ConcurrentFetcher` → `Mapper/BriefingResponseMapper`.
- The refresh rule is `RefreshPolicy::decide()` with each source's `refreshAfter()`; the upserts in `SourceSnapshotRepository` make sure the newer data wins.
- A circuit's state lives in `CircuitBreaker/RedisCircuitBreakerStore`.
- Bookmark ownership is enforced in `BookmarkService` and `BookmarkRepository`, which always filter by the owner.
- On naming: `Model` is a source's stored data, `Contract` is what other modules may use, `Dto` holds the API's request and response shapes, and `Entity` holds the Doctrine rows.

Adding a fourth source is described under Design notes, in "Modules, layers and providers".

## Use of AI

I planned and designed the project and reviewed every change; Claude Code wrote most of the code.

## Running and operating

<details>
<summary><strong>Configuration and the NASA key</strong></summary>

`.env` is committed and holds only non-secret defaults; `.env.example` lists the same keys (`bin/check-env-keys` keeps them in sync). Local overrides go in files that git ignores.

`NASA_API_KEY` is empty in `.env`; the dev environment uses `DEMO_KEY` from `.env.dev`, which allows about 30 requests an hour and 50 a day per IP. When NASA answers 429, the APOD circuit opens until `Retry-After` (at least 60 seconds), or for 15 minutes without it; until then the briefing shows APOD as missing (or serves stored data as stale), and `/api/health` shows `"apod": "open"`.

Your own key must go into `.env.dev.local`, not `.env.local`, because `.env.dev` is loaded after `.env.local` and would override it. php-fpm reads the `.env` files on every request in dev, so only the long-running worker needs a restart. Redis keeps no data on disk, so restarting it also closes an APOD circuit that `DEMO_KEY` opened.

In dev, error bodies also carry `class` and `trace`, because debug is on; add `| jq 'del(.trace,.class)'` to a curl to read them. The prod image and the tests send Problem Details without them.

</details>

<details>
<summary><strong>Services and Podman</strong></summary>

| Service | What it does |
|---|---|
| `nginx` | the only published port, `127.0.0.1:8080` (change it with `HTTP_PORT`) |
| `php` | php-fpm with the app; runs the migrations on start |
| `worker` | `messenger:consume async`, the same image; waits for the migrations, never runs them |
| `database` | PostgreSQL 18 |
| `redis` | Redis 8: circuit breaker state, rate-limit counters, `cache.app` and the metrics; no persistence, and only keys with a TTL are evicted |

`compose.yaml` uses full image names and SELinux volume labels, so it also runs with `podman compose`. CI starts it from a clean checkout with rootless Podman 5 (5.7 at the time of writing) and podman-compose 1.6.0 on Ubuntu 26.04 and runs `bin/smoke-test`. Podman 4.x, as on Ubuntu 24.04, ships an aardvark-dns that loses DNS between containers at random ([containers/aardvark-dns#389](https://github.com/containers/aardvark-dns/issues/389)), so use Podman 5. podman-compose has no `--wait`, so after `podman compose up --build --detach` wait until `/api/health` answers.

</details>

<details>
<summary><strong>Prefetch and Messenger</strong></summary>

```sh
docker compose exec php bin/console almanac:prefetch 2024-03-01 2024-03-07
docker compose exec php bin/console almanac:prefetch 2024-03-01 2024-03-07 --lat=48.86 --lon=2.35
docker compose exec php bin/console almanac:prefetch 2024-03-01 2024-03-31 --async
docker compose logs -f worker
docker compose exec php bin/console messenger:failed:show
```

The synchronous run shows a progress bar and a table of problems at the end, and exits with 1 if any date had a problem. Its input is validated with `#[MapInput]`: strict `Y-m-d`, the same window as the API, `to >= from`, at most 366 days. `Prefetcher` calls the same aggregator as the controller, one transaction per date. `--delay=120000` (milliseconds) waits after each date that called upstream, which keeps `DEMO_KEY` under its hourly limit; it sleeps through `ClockInterface`, so tests do not wait.

`--async` sends one scalar-only message per date to `doctrine://default` and returns; with `--delay`, date number n becomes available n × delay after queueing. The table comes from a migration, and LISTEN/NOTIFY wakes the `worker` service. Upstream failures are stored as snapshots, as in the synchronous run. A date that waits for a 10-minute pause, a `Retry-After` or an open circuit is redelivered at that moment, plus up to 30 seconds of jitter, for up to a day. Failures that waiting cannot fix are acknowledged with the source missing. Bugs and database errors are retried after 1, 4 and 16 seconds, then go to the `failed` queue.

`Prefetcher` queues dates through the `PrefetchQueue` port; `Messenger\MessengerPrefetchQueue` and `Messenger\PrefetchDateMessageMapper` hold both directions of the queue format, and `MessageHandler` keeps the handler, the retry strategy and its listener next to `CodeFailureStamp`, whose class name is stored with queued envelopes.

</details>

<details>
<summary><strong>Monitoring and metrics</strong></summary>

```sh
docker compose --profile monitoring up -d
```

Grafana opens at `http://127.0.0.1:3000` with one dashboard and no login (viewer only), and Prometheus at `http://127.0.0.1:9090` with five alerts.

The metrics answer questions that lead to a decision; a metric with no such question is left out.

| Question | Decision |
|---|---|
| How many upstream calls do snapshots save, per source, for past days and for today and later? | Whether the weather `refreshAfter` (1 h) fits and whether prefetch pays off. Past days should be 100% from storage. |
| How fast do we use the providers' limits? | When to move from `DEMO_KEY` to your own NASA key, and which `--delay` to give prefetch. |
| How often do we serve `stale` or `missing`? | Whether the 10-minute pause and the breaker thresholds fit. |
| Does the breaker open again and again, or not at all? | The 60 s and 15 min openings; how reliable a provider is. |
| How long does each source take against its limit and the 8.5 s deadline? | Whether `max_duration` (3 s, Wikipedia 8 s) and the deadline fit. |

Prefix `almanac_`, the unit in the name, `_total` for counters. Every label has a fixed, short list of values, well under 300 series in all.

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

Upstream buckets are `0.1, 0.25, 0.5, 1, 1.5, 2, 3, 5, 8, 8.5` seconds: 1 s is the connect limit, 3 s `max_duration`, 5 s splits Wikipedia's uncached answers, 8 s its `max_duration` and 8.5 s the deadline, so a fetch above 8.5 s means the deadline did not work. HTTP buckets run from 25 ms to 30 s, with 1 s and 9 s for the SLO and 30 s for `request_terminate_timeout`. There are no summaries, because they cannot be added up across processes.

Dates, coordinates, `scope_key`, user and token ids, IP addresses, request ids, bookmark ids, raw paths, the upstream HTTP status and exception messages never become labels: each would add a series per value, and coordinates and addresses are personal data the logs already mask. Those details are in the JSON logs. An e2e test sends requests with dates, coordinates, UUIDs and a request id, then checks that every label on `/metrics` is a catalogue name with a bounded value.

Modules record through the `App\Shared\Metrics\Metrics` port (`increment`, `observe`); a phpat rule keeps `promphp/prometheus_client_php` and its adapter inside `Shared\Metrics`. The values live in Redis, which `php` and the worker share; APCu would not be shared across containers. The store uses the app's connection and database under the library's `PROMETHEUS_` prefix: a second persistent connection that selected another database came back from phpredis's pool to the app on a later request, and the breaker then wrote into the metrics database. Any failure of the store drops the write and logs one warning; metrics then rest for 30 seconds, which under php-fpm means the rest of the request and in the worker half a minute. Redis evicts only keys with a TTL (`volatile-lru`), so a full Redis drops cache entries and rate-limit windows, never an open circuit or a metric.

`/metrics` is served by a second nginx server on port 9100, which compose does not publish and which allows only private networks. It passes the FastCGI parameter `ALMANAC_METRICS_SCRAPE`, which no HTTP header can set; without it the controller answers 404, and the public port answers 404 before reaching PHP. The route is not part of the public API, so `openapi.yaml` leaves it out.

#### SLOs, over 28 days

- Availability of briefings: responses other than 5xx among all except 4xx, target 99.5% (an error budget of about 3.4 hours). The three providers have no SLA, and stale data and partial 200s hide outages, so 99.9% would be fiction.
- Response time: 95% under 1 s, the stored path, and 99% under 9 s, which shows whether the 8.5 s deadline really cuts the wait; only a day not yet stored can take that long, and prefetch keeps those out of the user's way.
- Completeness: `ok` and `stale` among `ok`, `stale` and `missing`, target 99%. It is the only SLI that sees one source failing behind a 200.

#### Alerts

Each alert in `docker/prometheus/alerts.yml` has `promtool` tests for when it fires and when it stays quiet:

1. The scrape of `nginx:9100` fails for 2 minutes: nginx or php-fpm is down, and then the app counts nothing.
2. The briefing error budget burns 14.4 times too fast in both the last hour and the last 5 minutes, with at least 20 requests an hour that are not 4xx.
3. Any `misconfigured` or `invalid_payload` answer within 15 minutes: a bad key or User-Agent, or a changed format.
4. A circuit opens at least three times in an hour.
5. Failed prefetch messages wait for 15 minutes.

The library creates a series at its first count, with no zero before it, and Redis restarts with none, so the counter alerts compare with the value 15 minutes or an hour ago, or with 0 when the series did not exist then, instead of `increase()`, which would miss the first event. Status codes outside the known list are labelled by class (`5xx`), so they still count as errors. Prometheus keeps 30 days, which covers the 28-day window. Refresh decisions and fetches include prefetch, which runs the same aggregator; the served status counts only briefing responses. There is no alert on an open circuit: without traffic a circuit stays half-open until the next request, so it would ring all night.

Not chosen: a Prometheus bundle (a second dependency with its own names), the OpenTelemetry SDK (about ten packages, a PECL extension and a collector; the way forward once there is tracing), statsd (an exporter container).

</details>

<details>
<summary><strong>Checks, CI, release and deploy</strong></summary>

```sh
docker compose exec php composer check:fast   # composer validate, env keys, YAML lint, CS-Fixer, container lint, schema mapping, PHPStan level 9 with the phpat rules, composer audit, unit tests
docker compose exec php composer cs-fix       # fixes the style
docker compose exec php composer test:live    # calls the real APIs; run by hand, not part of the other suites or CI
docker compose exec php vendor/bin/phpunit --testsuite phpstan    # the custom PHPStan and architecture rules against their fixtures
docker compose run --rm --no-deps redocly lint docs/openapi.yaml  # OpenAPI lint, as in CI
docker compose run --rm --no-deps php composer test:unit          # unit tests without the database, once vendor/ exists
```

`bin/smoke-test` takes `COMPOSE='podman compose'` for Podman and leaves a smoke-test user and discovery behind. Git hooks, once per clone: `git config core.hooksPath .githooks`; `commit-msg` checks the Conventional Commits format and length, and `pre-push` runs `check:fast` in the container (Docker only).

CI (`.github/workflows/ci.yaml`) runs `check:fast`, the Redocly lint, a build and check of the prod image, a clean `docker compose up --wait` with all tests and `bin/check-php-restart`, `promtool` checks and alert tests for the monitoring profile, the same stack under Podman with `bin/smoke-test`, a check of the commit subjects, gitleaks on the history and hadolint. A tag such as `v1.0.0` runs `release.yaml`, which publishes the prod image to GHCR with an SBOM. After CI passes on `main`, `deploy.yaml` builds the prod image of that commit, checks it, gates it on Trivy, attests it, pushes it to GHCR and deploys it by digest to a test server over SSH, with a key the server restricts to that one deploy command.

Each slice of work has its own branch (`type/NN-description`) and PR, merged with a merge commit, and each commit is one green step, so the history shows how the project grew. Commits follow Conventional Commits with a fixed list of scopes; the `commit-msg` hook and CI check them.

Symfony is used as a set of components: only what the code calls is required, one package at a time, without packs, so there is no Twig, forms, profiler or maker. `composer show --direct` lists the whole dependency surface.

</details>

## Design notes

<details>
<summary><strong>Modules, layers and providers</strong></summary>

| Module | Owns | Tables | Contract |
|---|---|---|---|
| `Briefing` | sources, snapshots, refresh policy, circuit breaker, discoveries, prefetch | `briefing_source_snapshot`, `briefing_discovery` | `DiscoveryCatalog`, `DiscoveryView`, `DiscoveryId`, `DiscoveryType` |
| `Identity` | users, tokens, Bearer authentication | `identity_user`, `identity_access_token` | `AuthenticatedUser`, `UserId` |
| `Bookmark` | bookmarks | `bookmark` | none |
| `Shared` | Problem Details, request id, logging, health, API document | none | `HealthCheck` port, uid base type |

A module uses only its own classes, `Shared`, the `Contract` namespace of any module, and code outside `App`; `Shared` uses no module. `config/` is the composition root. Foreign keys exist only inside a module: `bookmark.user_id` and `bookmark.discovery_id` are plain `uuid` columns, `DiscoveryCatalog::find()` runs before the insert, and users and discoveries are never deleted. A bookmark whose discovery the catalog does not know breaks an invariant, so listing throws a `LogicException` (a logged 500) instead of leaving the entry out while `total` still counts it. Account deletion would need an event from Identity that Bookmark handles. In return, `Briefing` could become a separate service by turning `DiscoveryCatalog` into an HTTP client, with no change in `Bookmark`.

Inside a module the layout is plain Symfony, with no separate domain layer:

- **Edge** (controllers, commands, message handlers, queue adapters in `Messenger`, value resolvers, security, health checks, HTTP listeners): input and output only, with no repositories, Doctrine or upstream clients. `AccessTokenHandler` is the one exception.
- **Services** in the module root (`BriefingAggregator`, `Prefetcher`, `AccessTokenIssuer`, `BookmarkService`) know nothing about HTTP. Status codes come from `framework.exceptions`, bodies from mappers.
- **Model and contracts** are readonly data, free of Doctrine, HTTP and entities. Contracts depend only on contracts.
- **Sources**: each `Source/<Name>/` has a client, a typed upstream response, a mapper and a definition, and is private to itself. Response DTOs use their own enums, so a domain enum cannot leak into the API.

These are phpat rules in `tests/Architecture/`. They run inside PHPStan and cannot be ignored or baselined. A rule that selects nothing passes silently, so every rule has fixtures that break it and fixtures that are allowed, and a test expects the exact list of violations. `CLAUDE.md` lists what phpat cannot see, for review.

**Providers stay in their directory.** Code belongs to a provider when it would change if that provider were swapped for another with the same content: addresses and hosts, the response format, authentication, the dates it covers, how it identifies things, its attribution and the quirks of its data. Everything else changes only with the product: the briefing's sections, `SourceName`, the model, the formats of discovery and snapshot keys, and the public API.

- `src/Briefing/Source/<Provider>/` (`Apod`, `OnThisDay`, `OpenMeteo`) holds its client, response, mapper and definition (the `*Source` class); a directory without a definition is not a provider. The definition builds the snapshot scope itself, with `SnapshotScope::dated()` or `undated()`.
- Its scoped HTTP clients, with their time limits and headers, sit in `config/packages/framework.yaml`; they share one set of options through YAML anchors and get the retry from `http_client.default_options`. That retry also covers every other consumer of the base `http_client`, so a new caller that must not retry needs its own scoped client with `retry_failed: false`.
- Where shared code needs a provider's view of something, a port lies outside the provider: `Discovery\PersonIdentity` says how a provider names a person, and `Source/OnThisDay/WikipediaPersonIdentity`, bound with `#[AsAlias]`, reads Wikipedia's page titles and Wikidata items from the fields already stored. `Discovery\PersonReference` owns the person key formats (catalogued, page, unlinked), and `StoredDiscoveryKeysTest` pins the keys and ids of stored payloads. The model calls the item `catalogueId`; `#[SerializedName('wikidataId')]` keeps the key that stored snapshots use.

Two checks keep it so. The PHPStan rule `ProviderLiteralRule` rejects, anywhere in `src/` outside a provider directory, a `/wiki/` path, a Wikipedia, Wikimedia, Wikidata, NASA or Open-Meteo host, and any `http(s)` address except the hosts allowed in `phpstan.dist.neon` (IETF, RFC Editor, Symfony's error types, Creative Commons). It reads plain and interpolated strings, cannot be ignored or baselined, and refuses a source root that does not exist. `ProviderClientsConfigTest` checks that the upstream stream is an alias of the base `http_client` and that no config file other than `framework.yaml` names a provider client outside its test section.


**Input and output.** Input goes through the Validator: value resolvers and `#[MapQueryString]` for URLs, `#[MapRequestPayload]` DTOs for bodies (unknown fields rejected), `#[MapInput]` for the console. DTOs and queued messages only carry fields: a rule across fields is a class constraint with its own validator in the module's `Validator` directory (`ValidPrefetchRange`, `ValidTokenLifetime`), and an input mapper turns the validated fields into domain objects with `convertTo…()` methods (`BookmarkRequestMapper`, `BriefingQueryMapper` and `PrefetchInputMapper`, which read coordinates through `CoordinateMapper`, and `CreateUserInputMapper`). Value objects keep their own rules: `TokenLifetime` parses only 1 h to 365 days. Responses are separate DTOs built by a mapper per module and serialized by Symfony's `#[Serialize]` attribute, so an entity never reaches the serializer. The PHPStan rule `DataCarrierRule` rejects, in a class under `Dto\` or `Message\`, any method but the constructor and console `#[Interact]` methods, any property hook, any trait, and any constructor statement other than assigning a parameter, a constant or a literal to a property.


**Adding a source.** A fourth source, such as Wikipedia Page Summary, touches about a dozen places: a `Source/<Name>/` directory (client, response DTO, mapper and definition), a payload model, cases in `SourceName`, `SourceKey` and `SourcePayloadCodec`, a scoped client in `framework.yaml`, the briefing's response DTOs and mappers, the discovery extractor if its items can be bookmarked, `openapi.yaml`, and fixtures with tests. The schema does not change, and the breaker, the health check and the metrics pick the source up from `SourceName`.

</details>

<details>
<summary><strong>Briefing flow and failures</strong></summary>

1. The date must be `Y-m-d` and a real calendar day (else 400), from 1995-06-16 to today + 15 days in `APP_TIMEZONE` (else 422). `lat` and `lon` are validated as plain decimal strings, both or neither, and rounded to two decimals; weather is stored per day and that cell.
2. Each source says whether it covers the date: APOD from 1995-06-16 by New York's publication day; Open-Meteo from 1940-01-01 to today + 14 days, through the archive for days six or more days ago and the forecast API for the rest. Otherwise the source is `not_applicable`, computed per request and never stored.
3. The aggregator reads the stored snapshots, asks the refresh policy what to fetch, and asks each source's circuit for permission.
4. Permitted sources are fetched concurrently in one HttpClient `stream()` on `app.briefing.upstream_stream`, an alias of the base `http_client`; one `RetryableHttpClient` streams the responses of every scoped client, and `ConcurrentFetcherWiringTest` checks that in dev and prod. Each client has a 3-second `max_duration`, one retry on network errors and 500, 502, 503 or 504, no retry on 429 or with `Retry-After`, and no redirects; the whole fetch ends after 8.5 seconds. Wikipedia gets 8 seconds because its CDN answers a day it has not cached slowly: twelve uncached On This Day lists measured on 2026-11-21 took 1.6 to 5.4 seconds, median about 2.4, and with 3 seconds new dates often came back `unavailable`. After a fast 5xx Wikipedia is still retried; after a timeout or a slow failure the deadline cuts the retry off.
5. Results are mapped into the internal model, discoveries are extracted, and snapshots and discoveries are saved in one transaction.
6. The response is built from storage: 200 if any source has data, otherwise 502. `sources` holds each source's status (`ok`, `failed` or `not_applicable`, with `stale: true` when old data is served after a failed refresh), and `missing` lists the sources without data.

A failed source is data, not an exception. Each failure has a kind: `unavailable` (timeout, network, 5xx), `rate_limited` (429), `rejected` (another 4xx), `misconfigured` (401, 403, 3xx) or `invalid_payload` (including APOD's "NASA Science" stand-in). The kind sets the log level and whether the breaker counts it.

Text from providers reaches the API as plain text: APOD's HTML through `textContent`, and Wikipedia's titles, texts and descriptions through `PlainText::fromMarkup()`, which decodes entities and then removes tags and comments, so a vandalised description cannot carry markup into a briefing or a bookmark. Each On This Day list is capped at `ALMANAC_OTD_LIMIT` (20) items. Provider addresses must use `https`; any other scheme counts as an unusable address.

</details>

<details>
<summary><strong>Refresh policy</strong></summary>

`RefreshPolicy` is a pure function of the snapshot state, the source's `refreshAfter`, the date and now:

| Snapshot | Date | Decision |
|---|---|---|
| none | any | fetch |
| has data | before today | final, never fetched again |
| last attempt failed under 10 minutes ago | today or later, or past without data | wait |
| last attempt failed 10 or more minutes ago | same | fetch |
| ok, no `refreshAfter` (APOD, On This Day) | today or later | final |
| ok, `refreshAfter` 1 hour (weather) | today or later | fetch when older than an hour |

The past-date rule comes first, so a stored forecast for a day whose last refresh failed is not retried forever. Weather records which data it was when fetched: `final` (the archive, six or more days ago), `provisional` (the forecast API's last five days) or `forecast`. A stored past day is not fetched again, so a day stored as a forecast keeps `kind: forecast`. When a refresh fails and old data exists, it is served with `stale: true`, the original `fetchedAt`, the `error` and `lastAttemptAt`. Final data is never stale.

</details>

<details>
<summary><strong>Circuit breaker and rate limiting</strong></summary>

The refresh policy protects one snapshot; the breaker protects an upstream, so that an outage or `DEMO_KEY`'s limit is not hit again by every new date and queued message.

- There is one circuit per source in Redis, shared by `php` and `worker`. One `open_until` key holds the state: missing means closed, a future instant open, a past one half-open.
- Only outages count (`unavailable`, `rate_limited`). Three of them, each within 30 minutes of the last and with no successful call in between, open the circuit for 60 seconds. A failure that is not an outage (`rejected`, `misconfigured`, `invalid_payload`) neither counts nor resets the count, so a 503, a 400 and a 503 still add up. A 429 opens it until `Retry-After`, but for at least 60 seconds, or for 15 minutes without it.
- Half-open allows one trial, claimed with `SET NX PX` for 30 seconds; only the trial closes or reopens the circuit. Opening and claiming are single Lua calls, safe between processes.
- A refused source makes no request and saves nothing. It serves stored data as stale, or is `failed` with `error: unavailable`.
- If Redis fails, the breaker fails open and logs one warning per request or message. Lost Redis data only closes circuits. `/api/health` shows each circuit and always answers 200.

`#[RateLimit]` on the briefing allows 60 requests a minute in a sliding window. The key is the client network (IPv4 address, IPv6 /64), not network plus path, so all dates share one limit, and it is checked after the date resolver, so a malformed date is not counted. Counters are in Redis with no lock, so a burst of parallel clients can pass up to about 2.5 times the limit, and a Redis outage lets traffic through. The key is `REMOTE_ADDR`; no trusted proxies are set, so `X-Forwarded-For` cannot change it. Locally every request through the published port comes from the compose gateway, so the whole host shares one limit; behind a load balancer, set `SYMFONY_TRUSTED_PROXIES`. Above the limit the API answers 429 with `Retry-After`. Bookmarks rely on the token.

</details>

<details>
<summary><strong>Storage and schema</strong></summary>

Five tables, each owned by one module and created only by Doctrine Migrations, which the `php` container runs on start:

- **`briefing_source_snapshot`**: one row per `(source, scope_key)`. The key is `2024-03-15` for APOD, `03-15` for On This Day, and `2024-03-15@52.23,21.01` for weather. `status` and `error` describe the last attempt; `payload` (JSONB) is the last good data as our internal model, never the upstream body.
- **`briefing_discovery`**: what can be bookmarked. The id is a UUIDv5 of a stable key (`apod:{date}`, `event:{year}-{mm-dd}:{text hash}`, `person:{Wikidata item}`), so a briefing knows every `discoveryId` without a query. Rows are inserted once and never changed.
- **`identity_user`** and **`identity_access_token`**: users with a unique email, and tokens stored only as sha256 with an expiry; revoking a token deletes its row.
- **`bookmark`**: unique `(user_id, discovery_id)`, an index on `(user_id, created_at DESC, id DESC)` for newest-first pages, offset pagination.

Users, tokens and bookmarks have UUIDv7 ids; the user, bookmark and discovery ids have their own Doctrine types. Instants are UTC. A new migration starts from `doctrine:migrations:diff`, or from `doctrine:migrations:generate` when the change is one the mapping cannot express, so every version number is the generator's timestamp and two branches cannot pick the same one.

**Rules in PHP, not in CHECKs.** The database holds the types, keys and unique indexes; the other rules live where the rows are built, so they fail in the code that broke them, with a test, and the schema stays what the mapping describes; a migration dropped the earlier CHECK constraints. `DiscoveryRecord` refuses an empty title, a key that does not name something after its type and a picture of the day without a date. `SnapshotScope` refuses a date for On This Day and requires one for the other sources. Enums limit sources, statuses, errors and types; the id classes fix the UUID versions. `SourceResult` can only be ok with a payload or failed with an error, `saveResult()` refuses a not-applicable result, and the two snapshot upserts write those columns together. A native write that skips these classes is not checked.

**Native upserts.** Doctrine handles all entities, reads and the schema, and every entity has its own `ServiceEntityRepository`. Three writes use `INSERT … ON CONFLICT` on the repository's connection: snapshots, discoveries and bookmarks. The API and the worker write the same keys at once; a read-then-write is not atomic, `MERGE` still fails on a concurrent insert, and a unique violation closes the EntityManager. The snapshot upsert is guarded: a success replaces the payload only if it was fetched later, a failure never touches the payload, and `last_attempt_at` never moves back. Discoveries are inserted sorted by key, so parallel batches lock in the same order, and a batch carries each key once: when a day lists the same person or event twice, the first record wins. Snapshots are re-read with `Query::HINT_REFRESH`, because the identity map would return the state from before the native write.

</details>

<details>
<summary><strong>Errors and Problem Details</strong></summary>

The status codes are in the table under Endpoints. Every response has an `X-Request-Id` header.

Every error from the app is `application/problem+json` from Symfony's `ProblemNormalizer`, whatever the `Accept` header: 404 and 405 from routing, 401 from the firewall, and `violations[]` for validation. A validation error (400 or 422) keeps Symfony's `type: https://symfony.com/errors/validation` and `title: Validation Failed` with one entry in `violations[]` per invalid field. For every other error a decorator sets `type: about:blank` with the status name as `title` (RFC 9457 §4.2.1) instead of the obsolete RFC 2616 link and "An error occurred". `detail` is the message of our own exceptions, written for the client; framework errors get one fixed sentence per status, and a 500 only asks to quote `X-Request-Id`, so no internal message reaches the client, in dev either. Someone else's bookmark is a 404, so the API does not reveal that it exists. Client errors are logged at `notice`.

nginx answers its own errors in the same format from named locations: 413 for a body over 16 KB, 400 for headers it cannot read, 503 when php-fpm does not answer. Only a URL nginx cannot parse, such as `/api/%`, still gets its HTML 400.

</details>

<details>
<summary><strong>OpenAPI and logging</strong></summary>

`docs/openapi.yaml` is hand-written OpenAPI 3.0.3, because the validator does not support 3.1, and `/api/docs.json` serves it as JSON. It is checked, not only written:

- `OpenApiValidatingBrowser` is the test client. Every functional response must match the document, and so must the request of every 2xx. An undocumented route must answer Problem Details.
- Schemas set `additionalProperties: false` and require every key, and there are no `default` responses, so an extra field or an undocumented status fails.
- `OpenApiCoverageTest` matches routes and paths both ways. Redocly lints the file in CI, and `OpenApiDocumentTest` checks the examples after PHP has parsed the YAML.

Logs:

- One JSON line per entry on stderr. A request id is assigned or validated by nginx, re-checked in PHP, returned as `X-Request-Id` and added to every entry.
- `warning` means degraded but coping (source outage, open circuit, Redis down); `error` means something on our side must change (a bad key, a changed upstream format). Each failed source is logged once.
- Our channels accept only the context keys in `LogContextKey`. Monolog's `RedactingFormatter` on every handler masks tokens, keys, emails and coordinates. The NASA key travels in a header, and the security identifier is the user's UUID.
- `LogLeakTest` replays its listed scenarios at prod log levels (a briefing with coordinates whose source fails, a 404 whose `Referer` carries coordinates, the user command, a valid and a wrong token) and finds no coordinates, email, token or token hash; it is a regression check for those paths.

</details>

<details>
<summary><strong>Security</strong></summary>

- Tokens are 32 random bytes; only their sha256 is stored. They expire (30 days by default), and running the user command again for the same address deletes the old ones. `--ttl` sets the lifetime (`12h`, `30d`). Unknown, expired and revoked tokens get the same 401.
- The token is a Bearer secret, so outside localhost the API must sit behind a reverse proxy that terminates TLS and sends HSTS. Only nginx is published, on `127.0.0.1`, with security headers and a 16 KB body limit.
- PHPStan level 9 with strict and deprecation rules, phpat, and a rule that requires `declare(strict_types=1)`; PHP-CS-Fixer with the Symfony rule sets. `composer audit` runs in every check; CI adds gitleaks on the full history, hadolint and a commit-subject check.
- The prod image has no dev tools, its files are owned by root, and it runs as uid 82. It is checked in CI, and a release is published only after Trivy and Grype pass.
- nginx finds php by name at request time, not once at start: `fastcgi_pass` takes the address from a variable, and the `resolver` is the container DNS that the nginx image reads from `/etc/resolv.conf` (`NGINX_ENTRYPOINT_LOCAL_RESOLVERS`): 127.0.0.11 under Docker and the network's aardvark-dns under Podman. Each worker keeps an answer for 10 seconds and waits at most 2 seconds for the DNS, so after php restarts at a new address the API answers again within about that time. `bin/check-php-restart` checks it in CI under Docker and Podman: it disconnects php from its network and connects it again at a free address with the alias `php`, then expects health and `/metrics` to answer 200 while nginx keeps running. Some Docker versions accept `--ip` only on a network with a declared subnet, so the Docker job adds `compose.ci.yaml` with 10.210.0.0/24; Podman needs no subnet. The resolver list holds every nameserver in `/etc/resolv.conf`, so the container DNS must stay the only one, and only `NGINX_` variables are substituted into the template.

</details>

<details>
<summary><strong>Known limits</strong></summary>

- Two parallel first requests for one scope both call upstream; the guarded upsert keeps the newer data. A lock per scope would need `symfony/lock`.
- Days stored before discoveries existed have no discovery rows; past days are not fetched again.
- Forecast and archive share the weather circuit, and the breaker honours `Retry-After` only on 429.
- A past day that has only a forecast keeps it: fetching observed weather once would need an agreed exception to the rule that stored past days never call upstream.
- Further ideas: a separate circuit for each Open-Meteo address, and a manual reset command instead of restarting Redis; the request id passed into Messenger messages, so a queued date can be traced to its command; a PostgreSQL schema per module with its own database user, so the boundaries hold in the database too, the step before splitting `Briefing` into a service behind `DiscoveryCatalog`.

</details>
