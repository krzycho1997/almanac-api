# Upstream fixtures

Bodies served by `UpstreamMock::fixture()` in tests, which rejects a file that is not valid JSON. Real responses are recorded with `DEMO_KEY`, changed only where noted. Every file uses one format: four-space indentation, unescaped slashes and Unicode, zero fractions kept (`50.0`) and a final newline.

## Recording

`tests/Support/Briefing/Recording/UpstreamFixtures.php` lists every fixture with the request it was recorded from, the status it must answer with, how it is trimmed and, when one of our clients makes that request, the date and place the client is called with. `UpstreamFixturesClientTest` checks that the client really requests that URL.

`composer test:live` fetches each repeatable fixture, one request a second, and passes the trimmed answer through our own client: an answer the client no longer accepts fails before anything is written. Historical data (APOD and the Open-Meteo archive) must also match the file exactly, keys in any order and `generationtime_ms` ignored; On This Day is checked for shape only, since Wikipedia pages are edited all the time. A 429 or 503 with `Retry-After` of at most 10 seconds is retried once; any other unexpected status fails and is never written. `RECORD_FIXTURES=1 composer test:live` writes the answers that changed and their entry in the directory's `recorded.json` (URL, status, day of recording and the `content-type`, `date`, `retry-after` and rate-limit headers).

Frozen fixtures (the placeholder, the forecasts and both out-of-range errors) depend on the day they were recorded or on a passing upstream state, so they are listed but never fetched again. No repeatable fixture needs the NASA key.

## NASA APOD (`apod/`)

From 2026-12-01 api.nasa.gov forwards every date to `https://science.nasa.gov/wp-json/wp/v2/apod-basic/{yymmdd}`, sets `url` to `hdurl` and adds `service_version` ([nasa/apod-api](https://github.com/nasa/apod-api)). The `passthrough-*` files are those WordPress responses with the same two changes and with `basic_html` removed by us, since the forwarder keeps it and we never read it.

| File | Source | Recorded |
|---|---|---|
| `placeholder-2026-11-18.json` | `GET https://api.nasa.gov/planetary/apod?date=2026-11-18` | 2026-11-20; the API answered every date with this "NASA Science" placeholder |
| `passthrough-2024-03-15.json` | `GET https://science.nasa.gov/wp-json/wp/v2/apod-basic/240315` | 2026-11-20 |
| `passthrough-video-2021-06-23.json` | `GET https://science.nasa.gov/wp-json/wp/v2/apod-basic/210623`; a video, whose `hdurl` is NASA's generic `news-thumbnail.png` | 2026-11-20 |
| `passthrough-first-picture-1995-06-16.json` | `GET https://science.nasa.gov/wp-json/wp/v2/apod-basic/950616`; the first picture, with the generic thumbnail and an empty credit | 2026-11-20 |
| `date-out-of-range.json` | `GET https://api.nasa.gov/planetary/apod?date=1995-06-15`, HTTP 400 | 2026-11-20 |

## Wikipedia On This Day (`on_this_day/`)

Recorded with a plain User-Agent, without the NASA key. Every entry of each list is kept, in Wikipedia's order (newest first). To keep the files small, each page keeps only the fields we read (`type`, `title`, `normalizedtitle`, `description`, `thumbnail` with `source`, `width`, `height`, `content_urls.desktop.page` and `wikibase_item`).

| File | Source | Recorded |
|---|---|---|
| `selected-03-15.json` | `GET https://en.wikipedia.org/api/rest_v1/feed/onthisday/selected/03/15`; 22 entries, so a limit of 20 trims it, the last one in 44 BC | 2026-11-20 |
| `births-03-15.json` | `GET https://en.wikipedia.org/api/rest_v1/feed/onthisday/births/03/15`; 189 entries | 2026-11-20 |
| `deaths-03-15.json` | `GET https://en.wikipedia.org/api/rest_v1/feed/onthisday/deaths/03/15`; 86 entries, including Julius Caesar (44 BC) | 2026-11-20 |

## Open-Meteo (`open_meteo/`)

Recorded on 2026-11-20 (winter, UTC+1 in Europe) with a plain User-Agent and the query the client sends (`daily=weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum,wind_speed_10m_max,sunrise,sunset,daylight_duration&timezone=auto&timeformat=unixtime&temperature_unit=celsius&wind_speed_unit=kmh&precipitation_unit=mm`), pretty-printed. Open-Meteo applies today's UTC offset to every date, so the summer files carry `utc_offset_seconds: 3600` although the place was on summer time; Unix times stay correct. `UpstreamMock::archiveOn()` and `forecastOn()` serve `archive-2024-03-15.json` with its times shifted to the requested day.

| File | Source |
|---|---|
| `archive-2024-03-15.json` | `GET https://archive-api.open-meteo.com/v1/archive?latitude=48.86&longitude=2.35&start_date=2024-03-15&end_date=2024-03-15` (Paris) |
| `archive-summer-2024-07-01.json` | the same place on 2024-07-01; sunrise 05:51 CEST, which the local-time format would give as 04:51 |
| `archive-sunset-after-midnight-2024-05-17.json` | `latitude=69.65&longitude=18.96` (Tromsø) on 2024-05-17; the sun sets at 00:22 on 18 May |
| `archive-polar-day-2024-06-21.json` | `latitude=78.22&longitude=15.65` (Longyearbyen) on 2024-06-21; `daylight_duration` 86400 |
| `archive-polar-night-2024-12-21.json` | the same place on 2024-12-21; `daylight_duration` 0 |
| `archive-point-nemo-2024-03-15.json` | `latitude=-48.88&longitude=-123.39` (Point Nemo, open ocean); time zone `Etc/GMT+8` |
| `archive-north-pole-2024-03-15.json` | `latitude=90.00&longitude=0.00`; time zone `Etc/GMT`, `daylight_duration` 0 |
| `forecast-2026-11-20.json` | `GET https://api.open-meteo.com/v1/forecast?latitude=48.86&longitude=2.35&start_date=2026-11-20&end_date=2026-11-20` |
| `forecast-beyond-the-horizon-2026-12-05.json` | `latitude=21.30&longitude=-157.85` (Honolulu) on 2026-12-05; HTTP 200 with every measure null, since the local day lies beyond the forecast |
| `forecast-out-of-range.json` | `start_date=2026-12-06`, HTTP 400 |
