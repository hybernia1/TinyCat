# Core legacy cleanup after 6.0.6

The supported source baseline is a completed TinyCat 6.0.6 installation. The
published 6.0.6 release remains the unchanged signed bridge from 6.0.4/6.0.5.
The development candidate is 6.0.7-dev.3; it is not a published release.

| Former layer | Current behavior |
| --- | --- |
| Database `SchemaInspection` and reflection fallback | Adapter deleted; inspection uses `Database\Schema`. |
| Physical `schema_migrations` / `module_migrations` conversion | Current journal reads and writes `migrations` only. Incomplete older storage is rejected before preparing replacement objects. |
| `module_migrations` compatibility writer view | New task-schema step 5 drops the view and SQLite trigger, preserving every physical journal row. |
| Core global-table adoption | Identity, Security, Settings and Notifications no longer declare pre-baseline adoption callbacks. Their obsolete adoption entries are removed. |
| Social/Blog global-table adoption entry points | Pre-baseline entries are removed from current schema registrations and update payloads. |
| Installer/readiness legacy switch | One current owner-schema path; no `Startup::$legacySchemas` or installation initialization callback. |
| Notifications ad hoc `upgrade()` | Unused runtime DDL fallback removed. Fresh inbox storage uses the generic opaque-subject schema. |
| Core task-history adoption exception | Removed from generic schema validation; current execution IDs and signed stream history remain intact. |
| Social fresh-install wrapper replaying notification conversion | Current fresh installation uses the owned-table DDL directly, retaining the same incremental history and schema version. |

## SQL snapshots retained in core

The remaining owner migration files are signed history and fresh-install
snapshots. For example, Notifications retains its two applied incremental steps
and its current fresh-v2 baseline. Installed checksum validation still resolves
these exact files. Current updates from 6.0.6 skip those applied steps, and fresh
installation uses the fresh baseline.

Two immutable applied snapshots still mention the retired inspection class;
the applied task writer snapshot mentions its retired journal method. These
three historical files are excluded from PHPStan's current-runtime type analysis,
while syntax lint and signed checksum validation still cover them. They are not
execution inputs from the supported baseline. No runtime class stub or alias was
added to satisfy those archived references.

Their SQL bytes, IDs and checksums remain unchanged. Physical migration resources
remain owned and bounded by each schema's registered resource directory, as
required by the published 6.0.6 updater. The source cleanup does not relax module
resource containment or introduce a separate core migration engine.

## Verification

The existing signed-updater rehearsal now starts from the published v6.0.6 tag,
loads its original schema/journal/lifecycle classes, seeds retained long version
labels and an old writer view, applies the candidate, checks unchanged history
and content, and performs exact database/file rollback including the view.

The HTTPS tinycat.test upgrade used the canonical signed updater and preserved
all 35 content-table fingerprints and all 23 prior journal records. Its task
schema finished ready at version 5, with the runtime adapter absent and no
module_migrations view. Configuration remained unchanged; maintenance ended.

All 90 canonical test groups were covered across the measured preflight and its
continuations after test-fixture corrections: 89 passed and Memcached was skipped
because its extension was unavailable. Application-source hashes were identical
across those continuation reports. The Windows symlink fixture was unavailable.
Changed test files passed syntax and style checks. The final signed platform
artifact passed 2,363 checks, including fresh MySQL installation and route checks.
The final HTTPS smoke passed administrator login, scheduler metadata and public
pages. The candidate contains 14 fewer runtime files than the published 6.0.6
platform package, including six fewer core PHP files.

## Functional-module references removed from core

The current descriptor path accepts only format 3. Framework capability IDs live
in Runtime/Framework; the activation Upgrade adapter, old Social fragment list,
and publishing-dependent format/registration branches are removed. Existing
canonical installed/active IDs are retained verbatim, with unknown installed
owners rejected rather than silently dropped. Published 6.0.6 remains the bridge.

Social owns its image-setting section presentation and translations, including
its current table-purpose descriptions. The installer intro and generic inbox
purpose no longer assume Social. All twelve locales move together. Obsolete
global table-purpose identifiers are replaced by the actual owner table names.

Shared text/resource rendering requires an explicit kind. Social passes post;
Blog passes article. A foreign or unregistered kind uses neutral core rendering,
even with Social active. External-link provider categories named social remain
generic metadata and do not refer to the Social module.

The module-ownership stage passed one complete canonical `composer preflight`
for 6.0.7-dev.3: all 90 groups were visited, 89 passed and Memcached integration
was skipped because the extension was unavailable. The Windows symlink case
was also unavailable. This includes native frontend scenarios, isolated core,
Social and Blog assemblies, coexistence, HTTP API, query budgets, signed module
lifecycle, fresh installation and the published 6.0.6 update with exact rollback.
The existing activation fixture now starts with canonical completed 6.0.6 state;
it checks inactive owners, rejection of unknown installed IDs, optional added
owners, and rejection of retired descriptor formats. Selection fixtures verify
that Social table descriptions and image-setting labels disappear with Social.
A foreign text kind remains neutral while Social is active.
