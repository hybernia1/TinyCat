# TinyCat updates

TinyCat updates are curated, signed release packages. The application does not
download a repository snapshot and does not run `git` on the production server.

## Bootstrap

The first release containing the updater must be deployed once by the existing
manual process. Future stable releases can then be installed from **Admin →
Updates**. The update page uses the public GitHub Releases API and does not need a
GitHub token for public releases.

## Signing key

Generate the Ed25519 release key once:

```bash
php tools/update-key.php
```

The command writes the private key to the ignored
`storage/update-signing.key` file and prints the public key. The public key is
pinned in `App/Updater.php`; the private key must never be committed or uploaded
to a web release.

Back up the private key in a secret manager before publishing the first signed
release. Losing it means existing installations cannot authenticate packages
signed with a replacement key. CI can provide the base64 private key through the
`TINYCAT_UPDATE_SIGNING_KEY` environment variable.

## Creating a release

Set `Core::VERSION`, commit the release, and build from a clean worktree:

```bash
php tools/build-update.php --version=1.0.8 --minimum-version=1.0.4
php tools/verify-update.php dist
```

The builder creates:

```text
dist/tinycat-1.0.8.zip
dist/tinycat-update.json
dist/tinycat-update.sig
```

Create a GitHub release for the matching `v1.0.8` tag and upload all three files
as release assets. Asset names `tinycat-update.json` and `tinycat-update.sig` are
fixed within each release; the package name is declared by the manifest.

Only managed application and documentation files are packaged. Configuration,
uploads, storage, tests, tools, and Git metadata are excluded. Removed runtime files are
listed by target version in `tools/update-deletions.json`; this prevents legacy
PHP files from surviving an overlay update.

`--without-migrations` is reserved for an updater compatibility bridge: it
ships authenticated migration files but leaves the manifest migration list
empty so an older updater can replace itself safely. Follow such a bridge with
a normal release that applies every pending migration. Never use this option to
skip an application migration permanently.

## Migrations

Migration files live below `migrations/`, use a sortable unique name, and return
a callable:

```php
<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    return null;
}

return static function (PDO $database): void {
    // Validate the current state, clean incompatible rows, then change schema.
};
```

Every migration is authenticated by the release manifest and recorded with its
checksum in `schema_migrations`. A previously applied migration whose checksum
changes is rejected. Never edit a published migration; create a new one.

MySQL schema changes can commit implicitly. Migrations must therefore be safe to
restart and should inspect existing columns, indexes, and constraints before
changing them. Do not assume that wrapping `ALTER TABLE` in a transaction makes
it reversible.

Fresh installations must also be updated in `Public/install/index.php`. The
installer creates the latest schema directly and does not replay historical
migrations.

## Installation sequence

The web updater performs these steps:

1. Fetch and verify the signed manifest.
2. Download and hash the declared ZIP package.
3. Validate every archive path and extracted file hash in a staging directory.
4. Check version, PHP extensions, disk paths, and write permissions.
5. Enable maintenance mode and acquire the update lock.
6. Back up managed application files and the database.
7. Replace managed files, run unapplied migrations, and remove declared legacy files.
8. Clear runtime caches and disable maintenance mode.

If a failure occurs before application files change, maintenance mode is removed
automatically. If files may already have changed, maintenance mode remains active
and the administrator must inspect or restore the backup before reopening the
site.

## Recovery

Backups are stored under a directory such as:

```text
storage/updates/backups/1.0.7-to-1.0.8-20260805-120000/
```

`files/` contains the previous managed files, `database.sql` (or
`database.sqlite`) contains the database snapshot, and `backup.json` identifies
the source and target versions. After restoring both code and database, remove
`storage/maintenance.json` or use the guarded maintenance button on the update
page.

Apache installations are protected by the bundled `.htaccess`. Other web
servers must deny public access to all of `storage/`, including database backups
and signing material.
