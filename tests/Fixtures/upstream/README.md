# Upstream fixtures

Bodies served by `UpstreamMock::fixture()` in tests, which rejects a file that is not valid JSON. Real responses are recorded with `DEMO_KEY`, changed only where noted.

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
