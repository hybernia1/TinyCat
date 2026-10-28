# Cache correctness and local HTTP measurements — 2026-10-28

Memcached 1.6.8 is running on loopback port 11211 with 64 MiB. The private local
configuration selects Memcached and an installation-specific prefix. It was
started as a hidden process, not installed as a Windows service; restart it via
Laragon or the same executable after reboot.

## Changes

- Read the filesystem fallback on primary misses as well as connection failures.
- Delete an older primary entry when its replacement falls back to disk, including
  oversized entries. Successful primary writes discard old disk copies.
- Report failed primary invalidation instead of masking it with successful disk deletion.
- Enforce filesystem TTL plus stale retention during reads, without waiting for cron.
- Include the release version in Memcached keys. Old releases expire independently;
  no server-wide flush is needed. Test cache directories also isolate Memcached keys.
- Make update cleanup respect the configured filesystem cache directory, preserving assets.
- Add a profiler backend selector with a unique Memcached prefix per run and an
  explicit local-server availability check. The profiler previously forced filesystem.

Per-key refresh locks coordinate workers sharing a filesystem, including when
values are in Memcached. These locks are not distributed across separate hosts.
A failed invalidation during a Memcached outage is reported; cache operations do
not provide transactional guarantees with database writes.

## Method

Same existing isolated large MySQL database (`storage/performance/large-config.php`),
current checkout, PHP 8.4.12, OPcache enabled, four independent loopback workers,
100 measured warm requests per route per run. Order: filesystem A, Memcached A,
Memcached B, filesystem B. No test or static-analysis process ran concurrently
with these final measurements. This remains a local workstation measurement,
not Apache capacity or a cold MySQL buffer-pool test.

Each run starts with a private empty cache and a four-request cold wave, then two
warmup waves. Both routes receive fresh PHP request state. The feed runs after
the sidebar has populated settings. SQL profiling overhead is identical.

| Run | Route | Warm p50 ms | Warm p95 ms | SQL/request |
| --- | --- | ---: | ---: | ---: |
| fs-a | `/api/site/sidebar` | 29.12 | 44.52 | 0 |
| fs-a | `/api/statuses?limit=50` | 44.30 | 60.30 | 3 |
| fs-b | `/api/site/sidebar` | 28.76 | 40.70 | 0 |
| fs-b | `/api/statuses?limit=50` | 46.20 | 64.05 | 3 |
| mc-a | `/api/site/sidebar` | 24.06 | 44.37 | 0 |
| mc-a | `/api/statuses?limit=50` | 42.95 | 61.91 | 3 |
| mc-b | `/api/site/sidebar` | 24.72 | 43.28 | 0 |
| mc-b | `/api/statuses?limit=50` | 43.50 | 63.26 | 3 |

Memcached modestly reduces sidebar median latency on this machine. Sidebar p95
has no consistent improvement. Feed latency differences are small and do not
justify adding a persistent full-feed cache. Both backends avoid all warm sidebar
SQL; feed stays at three statements. Across the cold sidebar wave, each run
executes the ranking pair once total, plus one settings read per worker.

All 800 measured warm requests returned HTTP 200 and valid JSON. Response hashes
match across all four runs for both routes after normalizing the loopback origin.
Raw results and request traces are ignored under `storage/performance/` with the
prefix `cache-20261028-final-`. Earlier exploratory r1/r2/r3 reports are retained
but excluded here because some overlapped verification work.

## Verification

- Five cache tests; 13 real Memcached integration checks; 21 concurrency checks
  per backend, including cold coalescing, stale refresh, exception recovery and
  unavailable locking.
- PHPStan level 8, PHP syntax lint, style and repository security passed.
- All full-suite groups preceding update rehearsal passed; its cache-directory
  fixture was corrected and the complete update/rollback rehearsal passed on rerun.
  The final MySQL installer rehearsal also passed (65 checks).
- Existing HTTP contracts, visibility checks, query budgets, signed artifact,
  cleanup, search and installer tests passed.

To repeat, use `tools/profile-performance.php --config=storage/performance/large-config.php
--output=<new-report.json> --requests=100 --workers=4
--paths=/api/site/sidebar,/api/statuses?limit=50 --cache=filesystem` and then
`--cache=memcached` with a different output. Repeat in reverse order.

## Follow-up: filtered tag archives

The main/home feed currently ignores `media`; filters apply to author and tag
archives. These query results have no persistent Memcached result cache.

Measured the populated `community` tag (13,030 tag relations), 50 posts per
response, four workers and 40 warm requests per route, with the same backend order
filesystem / Memcached / Memcached / filesystem. OPcache stayed enabled. All 960
measured responses succeeded and JSON response hashes matched across backends.

The original performance database has zero images and only `link` metadata.
For this measurement only, an isolated synthetic fixture inserted 9,982 image
relations (every tenth content ID) and classified existing links into 4,624 video,
4,625 audio and 4,624 ordinary links. These are database filtering fixtures, not
real media downloads or browser playback tests. The fixture was removed in a
finally block; verified restored counts are zero images and zero non-link types.
The development website/database was not modified.

Ranges below show the two measured warm p50 values per backend, in milliseconds.
These are filtered tag archives, so they are not directly comparable to the earlier
unfiltered home-feed measurements. Link filters also include video/audio links.

| Media | Filesystem p50 ms | Memcached p50 ms | SQL/request |
| --- | ---: | ---: | ---: |
| all | 137–137 | 179–181 | 4 |
| images | 88–112 | 108–111 | 4 |
| links | 135–142 | 150–162 | 4 |
| video | 165–170 | 162–165 | 4 |
| audio | 167–168 | 178–187 | 4 |
| text | 248–250 | 250–255 | 4 |

Memcached does not eliminate archive SQL. The expensive part is finding and
sorting matching IDs for a populated tag, including EXISTS/NOT EXISTS media checks.
Total SQL p50 ranges from about 47–66 ms for images, 73–91 ms for links,
106–121 ms for video, 105–123 ms for audio and 201–214 ms for text. The expected
`content_tags_term_index (term_id, content_id)` is present. Backend latency
variations do not establish a Memcached benefit for these uncached result queries.
A next optimization should examine the selection query and execution plan before
adding result caching and its invalidation requirements.

Raw reports: `storage/performance/filter-20261028-{fs,mc}-{a,b}.json` and adjacent
request traces. Fixture runner/recovery instructions are retained there as ignored
artifacts. Existing archive correctness tests cover inactive/future posts,
deduplication, the five media filters and cursor pagination.
