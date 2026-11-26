# Filesystem storage

`TinyCat\Support\Storage` owns filesystem primitives shared by runtime modules:

- `path(relative)` resolves paths below the existing `storage/` directory. It normalizes separators and rejects absolute paths, empty segments and traversal.
- `ensureDirectory(absolute, mode)` creates a directory recursively and tolerates concurrent creation.
- `write(absolute, content, mode, replaceDisposable)` checks the complete write, applies permissions and publishes a temporary file from the destination directory by rename. Temporary files are removed after failure. The default file mode is `0600`.

Absolute write targets must be validated by their owner. This supports configured cache directories and public uploads without relocating existing data. This API is not a sandbox for untrusted paths or symlink trees.

On Windows, `chmod` and the permissions of the destination directory do not
establish the effective NTFS ACL of a file published by rename. A file moved
within a volume can retain its staging ACL. Code publishing or restoring a
private configuration must verify protection of the resulting file in the
actual installation; verifying only the staging directory is insufficient.
`Storage::write` does not perform that verification or preserve an existing
file's ACL. Apache rules protect HTTP access only and do not isolate local OS
accounts. Do not claim Windows ACL safety where it cannot be verified.
The Windows installer uses its runtime helper
[`configuration-windows.ps1`](../../App/Core/Installation/configuration-windows.ps1)
through Windows PowerShell 5.1 (.NET Framework) and PHP `proc_open`. It requires a
local NTFS filesystem on a Fixed or Removable drive without reparse paths.
Network shares, SMB, ReFS and other filesystems are rejected. Root, storage, updates and backup
directories must be owned by the runtime SID, SYSTEM or Administrators and must
not grant mutating Allow rights to another SID. Existing directory ACLs are
verified, never rewritten. An unsuitable deployment is rejected before secrets
are written or installation data is changed.

Secret staging files are created with an explicit protected DACL before content
is written, inside `storage/updates/installation-private` on the same volume.
Only this new private staging directory receives a protected directory DACL;
existing root, storage, updates and backup directory ACLs are not rewritten.
The helper verifies
the resulting file identity and DACL after publication and restoration. Private
configuration and snapshot files allow FullControl only to the runtime SID,
SYSTEM and Administrators. Restoring configuration normalizes its NTFS ACL to
this policy; it does not preserve the original ACL. Other service identities
cannot read the normalized file. POSIX restoration preserves bytes and mode.
These controls do not protect against the runtime account, SYSTEM or an
administrator acting maliciously; Apache access restrictions are still required.
The helper bounds stdin before JSON parsing and accepts at most 1 MiB of content.
Malformed input is rejected without echoing its potentially secret bytes.

Cache retains its private namespaces, configured directory, TTL, Memcached fallback and `0664` disposable files. Only disposable cache writes enable the Windows unlink replacement fallback. Update state never enables it, so failed replacement preserves the previous state. Upload owners retain image validation, encoding and naming; they share directory creation.

New filesystem consumers should use these primitives. Format, retention, cleanup predicates and domain validation stay with the owning module. Developer tooling with explicit external output destinations remains independent.

Raster conversion is shared by `Core/Files/RasterStorage`; domain subclasses
provide paths and upload/URL policies. `ProvidesFileReferences` contributes lazy
retention queries to `Core/Files/References`, including installed inactive owners.
The core file layer has no publication SQL. Delete superseded files only after
commit and check retained references before unlinking. Legacy physical paths do
not imply shared database ownership; see [domain storage](domain-storage.md).

## Uploaded file publication and recovery

Identity stores a nullable `users.avatar_version`, independent of `updated_at`.
New uploads have immutable `id-32hex.webp` paths; a null version retains the
legacy `id.webp?v=updated_at` URL. Publication creates a fresh file exclusively and checks the complete copy
before opening the account transaction; it does not require hard-link support
or replace a live file. The locked account transaction switches the reference;
rollback removes only the new file. After commit, the owner rechecks the current
reference before deleting a superseded path, protecting both immutable and
legacy current avatars. An uncommitted deletion view is refused. A process
crash leaves an unreferenced file rather than changing a committed avatar.
The bounded avatar scan removes unreferenced files after 24 hours. Existing
unidentified recovery backups remain preserved.

Branding uploads reserve random identities and prepare their WebP/PNG sets
before the settings transaction. Settings owns their path and URL references.
A rollback discards only the unreferenced new set; replaced, removed or partial
sets are recovered by the bounded branding scan after one hour. Fresh members
protect an entire set from cleanup. Disposable image preparations retain their
48-hour grace period, including orphan scans explicitly enabled for inactive
owners. Neither scan follows symlinked upload directories or files.

`Core/Files/References::owners()` shares the installed-owner selection with file
recovery. Missing deployment metadata or provider source stops deletion before
unlink. Inactive maintenance is an explicit owner opt-in, not module activation;
see the [module contract](../contracts/module-contract.md#zdroje-a-životní-cyklus).

## Settings and public profile cache

Settings uses `Cache::synchronized()` to coordinate the whole cold DB read/fill
with postcommit invalidation on the application node. It does not use the stale
fallback intended for computed rankings. Cache coordination failure can fall
back to an uncached DB read; an operational SQL failure propagates and never
publishes an empty settings array. Pre-DB and complete-only configuration still
use the existing pure configuration fallback. A warm request snapshot stays
stable; cold transactional reads never enter shared or request cache, so a
rollback cannot leak their uncommitted values. Filesystem coordination applies
to the node's configured cache directory with either filesystem or Memcached
storage; it is not a distributed lock across unrelated application nodes.

Social rankings cache only IDs, counts and ordering facts. Every result is
boundedly hydrated from current users, including status, ranking role, profile
text and avatar identity. Stale order or counts cannot resurrect a deleted,
inactive or superseded personal projection.

## Development outputs

The repository's `storage/` is reserved for application runtime state. Developer
outputs belong to ignored `.work/`, through `TinyCat\Tools\Workspace` in
[tools/support/workspace.php](../../tools/support/workspace.php). Explicit CLI
output arguments remain supported. This helper is development-only and must not
be loaded by application runtime or included in release packages.

| Directory below `.work/` | Contents |
| --- | --- |
| `tests/runs/` | Test execution reports |
| `tests/tmp/` | Isolated test installations and fixtures |
| `performance/` | Measurements and instrumented copies |
| `cache/` | Static-analysis cache |
| `tools/` | Local browser tooling and its dependencies |
| `keys/` | Private local signing keys; never commit or publish |
| `releases/` | Local release build workspaces |
| `imports/` | Import checkpoints and reports, separated by installation path |
| `archive/` | Preserved historical outputs; not automatically disposable |
| `cleanup/` | Local inventories and verified move manifests |

On 2026-11-15, the repository storage cleanup moved 710 entries without deletion.
Historical loose files were verified with SHA-256. The local manifest is
`.work/cleanup/storage-20261115/move-plan.json`. Runtime `modules/`, `updates/`
and two keys with uncertain historical ownership were retained in `storage/`.
This does not relocate live installations, media or runtime cache, and does not
introduce a CDN or media-storage abstraction. Never sweep `.work/` indiscriminately:
it can contain private keys, recovery copies and resumable import state.

Versioned reference measurements live in `docs/performance/results/`; new local
measurements stay in `.work/performance/`.

## Public runtime assets

`Assets` publishes immutable `/cache/assets/<name>` URLs below the fixed
`storage/cache/assets` web-server mapping. `cache.directory` and
`TINYCAT_CACHE_DIRECTORY` relocate only private computed cache entries. Prepared
inventories, publication revision and the runtime asset coordination lock remain
under the same stable `storage/cache` root. Existing URLs and generations survive
updates; no migration or public URL change is required. Previously misplaced
outputs under a custom private cache are regenerated when requested.

Test bootstrap explicitly sets `TINYCAT_RUNTIME_ASSET_DIRECTORY` to its isolated
cache workspace, inherited by child workers. This is a fixture override, not a
production relocation option: Apache continues to map the fixed public root.
Tests overriding private configuration must keep that explicit asset isolation.

Warm outputs take shared admission; publication and hourly retention renewal take
exclusive admission and change the publication revision. Cleanup first visits a
bounded candidate batch. With no expired candidates it skips inventory enumeration
and reference parsing. Deletion batches still inspect all recent roots and their
transitive JS/CSS references, then validate the publication revision and inventory
snapshot under exclusive admission before deleting. Thus the batch bounds visits
and deletion candidates, **not** worst-case graph inspection. No persistent graph
index is introduced. The 30-day retention and prepared-generation pins are retained.

Array-cache cold misses retain their two-second coordination limit and stale
refresh behavior. A final fresh lookup also covers a producer that has published
but still holds its compute lock at the deadline. Locks for computation, cache
mutation, deployment, staging, cleanup and database tasks keep separate ownership.

Public storage, coordination and runtime cleanup now belong to the existing
`Assets` class. `Minifier` retains publication entry points needed by already
loaded updater callers. `CompiledAssets` and `PreparedAssets` retain their
existing boundary: merging them would require a compatibility bridge for
already loaded deployment/readers during supported updates. `AssetGraph` owns
source resolution and ordering; `AssetCompiler` remains build-only and lazy.

When an updater retains the previous `Assets` class in memory, optional preparation
is deferred rather than calling methods absent from that class. Fresh requests
continue through the signed inventory or ordinary source fallback; operators can
prepare the selection with the existing assets CLI in a fresh process. This
compatibility check does not apply to normal current-code preparations.
