# TinyCat 2.0.26 monolith backport plan

TinyCat 2.0.26 will start from the released `v2.0.25` tag. The local 2.5 work
is an experimental source of measured query improvements, correctness fixes,
security checks and release tooling; it is not an ancestor to merge into the
new release branch.

The goal is to keep the small, low-overhead 2.0.25 runtime while applying only
changes that can demonstrate a concrete benefit. `App/Core.php`,
`App/functions.php` and the combined updater remain intentional parts of the
design. A large class graph, a compatibility layer for unpublished 2.5 code,
and forwarding facades are explicitly out of scope.

## Evidence behind the decision

The released baseline has 18 PHP files below `App/`, with 15 files declaring a
class or interface. The local 2.5 tree has 260 PHP files and 257 class,
interface or enum files. Its normal web runtime eagerly constructs a broad
repository, service, controller and web-facade graph.

On the deterministic medium dataset, 2.5 reduced database work substantially:

| Route | 2.0.25 queries | 2.5 queries |
|---|---:|---:|
| Public feed | 24 | 4 |
| Status detail | 8 | 7 |
| Tag feed | 25 | 5 |
| Author profile | 25 | 17 |
| Search | 20 | 8 |

That improvement did not consistently become lower response latency. With
production-equivalent OPCache and filesystem cache, 2.5 p95 was 4.39% to
18.93% slower on all five measured routes. With Memcached, feed and tag were
faster, but status detail was 35.71% slower. Without OPCache, the cost of the
larger source graph became much more pronounced.

This makes query batching and render-only partials useful backport candidates,
while the new runtime architecture is not.

## Selection rules

Every 2.5 change is assigned to one of these groups before implementation:

| Group | Treatment | Typical candidates |
|---|---|---|
| Direct backport | Copy the small behavior change into the existing 2.0.25 owner and add a regression test. | SQL/index corrections, bounded input checks, package validation checks, test and benchmark tooling. |
| Monolithic rewrite | Preserve the result, but implement it in `Core.php`, `functions.php`, an existing class or route owner. | Batched feed preparation, transaction boundaries, prepared partial data, validation fixes. |
| Development tooling only | Keep outside the production runtime and release package. | Deterministic importer, comparative benchmark, lint/style/security scripts, release rehearsals. |
| Reject | Do not bring it to 2.0.26. | Composition root, ports/adapters, repository-per-entity graph, application services, web facades, `ViewContext`, class renames and 2.5 compatibility shims. |

A change is accepted only when all of the following are true:

1. It fixes a reproducible bug, removes measured database work, improves a
   security boundary, or strengthens build/update verification.
2. It has a focused automated test or a repeatable benchmark.
3. It does not make the 2.0.25 extension or update contract incompatible.
4. It does not require importing the 2.5 dependency graph.
5. Its hot-path latency and memory cost stay inside the release gates below.

## Monolith boundaries

- `Core.php` remains the runtime, configuration, database and query owner.
- `functions.php` remains the procedural application layer. It is organized
  into named sections with explicit public entry points and private helpers;
  it is not split merely to make the file shorter.
- Public routes orchestrate a request. Partials render data supplied by their
  caller and must not query the database.
- Existing substantial classes such as `Cache`, `Minifier`, `StatusLinks`,
  `LinkMetadata`, media handlers and the combined package manager remain under
  their 2.0.25 names. A rename is not an improvement.
- A 2.0.25 facade used by extensions remains compatible in a patch release.
  An internal facade may be removed only after a complete call-site scan and a
  contract test prove that it is neither public API nor a real state owner.
- No 2.5 legacy or compatibility layer is created. The unpublished 2.5 API has
  no place in the 2.0.26 runtime.
- Production class count must not grow beyond the 2.0.25 baseline by default.
  Any exception must replace more code than it adds and pass a separate cost
  review; a new layer or one-method forwarding class is never an exception.

## Stage 0: preserve the experiment and create a clean baseline

- Preserve the current 2.5 work, benchmark reports and importer on an archival
  branch or tag before switching histories.
- Create the 2.0.26 branch directly from `v2.0.25`; do not merge or revert the
  2.5 refactor commit into it.
- Transfer the importer, comparison runner and useful reports independently of
  production code.
- Run the untouched 2.0.25 test suite and benchmark matrix and record PHP,
  OPCache, MySQL, Memcached, web-server and hardware details.
- Capture latency, throughput, SQL questions, FastCGI working set, PHP peak
  memory, included-file count and loaded-class count.

**Exit:** a reproducible clean 2.0.25 baseline, an archived 2.5 experiment and
no mixed production history.

## Stage 1: install quality gates without changing runtime behavior

- Backport the PHP lint, deterministic style, PHPStan, security and preflight
  tooling as development-only dependencies.
- Bring over the public-route smoke test, view output tests, query accounting,
  updater artifact checks, update/rollback rehearsal and fresh MySQL installer
  rehearsal, rewriting them against the 2.0.25 API.
- Keep useful GitHub quality automation, but exclude Composer, `vendor/`, test
  fixtures, importer data and benchmark reports from production packages.
- Reject tests that merely assert the 2.5 class layout. Replace them with
  behavioral and monolith-boundary checks.
- Add a dependency rule that prevents production code from referencing
  `TinyCat\Application`, `TinyCat\Composition`, repository ports, web facades
  or a 2.5 compatibility namespace.

**Exit:** the unmodified 2.0.25 application passes a single local preflight;
the new tools have zero effect on public responses and production boot cost.

## Stage 2: backport database gains into the hot read paths

- Trace every query for feed, tag, author, search and status detail against the
  existing `public_status_query()` and `status_preload_feed()` flow.
- Batch viewer likes, comment counts, mentions and link data once per result
  set. Reuse values already selected by the main query instead of asking for
  them again from a partial.
- Load comment trees and their viewer reaction state in bounded batches rather
  than recursively or per rendered item.
- Port only SQL or index changes that improve an `EXPLAIN ANALYZE` result on
  the representative dataset. Preserve keyset pagination and bounded result
  sizes.
- Add route-level query budgets. The first target is no worse than the observed
  2.5 counts (4/7/5/17/8 respectively), unless a documented correctness query
  makes a route intentionally different.
- Do not introduce repository, endpoint, presenter or service objects.

**Exit:** the five read paths meet their query budgets, return byte-equivalent
content where expected and show no latency or memory regression.

## Stage 3: make partials render-only with plain data

- Change status actions to consume preloaded `comments_count` and
  `viewer_liked` values without fallback queries.
- Pass an already loaded comment tree to `comments-thread.php`.
- Prepare time-link, link-card, notification, pagination and similar repeated
  values in the route or existing function owner, using plain arrays rather
  than `ViewContext` or view-model classes.
- Remove database and service lookups from `Public/parts`. Add a static test
  for query-capable calls plus output tests for the affected partials.
- Keep reusable escaping, translation and URL formatting helpers; render-only
  does not mean duplicating presentation primitives in every template.

**Exit:** partial rendering cannot increase the SQL count, and HTML/API
contracts remain stable.

## Stage 4: clean the monolith without decomposing it

- Build a call-site inventory of every function and of the existing `Core`,
  `Cache`, `Minifier`, `StatusLinks`, `LinkMetadata`, `UserRoles`, media,
  extension and package-manager APIs.
- Remove dead private functions, duplicate branches and pass-through wrappers
  only after tests prove they have no extension or runtime caller.
- Group `functions.php` into consistent sections: bootstrap/runtime, input and
  response, users/auth, statuses/comments/reactions, search, moderation,
  notifications, administration and maintenance. Keep dependency direction
  visible in section documentation.
- Consolidate duplicate normalization, pagination and permission checks into
  one existing owner. Avoid generic helper bags and hidden service locators.
- Keep large cohesive implementations in their current class when they own
  real state or a security boundary. `Cache` and `Minifier`, for example, are
  implementations rather than disposable one-line facades.
- Measure included files, loaded classes, peak memory and representative
  request latency before and after every cleanup batch.

**Exit:** fewer dead/duplicate paths, no public compatibility break, no net
production class growth and no new indirection layer.

## Stage 5: port proven write-path and policy fixes

- Differentially test create/edit/delete status, tag synchronization, links,
  images, nested comments, reactions, follows, account changes and moderation
  between 2.0.25 and the intended behavior.
- Backport valid 2.5 transaction boundaries into existing monolithic
  functions so multi-table writes are atomic, without application services or
  repository ports.
- Audit every validation difference. Remove arbitrary restrictions such as
  the former maximum-ten-tags rule when they are not required by storage,
  security or an explicit product contract; retain documented size and abuse
  bounds.
- Preserve authorization, CSRF, rate-limit and ownership checks at every write
  entry point. Add a regression test before changing each rule.
- Port response consistency fixes only through existing API/route helpers.

**Exit:** accepted behavior fixes have focused regression tests and write
failures cannot leave partially synchronized relations.

## Stage 6: port security and operational hardening in place

- Compare `LinkMetadata` with 2.5 host and public-URL validation. Fold missing
  redirect, IPv4/IPv6, private-range and DNS checks into the existing class;
  do not import `Host` and `PublicUrlGuard` solely to reproduce a split.
- Compare avatar, status-image and site-image decoding. Backport verified size,
  memory, MIME, orientation and malformed-image protections into their current
  owners, sharing only a small existing helper where useful.
- Compare cache and minifier implementations line by line. Port real bug fixes
  while retaining their names and static lazy initialization.
- Keep the combined 2.0.25 package manager. It already owns signed manifests,
  path validation, migrations, backup and rollback; port only missing checks
  such as a demonstrable collision edge case, with an updater regression test.
- Rehearse fresh install, interrupted/repeated install, signed update from an
  exact 2.0.25 tree and rollback. Do not port installer fixes that only repair
  2.5 repository classes.

**Exit:** security tests and real MySQL product rehearsals pass without a new
runtime dependency tree.

## Stage 7: validate scale and tune only from evidence

- Use the deterministic, batched and resumable importer with deeper comment
  trees, more comments per post, reactions, follows and notifications.
- Add a large profile that reaches at least one million relational rows while
  keeping batches independently committable and progress/ETA visible.
- Benchmark cold and warm feed, status, tag, author and search reads, plus
  representative writes and maintenance tasks.
- Inspect slow-query logs and `EXPLAIN ANALYZE`. Add or change an index only
  when measured read benefit outweighs write/storage cost and the upgrade can
  be performed safely.
- Test long-running work with fixed memory ceilings and verify that pagination,
  cleanup and importer logic never materialize an unbounded table in PHP.

**Exit:** the candidate completes the large profile with stable memory, no
timeouts/fatals and documented query plans for every changed hot query.

## Stage 8: comparative acceptance benchmark

Run independent local installations of exact `v2.0.25` and the candidate
`2.0.26` against identical deterministic data. Alternate baseline/candidate
order over at least three rounds and test:

- filesystem cache and Memcached;
- OPCache enabled as the production case and disabled as a sensitivity case;
- cold request, warmed sequential requests and concurrent load;
- latency p50/p95/p99, throughput, errors, SQL questions, cache counters, PHP
  peak memory, loaded files/classes, FastCGI working set and available CPU
  utilization.

Release gates:

1. Zero HTTP 5xx responses, PHP warnings/fatals and data-count mismatches.
2. Median-of-rounds p95 for every primary route is at most 5% slower than
   2.0.25; the geometric aggregate must not regress.
3. Peak PHP memory and warmed FastCGI working set are at most 5% above 2.0.25.
4. Query budgets from Stage 2 do not regress under either cache driver.
5. OPCache-disabled results remain close to the monolithic baseline and do not
   show the large source-loading penalty observed in 2.5.
6. A failed gate blocks release. The responsible backport is simplified or
   reverted; the budget is not loosened to accept it.

**Exit:** a checked-in JSON/Markdown report showing 2.0.25 versus 2.0.26 and a
written accept/reject decision for every gate.

## Stage 9: package and update release 2.0.26

- Set the version and write a changelog that describes performance,
  correctness and security changes without presenting the rejected 2.5
  architecture as released history.
- Build the signed release artifact from a clean tree and verify its exact file
  inventory, exclusions, hashes, minimum version and deletion list.
- Perform a real update from exact 2.0.25 with representative database,
  uploads, configuration and a compatible extension; then verify rollback
  restores the original managed tree and data.
- Repeat fresh installation and full preflight against the artifact, not only
  the working tree.
- Publish only after the acceptance benchmark and update rehearsal reports are
  attached to the release decision.

**Exit:** a reproducible 2.0.26 artifact that upgrades 2.0.25 without a schema,
extension or runtime compatibility surprise.

## Explicitly not carried over from 2.5

- `Application`, `Composition`, `Http`, `Presentation` and `Web` class graphs;
- repository-per-entity and port/adapter layers;
- eager `ApplicationFactory` and `WebServices` construction;
- `ViewContext`, presenters and prepared-data classes when a plain array is
  sufficient;
- renames of `Cache`, `Minifier`, `StatusLinks`, `LinkMetadata` or the combined
  updater;
- the removal of stable 2.0.25 extension facades;
- a compatibility or legacy layer for local, unpublished 2.5 code;
- architecture tests whose only purpose is preserving those layers.

## Expected result

2.0.26 should look recognizably like 2.0.25: a small monolithic runtime with a
better organized procedural application layer. Its main inherited gains from
the 2.5 experiment should be batched queries, render-only partials, selected
atomicity and validation fixes, stronger regression/release tooling and a
repeatable performance proof—not hundreds of production types.

## Execution record

### Stage 0 — completed 2026-08-09

- Archived the complete local 2.5 experiment, importer and final benchmark
  reports on `codex/archive-2.5-experiment` at commit `f0fe5aa`.
- Created `codex/release-2.0.26` directly from released tag `v2.0.25` at commit
  `c81288bf3d7b47cdb6cabebefc90690191a448a9`.
- Transferred only development tooling, historical reports and this plan; no
  2.5 production application file was transferred.
- Recorded the exact source, environment, dataset and verification results in
  [the 2.0.25 baseline](baseline-2.0.25.md).
- Verified all 124 shipped PHP files and all four shipped cache tests. The tag
  contains no general test/preflight runner; creating it belongs to Stage 1.

### Stage 1 — completed 2026-08-09

- Added a development-only Composer toolchain and one preflight covering
  metadata/advisories, deterministic style, PHP 8.4 lint, PHPStan level 8,
  repository security and the complete behavioral suite. Production Composer
  dependencies remain forbidden and `vendor/` is not packaged.
- Froze the existing monolith's static-analysis debt in a baseline of 740
  findings. All production PHP is analyzed; new findings fail and the baseline
  may not grow.
- Added monolith boundary checks that lock the 18 `App/` files, 15
  class-bearing files and rejected 2.5 namespaces, plus render snapshots and
  anonymous/authenticated route smoke checks.
- Locked the measured 2.0.25 SQL-question baseline for five routes under both
  cache drivers. Live candidate budgets remain Stage 2 work.
- Added signed-package inventory/hash/signature verification and a disposable
  synthetic 2.0.25-to-2.0.26 update that verifies maintenance mode, protected
  runtime data, exact rollback and fresh-artifact boot.
- Ran the existing and new MySQL tests locally. The fresh installer created its
  schema, admin, settings and email defaults twice in a disposable database,
  passed 14 restart-safety checks and removed the database. CI provisions
  MySQL 8.4 so these tests do not silently skip there.
- Verified that no production file below `App/`, `Public/`, `assets/`,
  `Extensions/`, `migrations/` or the root entry points changed from
  `v2.0.25`.

### Stage 2 — completed 2026-08-09

- Traced every application SQL statement for feed, status, tag, author and
  search against the deterministic medium MySQL dataset. The dominant N+1 was
  `status_image_url()`: a selected `NULL image_path` was treated as if the
  column had not been selected, causing one `content_images` lookup per
  image-less card and four repeated lookups on status detail metadata.
- Kept the fix in `functions.php`: selected empty image values are now final,
  while legacy callers that do not provide either image-path key retain the
  single-record fallback. No repository, presenter, service or production
  class was added.
- Cached the resolved authenticated user for the lifetime of one request.
  Status-detail comments now select the owning status author in their existing
  query, preload all viewer comment reactions in one bounded query and reuse
  the same authenticated-user array throughout the route. This removes both
  per-comment permission reads and per-comment reaction reads.
- Added live SQLite query-budget scenarios using the production SQL owners and
  cardinalities large enough to expose N+1 behavior. They pass at
  feed/status/tag/author/search = `3/6/4/8/7` application statements against
  budgets `4/7/5/17/8`; status is intentionally authenticated and includes
  batched status and comment viewer reactions.
- Repeated the anonymous trace on the medium MySQL installation. Application
  statements fell from `23/7/24/24/19` to `3/3/4/8/7`; the HTTP benchmark's
  MySQL `Questions` metric adds its one status-probe statement. The comment
  query retained the same indexed execution plan; MySQL eliminated the
  primary-key owner join from the physical plan, so no new index was justified.
- After CSRF-token normalization, all five HTTP responses were byte-equivalent
  to exact `v2.0.25`. Peak CLI memory remained 6 MiB, included files remained
  35–37 and declared classes remained 270 on every route. A contemporaneous
  directional Apache sample improved geometric mean request time by 4.66%; its
  geometric p95 moved by +1.47%. This directional run is not the release
  acceptance result; the full alternating filesystem/Memcached/OPCache matrix
  and its per-route gates remain Stage 8 work.
- Completed the full 16-group preflight: PHPStan level 8 introduced no new
  baseline entries, MySQL rehearsals passed and the signed update/rollback
  rehearsal remained green.

### Stage 3 — completed 2026-08-09

- Added plain-array preparation in the existing procedural and notification
  owners for status cards, comment trees, editor fields, links, images, time
  labels, feed cursors, notification text and administration pagination. No
  production class or compatibility layer was added.
- Status and comment partials now consume preloaded counts, viewer reactions,
  permissions, rendered mentions and the already loaded comment tree. Numeric
  comment mentions are collected across the tree and resolved in one bounded
  preload before any comment is rendered.
- Removed configuration, cache, database and service lookups from
  `Public/parts`, including the less obvious editor-tag, image-setting,
  settings-field, sidebar-footer and per-page fallbacks. Routes and existing
  application owners now pass all required values explicitly.
- Added a token-based boundary test over every PHP partial. It rejects direct
  database primitives, service owners and the known query-capable or repeated
  preparation helpers, while preserving reusable escaping, translation, icon
  and pure URL helpers.
- Expanded presentation snapshots for prepared time links, video cards,
  comments, notifications and pagination. The public route smoke suite passed
  34 checks including eight authenticated/admin renders with no skipped
  scenarios.
- Query budgets remained feed/status/tag/author/search = `3/6/4/8/7` against
  `4/7/5/17/8`. On the deterministic medium MySQL installation, normalized
  HTML for `/`, `/status/4`, `/tag/benchmark`, `/author/11` and
  `/search?q=performance` matched Stage 2 exactly; only template whitespace
  and random CSRF values were normalized.
- Completed the full 17-group preflight. PHPStan level 8 reported no new
  findings, all 152 PHP files passed lint, the disposable MySQL installer was
  removed cleanly and signed update/rollback rehearsal remained green.

### Stage 4 — completed 2026-08-09

- Added a development-only token inventory for every production global
  function, class and method. It locks the 18-file/15-class-bearing `App/`
  boundary and distinguishes the tested `sitemap_url()` and `sanitize_html()`
  contracts from genuinely unreferenced code.
- Removed the only proven dead private method, `Core::requireMethod()`, and
  reduced the PHPStan baseline by three obsolete findings. No extension facade,
  state owner or security boundary was removed.
- Consolidated repeated positive-ID normalization, comment-tree traversal and
  administrator-role checks into three procedural owners. Removed one
  statically impossible pagination branch and organized `functions.php` with
  named responsibility sections; no production file or class was added.
- Query budgets remained `3/6/4/8/7`. Stage 3 and Stage 4 produced identical
  normalized HTML on all five hot routes, with the same 6 MiB peak memory,
  34-36 included files and 270 loaded classes.
- The three-round directional HTTP sample completed 1,200 requests without a
  failure or detected warning/fatal. Its geometric p50/p95 moved by
  `-10.36%/-16.05%`, but route-level p95 was too noisy for an acceptance claim;
  feed and tag are explicitly deferred to the full alternating Stage 8 matrix.
  Detailed method and measurements are in
  [the Stage 4 cleanup report](stage-4-monolith-cleanup.md).
- Completed the full 18-group preflight: deterministic style and PHP 8.4 lint,
  PHPStan level 8, security, route/presentation/query tests, signed update and
  rollback, and disposable MySQL installer rehearsal all passed.

### Stage 5 — completed 2026-08-09

- Differentially audited status/comment/reaction, tag, link, image, follow,
  profile, recovery and moderation mutations against the archived 2.5
  behavior. Kept only procedural outcomes; no application service, repository,
  port, controller or compatibility class was transferred.
- Made status and comment aggregate deletion, direct tag/link synchronization,
  profile plus profile-link changes, and password-reset token replacement and
  consumption atomic in their existing monolithic owners. Database failures
  now roll back all related rows while external image/avatar deletion remains
  after commit.
- Removed the arbitrary maximum-ten-unique-tags validation and both dead
  translations. Individual tags remain bounded to 32 characters and complete
  post/comment input remains bounded by the 2,000-character storage contract.
- Replaced broad duplicate-race catches with a narrow unique-constraint check;
  non-unique trigger, foreign-key and connectivity failures now propagate.
  Recovery token consumption is conditional, so the same token cannot change
  the password twice.
- Fixed moderation removal notifications that were previously erased by the
  deleted content's foreign-key cascade. Removal notifications now survive
  without a dead content target; dismissal notifications retain their target.
- Added 74 SQLite policy/rollback checks, including forced failures during
  profile, token, tag, link, comment and status mutations plus create/edit/
  delete, reactions, nested comments and follow cycles. Auth, CSRF, interval,
  ownership and moderation boundaries remain locked.
- Completed the full 19-group preflight. PHPStan level 8, route and output
  contracts, signed update/rollback, disposable MySQL installation and query
  budgets `3/6/4/8/7` all passed. Detailed decisions are recorded in
  [the Stage 5 write-path report](stage-5-write-paths.md).
