# Stage 4 monolith cleanup report

Stage 4 compares commit `b75b2fc` (the completed render-only partial stage)
with the monolith-cleanup candidate on `codex/release-2.0.26`. The cleanup is
deliberately procedural: it adds no production file, class, facade, service
locator or compatibility layer.

## API and call-site inventory

`php tools/monolith-inventory.php` tokenizes all production PHP and reports the
definition and reference counts for global functions and class methods. Its
current baseline is:

- 108 production PHP files;
- 18 files below `App/`, of which 15 contain a class or interface;
- 503 global functions;
- 22 production classes, interfaces or enums with 509 methods.

The only global functions with no production caller are `sitemap_url()` and
`sanitize_html()`. Both remain because the extension-capability and HTML
sanitizer suites exercise them as documented contracts. The only proven dead
private method was `Core::requireMethod()`; it had no runtime, extension or
test caller and was removed together with its two PHPStan baseline entries.

The inventory is a release guard rather than a claim that name counting alone
can prove an API disposable. `tests/quality/monolith-inventory.php` therefore
also locks the known extension facades and tests the behavior of the shared
normalization, role and comment traversal functions.

## Consolidation performed

- Replaced repeated positive-ID map/filter/unique pipelines with the ordered
  `positive_int_ids()` procedural owner.
- Replaced two duplicate iterative comment-tree walks with
  `status_comment_tree_rows()`.
- Centralized the administrator-role decision in `user_is_admin()` across the
  procedural layer and the two public route/layout callers.
- Removed the statically impossible empty fallback from the fixed
  administration page-size options.
- Added descriptive section boundaries to `functions.php` while leaving the
  implementations and state-owning classes in place.

`Cache`, `Minifier`, `StatusLinks`, `LinkMetadata`, `UserRoles`, media,
extension and package-manager owners remain intact. They implement state or a
security/extension boundary and are not forwarding debris.

## Runtime comparison

The local medium MySQL installation was measured through Apache at
`127.0.0.1:8098`. Each HTTP sample used five warmups and 40 sequential
requests. Version order alternated over three rounds: baseline/candidate,
candidate/baseline, baseline/candidate. The table contains the median result
of those rounds.

| Route | Stage 3 p50 | Stage 4 p50 | Change | Stage 3 p95 | Stage 4 p95 | Change |
|---|---:|---:|---:|---:|---:|---:|
| Feed | 121.444 ms | 139.224 ms | +14.64% | 143.038 ms | 226.171 ms | +58.12% |
| Status | 77.503 ms | 74.686 ms | -3.63% | 105.208 ms | 89.592 ms | -14.84% |
| Tag | 146.811 ms | 144.104 ms | -1.84% | 177.316 ms | 239.107 ms | +34.85% |
| Author | 119.462 ms | 91.084 ms | -23.75% | 164.731 ms | 111.341 ms | -32.41% |
| Search | 165.904 ms | 116.166 ms | -29.98% | 378.567 ms | 128.658 ms | -66.01% |

The geometric change was -10.36% at p50 and -16.05% at p95. There were zero
HTTP failures or detected PHP warnings/fatals in 1,200 measured requests.
Individual p95 values were visibly noisy on both versions (roughly 85-409 ms
between round medians), so this small directional run is not used as the Stage
8 release gate and does not support a claim that the cleanup made requests
faster. In particular, feed and tag p95 must be measured again in the complete
acceptance matrix.

Five fresh CLI processes per route produced the same structural footprint for
both versions: 6 MiB peak allocated memory, 34-36 included files, 270 declared
classes and no fatal shutdown. Query budgets remained
feed/status/tag/author/search = `3/6/4/8/7`. After normalizing the random CSRF
token and inter-tag whitespace, all five candidate HTTP bodies were byte
identical to Stage 3.

## Decision

Accept Stage 4. It removes one proven dead path and several duplicate branches,
keeps public contracts, SQL work, output, memory, loaded files and loaded
classes stable, and creates no production indirection. The full preflight
passes 18 groups. The noisy route-level HTTP figures are retained explicitly
for the Stage 8 comparison rather than being hidden or promoted to an
acceptance result.
