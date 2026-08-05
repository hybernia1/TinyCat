# TinyCat extensions

Extensions are self-contained directories below `Extensions/`. Runtime discovery
does not install code or execute database changes. Only extensions enabled by
their manifest default or a stored administrator override are booted.

## Manifest

Every extension contains `extension.json`:

```json
{
    "schema": 1,
    "slug": "example",
    "name": "Example",
    "descriptions": {
        "en": "A short store description."
    },
    "homepage": "https://github.com/example/example-extension",
    "version": "1.0.0",
    "requires": {
        "tinycat": "1.0.14",
        "php": "8.4.0"
    },
    "entry": "bootstrap.php",
    "migrations": [
        "migrations/20260805_001_create_example.php"
    ],
    "autoload": false
}
```

The directory name must match `slug` case-insensitively. Entry and migration
paths must stay inside the extension directory. Migration names are sortable,
explicitly listed, and immutable after publication.

The entry file should remain a small bootstrap that loads the extension's own
classes and registers its integration points. Extension files are never served
directly by the bundled Apache configuration; public behavior must be exposed
through registered TinyCat routes.

`legacy_version` is reserved for a bundled feature that existed before the
extension system. It provides a temporary data-version fallback while a signed
core migration records the adopted version. New extensions must not use it.

TinyCat 1.0.13 used this bridge to adopt the formerly built-in Bots feature.
From 1.0.14 onward, Bots is distributed by the official extension store.
Existing bot accounts, sources, run history and imported-item history keep
their original tables and IDs. The core updater deliberately leaves the bridge
files in place until the store replaces them with the official package.

## Official store

The administration reads the latest release from
[`hybernia1/TinyCat-Extensions`](https://github.com/hybernia1/TinyCat-Extensions).
Its Ed25519-signed catalog declares every package checksum, byte size and exact
file hash. TinyCat verifies those values, safe archive paths, manifest identity,
and TinyCat/PHP compatibility before promoting any files.

An update first moves the current extension directory below
`storage/extensions/backups/`. A failed preflight restores it; published
migrations are restart-safe and remain retryable if execution is interrupted.
The private signing key never ships with TinyCat.

## Lifecycle

Enabled state and installed data versions use the existing settings table:

- `extensions.states` contains administrator overrides;
- `extensions.installed_versions` contains applied extension versions.

Disabling an extension removes its runtime registration, routes, navigation and
scheduled tasks. Files, migration history and application data are retained.

Extension migrations use the shared `schema_migrations` registry with an ID such
as `extension:example:20260805_001_create_example`. The registry verifies a
normalized SHA-256 checksum and rejects changes to an applied migration.

Migration files return a callable accepting `PDO`, just like core migrations.
They must be restart-safe because MySQL schema changes can commit implicitly.
Never edit a published migration; add a new one.

The lifecycle service does not expose a standalone web action for migrations.
Only the verified store installer can install authenticated files and then call
the lifecycle. This prevents an administrator from running arbitrary migration
files directly from the extensions list.
