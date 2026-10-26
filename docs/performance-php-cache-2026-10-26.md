# PHP and concurrent cache profiling — 2026-10-26

This pass uses the same 11.3-million-row database as the
[first measurements](performance-2026-10-26.md), PHP 8.4.12, MySQL 8.4.3, OPcache,
compiled release assets and the unchanged 128 MiB MySQL buffer pool. Both variants
already have the indexes from the first pass. No dataset reset or global database
tuning was needed.

## PHP feed cost

Ten instrumented 50-post requests showed 744 calls to `Text::slug` per request,
mostly through hashtag normalization and segment URLs. The procedural ICU
transliterator was repeatedly initialized even for already canonical ASCII tags.
Mean self time in that method was 34.57 ms; the candidate measured 0.24 ms.

The shared slug helper now returns an already canonical lowercase ASCII slug
directly, only with the default separator. Noncanonical strings, Unicode text and
custom separators keep their existing normalization path. There is no persistent
user-text cache or new invalidation requirement. The expensive path was fixed in
shared infrastructure, without adding special rendering behavior to the feed.

Uninstrumented HTTP results, 40 warmed fresh requests per endpoint and variant:

| Feed | Before p50 | After p50 | Before p95 | After p95 | SQL count |
| --- | ---: | ---: | ---: | ---: | ---: |
| 50 posts | 69.98 ms | 36.04 ms | 102.89 ms | 56.97 ms | 3 → 3 |
| 24 posts | 55.15 ms | 33.56 ms | 85.79 ms | 55.03 ms | 3 → 3 |

For 50 posts, SQL p50 stayed approximately 5.97 → 5.95 ms. A second round in
candidate/baseline order reproduced the improvement: 50-post p50 71.19 → 43.20 ms;
24-post p50 63.26 → 35.60 ms. Local variation remains, but both orders show the
same reduction outside SQL. Nested-call instrumentation is used only for diagnosis,
not for the HTTP improvement numbers.

## Concurrent cache behavior

Four independent loopback PHP workers share one private cache. Eight-worker
checks were also run; this is actual simultaneous application execution, not a
single PHP development server serializing queued requests.

The original cold-cache burst reproduced a race in `Cache::writeFile`: two of
four HTTP 200 responses contained `mkdir(): File exists` warnings and were invalid
JSON with the local `display_errors` setting. All four workers also executed both
ranking queries. Cache directory creation now treats another worker creating the
directory as success; filesystem reads also tolerate replacement races.

`Cache::rememberArray` now coalesces expensive array computations using per-key
filesystem locks. Ranking queries remain owned by Status and Users. Fresh values
return immediately. If another worker refreshes an expired value, contenders can
serve its previous value. For an empty cache, contenders wait and recheck the value
after acquiring the lock. Waiting is bounded to two seconds; if locking is
unavailable or takes too long, computation can proceed independently so caching
does not become a hard availability dependency. Producer exceptions release locks.
Lock files remain in place to avoid splitting waiters across different inodes.

| Scenario, four workers | Before | After |
| --- | ---: | ---: |
| Ranking computations in cold burst | 8 | 2 |
| All SQL in cold burst, including settings | 12 | 6 |
| Invalid JSON in recorded cold burst | 2 / 4 | 0 / 4 |
| Ranking computations after expiry | 8 | 2 |
| Warm sidebar SQL per request | 0 | 0 |

After expiry, baseline SQL counts were `[2,2,2,2]`; candidate counts were
`[0,0,1,1]`. Across that single four-request wave, median latency was
583.91 → 21.70 ms. The slowest producer still needed 559.73 ms; these four samples
are evidence of stale-serving behavior, **not a stable production latency percentile**.
The published values and the warmed sidebar response hash matched.

At eight workers, the cold burst executed the ranking pair once in total and
returned valid JSON in all responses. Eight settings reads are still allowed:
these are cheap initialization reads, not the expensive ranking aggregation.
With 80 warmed requests per route:

| Eight-worker candidate | HTTP p50 | HTTP p95 | SQL/request |
| --- | ---: | ---: | ---: |
| Sidebar | 24.31 ms | 32.05 ms | 0 |
| 50-post feed | 37.34 ms | 58.52 ms | 3 |

The locks coordinate workers sharing this node's cache directory. They do not
provide distributed locking across hosts with separate filesystems, including
hosts sharing Memcached. No claim about Apache capacity, browser rendering or
multi-host throughput is made.

## Verification and artifacts

- Full preflight passed: 34 groups, including PHPStan, HTTP contracts and
  unchanged hot-read SQL budgets.
- Multi-process cache tests cover a single cold computation, stale serving,
  exception recovery, unavailable locking and cached empty arrays (21 checks).
- Slug tests cover canonical forms, whitespace, noncanonical text, custom
  separators and Czech Unicode transliteration.
- Complete feed, status detail, populated tag-feed and warmed sidebar JSON hashes
  matched the baseline after normalizing only the temporary origin.
- The profiling self-time/inclusive-time calculation has a nested-call check.
- No application PHP worker or local profiling server is retained after a run.

Local artifacts remain ignored under `storage/performance/`:
`php-call-feed-before/after.json.requests.jsonl`, `slug-before/after.json`,
`slug-before/after-r2.json`, `cache-cold-baseline-r3.log` and its request trace,
`cache-burst-after.json`, `cache-burst-eight.json`, `stale-burst-before/after.json`,
and the `phase2-parity-*` / `tag-parity-*` comparisons.

Use [the profiling instructions](performance-profiling.md) to repeat these checks.
