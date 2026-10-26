# Performance measurements — 2026-10-26

The first performance pass adds repeatable fresh-request profiling and fixes two
measured database access paths. The application and JSON contracts are unchanged.
The candidate adds two secondary indexes, through migrations and the installer.

## Dataset and environment

- Isolated database: `tinycat_perf_20261026`; the `tinycat.test` database was not reset.
- 10,001 accounts, 99,827 posts, 1,396,921 comments, 999,558 post likes,
  4,191,783 comment likes, 3,907,620 notifications, 180,263 follows,
  499,576 tag relations, 13,873 links and 3,023 reports.
- Total: **11,316,358 relational rows**. Import peak PHP memory: 22 MiB.
- Seed 250, anchor `2026-10-26 10:00:00`, dataset fingerprint
  `faeede0b080d7fea738bb8b4a9a1379f8627b00b98a195b2f43e71b569d6df09`.
- Windows, PHP 8.4.12, MySQL 8.4.3, OPcache enabled, extracted signed 4.0.0
  application with compiled assets and translations.
- MySQL buffer pool **128 MiB for all measurements**. It was temporarily enlarged
  to 2 GiB during import, then restored before measuring; no Laragon configuration
  file was changed.
- One loopback PHP development-server worker; 20 measured requests per route,
  preceded by its first request and two warmups. Each run has a separate filesystem
  cache and each HTTP request has fresh PHP request state.

These are instrumented local server response times, not browser interaction times
or concurrent production capacity. Persistent caches are warm in the table below.
Full first-request results and SQL traces remain in `storage/performance/`.

## Paired HTTP results

Milliseconds, before → after. SQL counts are executed statements per request.

| Route | HTTP p50 | HTTP p95 | SQL count | SQL p50 |
| --- | ---: | ---: | ---: | ---: |
| Home | 37.8 → 39.7 | 69.9 → 61.5 | 3 → 3 | 2.5 → 2.6 |
| Feed, 24 posts | 57.4 → 54.2 | 74.2 → 80.3 | 3 → 3 | 3.3 → 4.4 |
| Feed, 50 posts | 64.7 → 87.6 | 92.9 → 105.0 | 3 → 3 | 4.9 → 7.1 |
| Cursor after 50,000 posts | 59.6 → 59.2 | 74.9 → 85.9 | 3 → 3 | 3.9 → 4.8 |
| Status detail | 37.0 → 39.7 | 48.7 → 60.5 | 3 → 3 | 1.5 → 2.1 |
| Tag feed | 63.2 → 68.5 | 84.3 → 91.8 | 4 → 4 | 5.9 → 6.3 |
| Author | 49.9 → 50.8 | 70.0 → 66.6 | 6 → 6 | 4.8 → 7.1 |
| Search page | 110.2 → 79.4 | 132.5 → 100.9 | 7 → 7 | 56.3 → 24.5 |
| Search suggestions API | 33.4 → 33.5 | 49.3 → 48.7 | 2 → 2 | 0.7 → 0.9 |
| Cached sidebar API | 24.8 → 22.5 | 39.3 → 48.3 | 0 → 0 | 0 → 0 |
| Admin dashboard | **3209.3 → 25.9** | **3804.8 → 51.0** | 2 → 2 | **3173.7 → 1.4** |
| Admin users | 48.3 → 52.4 | 62.8 → 65.5 | 4 → 4 | 12.6 → 11.8 |
| Admin reports | 86.0 → 86.1 | 98.2 → 112.2 | 2 → 2 | 33.5 → 27.3 |
| Notifications API | 35.6 → 32.5 | 56.8 → 47.5 | 4 → 4 | 5.7 → 3.7 |

All measured responses returned HTTP 200. The 24/50-post and deep-cursor cases
retain three SQL statements: no per-post SQL growth was found in these cases.
Counts exclude setup outside the request and statement preparation alone.

### Repeated order check

Fast endpoint times varied across local runs. The apparent 50-post regression in
the first pair was therefore investigated with candidate/baseline/baseline/candidate
runs, temporarily removing and restoring the indexes **only in the performance
database**. The candidate indexes were restored in a `finally` block.

Across three 20-request rounds per variant, the median of round medians was:

| Scenario | Before HTTP p50 | After HTTP p50 | Before SQL p50 | After SQL p50 |
| --- | ---: | ---: | ---: | ---: |
| 50-post feed | 99.4 ms | 87.6 ms | 7.9 ms | 7.1 ms |
| Search page | 130.0 ms | 81.2 ms | 56.3 ms | 22.5 ms |

Search improvement reproduced. Feed variation did not establish an index-related
regression or a meaningful improvement; its query count stayed three. Small
movements on unchanged routes should not be treated as causal gains.

## Changes and query-plan evidence

`content_comments_recent_index (created_at, id)` lets the dashboard walk backward
through the latest comments. Before: table scan of 1.4 million comments plus sort;
`EXPLAIN ANALYZE` took 4,571 ms. After: reverse index scan, eight comment rows and
eight primary-key lookups per joined table; the first measured plan took 2.47 ms.
The returned eight IDs and their order were identical.

`users_status_username_index (status, username, id)` lets active-user search apply
the username condition in the index before loading profile data. For an absent
substring, the original plan scanned all 10,001 wide user rows through the unique
username index (69.6 ms); the candidate used an index condition (7.92 ms). Both an
absent term and a matching substring returned identical rows and ordering.
Leading-wildcard matching still scans active usernames; this improves access cost
without changing substring-search behavior.

Indexes have a write and storage cost. An isolated insert-execution probe inserting
100 comments and rolling back measured p50 2.01 ms before and 3.51 ms after; a later
candidate check measured 4.17 ms. Its p95 was 4.13 ms before and about 9.45 ms after.
This is a small batch probe, **not commit latency or a full write-API benchmark**.
The extra index is justified by removing a multi-second dashboard scan, but these
numbers do not imply free writes. No global database tuning was retained.

## Validation and artifacts

- All table counts and inspected ordered IDs match before/after.
- Full preflight passed: 33 groups, including 1,536 API HTTP contract checks.
- After the final index addition: MySQL installer/migration rehearsal passed
  58 checks, including repeated migrations; signed-package boundary, style and
  SQL-profiler execution-count checks passed.
- Raw reports: `before-public-r2.json`, `before-admin.json`, `after-public.json`,
  `after-admin.json`, `baseline-r2.json`, `baseline-r3.json`, `candidate-r2.json`,
  `candidate-r3.json`, with adjacent `.requests.jsonl` traces.
- Dataset/plans: `large-import.json`, `baseline-data.json`, `before-users.json`,
  `after-data.json`, `after-users.json`, `final-data.json`.
- Local configuration and these runtime artifacts stay ignored under
  `storage/performance/`; the larger dataset is retained for further measurements.

See [profiling instructions](performance-profiling.md) to reproduce the import and
HTTP measurements. Further investigations should separate PHP response-building
cost from SQL and measure concurrency on the actual Apache/PHP deployment; neither
browser rendering nor production throughput was measured by this pass.
