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
    "version": "1.0.0",
    "requires": {
        "tinycat": "1.0.13"
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

TinyCat 1.0.13 uses this bridge to adopt the formerly built-in Bots feature.
Existing bot accounts, sources, run history and imported-item history keep
their original tables and IDs; only their owning code, routes, translations,
administration and scheduled task move below `Extensions/Bots/`.

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
A verified package installer must acquire its update lock, enter maintenance
mode, create file and database backups, install authenticated files, and only
then call the extension lifecycle. This prevents an administrator from running
unbacked SQL directly from the extensions list.
