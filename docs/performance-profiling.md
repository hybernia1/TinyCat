# Repeatable performance profiling

The first large-data results are in [the 2026-10-26 report](performance-2026-10-26.md).
PHP and concurrent-cache results are in [the second pass](performance-php-cache-2026-10-26.md).

Use a dedicated database, never reset the development or production installation.
The preparation tool copies table definitions only and writes a private configuration
with a random `tinycat_perf_*` database name. Keep that directory outside public
hosting and source control; its configuration contains database credentials.

```sh
php tools/prepare-performance.php --config=config.php --directory=storage/performance/run
php -d memory_limit=256M tools/demo-import.php --config=storage/performance/run/config.php --profile=million --users=10000 --min-posts=8 --max-posts=12 --min-comments=10 --max-comments=18 --batch-size=500 --seed=250 --anchor="2026-10-26 10:00:00" --state=storage/performance/run/state.json --report=storage/performance/run/import.json --jsonl=1
```

This produces roughly 100,000 posts and several million related rows, including
nested comments, likes, follows, notifications, tags, links and reports. The seed
and anchor make the dataset repeatable. Choose and record an anchor near the
measurement date so seven-day rankings have data. After an interruption, repeat
the same command with `--reset=0`; the importer resumes from its checkpoint.
Wait for a complete import before collecting results.

```sh
php tools/profile-performance.php --config=storage/performance/run/config.php --root=storage/performance/release-app --output=storage/performance/run/before-public.json --requests=40
php tools/profile-performance.php --config=storage/performance/run/config.php --root=storage/performance/release-app --output=storage/performance/run/before-admin.json --requests=40 --user=1 --paths=/admin,/admin/users,/admin/moderation/reports,/api/notifications
```

`--root` selects an extracted release or a source checkout. Prefer the extracted
release for production comparisons: it includes compiled assets and translations.
For a candidate, copy the changed application files into that isolated release
tree, apply candidate migrations only to the performance database, and run again
with new `after-*` output names. Keep the same PHP settings, dataset, routes and
number of requests. Repeat in reverse order if differences are small.

The tool starts temporary **loopback-only** PHP workers and stops them on completion
or failure. Its router accepts only GET requests and only `tinycat_perf_*` databases.
`--user` selects a seeded account on this benchmark server; it does not sign into
the development website. Authentication resolution still runs in each request.
An isolated filesystem cache is used per output, independently of the site's cache.

Each scenario records its first request per worker, two warmup waves and the measured requests.
Every HTTP request starts with fresh PHP request state. Reports include HTTP p50,
p95, response size, executed SQL counts and timings, plus the most expensive SQL
shapes. The adjacent `.requests.jsonl` contains every execution, including failures;
statement preparation alone is not counted, and repeated execution is counted each
time. SQL timings include execution and buffered retrieval, excluding preparation.
The profiler is not loaded by the normal application.

The first request is not a cold MySQL buffer-pool measurement. The default is one
worker; `--workers=4` or `--workers=8` starts independent processes with a shared
isolated cache and routes each active request to a free worker. This tests actual
application concurrency even on Windows, rather than queueing every request at
one development server. These are **not Apache production capacity measurements**.
They exclude browser layout, asset downloads,
TLS and network latency. Profiling adds some overhead; compare instrumented runs
with the same instrumentation. Use the actual Apache/PHP deployment for a separate
deployment load test. Never compare an in-process helper loop with fresh HTTP requests:
the legacy scale-worker's repeated calls can reuse request-local static caches.

Investigate the slowest SQL using `EXPLAIN ANALYZE` with representative parameters
before adding indexes. Validate changes on the same data, check write overhead,
and add a migration plus installer schema changes when an index is justified.
Keep query-budget and HTTP-contract tests passing so faster responses retain the
same visibility, permissions, content and pagination behavior.

## Concurrent refresh and response validation

```sh
php tools/profile-performance.php --config=storage/performance/run/config.php --root=storage/performance/candidate-app --output=storage/performance/run/cold-cache.json --workers=4 --requests=40 --paths=/api/site/sidebar
php tools/profile-performance.php --config=storage/performance/run/config.php --root=storage/performance/candidate-app --output=storage/performance/run/expired-cache.json --workers=4 --requests=40 --paths=/api/site/sidebar --expire=public_trending_tags_8_7,public_top_authors_ranked_5_7
```

The first command sends a simultaneous cold burst, then warm traffic. `--expire`
first primes the workers and cache, then ages only the named JSON entries created
in that run's private cache before the measured initial burst. The report separates
SQL counts in the initial wave from warmed requests. Neither command flushes the
running site's cache. Raw traces include JSON validity and a response-body SHA-256
with the temporary loopback origin normalized, for semantic comparisons between
equivalent API runs. HTTP 200 with a warning-corrupted JSON body fails validation.

## PHP call costs

```sh
php tools/instrument-performance.php --source=storage/performance/candidate-app --directory=storage/performance/profile-candidate
php tools/profile-performance.php --config=storage/performance/run/config.php --root=storage/performance/profile-candidate --output=storage/performance/run/php-calls.json --requests=10 --paths=/api/statuses?limit=50
```

The first command copies an uninstrumented export into a **new** directory under
`storage/performance`, outside the source export. It instruments completed named
PHP functions using `try/finally`; closures, reference-returning functions,
templates and compiled catalogs remain untouched. Raw request traces then include
call counts, inclusive time and self time. Calls ending with `exit` are not reported
as completed scopes. Nested times must not be summed as independent request costs.
This instrumentation changes timing: use it to find expensive methods, then verify
response-time improvements against **uninstrumented** baseline/candidate exports.
Instrumented exports must be run with this profiling router, never deployed.
