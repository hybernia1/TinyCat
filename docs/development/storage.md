# Filesystem storage

`TinyCat\Support\Storage` owns filesystem primitives shared by runtime modules:

- `path(relative)` resolves paths below the existing `storage/` directory. It normalizes separators and rejects absolute paths, empty segments and traversal.
- `ensureDirectory(absolute, mode)` creates a directory recursively and tolerates concurrent creation.
- `write(absolute, content, mode, replaceDisposable)` checks the complete write, applies permissions and publishes a temporary file from the destination directory by rename. Temporary files are removed after failure. The default file mode is `0600`.

Absolute write targets must be validated by their owner. This supports configured cache directories and public uploads without relocating existing data. This API is not a sandbox for untrusted paths or symlink trees.

Cache retains its namespaces, configured directory, TTL, Memcached fallback and `0664` generated files. Only disposable cache writes enable the Windows unlink replacement fallback. Update state never enables it, so failed replacement preserves the previous state. Upload owners retain image validation, encoding and naming; they share directory creation.

New filesystem consumers should use these primitives. Format, retention, cleanup predicates and domain validation stay with the owning module. Developer tooling with explicit external output destinations remains independent.

Raster conversion is shared by `Core/Files/RasterStorage`; domain subclasses
provide paths and upload/URL policies. `ProvidesFileReferences` contributes lazy
retention queries to `Core/Files/References`, including installed inactive owners.
The core file layer has no publication SQL. Delete superseded files only after
commit and check retained references before unlinking. Legacy physical paths do
not imply shared database ownership; see [domain storage](domain-storage.md).

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
