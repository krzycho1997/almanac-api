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
