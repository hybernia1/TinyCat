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
pinned in `App/Updates/Manager.php`; the private key must never be committed or uploaded
to a web release.

Back up the private key in a secret manager before publishing the first signed
release. Losing it means existing installations cannot authenticate packages
signed with a replacement key. CI can provide the base64 private key through the
`TINYCAT_UPDATE_SIGNING_KEY` environment variable.

## Upgrading to 5.0.0

External scheduler integrations must switch before relying on the new release:
HTTP `/scheduled-tasks.php` returns 410. Use `POST /api/scheduler/executions` with
`Authorization: Bearer TOKEN` and a JSON body such as
`{"task":"feeds","options":{"bot_limit":20}}`. Health checks use authenticated
`GET /api/scheduler/health`. Query tokens and alternate token headers are not
accepted. Existing configured scheduler tokens remain valid.

CLI commands remain supported; update JSON consumers to read successful task
results from `data.tasks` and failures from `error.details`. Browser writes use
the canonical module APIs described in `docs/api.md`; legacy page POSTs return 405.
Public document URLs and formats remain unchanged. This release needs no new
schema migration, and the update manifest removes the old standalone installer.

## Upgrading to 5.4.0

The normal signed update applies `20261029_002_bot_publication_interval` before
leaving maintenance mode. It adds `publication_interval_minutes` with a default
of 30 for existing sources and new rows. Existing `interval_minutes` polling
settings and GUID histories remain unchanged. Fresh installations include the
new column directly.

Feed imports now save every new usable item returned by the bounded parser and
append publication slots to the bot's existing timeline. Public routes continue
to hide future posts. Changing publication spacing affects subsequent imports;
editing a scheduled post preserves its publication time. Scheduler URLs and
credentials do not change.

## Upgrading to 5.6.0: feed-specific publication queues

Migration `20261029_003_feed_publication_queue` adds `bot_source_posts` to retain
the source of each newly imported post. Publication slots append only to that
source's timeline, independently of other sources using the same bot account.
The source relation and post are saved in the same transaction as the GUID claim.
Deleting a post or source removes its relation; deleting a source preserves posts.

Legacy posts have no reliable source relation. Their publication times and GUID
history are preserved, and they do not delay new feed-specific queues. The first
new item for each source can therefore publish immediately, even if legacy posts
are still scheduled. No legacy posts are rescheduled or imported again.

## Upgrading to 5.7.0: unified feed formats and source previews

This release needs no new database migration. Existing source URLs, IDs, GUID
history, post mappings and publication times are retained. Sources automatically
accept RSS, Atom, RSS 1.0/RDF and JSON Feed 1.0/1.1. The source form can discover
advertised feeds from a website URL and preview its current template before
saving. Run now continues to import directly.

Add `{{image}}` explicitly to a source template to attach the first usable feed
image using normal upload rules. Existing templates do not start uploading
attachments automatically. Source identity and enabled state are checked again
under a row lock before each post is saved, so edits during a download cannot
attribute an item to a different feed or bot account.

## Upgrading to 5.7.1: WordPress feed artwork

No database migration or source reconfiguration is required. Sources using
`{{image}}` can now fill missing feed images through an automatically verified
WordPress REST API. The site's generator declaration is only a hint; its API
namespace and returned article identities are checked before artwork is used.
API requests are bounded, batched and cached. Feed-provided images retain
priority, and failed API discovery leaves ordinary feed processing intact.
Existing source IDs, URLs, publication queues and GUID history are unchanged.

## Upgrading to 5.8.0: home feed content filters

No database migration or configuration change is required. The home page adds
the same content filter used by author and tag archives to both public and
following feeds. Filter links use `media=images|video|audio|links|text`; omitting
the parameter shows all content. Selected filters persist through feed switches,
pagination and history navigation. After publishing or editing in a filtered
home feed, shared page navigation reloads the results from the server.

## Upgrading to 5.9.0: consistent administration and account editors

No new database migration or configuration change is required. Settings now
open from administration navigation in the shared tabbed modal. Blocked websites
are under Security; `/admin/settings` and `/admin/moderation/blocking` are retired.
Existing configuration and URL blocking rules are retained.

Bot links and edit actions open the shared workspace, including paginated run
history. Account/source activation uses explicit actions; deactivating a bot also
disables its sources, and reactivation does not implicitly re-enable those sources.
Lists share automatic dropdown filters and filtered result summaries. The update
manifest removes superseded pages, templates and styles from existing installations.
Personal account editing uses the same modal frame and preserves other tab drafts
after a partial save. Security and email verification endpoints retain their access
checks and behavior.

## Upgrading to 5.10.0: responsive tables and modal navigation

No database migration or configuration change is required. Small-screen lists
use labeled row cards, while tabbed editors share a compact section selector.
Bot workspace pagination and navigation stay outside the scrolling content.
Existing filters, sorting, drafts and activation behavior are retained.

Shared tooltips replace browser hints and expose complete visually truncated
table text. Embed communication now pins the HTTP(S) parent origin instead of
broadcasting replies. Newly generated iframe snippets use `strict-origin`
referrers, revealing no parent path or query. Existing `no-referrer` snippets
continue to resize after the TinyCat or WordPress helper sends its `ready`
handshake; without a handshake they retain their declared height.

## Upgrading to 5.11.0: compact list controls

No database migration or configuration change is required. Mobile sorting shares
the filter popover appearance and remains open while choices update the list.
Active filters are removable chips; removing one preserves other filters, search,
sorting and page size, and returns to the first page. Unavailable bot/source
choices are normalized to All before querying, including source choices from a
different bot. List controls and URL state retain the existing access checks.

## Upgrading to 5.12.0: modal history and validation

No database migration or configuration change is required. Remote modals and
persistent local editors share fragment URLs, for example
`/admin/bots/accounts#modal=bots/accounts/123&panel=detail&tab=history`.
Back closes an editor through the existing unsaved-change guard; Forward,
reload and direct links restore the editor and selected tab. Tab changes replace
the current entry. Nested editors have separate steps, while short-lived prompts
do not create bookmark URLs. Draft contents are never stored in URLs or history.

Lists keep their filters, scroll and focus on a read-only modal return. Validation
reveals and marks the first failing field, including inactive tabs and rich-text
editors, and displays errors without an editable target inside the form. Tabbed
forms retain hidden transport controls, including the new user's active status.
Existing authorized APIs and server validation remain in effect.

## Upgrading to 5.13.0: dashboard and mobile controls

No database migration or configuration change is required. The dashboard uses
shared administration components and a responsive recent-comment table. Cache
diagnostics and the protected OPcache reset remain available below recent activity.
The update manifest removes the superseded dashboard PHP template.

Filter and sorting triggers handle activation directly, including clicks on
their text and SVG icons. Flash notifications follow the visible mobile viewport
when a keyboard opens, and touch hover events no longer shorten their display time.
Existing list state, menu restoration and notification close controls are retained.

## Creating a release

Set `TinyCat\Support\Version::CURRENT`, commit the release, and build from a clean worktree:

```bash
php tools/build-update.php --version=5.0.0 --minimum-version=2.0.25
php tools/verify-update.php dist
```

The builder compiles JavaScript, CSS, the icon sprite, scoped UI definitions
and all translation catalogs into `assets/build/`, including a runtime manifest.
These generated files are added directly to the signed package; the source
checkout and runtime cache are not modified. Production pages select URLs from
this inventory instead of minifying or scanning translation sources per request.
Source checkouts without the inventory retain an on-demand development fallback.

The builder creates:

```text
dist/tinycat-5.0.0.zip
dist/tinycat-update.json
dist/tinycat-update.sig
```

Create a GitHub release for the matching `v5.0.0` tag and upload all three files
as release assets. Asset names `tinycat-update.json` and `tinycat-update.sig` are
fixed within each release; the package name is declared by the manifest.

Only managed application and end-user documentation files are packaged.
Configuration, uploads, storage, tests, tools, Git metadata, benchmark results,
internal release reports and the repository-level changelog are excluded. A
new top-level managed file requires a prior updater bridge release. Removed runtime files are
listed by target version in `tools/update-deletions.json`; this prevents legacy
PHP files from surviving an overlay update.

TinyCat 2.x has a fixed 1.0.14 database and updater baseline. Install 1.0.14
before entering the 2.x series. Historical 1.x migrations remain available in
Git history and signed 1.x releases, but are no longer shipped as active runtime
files.

TinyCat 3.0.0 accepts signed upgrades from 2.0.25 and newer, including 2.1.0.
It replaces legacy API routes and HTML fragment responses with module-owned
JSON APIs. Update external integrations using `docs/api.md`; the built-in UI
is updated with the application. The database baseline and PHP 8.4 requirement
are retained.

For a fresh installation, build into a separate output directory:

```bash
php tools/build-update.php --version=5.0.0 --fresh-install --output=dist/fresh
```

The fresh-install outputs are `tinycat-5.0.0-install.zip`, `tinycat-install.json`
and `tinycat-install.sig`; they cannot overwrite or be mistaken for updater assets.
Verify them with `php tools/verify-update.php dist/fresh --fresh-install`.
This package excludes `migrations/` and update deletions. The installer creates
all current tables and indexes directly. Use this ZIP only for new installations;
publish the normal signed update package as the updater release asset.
Only `docs/api.md` and `docs/updates.md` are shipped as public documentation.

`--without-migrations` is reserved for an updater compatibility bridge: it
ships authenticated migration files but leaves the manifest migration list
empty so an older updater can replace itself safely. Follow such a bridge with
a normal release that applies every pending migration. Never use this option to
skip an application migration permanently.

A release that introduces a new managed top-level directory needs a bridge
release first. The bridge updates the updater allowlist without shipping files
inside the new directory. Only the following release may populate that directory.

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
The package builder normalizes migration files to LF before hashing and archiving
them so the checksum stays stable across Windows and Linux release worktrees.
It also uses `SOURCE_DATE_EPOCH` for every ZIP entry (or the ZIP epoch when the
variable is absent), allowing the same source and toolchain to produce the same
archive bytes.

MySQL schema changes can commit implicitly. Migrations must therefore be safe to
restart and should inspect existing columns, indexes, and constraints before
changing them. Do not assume that wrapping `ALTER TABLE` in a transaction makes
it reversible.

Fresh installations must also be updated in `App/Installation/Installer.php`. The
installer creates the latest schema directly and does not replay historical
migrations.

## Installation sequence

The web updater performs these steps:

1. Fetch and verify the signed manifest.
2. Download and hash the declared ZIP package.
3. Validate every archive path and extracted file hash in a staging directory.
4. Check version, PHP extensions, disk paths, and write permissions.
5. Enable maintenance mode and acquire the update lock.
6. Back up only managed application files that will change or be removed. Create a database backup only when the release has pending migrations.
7. Replace managed files, run unapplied migrations, and remove declared legacy files.
8. Clear runtime caches and disable maintenance mode.

If a failure occurs before application files change, maintenance mode is removed
automatically. If files may already have changed, maintenance mode remains active
and the administrator must inspect or restore the backup before reopening the
site.

## Recovery

Backups are stored under a directory such as:

```text
storage/updates/backups/1.0.12-to-1.0.13-20260805-120000/
```

`files/` contains the previous versions of files changed by the update. For a
release with pending database migrations, `database.sql` (or `database.sqlite`) also
contains the database snapshot. `backup.json` identifies the source and target
versions and whether a database backup was required. After restoring the
available snapshot, remove `storage/maintenance.json` or use the guarded
maintenance button on the update page.

Apache installations are protected by the bundled `.htaccess`. Other web
servers must deny public access to all of `storage/`, including database backups
and signing material.

### Scheduled backup retention

After all update steps succeed, the updater writes `completed.json` beside
`backup.json`. The shared cleanup task may retire these confirmed backups after
30 days, keeping the newest three complete snapshots regardless of their age.
Backups without the completion marker, incomplete payloads and snapshots from
failed updates remain protected. Older TinyCat releases did not create this
marker: their existing backups require manual review and are not automatically
deleted or retroactively labelled successful.

The optional `updates.backups` configuration supports `cleanup` (default `true`),
`keep` (default and minimum `3`) and `retention_days` (default and minimum `30`).
Set `cleanup` to `false` to retain all backups. These options do not disable
cleanup of abandoned updater downloads and work directories after 48 hours.
The cleanup takes the same `update.lock` as the updater and skips all update
files while maintenance mode is active. Retirement is resumable and removes
recovery metadata only after the payload has been removed.

Backup retention, downloads, staging and verification now have independent scan
cursors and reserved shares of each cleanup batch. A growing work directory
cannot consume the backup scan's share. Snapshot payloads are validated when
reached; retention still verifies the newest complete snapshots before protecting
them. A catalogue exceeding 5000 entries raises a visible cleanup error instead
of silently returning a successful zero-deletion result.
