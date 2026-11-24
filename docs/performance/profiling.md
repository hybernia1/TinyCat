# Repeatable performance profiling

New and changed list queries follow the [selection and pagination standard](../contracts/query-selection-standard.md).
Store each run's reports with its commit, dataset and environment below
`.work/performance/`; historical measurements do not describe current performance.

The [24 November core audit](../audits/core-performance-2026-11-24.md) measures
the current compiled/source fallback paths, repeated deployment identity checks
and framework metadata preparation. It separates live Apache samples from
isolated worker measurements and lists the remaining load/data coverage gaps.
Its implementation follow-up records scoped metadata/asset reuse, paired
before/after measurements and restoration of matching compiled catalogs on
the existing Laragon site; full preflight was explicitly deferred.

The [follow-up data/concurrency audit](../audits/core-data-performance-2026-11-24.md)
measures notification query plans on 310,100 synthetic rows and identifies full
inbox polling and settings/session serialization. Its discovered `--user`
credential defect is now corrected through canonical login followed by a fresh
identity resolution in dispatch. Invalid or noninteractive actors fail instead
of silently becoming guests. Raw traces separate `authentication_setup` SQL and
time from dispatched SQL; HTTP elapsed time still includes that benchmark setup,
so authenticated timings are not deployment TTFB measurements.

The [HTTP/accounts/inventory audit](../audits/core-http-performance-2026-11-24.md)
adds query plans for 100,000 synthetic accounts, fresh-process inactive-module
inventory measurements, routing/input scale probes and live generated-avatar
GET/304 samples. It distinguishes hot-path costs from setup-only readiness.

The [account/inbox implementation results](../audits/core-list-performance-fixes-2026-11-24.md)
record version 3 read indexes, state-only polling, reused unfiltered counts and
authenticated API measurements, including the runtime control and remaining
limitations. Full preflight and live schema deployment remain deferred.

Use a dedicated database, never reset the development or production installation.
The preparation tool copies table definitions only and writes a private configuration
with a random `tinycat_perf_*` database name. Keep that directory outside public
hosting and source control; its configuration contains database credentials.

```sh
php tools/performance/prepare-performance.php --config=config.php --directory=.work/performance/run
php -d memory_limit=256M tools/data/demo-import.php --config=.work/performance/run/config.php --profile=million --users=10000 --min-posts=8 --max-posts=12 --min-comments=10 --max-comments=18 --batch-size=500 --seed=250 --anchor="2026-10-26 10:00:00" --state=.work/performance/run/state.json --report=.work/performance/run/import.json --jsonl=1
```

This produces roughly 100,000 posts and several million related rows, including
nested comments, likes, follows, notifications, tags, links and reports. The seed
and anchor make the dataset repeatable. Choose and record an anchor near the
measurement date so seven-day rankings have data. After an interruption, repeat
the same command with `--reset=0`; the importer resumes from its checkpoint.
Wait for a complete import before collecting results.

```sh
php tools/performance/profile-performance.php --config=.work/performance/run/config.php --root=.work/performance/release-app --output=.work/performance/run/before-public.json --requests=40
php tools/performance/profile-performance.php --config=.work/performance/run/config.php --root=.work/performance/release-app --output=.work/performance/run/before-admin.json --requests=40 --user=1 --paths=/admin,/admin/users,/admin/moderation/reports,/api/notifications
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
The extra seed-login query is recorded under `authentication_setup.queries`,
outside dispatched `sql_count`. Its time remains included in HTTP duration.
An isolated filesystem cache is used per output, independently of the site's cache.
Pass `--cache=memcached` to measure the local Memcached server at 127.0.0.1:11211
with a unique prefix per output. A missing server fails the run. The default is
`--cache=filesystem`; reports and request traces record the selected backend.
`--expire` supports filesystem entries and ages them just past their fresh TTL,
within their stale retention window.

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
php tools/performance/profile-performance.php --config=.work/performance/run/config.php --root=.work/performance/candidate-app --output=.work/performance/run/cold-cache.json --workers=4 --requests=40 --paths=/api/site/sidebar
php tools/performance/profile-performance.php --config=.work/performance/run/config.php --root=.work/performance/candidate-app --output=.work/performance/run/expired-cache.json --workers=4 --requests=40 --paths=/api/site/sidebar --expire=public_trending_tags_v2_8_7,public_top_authors_ranked_5_7
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
php tools/performance/instrument-performance.php --source=.work/performance/candidate-app --directory=.work/performance/profile-candidate
php tools/performance/profile-performance.php --config=.work/performance/run/config.php --root=.work/performance/profile-candidate --output=.work/performance/run/php-calls.json --requests=10 --paths=/api/statuses?limit=50
```

The first command copies an uninstrumented export into a **new** directory under
`.work/performance`, outside the source export. It instruments completed named
PHP functions using `try/finally`; closures, reference-returning functions,
templates and compiled catalogs remain untouched. Raw request traces then include
call counts, inclusive time and self time. Calls ending with `exit` are not reported
as completed scopes. Nested times must not be summed as independent request costs.
This instrumentation changes timing: use it to find expensive methods, then verify
response-time improvements against **uninstrumented** baseline/candidate exports.
Instrumented exports must be run with this profiling router, never deployed.

## Startup investigation, 15 November 2026

An initial comparison against 5.22.2 identified startup as a regression candidate:
fresh CLI processes with warmed OPcache file caches took median 15.3 ms for
5.22.2, 45.8 ms for 6.0.4 core and 68.9 ms for 6.0.4 with Social. These are
startup-only measurements with an in-memory SQLite configuration, not Apache
TTFB or equivalent page workloads. The installations register different routes
and capabilities; these numbers do not establish a production slowdown ratio.

Registration now wraps operational page handlers, scheduler authorization,
identity resolution and diagnostics in deferred closures. First-class callables
were loading their target classes while registering the framework. The existing
runtime registry runner now checks deferred loading in a fresh framework process,
including that registration opens neither a database connection nor a session.

The Apache check used the existing core-only `https://tinycat.test/` installation,
verified HTTPS and sequential curl GETs: five warmups and 25 retained samples per
route before and after synchronization. Median TTFB was 64.87 → 64.32 ms for
`/login`, 60.41 → 60.09 ms for `/privacy`, and 57.65 → 59.51 ms for `/`. Median
`time_starttransfer - time_pretransfer` was respectively 34.08 → 34.02 ms,
28.11 → 27.42 ms, and 27.60 → 26.31 ms. This small single-round difference does
not establish a material TTFB improvement. Metadata parsing and filesystem checks
remain a separate optimization target; fewer loaded classes alone are not proof
of faster responses. A trial startup cache for schema descriptions and repeated
provider ownership checks showed no repeatable benefit and was discarded.

Raw samples are in ignored `.work/performance/ttfb-20261115/`, including
`startup-filecache-comparison.json`, `providers-comparison.json`,
`lazy-comparison.json`, and `http-lazy-comparison.json`. No installation data,
configuration, enabled modules or database schema were changed.

### Apache phase measurements and compiled server components

Temporary, loopback-only, header-gated timing probes measured the actual Apache
request with OPcache enabled. They recorded timings and counts, never SQL values,
configuration, credentials or response bodies. Original runtime files were backed
up and restored after each series. This was not deployment of an instrumented
application export. The anonymous warm-cache routes `/login`, `/privacy`, `/` and
the `/api` 404 opened no PDO connections and executed no SQL. This result does not
describe authenticated routes or a cold settings cache.

The first phase-only series measured median PHP totals of 29.91 ms for `/login`,
23.55 ms for `/privacy` and 22.84 ms for `/`. Catalog loading cost roughly 8 ms;
services, registries and route registration together cost another 8 ms. Targeted
metadata probes counted 56 `realpath`, 39 `is_dir`, 17 `is_file` and 44
`is_readable` calls per request. These checks cost roughly 4–5 ms in one series.
They remain intact. Timings vary with the machine load and instrumentation;
inclusive method times must not be added to their parents.

The additional login cost included preparing server component definitions from
JSON. The existing asset compiler now also emits a content-addressed PHP catalog
of the validated, resolved components. `CompiledAssets` selects it through the
same manifest and selection guard used for client assets. `Components` retains
source fallback for older manifests and bypasses compiled definitions during
fresh builds and scoped selections. The build also records the optional module
package registry checksum: independent package changes invalidate the server
catalog even when the selected module IDs stay the same. No component definitions, HTML or client
bundles changed. The generated core-only PHP catalog is 302,498 bytes on disk;
it is private server code, not another browser download.

An uninstrumented Apache comparison alternated candidate/baseline/baseline/candidate,
with five warmups and 25 retained GETs per route per round (50 retained samples
per variant). No other checks ran during this comparison.

| Route | Median TTFB before → after | Median wait after transport setup before → after |
| --- | --- | --- |
| `/login` | 66.61 → 57.56 ms | 33.33 → 27.25 ms |
| `/privacy` | 57.20 → 55.17 ms | 26.97 → 25.73 ms |
| `/` | 57.63 → 58.86 ms | 27.36 → 28.39 ms |

Login wait decreased by 18.2% in this series; homepage did not improve. Response
lengths matched for every route. A subsequent targeted profile confirmed that
component catalog preparation was replaced by loading the compiled PHP array;
it is attribution evidence, not a second latency benchmark. This change does
not establish parity with 5.x or remove the independent catalog-loading cost.

Raw phase traces are under `.work/performance/ttfb-20261115/web-phases-*`;
the uninstrumented comparison is in `components-abba-224218/`. Tests cover compiled
source parity, source-free loading, deterministic fresh builds, invalid paths,
older manifests, independent package changes and scoped module selection. The local build was checked to have
identical existing client assets before synchronizing its generated server catalog.

After adding package invalidation, a second block comparison again favored login,
but even the control routes changed with machine load. The decisive follow-up
therefore alternated individual source/compiled requests in the same warmed Apache
code, switching order for successive pairs. A temporary loopback/header-gated
branch selected the existing JSON fallback only for baseline requests; it was
removed afterwards. Each variant retained 50 samples per route after ten warmup
pairs. No other checks ran during the measurement.

| Route | Median TTFB source → compiled | Median wait source → compiled | Median paired wait saving |
| --- | --- | --- | --- |
| `/login` | 63.07 → 59.64 ms | 34.17 → 28.97 ms | 5.36 ms |
| `/privacy` | 58.61 → 58.74 ms | 28.63 → 28.66 ms | 0.16 ms |
| `/` | 60.86 → 59.19 ms | 28.02 → 27.87 ms | −0.64 ms |

These controls support a roughly 5 ms login saving from the compiled component
catalog. The block-series improvements on unrelated pages should not be attributed
to this change. Raw paired samples and their summary are in
`.work/performance/ttfb-20261115/components-interleaved-225041/`; the final block
series is in `components-abba-224846/`. Response lengths matched and every response
was HTTP 200. The optimization is deployed on the existing local core-only site;
the independent module metadata/stat cost remains unchanged.

### Social comparison against 5.22.2

The production screenshots supplied afterwards describe Social, not the earlier
core-only local assembly: TTFB 69.048 → 186.586 ms, with DNS/TCP/TLS totals
29.915 → 43.485 ms. The remaining wait is approximately 39.133 → 143.101 ms;
it includes server/transport waiting and is not a pure CPU measurement. These
historical single samples establish the reported regression, not its cause.

A separate comparison in `.work/performance/ttfb-20261115/social-comparison/`
uses exported 5.22.2, released 6.0.4 and the working candidate, all with Social.
The archived 5.22.2 installer and importer created a disposable MySQL database
with 61 accounts, 240 posts, 2,400 comments and 11,765 relational rows. Its clone
passed the immutable shared-storage bridge migrations and current owner migrations.
`data-parity.json` verifies identical IDs, bodies, dates and relationship projections
by checksum, with all 4,097 notifications preserved. Neither production nor the
existing local installation was an installation/migration target.

The existing ProfileServerPool, profile-router and HttpLoadRunner collected fresh
HTTP requests in three independent loopback workers, rotating variant order for
successive pairs. All three workers reported OPcache enabled. The clean second
series retained 30 samples per variant/route after ten warmup waves:

| Route | 5.22.2 | Released 6.0.4 | Working candidate | Warm SQL count, all variants |
| --- | ---: | ---: | ---: | ---: |
| `/` | 51.52 ms | 69.37 ms | 59.68 ms | 3 |
| `/login` | 37.46 ms | 51.91 ms | 44.24 ms | 0 |
| `/privacy` | 30.91 ms | 46.80 ms | 47.27 ms | 0 |
| `/api/statuses` | 45.08 ms | 65.37 ms | 55.87 ms | 3 |
| `/api/site/sidebar` | 26.57 ms | 45.70 ms | 44.61 ms | 0 |

These medians are application-worker HTTP timings, not Apache/HTTPS production
TTFB. The profiler opens its PDO connection before application dispatch; SQL
times exclude preparation. Homepage SQL medians were about 2.5–2.7 ms for all
variants. Homepage included-file counts rose from 121 to 253 (candidate 245).
Thus the fixture reproduces a PHP/startup regression and partial candidate gains,
but not the entire production increase of about 104 ms after connection setup.
Larger production query plans and hosting conditions remain unmeasured.

Disposable instrumented exports identified 13 metadata parses per startup and
additional provider/autoload work. Current login Application::start took about
18 ms in that instrumented series; these inclusive call timings are attribution
evidence, not latency promises. Schema-owner collection itself was under 1 ms,
so caching schema objects is not a supported primary fix. Production OPcache
state and filesystem costs require confirmation before attributing the larger
hosting-specific difference. Raw clean samples are in `interleaved-224601/`;
call traces are the adjacent `*-calls-r1.json.requests.jsonl` files. Every measured
endpoint returned HTTP 200 and the temporary profiling workers were stopped.

### Deferred Social presentation callbacks (2026-11-16)

Social now registers closures for public page handlers, profile sitemaps,
embedding, the theme, information pages, bot administration pages and scheduler
run details. Their implementations load only when invoked. Signatures and
optional parameters are preserved; registration still performs no database or
session work. The existing minimal-boot fixture includes a Social-only startup
check that rejects eager loading of these operational classes.

On the same isolated Social dataset, included PHP files fall from 245 to 238
on the homepage, 220 to 212 on login, 215 to 208 on privacy, 221 to 212 on the
status API and 199 to 190 on the sidebar API. SQL counts remain unchanged.
The first timing run overlapped quality checks and is not evidence of a latency
improvement; fewer included files alone must not be presented as a TTFB win.

A second run after preflight stopped, with no test workers active, retained
30 requests per variant and endpoint with OPcache enabled. Login, privacy and
sidebar medians improved, but homepage and status API medians did not. These
results do not establish a consistent TTFB benefit from this small batch. Raw
samples: `.work/performance/ttfb-20261115/social-comparison/interleaved-231508/`.
The full preflight attempt stopped at the avatar rollback API scenario after
Windows failed to restore a temporary backup; the earlier PHP, boundary,
frontend, module and public-route checks passed.

The canonical test runner subsequently repeated the API group and completed all
39 remaining groups successfully, including 5,392 API checks, signed module
lifecycle, the 5.22.2 MySQL upgrade/data-preservation/rollback rehearsal and the
installer. Together with the successful preflight prefix, every scheduled group
was exercised; the initial full preflight report remains failed and is not
reported as a single clean pass. Memcached integration was skipped because its
extension is unavailable; the symlink escape fixture was also unavailable.
Reports: `.work/tests/runs/20261115-230507-986938689a/` and
`.work/tests/runs/20261115-231606-9e4a22d5a0/`.

### Common startup and routing (2026-11-16)

`Loader::load()` now lets deployment selection read the distribution once and
evaluates the source module map lazily only when no distribution exists. This
removes one duplicate descriptor read/validation/package-registry traversal;
installation still independently resolves its legacy-schema policy. No
persistent metadata cache or weakened manifest/resource checks were added.

Router registration maintains an index of exact method/path keys, including
implicit HEAD for GET. Each provider checks only its added routes against that
index. Both routes and index roll back on failure, including nested provider
failures; legacy duplicates outside provider registration remain accepted.
Static route mismatches no longer construct a regex. Encoded patterns retain
the existing normalization path.

The same isolated Social fixture was measured in alternating fresh HTTP
requests, 10 warmup waves and 30 retained samples per endpoint and variant,
with OPcache enabled. Temporary copies instrumented only Application::start,
Distribution::read, Router::registerModule/routeRegex and HttpKernel::respond
through the existing CallProfile collector. No production instrumentation was
added. The clean final run had no concurrent quality checks.

| Homepage measurement | Before | After |
| --- | ---: | ---: |
| Application startup, median | 14.863 ms | 13.893 ms |
| Route provider registration, median | 2.132 ms | 1.762 ms |
| Distribution reads per request | 3 | 2 |
| Distribution reads, median total | 1.565 ms | 1.200 ms |
| Route regex constructions per request | 141 | 59 |
| HTTP kernel, median | 17.095 ms | 16.671 ms |
| Included PHP files | 238 | 238 |
| Warm SQL queries | 3 | 3 |

Nested phase times must not be added: registration and descriptor work are
inside startup. Across all five endpoints, median startup improved by roughly
0.44?0.99 ms. End-to-end TTFB still varied by endpoint: the final homepage
medians were 54.971/51.641 ms, but login and privacy did not improve. These
results support a modest startup saving, not a universal TTFB claim or an
explanation of the approximately 104 ms production wait regression.

Raw final traces and phase summary:
`.work/performance/ttfb-20261115/social-comparison/interleaved-234003/`.
The earlier `interleaved-233801/` run overlapped style/static analysis and is
not used as final evidence. Workers were stopped before final quality checks.
Regression coverage extends the existing catalog/pilot tests for lazy source
selection, invalid source data, route collisions, nested rollback and literal
static routes with implicit HEAD.

Final validation: `composer preflight` passed all 89 test groups, including
5,392 API checks, signed module lifecycle, 5.22.2 MySQL data preservation and
rollback, and installation rehearsals. Memcached integration was skipped
because the extension is unavailable; the symlink escape fixture was also
unavailable. Report: `.work/tests/runs/20261115-234047-2d1b486637/report.json`.

After checking the three live files still matched their original sources,
only Deployment, Loader and Router were backed up and synchronized to the
existing Laragon installation. Apache returned HTTP 200 for `/`, `/login` and
`/privacy`; HEAD `/login` returned 200 with no response body. This installation
is core-only, so this is transport smoke coverage rather than a Social latency
measurement. Configuration, accounts, uploads and module activation were not
changed; production was not deployed. Backups and smoke results are in
`.work/performance/ttfb-20261115/startup-live-backup-20261116-000022/`.

### Extreme core file-count experiment (2026-11-16)

A user-reported regression after an older Core.php split motivated a controlled
physical file-count test at checkpoint `61b0677b`. The application source tree
and live Laragon installation were not modified. All generated PHP, private
configuration, caches and logs stayed under the existing isolated performance
workspace and its disposable MySQL databases.

A private token-based compiler packs 274 runtime PHP source files while keeping
class names, interfaces, imports, strict typing, original directory constants
and lazy class declarations. It rewrites the export's autoloader to call the
corresponding declaration closure from an already loaded bundle. The packed
originals are removed only from the disposable export. Templates, translations,
migrations, compiled catalogs and the bootstrap autoloader are not concatenated.
Provider ownership and resource validation remain unchanged.

Two builds were tested: 20 bundles by core area, and an extreme 10-bundle build
with nine provider-owner bundles plus one shared bundle. Keeping provider code
inside its existing owner root preserves Reflection-based ownership checks.
This tests file loading and autoloader filesystem work without deleting feature
logic or eagerly declaring unused classes. It does not isolate file-open cost
from the rest of the optimized autoloader.

The primary head-to-head comparison comprises two clean interleaved runs,
10 warmup waves and 30 retained requests per endpoint/variant/run: 60 samples
per endpoint and variant, with OPcache enabled. At most three loopback workers
and one controller were active, with no concurrent quality suite.

| Route | Existing TTFB ms | 20 bundles ms | 10 bundles ms | Included files: existing / 20 / 10 |
| --- | ---: | ---: | ---: | --- |
| / | 69.3 | 56.5 | 59.3 | 238 / 94 / 85 |
| /login | 51.9 | 44.7 | 48.3 | 212 / 79 / 71 |
| /privacy | 52.5 | 47.4 | 48.4 | 208 / 81 / 73 |
| /api/statuses | 62.6 | 57.0 | 52.9 | 212 / 81 / 72 |
| /api/site/sidebar | 46.3 | 36.4 | 39.1 | 190 / 73 / 65 |

Homepage startup medians were 29.457 / 19.263 / 19.049 ms. The homepage paired
median saving was 11.548 ms for 20 bundles (47/60 faster pairs) and 10.936 ms
for 10 bundles (44/60 faster pairs). Its warm SQL count remained three, with
SQL medians 2.420 / 2.623 / 2.636 ms. This supports a meaningful loading/startup
saving in this environment. Ten bundles did not consistently beat twenty.
PHP allocator peak with warm OPcache was 2 MiB in all three variants; this does
not measure OPcache shared-memory consumption.

Two earlier clean runs included v5.22.2 as a concurrent reference. One yielded
homepage medians 55.544 ms (5.x), 76.533 ms (checkpoint) and 56.009 ms (20
bundles); the other was 56.908 / 86.356 / 69.508 ms. Absolute times varied
considerably, so these are supporting runs rather than a production guarantee.
The initial pilot also wrote include/class audits and is excluded from the
primary timing evidence.

OPcache-disabled secondary runs used new workers and 30 samples per endpoint.
The 20-bundle homepage was 230.648 -> 199.170 ms. In a separate run, the
10-bundle homepage was 194.377 -> 191.804 ms, while its status API regressed
138.825 -> 145.635 ms. These separate runs must not be compared as a direct
20-versus-10 ranking. Parsing larger bundles without OPcache can offset the
I/O saving: PHP allocator peak rose from 12 MiB to 14 MiB (20 bundles) or
16 MiB (10 bundles). This is not evidence that the smallest possible bundle
count is the best production configuration.

Verification: every generated bundle passed PHP lint; both framework-only and
Social registration passed in all three variants without opening a session or
database. Loaded TinyCat class/interface/trait sets match on all five measured
endpoints. Separate content comparisons passed for homepage, login, privacy,
status API, sidebar API, robots, sitemap and global search. API/XML/text bodies
match exactly after normalizing the benchmark host; HTML also normalizes its
per-request CSRF token and CSP nonce. The old/current data projections still
match (61 users, 240 posts, 2,400 comments and their relationships).

These are experimental generated exports with target-specific paths, not
release packages. Full update/rollback and preflight coverage was not rerun
against the packed exports. Only the experiment documentation changes in the
repository; production and the live test installation remain untouched. A
release implementation would need relocatable bundles, canonical build and
updater integration, source mapping and the full existing regression suite.

Summary report: `.work/performance/ttfb-20261115/social-comparison/core-bundling-report.json`.
Primary raw runs: `interleaved-170351/`, `interleaved-170459/`. Supporting runs:
`interleaved-165540/`, `interleaved-165717/`. OPcache-off runs:
`interleaved-165851/`, `interleaved-170702/`. Content/class parity artifacts:
`bundle-parity-170015/` and `bundle-parity-170846/`. All profiling workers were
stopped after measurement.

### Core validation work and duplicated mechanisms (2026-11-16)

A fresh instrumented checkpoint profile still attributes substantial homepage
work to component evaluation (3,434 value calls, 873 node calls). The status
API makes 2,382 recursive Response::assertData calls. These are attribution
counts and instrumented times, not uninstrumented latency savings.

Implemented in this stage:

- Response validation checks each distinct accepted string key once per request
  in a cache capped at 256 entries. It recurses only into nested arrays, still
  rejecting objects/resources at every depth and preserving the existing
  case-insensitive HTML/UI-field policy and exception messages.
- Compiled asset and translation readers share their selection/deployment
  identity check in CompiledTranslations, which API requests already load.
  Scoped readers still opt out before reading files; source-free and legacy
  inventory fallback behavior remains covered. Catalog lookup reuses its
  already validated manifest rather than requesting it twice.
- ModuleSchemas delegates table existence to the existing explicit-PDO
  SchemaInspection helper. This removes duplicate SQL implementation without
  caching schema state or changing published migration files/checksums.

The validator microbenchmark uses the real isolated status API payload. Each
iteration clears the accepted-key cache before timing the data/meta/params
checks, simulating a fresh request. Four separate PHP processes ran in ABBA
order, with 100 warmup and 900 retained samples each. Baseline medians were
0.7980 / 0.7964 ms and candidate medians 0.5111 / 0.5111 ms: approximately
0.286 ms (36%) less validation work. This is not a 36% whole-request speedup.
Negative tests preserve nested forbidden fields, mixed-case names, objects,
open resources and validation after the key-cache capacity is reached.

Two interleaved HTTP comparisons retained 30 samples per endpoint/variant,
with OPcache enabled. Results varied by route and run, so no stable overall
TTFB gain is claimed. File and SQL counts stayed unchanged; the status and
sidebar API response hashes match the checkpoint. Raw runs are
`interleaved-173508/` and `interleaved-173755/` in the established Social
comparison workspace. The second run used a fresh reference export of a
similarly named directory.

The duplicate scan also found upload-validation boilerplate and owner-specific
schema factories. Their differing MIME/size policies and ownership boundaries
are intentional; existing shared image decoding/writing remains in use.
LegacyNames and compatibility aliases are mappings, not parallel implementations.
Application::start and direct context boot have different preparation order;
that is a separate follow-up requiring preservation of injected-context behavior.
The component interpreter remains a profiling candidate and was not rewritten.

Final `composer preflight` passed all 89 groups, including 5,392 API checks,
signed module lifecycle, 5.22.2 MySQL upgrade/data preservation/rollback and
installation rehearsals. Memcached integration and the symlink escape fixture
were unavailable. Report:
`.work/tests/runs/20261116-173854-e9bf360ada/report.json`.

Only the four changed runtime files were backed up and synchronized to the
existing Laragon installation after verifying their original source hashes.
Apache returned 200 for `/`, `/login`, `/privacy`, `/api/users/authentication`
and HEAD `/login`; the authentication resource returned `ok=true`. Configuration,
accounts, uploads and module activation were not changed. Backups and smoke
results: `.work/performance/ttfb-20261115/core-validation-live-20261116-180137/`.
The bundling prototypes remain separate; production was not deployed.

Audit/microbenchmark summary:
`.work/performance/ttfb-20261115/social-comparison/core-validation-audit-report.json`.

## Core Database audit, 2026-11-21

Snapshot export/restore was compared in ABBA order on one disposable local MySQL
InnoDB database: 12,000 rows with 2,048-byte text payloads. Each operation ran in a
separate PHP 8.4.12 process; provisioning and the post-run digest query were
outside the timer. No application database was changed. Raw measurements and
source hashes are in `.work/performance/database-20261121/benchmark.json`.

| Operation | Baseline, two runs | Candidate, two runs |
| --- | --- | --- |
| Export | 0.241 / 0.340 s | 0.085 / 0.112 s |
| Restore | 23.937 / 21.850 s | 1.429 / 1.588 s |
| Export PHP allocated peak | 30 / 30 MiB | 2 / 2 MiB |
| Indexed SQL statements per snapshot | 12,005 | 100 |

Every run returned the same row count, sum of IDs and sum of payload CRC32 values.
These are local synthetic snapshot measurements, not HTTP latency, server RSS or
a general database throughput guarantee. Memory remains proportional to the
largest row plus the current batch. Restore still cannot roll back all MySQL DDL.
The readiness regression separately confirms one schema query without the former
redundant `SELECT 1`; empty schema requirements retain the connectivity probe.

Consumer review covered core Identity, Settings, Notifications, module readiness,
Social publication/import/export/cleanup paths, and the standalone feeds app.
Social runtime queries use the shared connection; explicit PDO in schema factories
is intentional. Publication and feed GUID writes share a transaction, downloads
precede locks, public exports select bounded IDs before details, and notification
subjects hydrate in batches. Avatar replacement intentionally owns its PDO
transaction and filesystem compensation; converting it mechanically would change
its rollback guarantees. Standalone `feeds/` has no database dependency.

Account deletion's separate post-commit loop was moved to the shared runner, so
one owner's failing cleanup does not prevent the remaining owners' effects.
The subsequent CRUD pass implemented the consumer optimizations in the same stage:

- Post deletion removes comment reactions with one set-based statement. Thread
  deletion removes parent/direct-reply reactions and notifications in bounded
  statement counts, without materializing every comment in PHP. Account cleanup
  selects Social post/comment subjects in SQL and preserves other domains, kinds
  and overlapping numeric IDs. All changes stay inside the original transaction.
- `ModuleSchemas::ready` shares the explicit-PDO table inventory with HTTP schema
  checks and batches installation-state reads (500 names/IDs per batch). It does
  not cache readiness; dropped tables and changed states are immediately visible.
- `Transactions::afterCommit` accepts an optional namespaced key. The first
  callback with that key wins within the outer managed transaction; commit and
  rollback clear the keys. Settings coalesces autoload/sensitive-memory cache
  invalidation while keeping locale reset as a separate keyed effect.
- Social sitemap SQL selects a limited page of visible IDs before joining names
  or images. Offset semantics and numbered URLs remain unchanged. Profile
  sitemaps already select only IDs, so they retain their existing selection;
  replacing their offset with a cursor would change the discovery contract.
- Exact single-record reads in post/comment repositories and link hash lookup
  use `Connection::find`, retaining early rejection of invalid IDs/empty hashes.

Raw CRUD measurements and plans are in
`.work/performance/database-20261121/crud-benchmark.json`. The benchmark uses
ABBA order, the same disposable MySQL database and count/timing-only PDO
instrumentation. Fixtures contain 12,000 posts, 1,000 comments and 3,000
notifications, with existing publication/comment/subject access indexes.
Preparation, result validation and rollback are outside the timer. Additional
peak PHP memory is measured relative to each operation's starting usage; first
calls can include class loading. This is not an HTTP benchmark or server RSS.

MySQL cleanup uses type equality per delete so `(type, subject_id)` supports a
combined indexed lookup. Initial IN-subquery and multi-type join prototypes
reduced roundtrips but could repeatedly scan the target and regress elapsed time;
they were rejected after measurement. SQLite retains a set-based IN selection.
No production index or migration was changed. Read-only sitemap execution plans
are retained in the report, including actual loop counts from EXPLAIN ANALYZE.

| CRUD scenario | Baseline, two runs | Candidate, two runs | SQL calls before → after |
| --- | --- | --- | --- |
| Readiness, 8 streams / 24 tables, 50 checks | 2,132 / 2,067 ms | 150 / 137 ms | 2,000 → 100 |
| Repository deletion, 1,000 comments | 614 / 374 ms | 54 / 51 ms | 1,010 → 10 |
| Account notification cleanup | 6,324 / 4,171 ms | 65 / 72 ms | 950 → 7 |
| Thread deletion, 1,000 comments | 1,686 / 1,604 ms | 109 / 83 ms | 4,003 → 6 |
| Save 240 settings | 210 / 92 ms | 84 / 84 ms | 240 → 240 |
| Sitemap, offset 8,000 / limit 1,000 | 107 / 100 ms | 46 / 49 ms | 1 → 1 |

The deep sitemap's actual plan performs image primary-key lookups 1,000 times
instead of 9,878, while retaining the same offset selection and URLs. All
per-scenario result digests match between variants. Settings savings are in
queued effects and PHP work, not in the number of upserts: additional peak PHP
memory was 231–624 kB before and 31–32 kB after (decimal kB). Thread deletion's
additional peak fell from 457–511 kB to 32 kB. Readiness instead trades a small
batched PHP inventory allocation for substantially fewer server roundtrips;
not every scenario reduces PHP memory.

The CRUD audit found no second generic runtime database layer inside Social.
Own SQL remains appropriate for conditional rate-limit increments, one-use reset
claims, publication scheduling locks, aggregates, joins and bounded bulk deletes;
forcing these through simple array CRUD would lose their atomic conditions.
Schema factories and compensated avatar replacement keep their explicit PDO
operations. Simple `find` convergence is a consistency change; its measured
runtime overlaps the former query-builder path, so no speedup is claimed there.

Targeted checks are recorded under `.work/tests/runs/`. Full preflight is deferred
until a release decision at the user's request; this audit is not a release gate.

## Presentation and routing, 2026-11-22

The follow-up fixes one-time path decoding, combined compact/media page URLs,
scalar PHP/JS rendering parity and implicit HEAD in allowed-method metadata.
Real page consumers now pass the declared compact flag for home, author and tag
pages, and filter links retain it. Native dispatch and page resources share the
same decoded path. Public SSR forwards its already prepared document metadata to
the core/theme renderer instead of calculating it twice.

An ABBA PHP 8.4 CLI comparison uses the real 163-route table with no-op handlers,
an in-memory SQLite connection and warm component definitions. Per-round medians:

| Operation | Baseline, two rounds | Candidate, two rounds |
| --- | --- | --- |
| Dispatch `/login` | 0.0356 / 0.0362 ms | 0.0267 / 0.0269 ms |
| Dispatch `/status/42` | 0.1832 / 0.1856 ms | 0.1475 / 0.1484 ms |
| Missing public route | 0.1946 / 0.1963 ms | 0.1570 / 0.1578 ms |
| Render 100 shared detail rows | 3.1104 / 3.1017 ms | 3.1352 / 3.1277 ms |
| Metadata evaluations per resource + document preparation | 2 | 1 |

The route gain is about 0.036 ms in this isolated dispatch, not a 20% HTTP speedup.
The scalar parity fix leaves the table render within about 1% of the reference.
Document-data preparation includes development source-asset processing, so its
timing is not treated as compiled-release TTFB evidence. Prepared document and
table output hashes match between variants. Regex memoization was measured and
rejected: it reduced string constructions but did not improve request-like cold
dispatch consistently. The final router has no such cache.

Raw audit reproductions, the retained cache experiment and the final comparison
are under `.work/performance/presentation-routing-20261121/`, including
`audit.json` and `comparison.json`. Targeted tests cover percent escapes, Unicode,
segment separators, native/resource parity, query combinations, metadata reuse,
theme forwarding, numeric and boolean component cases and HTTP HEAD/405.
Full preflight remains deferred until the user's release decision.

Live Laragon verification used `https://tinycat.test/` after backing up and syncing
the 11 affected runtime files. Canonical asset compilation ran against the live
installation's unchanged module selection/package identity; only changed build
outputs were published, retaining previous content-addressed files. Configuration,
activation state, accounts and media were not replaced. Manifests and backups are
under `.work/performance/presentation-routing-20261121/live-backup/`.

Headless Chrome checked 1280px desktop and 390px mobile pages: home, an author
with compact/image filters, clearing the filter via shared AJAX navigation,
Back and reload, a Czech compact post detail, and login. Screenshots were reviewed;
all measured surfaces had one main target, no horizontal overflow and no browser
runtime exceptions. A live HEAD privacy response retained 200 with an empty body.
Raw states and screenshots accompany `live-smoke.json` in the same workspace.

## Cache and asset audit, 22 November 2026

Audit of the current working sources, including the preceding database and
presentation changes. Application fixes below are **not implemented** by this
audit. The only new developer source is the explicitly requested local
[Memcached lifecycle tool](../../tools/support/memcached.ps1); usage is in the
[tools guide](../../tools/README.md). No release or full preflight was run.

Confirmed findings, in implementation priority order:

1. **High: primary recovery can resurrect stale data.** In
   [Cache::put/lookup](../../App/Core/Support/Cache.php), a connection failure
   writes the replacement to disk and returns success even when deleting the
   previous Memcached value also fails. On reconnection, lookup always prefers
   that older primary value. Reproduction: primary `old`, temporarily refused
   client endpoint, successful fallback write `new`, primary restored: read
   returns `old`. The server remains running throughout, modelling a connection
   partition rather than a restart. `forget` returns false during the same
   partition, but callers such as settings invalidation do not act on that
   result, and the old value becomes visible again. Recovery needs a coherent
   invalidation/tombstone or generation policy, with regression coverage for
   a populated primary, not only an empty primary after an outage.
2. **High when sharing a backend: installation isolation depends on manually
   distinct prefixes.** The Memcached key includes release version and the
   `TINYCAT_CACHE_DIRECTORY` environment value, but omits `cache.directory`
   and installation identity. Two configurations with different cache
   directories, the same prefix and no environment override read each other's
   entry. Settings use this facade, so the impact can extend beyond anonymous
   computed lists. Isolate by a stable installation namespace as well as release;
   test distinct configuration directories without relying on test environment
   overrides. Unique configured prefixes are the current operational workaround.
3. **Medium: package updates can retain old compiled clients and catalogs.**
   [Resources::fingerprint](../../App/Core/Modules/Resources.php) hashes selected
   IDs only. [CompiledAssets](../../App/Core/Support/CompiledAssets.php) checks the
   package registry revision for server components, but its asset/client readers
   do not; [CompiledTranslations](../../App/Core/Support/CompiledTranslations.php)
   accepts the same selection without checking installed package revisions.
   Injecting a matching-selection manifest with a mismatched package revision
   rejects server components but still accepts the old asset URL. Extend the
   same deployment identity policy across readers and verify a module upgrade
   with unchanged selected IDs. This is not a defect in immutable URL hashing.
4. **Medium, latent API collision: filesystem keys are lossy.** `jsonFileName`
   replaces disallowed characters with `_`: `audit/a` and `audit:a` overwrite
   the same file, while Memcached hashes the original key. Confirmed by writing
   both through the facade. Current callers predominantly use safe keys, so no
   existing production collision was demonstrated. Hash arbitrary logical keys
   or enforce one documented key grammar consistently across backends.
5. **Performance opportunity: compiled asset lookup reads source first.**
   [Assets::asset](../../App/Core/Support/Assets.php) calls `AssetGraph::read`
   before checking the compiled URL. Every hit therefore still reads the source
   bytes and validates ownership. Preserve the ownership contract when moving
   that work off the compiled path; HTTP savings have not been measured.

Existing strengths: atomic disk writes, bounded stale lifetime, same-node
refresh locks, content-addressed asset names, retained previous asset files,
manifest selection checks, and server-component package checks. The optimizer,
dependency graph and all 22 delivery-budget scopes passed. Memcached tests now
ran against the real server rather than skipping.

Local service: installed Laragon Memcached **1.6.8**, loopback TCP 11211,
64 MiB, UDP disabled; lifecycle start/status/stop/restart and idempotent start
were exercised. The server was left running. PHP **8.4.12** has installed
Memcached **3.4.0**, but the extension is disabled in its ordinary CLI ini.
The test process tree enabled it via a private `PHP_INI_SCAN_DIR`; live settings
and PHP ini were not changed. No global cache flush was used.

Isolated single-process facade benchmark, 6 KiB repetitive JSON value,
seven batches of 1,000 operations, median batch mean per operation:

| Driver | Fresh read | Replacement write |
| --- | ---: | ---: |
| Filesystem | 0.1321 ms | 4.7035 ms |
| Memcached, loopback | 0.1236 ms | 0.2602 ms |

This is local Windows cache I/O, not an HTTP throughput result. Payload
compression, filesystem behavior and process topology affect the comparison;
it does not establish a general read-speed gain or multi-node refresh locking.

OPcache: ordinary CLI is loaded but disabled, intentionally preserving mutable
test fixtures. Its configured capacity is 256 MiB / 25,000 scripts, timestamp
validation on, revalidation interval one second. Two isolated child processes
read the same existing 549,648-byte compiled component array, seven batches of
100 includes: median **7.6162 ms** without OPcache versus **0.000164 ms** for
warm cached includes (700 hits); serialized output hashes match. This measures
repeated inclusion of an immutable array, not whole-request performance or the
cost of rendering it. Global CLI configuration was not changed.

Live HTTPS and web OPcache statistics were **not verified**: `tinycat.test:443`
refused connections, no Apache worker/listener was available, although Laragon
and MySQL processes existed. CLI ini is not evidence of web OPcache health.
No replacement HTTP stack was started and no runtime synchronization was needed
for this read-only source audit. Follow up on the existing Laragon Apache and
then inspect the authenticated cache overview for capacity, hit rate, restarts,
timestamp policy and actual response compression/cache headers.

Reproductions and measured JSON are in
`.work/performance/cache-assets-20261122/` (`audit.json`, `opcache-off.json`,
`opcache-on.json`); scripts are in `.work/tools/memcached/`. Targeted PHP report:
`.work/tests/runs/20261121-231739-3cd1d68e58/report.json` (six selected groups,
no skips, no source changes). Frontend cache-inspection report:
`.work/tests/runs/20261121-232018-12419be0ab/report.json` (one selected scenario).
Run IDs use UTC and therefore carry the previous calendar date.

### Implemented cache/asset follow-up

All five findings above are addressed in the same implementation stage, without
new source files. The historical audit and its measurements remain above.

- Cache mutations for Memcached serialize through per-key, same-node mutation
  locks, separate from refresh computation locks. A failed primary replacement
  or deletion persists a local bypass marker. Readers use the disk fallback or
  miss while that marker exists, even after the primary recovers or the fallback
  expires. A successful mutation removes the old fallback and then the marker.
  Failed primary deletion therefore counts as successful local invalidation only
  when both the durable bypass and disk deletion succeed. Markers deliberately
  survive until reconciliation; cleanup must not age them independently of the
  primary's lifetime. These guarantees cover workers sharing the installation's
  cache directory, not independent cache disks on multiple nodes.
- Memcached identity now includes the installation base path and effective
  configured cache directory as well as release and prefix. Disk JSON names hash
  the complete logical key. Existing old filenames become cold misses and expire
  through existing cleanup; no operational data is removed. Legacy JSON payload
  format remains supported. Scheduler fixtures and the profiler's explicit
  expiry operation use the same hashed names.
- The shared compiled-manifest reader validates the package registry revision
  for clients, individual assets, server components, application translations and
  email translations. The compiler writes the same existing identity field into
  both manifests. Mismatched outputs fall back to owned sources. Previously built
  translation manifests without that field are rejected when a package registry
  exists; recompile through the canonical builder when publishing.
- AssetGraph now separates ownership/path validation from reading source bytes.
  Compiled asset hits preserve ownership validation without reading the payload;
  source fallback still uses the existing graph and publisher.

Regressions cover populated-primary connection partitions, replacement,
reconnection, invalidation, fallback expiry, directory isolation, distinct
arbitrary keys, expired-file cleanup, mismatched package manifests and shared
compiler identity. No database schema, runtime configuration or generated output
was manually changed. Memcached remains running on loopback.

The follow-up cache microbenchmark is `audit-after.json` next to `audit.json`.
It observes medians of 0.3251 ms/read and 1.0116 ms/write for Memcached versus
0.1458 ms/read and 5.1090 ms/write for disk. The local synchronization and bypass
checks add work; this change prioritizes consistency and does not claim a cache
read speedup. These are separate local runs, not a controlled HTTP comparison;
the first seconds of the follow-up overlapped static checks, so precise relative
performance changes require a dedicated idle-machine rerun. No HTTP improvement
is inferred from the compiled asset source-read removal.

Verification, all passed with no selected skips:

- `.work/tests/runs/20261121-232418-a593bbf158/report.json`: cache, real Memcached,
  concurrent refresh, optimizer, source translations, dependency graph and
  delivery budgets. Later reports supersede amended regressions below.
- `.work/tests/runs/20261121-232519-f05a3cd341/report.json`: final asset/package
  regressions, canonical compiler preparation and module/dependency boundaries.
- `.work/tests/runs/20261121-232704-bbcd833111/report.json`: final Memcached
  partition/expiry regressions and scheduled file cleanup.
- `.work/tests/runs/20261121-232728-ee3592d73c/report.json`: HTTP API contracts and
  hot-read SQL budgets for public HTML/JSON, tags, authors and search.
- `.work/tests/runs/20261121-232856-a0ede2b69f/report.json`: scoped client assets,
  translation runtime and anonymous cache diagnostics lifecycle.
- PHP lint, deterministic style, PHPStan and diff whitespace checks passed.

The selected runs had no source changes during execution. Full preflight remains
explicitly deferred until release. Live HTTPS, native browser verification and
web OPcache diagnostics remain unavailable with the existing Apache listener
stopped; no replacement server stack or live-runtime synchronization was used.

## Scheduler audit, 22 November 2026

Read-only implementation audit of registration, transports, locks, history,
health, feed execution and registered maintenance. No scheduler implementation
was changed and no live publishing, cleanup or notification job was executed.
The following findings remain open.

1. **High: Bearer credentials have weaker transport enforcement than Basic.**
   [Tokens::requireBearer](../../App/Core/Tasks/Tokens.php) accepts a valid token
   on an HTTP request; `requireBasic` rejects insecure transport with 426.
   An isolated request with a synthetic token and `REQUEST_SCHEME=http` passed
   Bearer authorization. The runtime does not provide a universal HTTPS redirect.
   Require the same scheme policy for both credential transports and regress
   HTTP/HTTPS and supported proxy handling. No real token was sent over HTTP.
2. **High, conditional on shared data: scheduler locks identify a filesystem
   path instead of the database being mutated.**
   [Runner](../../App/Core/Tasks/Runner.php) includes a base-path hash in MySQL
   advisory locks. Two checkouts/deployments using the same database can acquire
   different locks for the same task. Two independent MySQL connections acquired
   both synthetic path-derived locks simultaneously (`1, 1`); audit lock names
   were random and released immediately. This permits overlapping cleanup/feed
   runs, and `History::start` can mark another path's live run interrupted.
   Lock identity must follow the shared storage domain while preserving isolation
   for different databases. SQLite file-lock isolation needs the same review.
3. **Medium: skipped runs can conceal failed or stale work.**
   [History::lastSuccessfulFor](../../App/Core/Tasks/History.php) includes
   `not_due` as successful work, while [HealthCheck](../../App/Core/Tasks/HealthCheck.php)
   examines failure only on the newest row. A stale completed run, a recent
   failure and a subsequent `not_due` changed the health result from `warning /
   failed` to `ok`, without completing any work. For cleanup this can occur when
   a forced admin run fails inside the hourly quiet period. Distinguish invocation
   liveness from last meaningful success and retain unresolved failure state.
4. **Medium: feed batch completion omits remaining work.**
   [Importer::runDueSources/scheduledResult](../../App/Modules/Social/src/Bots/Importer.php)
   limits selection but never reports a remaining due-source backlog; successful
   results always become `completed` with `has_more=false`. An isolated fixture
   with two due sources, limit one and a simulated lost claim returned completed,
   processed one skipped source and left both sources due. No feed was fetched.
   The same result-construction issue applies when a successful batch leaves
   additional due sources. Return truthful backlog/not-due state without widening
   the selected batch or publishing anything twice.
5. **Medium, performance: duplicate cleanup bounds deletion/output, not input
   hydration.** [DuplicateCleanup::candidates](../../App/Modules/Social/src/Bots/DuplicateCleanup.php)
   reads every duplicate candidate, hydrates all their relations in chunks and
   only then stops at the requested pair limit. With `candidates(1)`, an isolated
   SQLite fixture returned one pair for both 100 and 5,000 identical posts, but
   executed **4 vs 31 SELECTs**, with approximately **0.50 vs 2.36 MiB** additional
   PHP peak memory. The first round includes cold class loading, so its wall-clock
   time is not used as a speed comparison. A safe repair needs bounded candidate
   windows plus persistent progress/fairness and exact relation-set comparisons;
   simply adding an early LIMIT can hide duplicates or starve later candidates.

Additional registration hardening: [ScheduledTask](../../App/Core/Modules/ScheduledTask.php)
validates option names but does not enforce integer default values despite the
module-authoring contract. An option default of `not-an-integer` was accepted.
All three current registrations have valid defaults, so this is a future-module
contract gap rather than a demonstrated failure of a built-in task.

### Registration and task inventory

All current task IDs have a single owner; duplicate contribution IDs are rejected.
Collecting the complete registration inventory performed **zero SQL calls** in
an instrumented process. Runtime callbacks, normalization, health-age callbacks
and detail resolvers remain lazy. CLI, admin, Bearer execution and compatibility
triggers dispatch through the shared execution/runner; HEAD on a trigger only
checks availability and does not run the task. Contexts for all selected tasks
are validated before execution starts.

| Task | Owner | Defaults / policy | Audit coverage |
| --- | --- | --- | --- |
| `feeds` | `tinycat.social` | limit 20, clamped 1?100; admin-only source ID | claims, atomic GUID/publication, independent queues, retries; backlog finding above |
| `cleanup` | `tinycat.scheduler` | batch 500; hourly unless admin forces | rotating start, per-maintenance errors, bounded retention and file cursors |
| `site-health` | `tinycat.health` | once per day; admin can force | isolated checks, transactional notifications/day completion, retry on delivery failure |

The 14 maintenance contributions are: orphan terms/links, orphan status/link/avatar
files, temporary files, update files, expired JSON cache files, action limits,
password-reset tokens, read notifications, scheduler history, bot history and
bot duplicate posts. Each remains registered by its actual owner. The inventory
with canonical IDs, owners and execution order is `audit.json` in the workspace.
Update cleanup retains its updater lock, confirmed-backup rules and bounded
resumable scans. File cleanup preserves active/recovery artifacts and avoids
symlink traversal in the shared sweep. Bot history retains per-source successful
runs and recent error history. The exception is the unbounded candidate hydration
identified above, not a demonstrated unsafe deletion.

### Verification and local installation

- `.work/tests/runs/20261121-233654-8a54069b61/report.json`: eight passed groups:
  scheduler/MySQL, scheduler CLI, scheduled files, cleanup retention, site health,
  built-in bots, publication queue and per-feed queues.
- `.work/tests/runs/20261121-234021-3ce7c8b9f9/report.json`: module registration
  pilot and duplicate housekeeping, both passed. No selected skips or source
  changes occurred in either run. Existing green tests do not cover the newly
  reproduced gaps.
- Reused HTTP transport evidence from the preceding unchanged implementation:
  `.work/tests/runs/20261121-232728-ee3592d73c/report.json`; the full HTTP group was
  not repeated merely for this source audit.
- Read-only CLI health from `E:/laragon/www/tinycat` reports the same three tasks.
  Live HTTPS scheduler health returns **503 `scheduler_not_configured`** because
  no scheduler token is configured. That is an operational setup gap for HTTP
  invocation, not evidence that CLI execution is broken. No token was generated
  or rotated, no OS cron schedule was installed, and no live task was started.

Reproduction scripts and raw results are under
`.work/performance/scheduler-20261122/` (`audit.json`, `duplicate-scan.json`).
MySQL audit activity used only isolated tests and temporary advisory locks, not
live domain writes. No new runtime/source file was introduced. Full preflight
remains deferred to the release decision. No UI was changed, so no new native
visual regression run was necessary.

### Scheduler implementation follow-up

All five audit findings and integer-default validation are implemented in existing
source files. Both token transports use one HTTPS/cross-site/no-store guard.
MySQL task locks identify the selected database and task; SQLite locks follow
the actual main database file, with a separate identity for in-memory databases.
Workers using the same data must be upgraded together: old path-derived locks
cannot coordinate with the new lock namespace during a mixed-version rollout.

Health uses meaningful completed/partial work for success age and ignores skipped
or still-running invocations when selecting the latest work outcome. Feed batches
query for eligible due sources after processing and report continuation or no work
accurately. Registration rejects noninteger defaults before callbacks run.

Duplicate cleanup now reads at most 257?1,001 candidate rows per scan, depending
on the requested batch, and returns only IDs and exact keeper matches. The SQL
compares body bytes and both directions of each attachment/tag/link relation set.
Keepers can be older than the current window. A durable cursor advances after
successful processing; failure retries the same window, completion wraps to zero,
and preserved/unique rows cannot permanently starve later candidates. Preview
remains read-only and examines the first bounded window. The existing public
candidate helper has no application UI consumer. Merge transactions still recheck
the pair and preserve reactions, discussion, reports, aliases and file ownership.
No migration or generated asset change is required for this stage.

Regressions include HTTPS rejection for both Bearer endpoints, independent MySQL
connections sharing the database-derived lock, skipped runs after failure/stale
work, lost feed claims with remaining backlog, invalid default types and a late
duplicate beyond 650 unique rows whose keeper lies in an earlier window.

At 5,000 identical SQLite rows, `candidates(1)` now executes one SELECT instead
of 31 and uses about 115 KiB additional PHP peak memory instead of 2.36 MiB.
The synthetic fixture has no primary/author index, unlike production; the new
correlated SQL costs more database time there (82 ms vs the audit's 21 ms).
This demonstrates bounded PHP hydration and fewer round trips, not a general
latency gain. SQL keeper searching can still inspect older rows and depends on
author/ID indexes and data distribution. Raw results are `duplicate-scan-after.json`
next to the original measurement.

Targeted reports: `.work/tests/runs/20261121-234730-ba15bb535d/report.json`
passed duplicate, scheduler-file and health regressions before a test-only missing
context argument stopped the MySQL group. The corrected MySQL regression and HTTP
API suite both passed in
`.work/tests/runs/20261121-234800-e9f5f2947f/report.json`.
Lint, style and PHPStan passed. Final full preflight is now authorized for the
upcoming release and is run only after source, test and documentation edits end.
Its separate measured report determines complete source validation; targeted
passes alone are not presented as release verification. No live job was executed,
no scheduler token was created and no release was published by these source fixes.

## JavaScript audit, 22 November 2026

Audit of core/Social client sources at release commit `6421a0e2` (7.1.1):
94 authored JS files, 380,283 bytes on disk in this checkout. This sum is not a
per-page transfer budget: route-scoped compilation, minification and compression
produce different payloads. No client source, asset, configuration or live data
was modified. The following findings are open, with no implementation in this
stage. Static inspection covered lifecycle, modules, requests, navigation, DOM
construction, forms, dialogs, media and shared/domain resource ownership; it is
not a claim of an exhaustive browser-security proof.

### Confirmed findings

1. **Medium: modal keyboard traversal includes elements that are not Tab stops.**
   [dom.focusables](../../App/Core/Ui/assets/runtime/dom.js) includes native buttons
   and links through selector branches that do not exclude `tabindex=-1`.
   Its visibility check also misses effective disabled state inherited from a
   fieldset and ancestors marked `inert`. Native Chromium returned all four
   synthetic buttons: negative tabindex, normal, disabled-fieldset and inert;
   only the normal button was eligible for sequential keyboard traversal.
   [dialogs](../../App/Core/Ui/assets/dialogs.js) uses this array as the first/last
   boundary for Tab trapping, while [tabs](../../App/Core/Ui/assets/tabs.js)
   deliberately assigns -1 to inactive tabs. Distinguish programmatic focus
   targets from sequentially tabbable controls and regress modal wrapping after
   changing the selected tab. The probe demonstrates the incorrect traversal
   list; it does not claim every existing dialog currently loses focus.
2. **Medium robustness gap: one failing lifecycle hook stops unrelated hooks.**
   [lifecycle](../../App/Core/Ui/assets/runtime/lifecycle.js) sets `started=true`
   before initializing all services and stops at the first exception. With three
   services, an injected failure in the second prevented the third from starting;
   a second `start()` did nothing. During disposal, a failing middle hook likewise
   prevented the earlier service from cleaning up. The domain-module dispatcher
   has the same stop-on-first-error behavior. This can also block shared pending
   request cancellation, not only cosmetic hydration. Isolate hook failures and
   expose diagnostics without silently hiding errors; define retry behavior for
   partial startup. No spontaneous lifecycle exception was observed in the live
   navigation probe: this finding is demonstrated by controlled fault injection.
3. **Medium performance opportunity: every input validates the whole form.**
   [form-constraints](../../App/Core/Ui/assets/form-constraints.js) rescans all
   constrained fields for `input`, `change` and editor-sync events. Each byte check
   creates a new `TextEncoder`. One event in a synthetic form with 250 byte-limited
   fields created 250 encoders and encoded all 250 values, including untouched
   fields. Scope interactive validation to the changed field and any explicit
   proxy/dependency; keep full validation for hydration/submission and reuse an
   encoder. This is an allocation/work-count measurement, not evidence of a
   measured input-delay regression on the current settings page.
4. **Lower priority contract gap: generic form binding drops the submitter.**
   [forms.bind](../../App/Core/Ui/assets/form-requests.js) uses `new FormData(form)`
   without adding the activated submit button. The main AJAX binding already adds
   its name/value, so the two shared paths disagree. Native submission of a fixture
   included `intent=publish`; the bound request contained only the text field.
   The probe intercepted the API request locally, so no POST reached the server.
   The sole current application caller is installation, whose buttons do not
   demonstrate this data loss today. Preserve native submitter semantics before
   further consumers use this helper; do not add a third serialization path.

### Additional contract recommendations and a rejected leak hypothesis

Hydration sorts by `hydrateOrder`, but disposal reverses registration order.
With high-order service A registered before low-order B, the observed order was
hydrate B/A, dispose B/A, rather than reverse hydration. Initialization also uses
registration order. Define which ordering establishes dependencies and align
teardown with that contract; no concrete built-in dependency failure was shown.

Sticky layout registers observation for each profile/sidebar node but has no
lifecycle dispose hook. Eight same-document AJAX transitions left four detached
profile targets in instrumented observer bookkeeping. An initial strong Set in
the probe itself could retain those targets, so it was replaced with **WeakRef**
tracking and followed by two forced garbage collections. Only the connected public
sidebar remained alive afterward. **A persistent native heap leak was not
confirmed.** Explicit unobserve/cancel of layout work is a cleanup recommendation,
not a proven memory-leak finding. Existing mobile viewport cleanup unobserves
public sidebars; it does not provide a generic profile-subtree disposal hook.

The initial hypothesis about stale language-alternate head links was also not
confirmed: the live pages did not expose such links. It is not counted as a bug.

### Verification and evidence

- `.work/tests/runs/20261122-091712-7992f32b1a/report.json`: five selected scenarios
  passed: client lifecycle/disposed asynchronous work, shared UI/API primitives,
  translation, sidebar viewport cleanup and AJAX races. No skips or source changes.
  The new probes cover failure/boundary cases missing from those green tests.
- Native headless Chromium used `https://tinycat.test/` at desktop width and eight
  AJAX transitions in the same document, then WeakRef/GC inspection. No page errors
  were observed. Separate native form/focus probes used only temporary DOM and an
  intercepted fake API call. No live account, content or settings writes occurred.
- The live document reports an older runtime shell than repository release 7.1.1.
  Relevant lifecycle/DOM/forms/constraints/sidebar source files were compared with
  the checkout and match after line-ending normalization; no runtime synchronization
  was required for these unchanged mechanisms. This does not claim that all 7.1.1
  runtime changes have been deployed to the live installation.
- Existing final-commit source CI and artifact verification from release 7.1.1
  remain the broader successful baseline. They were not rerun without source
  changes. No new preflight was launched for this read-only JavaScript audit.
- The source scan found only the shared API client's fetch and the intentionally
  anonymous same-origin cache-header probe; the latter uses omitted credentials
  and redirect rejection. The two observed `innerHTML` assignments clear editor
  suggestion containers. No eval/Function-based code generation was found in the
  authored JS inventory. These observations are narrower than an XSS guarantee.

All raw evidence is in `.work/performance/javascript-20261122/`: `inventory.json`,
`audit.json`, `live-navigation.json`, `form-submitter.json`, and
`live-source-parity.json`, alongside the isolated reproduction scripts. No new
tracked source file was added; only this existing profiling document was updated.

### JavaScript implementation follow-up

The four confirmed JavaScript findings are fixed in existing shared files.
Lifecycle catches and reports each service/module hook error, completes unrelated
hooks and remembers only successful initialization for retries. Disposal now
reverses hydration ordering. Sequential focus excludes negative tabindex,
fieldset-disabled and inert controls while preserving implicit editable hosts,
including plaintext-only editors. Native form serialization includes the actual
submitter on both shared request paths. Input-time constraint checks validate
only the changed/canonical control and reuse one encoder; full submission checks
remain intact. Existing GET empty/repeated query semantics are retained through
a shared URL serializer. No CSS runtime rule was changed in this stage.

Regressions cover failed startup followed by retry without duplicate good init,
failed disposal without abandoned cleanup, reverse dependency order, editable
focus, external submitters serialized once, editor proxy validation and full-form
submission validation. In the 250-field native fixture, one input now performs
**one byte encoding instead of 250**; explicit full validation still encodes all
250 controls. This measures work/allocation reduction, not end-to-end latency.

Initial attempts to retain every old JS budget failed: the shared error/retry
boundary and native-focus rules added mandatory behavior to a near-full bundle.
After consolidating module dispatch and GET serialization, the maximum modeled
payload increase versus the signed 7.1.1 artifact is **557 uncompressed minified
bytes / 286 gzip bytes** across delivery scopes. Only required JS bounds were
rounded to the next 256-byte boundary, at most **512 bytes above the previous
limits**. CSS bounds, script counts, stylesheet counts and unrelated JS bounds
are unchanged. This explicit adjustment is not presented as a payload speedup.
Raw comparisons and exact changes: `payload-comparison.json`,
`after-assets.json`, `budget-adjustments.json` in the JavaScript workspace.

Final targeted frontend report:
`.work/tests/runs/20261122-094345-cb21031d91/report.json` (ten selected scenarios,
all passed, no skips/source changes), including native form validation,
popovers, dialog history, public navigation and compiled client assets. UI/modal
contracts, module/dependency boundaries, delivery budgets and diff checks passed.
Earlier failed fixtures were corrected to use the real lifecycle dependency,
realistic event targets and native two-argument FormData semantics; a malformed
new fixture branch was also corrected before the final native run.

Live verification synchronized seven backed-up JS sources and canonical generated
outputs only, then the final editable-focus helper/build correction. Backups and
manifests are in `javascript-20261122/live-backup/` and `live-backup-final/`.
Configuration, storage, accounts and uploads were not synchronized. Desktop/mobile
native probes confirmed exact submitter serialization and valid traversal; eight
same-document public transitions emitted no page errors. Native probe files with
`-after` suffix preserve the post-fix evidence. The older live runtime shell was
retained; this is not a claim that all PHP from release 7.1.1 was deployed.
No full preflight or new release was run, per the user's stage/release preference.

## CSS audit, 22 November 2026

Read-only follow-up audit: **129 source stylesheets / 101,425 bytes**, with 834
rule/declaration blocks identified by a lightweight source scanner. This inventory
includes owner and standalone embed styles, not just a single route's bundle.
The scanner is a candidate finder, not a full CSS parser or proof that moving a
rule is safe. Source CSS and generated inventory were not rewritten.

Prioritized findings and consolidation candidates:

1. **Hardcoded contrast and missing semantic tokens.**
   [reset.css](../../App/Core/Ui/assets/styles/reset.css) sets skip-link text to
   `#fff` over `--primary` rather than the existing `--on-primary`. Native computed
   styles confirm 16px white text over `#91b4cf` in dark mode: calculated contrast
   **2.18:1** (light mode **4.76:1**). The normal-text target is 4.5:1 in
   [W3C SC 1.4.3](https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html).
   The former Social `profile-navigation.css` stylesheet
   references `--on-warning`, which has no core declaration and falls back to
   `#1f1700`. Its computed warning palette is 3.54:1 light / 9.74:1 dark. The current
   element is an aria-hidden warning symbol with a separate accessible label, so
   these ratios are not asserted as a normal-text WCAG failure for that widget.
2. **Core Identity still consumes Social-owned CSS names.**
   [Identity components](../../App/Core/Identity/assets/components.json) use
   `profile-nav-link` and `profile-email-warning`, whose relevant rules live in
   Social's profile stylesheet. Core-only presentation therefore cannot rely on
   those declarations. Move generic account-menu/warning presentation into the
   existing core account styles and keep Social profile presentation with Social.
   This is an ownership correction; do not import Social CSS into core.
3. **Exact repeated bases: five detected groups of at least three declarations.**
   The strongest local opportunity is the identical border/radius/surface block
   for link/video cards and `.status-social-link` in
   [status-content.css](../../App/Modules/Social/assets/content/styles/status-content.css).
   The profile and public layout roots also share `display:grid; gap:18px;
   align-items:start` in separate Social stylesheets. Confirm route dependency
   selection before sharing their declaration in an existing owner stylesheet.
   Truncation repeats in five core/Social regions, including two rules within the
   same admin stylesheet. Prefer combining local selectors or existing component
   variants; a global cross-owner utility is not automatically an improvement.
4. **Repeated dimensions need semantic review, not blanket tokenization.**
   The scan found 12px 36 times, 8px 34 times and 10px 30 times in non-custom-property
   declarations. These counts include different purposes and are not defect counts.
   Review navbar/filter control geometry against existing `--control-height` and
   related tokens; e.g. 40px literals coexist with rem-scaled control dimensions.
   Shared layout gaps can use a meaningful owner variable where consumers really
   agree. Keep deliberate hairlines, image geometry and media ratios distinct.
   The 899/900px boundary also coordinates JavaScript media queries: CSS custom
   properties cannot simply replace native media-query conditions.
5. **Standalone embed palette repeats literal colors.**
   [embed.css](../../App/Modules/Social/assets/embed/embed.css) repeats muted
   `#687180` in four light-mode rules and duplicates its dark counterpart. Extract
   local embed palette variables inside that same file and keep this independent
   document independent of the full core theme. Identical iframe/thumb geometry
   across embed and feed is not sufficient reason to load one into the other.
6. **Notation and near-duplicates.**
   [cookie-consent.css](../../App/Core/Ui/assets/styles/cookie-consent.css) contains
   four leading-dot unit values (`.8rem`, `.2rem`, `.875rem`, `.5rem`) while the
   surrounding core styles use leading zeroes. Unify touched authoring syntax,
   including standalone opacity shorthand, without manually editing build output.
   Four rule pairs had at least 80% declaration overlap. For example `.check-line`
   and `.content-image-preview-empty` differ only in font weight but represent
   different components; similarity alone does not justify a new micro-utility.

The five `!important` declarations found are hidden-state enforcement and reduced
motion policy. They are not cleanup defects merely because they use importance.
No CSS size, render-time or visual improvement is claimed before implementation.
Next CSS stage should preserve cascade order, owner selection, responsive rules,
focus/hover states and theme contrast; verify affected public/admin surfaces,
mobile/desktop geometry, shared UI contracts and asset reports. Do not split the
styles into more source files.

Evidence: `.work/performance/css-20261122/audit.json`, `token-contrast.json` and
`theme-probe.json`, with scanner/native probe scripts alongside. The theme probe
used temporary DOM/style changes in headless Chromium on the existing Laragon
site, with no saved theme setting or user-data mutation. Rules/at-rule contexts
were retained when grouping candidates; all highlighted examples were read in
source before being recommended. CSS optimization itself remains unimplemented.

### CSS implementation and icon alert review ? 2026-11-22

Implemented the reviewed CSS corrections without adding source files:

- Skip-link foreground uses `--on-primary`; native dark contrast is now 7.51:1
  instead of 2.18:1. The light palette is unchanged.
- Account trigger positioning and the warning badge belong to existing Identity
  user-menu styles. Identity no longer references Social profile classes. The
  now-empty Social stylesheet and its manifest entry were removed. `--on-warning`
  is declared alongside the shared warning palette.
- Combined the exact local card and admin truncation bases, normalized leading
  zeroes, and consolidated the standalone embed palette in its existing file.
  Native Chromium comparison preserved all sampled embed colors and geometry in
  light and dark modes (36 elements). Separate owner layouts and near-duplicates
  remain separate: they do not have an established shared component contract.
  Fixed media/image dimensions and coordinated breakpoints retain their semantics.
- Regenerated the UI inventory and reviewed CSS rule snapshot: 784 to 781 rules.
  CSS delivery limits were not increased. These are maintainability and contrast
  corrections; no render-time speedup is claimed.

Targeted verification: UI ownership/contracts, dependency and module boundaries,
asset budgets (22 scopes), standalone Accounts presentation/build, component
presentation and asset graph passed. Six selected frontend scenarios passed in
`.work/tests/runs/20261122-095533-bc87e4eaa7/report.json`; its seventh scenario
(styles) initially reported the expected old inventory. After reviewing the
changed rules and updating the existing baseline, the isolated styles rerun
passed in `.work/tests/runs/20261122-095718-6d1ac6397e/report.json`. PHP checks:
`.work/tests/runs/20261122-095720-7be9f1b569/report.json`. No preflight was run.

Only changed runtime files and canonical build outputs were synchronized to
Laragon with backups under `.work/performance/css-20261122/`. Native 390px/1280px
light/dark screenshots checked the actual guest login and 404 surfaces, focus and
horizontal overflow. Guest `/` resolves to login and `/author/1` returns 404;
authenticated live feed/admin visual inspection was unavailable. Actual account,
admin and other component behavior was exercised by native isolated fixtures.
Live evidence: `live-after.json`; embed equivalence script/screenshots and before/
after CSS scans are in the same work directory. No user setting or data was saved.

GitHub [CodeQL alert 17](https://github.com/hybernia1/TinyCat/security/code-scanning/17)
is the relocated occurrence of previously dismissed alert 4, with no linked PR.
The reviewed code creates SVG nodes using `createElementNS` and writes the sprite
reference with `setAttribute`; it does not interpret the string as HTML. The icon
name is allowlisted, the owned sprite URL comes from `Assets::asset`, and document
attributes are escaped by the shared template. Native live metacharacter probes
on both viewports produced only a single SVG `use` child and rejected invalid
icon names, with no injected element or execution. This occurrence is assessed as
a false positive under the current source contract, not fixed by double-escaping
an attribute. The alert was left unchanged on GitHub. See the
[CodeQL rule explanation](https://codeql.github.com/codeql-query-help/javascript/js-xss-through-dom/).
