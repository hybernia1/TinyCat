# Repeatable performance profiling

New and changed list queries follow the [selection and pagination standard](../contracts/query-selection-standard.md).
Store each run's reports with its commit, dataset and environment below
`.work/performance/`; historical measurements do not describe current performance.

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
