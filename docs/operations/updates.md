# TinyCat updates

TinyCat updates are curated, signed release packages. The application does not
download a repository snapshot and does not run `git` on the production server.

TinyCat 6.0.1 provides signed product identities, selective
distributions and independently owned Blog and Social storage. See the
[module deployment guide](module-deployment.md) for CLI installation,
activation, migration snapshots and recovery.

## Upgrading to 6.0.1 from stable 5.x

Use **Admin → Updates** normally from TinyCat 5.16.0 or newer. In particular,
5.22.2 does not need a manually installed dev runtime. The same release also
updates 6.0.0. New installations must use the separate installation ZIP.

Release 6.0.0's main update manifest required dev.9.2, so the stable web updater
could not offer it as compatible. A manually applied bridge bundle was not an
equivalent replacement for the ordinary update flow. Use 6.0.1 instead.

Build the public Social/platform release with:

```bash
php tools/release/build-update.php --version=6.0.1 --assembly=platform --both --legacy-upgrade --output=dist/release-6.0.1
```

The update variant uses a legacy-compatible signed transport descriptor and
one version-specific exported root migration. Its separately authenticated
target descriptor declares the complete current framework and owner schemas.
During the update, owners request any pending historical migrations with their
unchanged checksums, core synchronizes the declared schemas, and the final
descriptor and activation state are published explicitly. Startup does not
perform this conversion. The installation variant keeps the normal format-3
descriptor and creates the current schema directly.

The old updater's original file backups remain available. Before converting
domain data, a canonical MySQL/SQLite and activation snapshot is also prepared
for the normal recovery CLI. For rollback from 5.x, original files and data are
restored; new private files are conservatively retained because that updater
never recorded whether those paths previously existed. Modern rollback retains
its exact managed-file restoration. Configuration and uploaded media are preserved.

Verify the actual stable update path in an isolated database:

```bash
php tools/release/release-update-rehearsal.php --artifact=dist/release-6.0.1/update --baseline-version=5.22.2 --direct-web
```

The same command accepts 5.16.0 and 6.0.0. It runs the published updater's
`check()` and `installLatest()` with the signed release assets; only downloads
are replaced by deterministic fixture transport. The 5.22.2 scenario is included
in the existing preflight update rehearsal.

## Historical 6.0.0 development bridge

The current distribution uses descriptor format 3 and requires the compatibility
runtime **6.0.0-dev.9.2** before applying its data conversion. Do not install the
new package directly over 5.x, dev.9 or the file-only dev.9.1 bridge. The frozen compatibility source used by
the update rehearsal is `tests/fixtures/releases/bridge-6.0.0-dev.9.1-source.zip`;
it is a build input, not an installation ZIP or a signed release asset.

Build both signed steps with the existing release key:

```bash
php tools/release/build-bridge.php --assembly=source --output=dist/compatibility-bridge
php tools/release/verify-update.php dist/compatibility-bridge/bootstrap
php tools/release/verify-update.php dist/compatibility-bridge/checkpoint
```

The legacy 5.x `bootstrap` step is dev.9.1 with `--without-migrations`; it
replaces the updater without changing the database. The `checkpoint` step is
dev.9.2 with the normal authenticated migration list. **Apply this second step
before 6.0.0** so that all historical migrations, including the shared-storage
provenance checkpoint, are complete. From the bridge installation root, the
normal production command is `php App/Console.php update MANIFEST SIGNATURE ZIP`,
using the three checkpoint files. Use the same command for the following 6.0.0
update. Never mark migrations applied manually or bypass their checksum checks.

`--assembly=platform` and `--assembly=blog` build matching bridges for existing
modular products; their minimum runtime is selected by the frozen builder.
Existing modular deployments must retain their product identity and available
modules in both packages; a Social-only package cannot replace a deployment
that already contains Blog.

The normal update preserves account and publication IDs, public URLs, file
paths, discussions, reactions and feed history while moving domain storage to
its owners. It creates the standard database and file snapshot before conversion.
Fresh installation uses the current owner schemas directly. Publish only after
the full preflight, extracted-artifact installation and representative MySQL
update/rollback rehearsal pass for the actual signed candidate.

The actual 5.x database path can be checked in an isolated database with:

```bash
php tools/release/release-update-rehearsal.php --artifact=dist/release-6.0.0/update --legacy-baseline=5.16.0 --bridge-bootstrap=dist/compatibility-bridge/bootstrap --bridge-checkpoint=dist/compatibility-bridge/checkpoint
```

The same command accepts `--legacy-baseline=5.22.2`. It seeds the published
schema before either bridge and uses the existing temporary MySQL database
and normal snapshot/rollback checks, never the live installation.

Updates supporting pre-6 installations retain a generated
`App/Support/Cache.php` loader so the old updater can finish in its existing
PHP process. The cache implementation lives only in `App/Core/Support/Cache.php`;
fresh installation packages do not include this compatibility loader.

## Upgrading to 5.22.2: release preparation and repository maintenance

This patch updates the shared asset/component helpers used by release compilation.
Build caches are scoped to one compilation and never replace live development
reads outside that scope. No database migration or configuration change is needed.
Supported updates still start at 5.16.0. Public API and update documentation now
live under `docs/reference/` and `docs/operations/`; the updater removes their
retired root-level copies.

## Upgrading to 5.22.1: RSS article URL duplicate protection

RSS import skips an item when its normalized article URL is already linked from
any post by the same bot, including queued posts and posts imported from another
feed. This check survives changed GUIDs and pruned GUID history. It requires an
existing stored post link; links posted only by other authors do not block import.
No new migration or configuration change is required.

## Upgrading to 5.22.0: configurable web-cron batches

Hosting web-cron URLs accept an optional `batch` parameter, using the existing
HTTP Basic username `scheduler` and cron token as password:

- `/api/scheduler/triggers/cleanup?batch=5000` selects the cleanup subtask batch.
  Supported sizes are 500, 1000, 2500 and 5000; omission keeps 500.
- `/api/scheduler/triggers/feeds?batch=50` selects the maximum number of feed
  sources, clamped to 1?100; omission keeps 20.

The task-specific aliases `cleanup_batch` and `bot_limit` are also supported.
Specify one batch parameter per URL. Malformed values and unsupported batches
for `site-health` are rejected before execution. HEAD remains a read-only probe.
Task locks, due intervals and authentication stay unchanged. Duplicate-post
merging retains its separate 500-copy cap. See `docs/reference/api.md` for the full contract.

No new migration or configuration change is required. Signed updates support
5.16.0 and newer and apply any earlier pending migrations normally.

## Upgrading to 5.21.0: automatic duplicate bot-post cleanup

Migration `20261102_001_compact_content_redirects` adds the two-column
`content_redirects(old_id, content_id)` table. The updater creates its normal
database backup before applying pending migrations. Fresh installations include
the table directly. Signed updates remain supported from 5.16.0.

The existing cleanup task now permanently merges newer identical public posts
of the same active bot into the lowest matching ID. Exact text, link and tag
sets, and attachment metadata must match. Each pair is rechecked and merged in
one transaction; batches process at most 500 copies. Hidden and future posts,
human accounts and differing metadata are excluded.

Likes, comments, comment history and reactions, reports and notifications move
to the surviving post. Duplicate likes and notification keys collapse without
sending new events or losing unread state. Old public addresses return 301;
read-only post and outbound-link resources resolve the surviving ID. Shared
image files remain available. Missing RSS sources do not prevent cleanup.
RSS imports, source configuration and GUID history remain unchanged. No new
configuration is required; cleanup begins with the next scheduled cleanup run.

## Upgrading to 5.20.1: RSS preview images and metadata consistency

This fixes missing RSS artwork for posts whose images come from stored link
previews or provider thumbnails. Attached images keep priority. Preview images
appear in HTML and Media RSS without fabricated enclosure sizes or MIME types.
Feed rendering batches stored metadata and does not download remote pages.

Author and tag RSS now preserve the page's media filter, just like search RSS.
RSS author metadata includes canonical profile URLs. Shared generators keep
HTML and client-navigation metadata aligned and describe posts consistently in
JSON-LD across detail, profile, tag and search pages. Search results remain
noindex and describe only their visible result list. Machine-readable dates
retain ISO 8601 formatting independently of display preferences.

No new migration or configuration change is required. Stored data, uploads and
RSS item GUIDs remain unchanged. Signed updates support 5.16.0 and newer.

## Upgrading to 5.20.0: public RSS and structured metadata

Public RSS exports normalized categories and author names, escaped HTML with
clickable links and line breaks, inline attached images with alternative text,
and Media RSS alongside existing enclosures. Item GUIDs remain canonical post
URLs. Titles omit external URLs and HTML markup; author feeds include their
avatar, and other feeds use configured site artwork when available.

Post pages expose the author's RSS subscription URL, canonical Open Graph
author URLs and article tags. Client navigation replaces these metadata fields
when moving between pages. JSON-LD uses `text` for posts, includes only visible
comments and their nested replies, and supplies explicit author names, types
and URLs on profile posts. Link-preview images remain a fallback when a post
has no attached image.

No new database migration or configuration change is required. Signed updates
remain supported from 5.16.0 and apply earlier pending migrations normally.

## Upgrading to 5.19.2: compact tags and prose translation

Hashtags use muted badges. Consecutive runs of five or more show the first three
and a keyboard-accessible disclosure for the rest; tagging and search are not
limited. Trailing tags form a separate block below the post text and its local
translation controls/output. Tags and overflow counts are excluded from
translation, including when the disclosure is open.

No new database migration or configuration change is required. Existing post
and comment bodies remain unchanged. Signed updates remain supported from
5.16.0 and apply any earlier pending migrations normally.

## Upgrading to 5.19.1: inline tokens and accessible links

Signed updates remain supported from 5.16.0. Migration
`20261101_002_numeric_tags` removes purely numeric terms and their content
relationships. Post and comment bodies remain unchanged: `#1` and `#2026`
are plain text, while mixed names such as `#1980s`, `#3d` and `#gothic-1`
remain supported tags. The updater creates its normal database backup before
applying pending migrations. The cleanup is transactional and safe to retry.
Fresh installations start without legacy numeric terms.

Tag and mention processing protects URL paths, queries and fragments.
Autocomplete uses canonical server values and cancels obsolete suggestions.
Repeated links expose their purpose and content context to assistive technology,
including current counts after AJAX updates. Scheduler polling and history
navigation encode only supported filters against a fixed API endpoint.
No configuration change is required.

## Upgrading to 5.19.0: post text and YouTube thumbnails

No new schema migration or configuration change is required. Signed updates
remain supported from 5.16.0. Manual post creation, edits, imports and link
descriptions share whitespace, read-more and truncation-marker cleanup. Existing
post bodies are not rewritten automatically during an upgrade; explicit CLI
plan/apply/restore maintenance tools live in the source repository under `tools/`.
Those development tools and their private backups are not release-package files.

### YouTube thumbnails

YouTube cards and exported players use direct `i.ytimg.com` URLs derived from
the verified video address: `default.jpg` for viewports up to 759 CSS pixels and
`mqdefault.jpg` for larger viewports. High-DPI mobile screens still select the
small source. The typical sizes are 120 × 90 and 320 × 180 pixels respectively.
Titles, descriptions and click-to-play behavior remain available.

New YouTube links no longer cache thumbnail files or retain an `image_url` value.
Feed imports also skip downloading those thumbnails as post attachments. Existing
cards render direct URLs even before optional historical thumbnail cleanup.
The source-repository cleanup tool backs up and clears old thumbnail paths;
deleting old files is a separate operation requiring an exact path and a check
for remaining references. Normal upgrades preserve stored data and uploads.

The separate `feeds/` service gains `/feeds/csfd/premieres`. It is versioned in
the repository but remains outside the managed application package. The updater
does not replace or delete an existing `feeds/` installation; deploy standalone
feed changes from the matching source tag when needed.

## Upgrading to 5.18.0: shared UI and on-demand dialogs

No new database migration or configuration change is required. Updates remain
supported from 5.16.0 and apply any earlier pending migrations normally.
Settings, user creation, bot-account creation and release notes now load through
authorized JSON resources when opened; closed editors are discarded and list
refreshes preserve open drafts. Popovers and search suggestions share mobile
placement, focus and dismissal behavior. Site-health and update notices retain
their complete warning text.

The signed update removes 127 retired PHP, CSS and JavaScript source paths,
including renamed files and the superseded server-rendered modal templates.
Configuration, uploads, stored data and user preferences are preserved.

## Upgrading to 5.17.0: independent moderation visibility

Migration `20261101_001_content_status` replaces `content.edit_locked_at`,
`edit_locked_by` and `edit_lock_reason` with `content.status`, defaulting to
`published`. Existing `moderation_hide` markers become `hidden`; other old edit
locks are removed without hiding their posts. Post IDs, text, media, comments,
relationships and report history are preserved. The migration is safe to retry
and does not undo a later administrative publication.

The former unique `(content_id, reporter_id)` report key becomes a non-unique
lookup index including report status. Reporting and review serialize on the
parent post: repeated submissions update a user's existing open report, while
a new reporting cycle appends a new row after review. A report never hides a
post automatically. Only administrative hide/publication actions change its
visibility. Editing preserves that state, and dismissal no longer locks edits.
Fresh installations include the current schema directly.

## Bootstrap

The first release containing the updater must be deployed once by the existing
manual process. Future stable releases can then be installed from **Admin →
Updates**. The update page uses the public GitHub Releases API and does not need a
GitHub token for public releases.

## Signing key

Generate the Ed25519 release key once:

```bash
php tools/release/update-key.php
```

The command writes the private key to the ignored
`storage/update-signing.key` file and prints the public key. The public key is
pinned in `App/Core/Updates/Manager.php`; the private key must never be committed or uploaded
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
the canonical module APIs described in `docs/reference/api.md`; legacy page POSTs return 405.
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

## Upgrading to 5.14.0: scoped AJAX and bounded public queries

No new database migration or configuration change is required. AJAX reads reject
obsolete responses and errors, list definitions preserve their own filter and
sorting rules, and replaced feed content releases its requests and resources.
Suggestion caches are bounded by size, age and interface context.

MySQL tag and media selections use bounded recent candidates with a complete
selective fallback; content search paginates narrow candidates before loading
post and author details. Visibility, literal matching and continuation URLs are
preserved. The signed update removes retired JS/CSS sources. Critical CSS and
inline JavaScript remain experiments; normal assets stay external and cacheable.

## Upgrading to 5.15.0: podcast audio and shared cards

Migration `20261031_001_link_audio` adds the nullable `links.audio_url` column.
It is safe to restart and fresh installations include the column directly.
Imported RSS/Atom enclosures and JSON Feed audio attachments now retain their
playback URL alongside the episode page. Direct audio links, web audio metadata
and mujRozhlas episode API links can also supply a playable source.

RSS, Spotify and SoundCloud share a card summary and play placeholder. Players
load only after interaction; native recordings release playback when AJAX
navigation disposes their card. Spotify uses a 152px embed and SoundCloud uses
its 166px classic player without duplicate artwork or timed comments.

Existing posts and GUID history are preserved. Refreshing existing link metadata
can discover page or provider audio, but missing historical RSS enclosures are
not backfilled and old episodes are not imported again.

## Creating a release

Set `TinyCat\Support\Version::CURRENT`, commit the release, and build from a clean worktree:

```bash
php tools/release/build-update.php --version=5.22.2
php tools/release/verify-update.php dist
```

The builder compiles JavaScript, CSS, the icon sprite, scoped UI definitions
and all translation catalogs into `assets/build/`, including a runtime manifest.
These generated files are added directly to the signed package; the source
checkout and runtime cache are not modified. Production pages select URLs from
this inventory instead of minifying or scanning translation sources per request.
Source checkouts without the inventory retain an on-demand development fallback.

The builder creates:

```text
dist/tinycat-5.22.2.zip
dist/tinycat-update.json
dist/tinycat-update.sig
```

Create a GitHub release for the matching `v5.22.2` tag and upload all three files
as release assets. Asset names `tinycat-update.json` and `tinycat-update.sig` are
fixed within each release; the package name is declared by the manifest.

Only managed application and end-user documentation files are packaged.
Configuration, uploads, storage, tests, tools, Git metadata, benchmark results,
internal release reports and the repository-level changelog are excluded. A
new top-level managed file requires a prior updater bridge release. Removed runtime files are
listed by target version in `tools/release/update-deletions.json`; this prevents legacy
PHP files from surviving an overlay update.

Release tools use **5.16.0** as the minimum supported update source, defined by
`TinyCat\Support\Version::MINIMUM_UPDATE`. Production update rehearsals start
from the exact `v5.16.0` tag and verify signed installation, protected files,
migration checksums and rollback. Older releases and upgrade instructions remain
available in Git history. Historical performance comparisons against 2.0.25 are
benchmark references, not supported upgrade paths.

For a fresh installation, build into a separate output directory:

```bash
php tools/release/build-update.php --version=5.22.2 --fresh-install --output=dist/fresh
```

The fresh-install outputs are `tinycat-5.22.2-install.zip`, `tinycat-install.json`
and `tinycat-install.sig`; they cannot overwrite or be mistaken for updater assets.
Verify them with `php tools/release/verify-update.php dist/fresh --fresh-install`.
This package excludes `migrations/` and update deletions. The installer creates
all current tables and indexes directly. Use this ZIP only for new installations;
publish the normal signed update package as the updater release asset.
The shipped operational documentation includes `docs/reference/api.md`,
`docs/operations/updates.md` and `docs/operations/module-deployment.md`.

To prepare both variants with a single validated compilation:

```bash
php tools/release/build-update.php --version=5.22.2 --both --output=dist/release-5.22.2
php tools/release/verify-update.php dist/release-5.22.2/update
php tools/release/verify-update.php dist/release-5.22.2/install --fresh-install
```

The update and install subdirectories each contain their ZIP, manifest and
signature. Preparation uses private temporary directories and publishes files
only after archive completion, hashing and signing. A `.build.lock` coordinates
replacement of previous outputs; it is not a release asset. Upload only the six
named ZIP/JSON/SIG assets. The output directory must be writable; interrupted
or failed publication can retain a `.build-*` directory with `.previous` recovery
copies when restoring a prior file fails.

Source hashing and ZIP emission use the same captured bytes. Asset sources and
imports, selected components, catalog keys and minified contents are reused only
inside one compilation. No persistent build cache is required or consulted.


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

Fresh installations must also be updated in `App/Core/Installation/Installer.php`. The
installer creates the latest schema directly and does not replay historical
migrations.

## Upgrading to 5.0.0: search feeds and native tables

No new database migration or configuration change is required. Search pages now
show posts only; existing category URLs still open the post search. Media filters
use the same `media=images|video|audio|links|text` values as other feeds and remain
selected during pagination. `/search/feed?q=...` exports up to 50 matching public
posts and accepts the same media filter. Autocomplete still suggests profiles
and tags; matching tag suggestions are ordered by published post count.

Administration settings save without a forced document reload. The ordinary
AJAX confirmation remains visible; changes to already-rendered global branding
appear on the next page load. Tables retain native rows and sortable headers on
all viewport sizes, scrolling horizontally inside their wrapper when necessary.
The signed update removes `assets/ui/tables.js` and
`assets/ui/styles/098-mobile-tables.css` from existing installations.

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
