# Filesystem storage

`TinyCat\Support\Storage` owns filesystem primitives shared by runtime modules:

- `path(relative)` resolves paths below the existing `storage/` directory. It normalizes separators and rejects absolute paths, empty segments and traversal.
- `ensureDirectory(absolute, mode)` creates a directory recursively and tolerates concurrent creation.
- `write(absolute, content, mode, replaceDisposable)` checks the complete write, applies permissions and publishes a temporary file from the destination directory by rename. Temporary files are removed after failure. The default file mode is `0600`.

Absolute write targets must be validated by their owner. This supports configured cache directories and public uploads without relocating existing data. This API is not a sandbox for untrusted paths or symlink trees.

Cache retains its namespaces, configured directory, TTL, Memcached fallback and `0664` generated files. Only disposable cache writes enable the Windows unlink replacement fallback. Update state never enables it, so failed replacement preserves the previous state. Upload owners retain image validation, encoding and naming; they share directory creation.

New filesystem consumers should use these primitives. Format, retention, cleanup predicates and domain validation stay with the owning module. Developer tooling with explicit external output destinations remains independent.
