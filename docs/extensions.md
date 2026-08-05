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
        "tinycat": "2.0.0",
        "php": "8.4.0"
    },
    "entry": "bootstrap.php",
    "migrations": [
        "migrations/20260805_001_create_example.php"
    ],
    "uninstall": {
        "handler": "uninstall.php",
        "options": [
            {
                "id": "keep",
                "labels": {"en": "Keep data"},
                "descriptions": {"en": "Remove the extension files and retain its data."},
                "danger": false,
                "recommended": true
            }
        ]
    },
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

Core extension services use the `TinyCat\Extension\` namespace. Register an
extension through `TinyCat\Extension\Registry`; TinyCat 2.x also exposes the
former global `ExtensionRegistry` name as a narrow compatibility alias for
already installed 1.x extension packages. This alias is temporary and will be
removed once all official packages use `TinyCat\Extension\Registry` directly.

From TinyCat 1.0.14 onward, Bots is distributed by the official extension
store. Extension installation state is always explicit in
`extensions.installed_versions`; manifests no longer provide legacy adoption
fallbacks.

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

An extension can expose its own uninstall choices through the optional
`uninstall` manifest block. Uninstall is available only after the extension is
disabled. The declared PHP handler must return a callable accepting the shared
`PDO` connection and a context array containing `slug`, selected `mode`, and
the selected option. It returns an array with a required boolean
`data_removed` value. When data is removed, TinyCat also clears that
extension's migration history so a later installation can recreate its schema.
When data is kept, the migration history remains intact.

TinyCat moves the extension files into a private
`storage/extensions/backups/` directory before invoking the handler and
restores them when uninstall fails. Uninstall handlers own their data model and
must be restart-safe, explicit about destructive choices, and avoid touching
data outside their namespace unless that effect is clearly described to the
administrator.

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
