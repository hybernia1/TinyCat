# TinyCat updates

TinyCat updates are curated, signed release packages. The application does not
download a repository snapshot and does not run `git` on the production server.

TinyCat provides signed product identities and selective distributions. See the
[module deployment guide](module-deployment.md) for CLI installation,
activation, migration snapshots and recovery.

## Interrupted web installation

The table and administrator steps each run as an exclusive deployment operation.
Before changes, the installer persists its recovery session and records a
`preparing` installation journal. It then snapshots the dedicated target database,
deployment state and the original configuration (including explicit absence).
The database must be dedicated to this installation: restoration also removes
changes made by other writers after the snapshot. Creating an empty database in
the connection step is outside these operations; recovery never drops that database.

Only the journal's `complete` phase commits a step. Publishing the deployment or
`install.complete` configuration flag alone does not commit it. An unfinished
installation blocks normal HTTP and scheduled entry points even without a
maintenance file. The installer redirects a failed step to `/install/recovery`.
The recovery page and POST require the original session, shared CSRF protection
and the exact operation identity, rechecked under the deployment lock.

Recovery of a table step restores the database before that step; recovery of an
administrator step restores the tables but removes its account, settings and
deployment changes. Configuration bytes and POSIX mode are restored from a
separate private snapshot, outside the package's managed-file namespace. Missing
or corrupt snapshots and a different target database are rejected before restore.
An interrupted restore retains its operation identity and can be repeated.

If the session is lost, the private rescue console accepts:

```text
php storage/updates/console.php rollback BACKUP_ID --installation-stdin < PRIVATE_DATABASE_JSON
```

The private input outside the web root contains a `database` object with `host`,
`name`, `user`, `password`, and optional `port` and `charset`. It is parsed as
bounded JSON, never executed as PHP; credentials are not command arguments or
journal fields. A `preparing` operation has not changed application data: use
`-` as its backup ID to abort preparation. A committed operation must instead
use `finish-deployment BACKUP_ID` if maintenance cleanup was interrupted.

Windows automatic installation and configuration restoration require Windows
PowerShell 5.1 (.NET Framework), PHP `proc_open`, and local NTFS on a Fixed or
Removable drive without reparse paths. Network shares, SMB, ReFS and other
filesystems are rejected. The runtime
[`configuration-windows.ps1`](../../App/Core/Installation/configuration-windows.ps1)
helper is part of both installation and update distributions. It checks root,
storage, updates and backup directory ownership and rejects mutating Allow rights
for identities other than the runtime SID, SYSTEM and Administrators. Existing
directory ACLs are not changed. Missing capabilities or unsuitable permissions
cause rejection before installation mutations.

Configuration and private snapshot files receive a protected DACL granting
FullControl only to the runtime SID, SYSTEM and Administrators before secret
content is written. Same-volume publication is followed by verification of the
resulting file identity and DACL. Restoration deliberately normalizes NTFS ACLs
to this policy instead of preserving the original ACL; different service
identities cannot read the result. See [filesystem storage](../development/storage.md).
Secret staging uses only `storage/updates/installation-private`; the installer
protects this directory when creating it and leaves existing directory ACLs
unchanged. Input is bounded before parsing and errors do not echo secret data.
POSIX configuration publication stages private bytes on the same filesystem,
then renames without a copy/unlink fallback. The guarantees cover process
interruption, not power-loss durability. Deployment-specific web protection and
local filesystem permissions remain required; directory ACLs alone do not prove
the protection of a renamed file.

## Tasks and deployment admission

Every task execution enters the deployment guard in `Core/Tasks/Runner`, before
its per-task lock, history writes and owner callback. This applies to Console,
scheduled CLI, scheduler HTTP triggers/executions, administration and health runs,
including direct Runner calls. Read-only scheduler health/HEAD probes run no tasks.
The existing task result and transport envelopes remain in use; denied admission
is a failed task without a new history row.

Tasks hold a shared `storage/updates/update.lock`; updates, lifecycle snapshots
and rollback require its exclusive mode. Different tasks may run concurrently,
while each task retains its own exclusive lock. Lock acquisition is nonblocking.
Maintenance or an operation journal in `applying`, `failed` or `restoring` also
denies admission after an interrupted deployment releases its process lock.
Restore the recorded backup before resuming work; removing a lock file is not
recovery and can break exclusion with a running owner.

If the operation journal already says `complete` but cleanup failed, do not
restore the backup merely to finish cleanup. Run
`php storage/updates/console.php finish-deployment BACKUP_ID` with the backup
ID from the journal. The command takes the deployment lock and accepts only
the latest committed operation. It is safe to repeat for that operation.

Update retention enters the same shared guard before its exclusive
`storage/updates/cleanup.lock`, including when called directly. It never upgrades
the shared deployment lock. Competing sweeps or closed deployment admission defer
retention. Recovery backups remain protected by the completion/retention rules;
rollback reads and restores its backup under the exclusive deployment lock.
Nested runs of a different task may acquire another shared guard; deployment
operations from inside a task are rejected instead of waiting for a lock upgrade.
Locks are released on normal completion, failure and process exit. A later task
lock owner marks abandoned history interrupted, using the existing recovery rule.

The isolated regression is
`php tests/run.php --only=tests/domains/scheduler/deployment.php`.

Version 7.0.0 removes the unused Blog module and its assembly preset.
The release-specific sections below retain historical commands and evidence for
their immutable packages. They do not restore Blog support in current sources.
An installed Blog owner blocks an update to the new distribution before changes;
do not bypass ownership checks or erase its data to force an update.

## Supported update baseline

New source packages require a completed TinyCat **6.0.10** installation or newer,
for all assemblies. Older supported sites first apply the unchanged signed
6.0.10 release, using its documented predecessor hops when needed. Before updating,
refresh the private rescue launcher as described below. The published
6.0.10 accepts completed 6.0.8 installations. The builder rejects older
minimum versions and `--legacy-upgrade`.

The 6.0.8 reader supports metadata-only archival prefixes in
distribution format 4. Its published bridge remains format 3 and retains all
published migration PHP so the 6.0.7 updater can install it. Current sources
use format 4 and archive all 24 existing built-in migration records. They require
6.0.8 and an installed module schema at or beyond each archived prefix. Historical
PHP is explicitly deleted during update; each owner retains an executable current
fresh baseline. Later schema changes append new executable migrations.
Independent module ZIPs use format 2 for this contract. Neither core nor module
updates bypass integrity checks, adopt unrelated tables or discard journal rows.
See [the archival contract](../development/domain-storage.md#archivace-migrační-historie).

The only physical journal is `migrations`. Existing IDs, stream names, checksums,
sequences, opaque version labels and application times are preserved. Historical
version values may be migration names up to 190 characters rather than SemVer.
`module_installations` retains current schema state separately.

The 6.0.7 core task schema step 5 removes the `module_migrations` compatibility
view and its SQLite trigger. This view served an already-loaded pre-6.0.6 writer;
completed 6.0.6 engines write the unified journal directly. Removing the view
changes no journal rows. Standard snapshot and rollback restore it when present
in the baseline. The current journal never reads, copies or adopts the retired
physical journal tables; an incomplete pre-baseline database is rejected.

Current installation and readiness use one owner schema path. Global-table
adoption callbacks and `SchemaInspection` are retired; normal inspection belongs
to `TinyCat\Database\Schema`. Archived IDs, sequences and checksums match the
immutable published SQL; no historical PHP is needed for validation. They are
never replayed when updating a completed 6.0.8 site, and fresh installations use
the current signed baseline.
Published release tags and assets remain unchanged.

Format-3 bridge descriptors retain executable history. Format 4 also supports
archived history records without executable files. Completed 6.0.6 deployment state
already contains canonical functional module IDs; updates preserve those IDs,
including installed inactive owners. Historical activation remapping and the
format 1/2 registration branches are removed from current sources. Unknown
installed owners still prevent an update that would discard their sources or data.


For a new release, use:

```sh
php tools/release/build-update.php --version=X.Y.Z --both --output=dist/release-X.Y.Z
php tools/release/release-update-rehearsal.php --artifact=dist/release-X.Y.Z/update --baseline-version=6.0.10 --direct-web --cached-autoload
```

The rehearsal uses the published `v6.0.10` source and a disposable MySQL database.
It checks representative Social data, retained long historical version labels,
the signed update and exact rollback, including
with the baseline schema, journal and lifecycle classes already loaded.
It refreshes an inherited private rescue launcher with the baseline CLI and uses
that launcher for rollback after deleting the facade. The release version must
be newer than 6.0.10. The automated file rehearsal
uses a synthetic successor in an isolated copy when the source matches the baseline.

The release notes below describe historical packages. Their old bridge commands
and supported version ranges do not apply to the current builder.

## Upgrading to 8.0.3

Requires completed **6.0.10 or newer** and PHP 8.4. This maintenance release
consolidates internal Core and Social PHP implementations without a new database
migration or configuration change. Signed update deletions retire 18 internal
classes and the flash-data partial. Existing accounts, content, files, module
activation, task IDs and migration histories are preserved.

Custom integrations that called removed internal helpers must use their canonical
owners: Identity resources/setup/read-model sorting, Tasks resources/repository
sorting, Core password and link-provider methods, and Social archive, moderation,
author-statistics and feed-publication implementations. Social registration now
belongs entirely to its module entry. The documented publicFooter theme fragment
remains supported. Deploy matching signed Core or Social packages and generated
assets; update and install variants remain separate.

## Upgrading to 8.0.2

Requires PHP 8.4 and a completed 6.0.10 or newer installation. Publish matching
signed Core and Social update/install packages. This release adds no database
migration or configuration change; existing migration history, accounts, uploads
and module activation are preserved.

Account/avatar and Social dialog editors load additively with their components,
locale messages and styles. Inline editors request the same payload when present.
Source fallback and compiled releases share selection, minification and dependency
rules. Deploy the matching runtime and generated inventory together. Existing
resource authorization, drafts, refresh cancellation and dialog history remain in
the common loader; no resource API embeds presentation or asset URLs.

Feed initialization no longer reads scroll position while the application bundle
is being evaluated. Public home, tag, author and search pages receive smaller
initial bundles. Core administration and account extensions remain available.

## Upgrading to 8.0.1

Requires PHP 8.4 and a completed 6.0.10 or newer installation. Publish matching
signed Core and Social update/install packages. The account stream adds the
nullable `users.avatar_version` column; existing avatars retain their legacy
paths until replaced. Fresh installations use the new account fresh-v2 baseline.
Published migration files and checksums remain unchanged. Configuration, media,
accounts and module activation are preserved.

Credential changes invalidate stale sessions and reset tokens. Deployment admission
now coordinates tasks and installation recovery; finish updating all workers before
resuming external scheduler invocation. The Windows configuration helper is a
required runtime file. On 6.0.10, refresh and verify the private rescue launcher
before applying the update, as described under the supported baseline above.

This release also fixes transactional Social writes, avatar/profile/settings cache
consistency, feed cursors, discussion pagination, shared form constraints and
authentication modal switching. Deploy the matching generated assets; search cards
now include their Social status dependencies. Authentication dialogs explicitly omit
Refresh while ordinary resource editors retain it.

## Upgrading to 8.0.0

Existing installations keep the public website enabled when `site.public_enabled`
has not been stored. Fresh installations start with it disabled; administrators
can change it later in site settings without rebuilding assets or activating
modules. Login, recovery, accounts and administration remain available. Closed
public routes return 404, discovery is withdrawn and robots disallow crawling.
Previously public uploads are not made private by this setting.

Runtime diagnostics now use `app.debug`: `'on'`, `'off'` (default), or `'log'`
(`storage/debug.log`). Existing booleans remain accepted. See
[runtime diagnostics](debugging.md). Extension authors must use explicit
`TinyCat\Http\HttpException` for safe public rejections; arbitrary exception
codes and `InvalidArgumentException` no longer imply a public HTTP status.
See the [error handling contract](../contracts/error-handling.md).

This is a major extension-contract release. `Router::debug()` and local debug
arguments of `Http\Api`, `Response::exception()` and `Tasks\Runner::run()` are
removed. Modules use the installation-wide diagnostic policy instead. Update
custom modules before deployment, including named arguments and the removed
presentation flags described below.

Custom themes receive `publicFooter` explicitly. Configurable `site.footer_html`
and its renderer are removed; an old stored value is inert. Use theme-owned
render-only markup for a custom footer. Pages select `surface` explicitly or
use the shared path classification; retired presentation flags do not select
layouts. Bot management resources now provide internal `update_url` and
`suggest_url` endpoints independent of public website availability.

No database migration is added. Published migration files, configuration,
accounts, uploads and module activation are preserved. Use the matching signed
Core or Social update and matching generated assets; install ZIPs are for new
sites. PHP 8.4 and completed 6.0.10 or newer remain required. Refresh the private
rescue launcher before updating from 6.0.10. Follow the 7.1.1 scheduler rollout
instructions below when updating older workers.

## Upgrading to 7.1.2

This maintenance release improves client lifecycle recovery, native/AJAX form
parity, interactive validation and keyboard focus traversal. Account-menu styles
now belong to core Identity, local CSS duplicates are consolidated, and the
skip-link uses its theme foreground token. The standalone embed retains its own
palette and does not load the core theme.

No database migration is added. PHP 8.4 and completed 6.0.10 or newer remain
required. Configuration, accounts, uploads, module activation and published
migration history are preserved. Use the matching signed Core or Social update;
install ZIPs are for new sites. Refresh the private rescue launcher before an
update from 6.0.10. When updating from before 7.1.1, also follow its scheduler
HTTPS and coordinated worker-rollout instructions below.

## Upgrading to 7.1.1

This maintenance release improves streaming/batched database snapshots, shared
CRUD cleanup, once-decoded routing and native/AJAX filter parity. It fixes cache
recovery after Memcached connection outages and rejects compiled client/catalog
inventories whose package registry revision no longer matches the installation.
There is no database migration or change to published migration checksums.
Existing old snapshot payloads remain readable. Data caches may cold-miss after
installation/key namespace changes; configuration, media and accounts are retained.

Scheduler Bearer clients now require HTTPS just like Basic hosting triggers;
proxy scheme detection still uses the shared trusted-proxy policy. During an
upgrade, pause external scheduler invocation and finish updating all PHP workers
and nodes before resuming it. The new database-scoped lock namespace cannot
coordinate with older path-scoped locks during a mixed-version rollout. Skipped
runs no longer conceal failed or stale work, and feed batches report remaining
due sources. Exact duplicate-post merging processes resumable bounded windows.

Core and Social update/install artifacts remain separate. Use the matching
product's signed update for an existing installation. Refresh the inherited
private rescue launcher before updating from 6.0.10, as required below.

## Upgrading to 7.1.0

Publish matching core and Social update/install artifacts. The minimum remains a
completed 6.0.10 installation with PHP 8.4; no database migration is added.
Configuration, uploads, module activation and migration history are preserved.
Core artifacts retire only their own module roots, so an independently installed
Social package is not included in core cleanup. Absent foreign-module paths remain
protected by the runtime; missing files within the artifact's own scope are harmless.

Social publishing/discussion client sources now live under its own assets directory.
Extension imports of the retired core discussion/text-resource helpers must use their
Social owners. Existing publishing component IDs remain available through Social.
Deploy the matching generated assets with the runtime; do not copy only PHP files.

Settings sections save independently and show unsaved-tab markers. PATCH `/api/settings`
returns the same password-safe `sections` resource as the editor GET, replacing the
previous saved/images summary. Resource refresh protects drafts and preserves history.
Core-only sitemap/privacy/crawler documents include only registered owner contributions.
Error, SVG, XML, TXT and RSS delivery use the shared protocol mechanisms.

## Upgrading to 7.0.0

Requires completed **6.0.10 or newer** and PHP 8.4. This major release consolidates
the framework and Social registrations, introduces optional standalone public
themes and moves asset sources to their owners. No database migration is added.
Configuration, accounts, Social content, uploads and activation are preserved.
An installation with Blog recorded as an installed owner is rejected before
changes; removing the owner record or its data is not an upgrade procedure.

Third-party API clients must use `/api/profiles/{id}` and the corresponding
following endpoints for Social profiles. Identity account management remains
under `/api/users`. Public search uses `/search/all` and `/api/search/query`;
the Social CAPTCHA verification endpoint is `/api/search/challenges`. Removed
search/profile paths are not aliases. See the [API reference](../reference/api.md).

Extensions must replace the removed `PageTheme`/`Contributions::themes` contract
with [standalone themes](../development/themes.md). The shared asset helper now
accepts an owned application-relative source path, for example
`App/Core/Ui/assets/icons.svg`; update old source imports and asset references.
The signed deletion plan removes retired source paths. Generated, versioned URLs
remain public; `/assets/embed.js` remains a stable Social-owned embed entry.
An old saved module theme ID falls back to the core layout without rewriting
site configuration.

One GitHub release contains both core and production Social update/install trios
(twelve files), each with its own signed product identity. Build from the same
clean commit using the [product channel procedure](#product-update-channels).
Verify each signature/archive and fresh installer, and rehearse the exact Social
update from both the supported 6.0.10 baseline and the previous 6.3.1 release,
including cached baseline classes and rollback. Source preflight is separate
from validation of these concrete signed artifacts.

## Upgrading to 6.3.1

Requires completed **6.0.10 or newer** and PHP 8.4. This patch refines shared
links, table interaction, sticky header backgrounds, neutral dark surfaces and
installer presentation. There are no database migrations or activation changes.
Configuration, accounts, content and uploads are preserved. The signed deletion
plan removes the obsolete inline-link hover stylesheet.

Build production update and installation packages with `--assembly=platform --both`.
Verify the exact signed update with the artifact preflight and representative
6.0.10, 6.0.11, 6.1.4, 6.2.0 and 6.3.0 production update/rollback rehearsals using
`--direct-web --cached-autoload`. Rehearse the extracted fresh installer against
an isolated MySQL database. The development Blog module remains excluded from
production distributions. A baseline created with `--platform-baseline` can
include Blog; it requires a development update assembly retaining that owner.
The production package must be refused for such a baseline rather than removing
its installed owner or data.

## Upgrading to 6.2.0

Requires completed **6.0.10 or newer** and PHP 8.4. Shared UI scale and density
now control typography, fields, buttons, dialogs, pagination and toasts. Layouts
use one mobile/desktop boundary at 900px. Administration is compact and flat;
public search uses a shared sidebar workspace with owner-rendered result cards.
Settings presentation is lazy owner metadata instead of initial HTML payloads.
Contextual help, validation and code-copy controls use shared core mechanisms.

No database migration or activation change is introduced. Existing data,
configuration, uploads and installed module ownership are retained. The signed
deletion plan removes the retired confirm-layout stylesheet; its desktop rule
is part of the shared desktop modal stylesheet.

```sh
php tools/release/build-update.php --version=6.2.0 --assembly=development --active=tinycat.social --output=.work/releases/release-6-2-0/update
php tools/release/build-update.php --version=6.2.0 --assembly=platform --fresh-install --output=.work/releases/release-6-2-0/install
```

Verify the exact update against published 6.0.10, 6.0.11 and 6.1.4 platform sources
with `--platform-baseline --direct-web --cached-autoload`, including retained Blog
ownership, data preservation and exact rollback. Verify the production package
with a fresh isolated MySQL installation. Refresh and verify the private rescue
launcher on 6.0.10 before updating, as described below.

## Upgrading to 6.1.4

Requires completed **6.0.10 or newer** and PHP 8.4. This release consolidates
product pages and editor bodies in owner component catalogs, standardizes JSON
authoring and removes redundant PHP templates. Document, layout, error and embed
templates remain explicit technical boundaries. Client lifecycle cleanup preserves
drafts and cancels obsolete reads. No database migration or activation change is
introduced. Data, configuration, uploads and installed module ownership are retained.

Build the retained-owner update and production fresh installation separately:

```sh
php tools/release/build-update.php --version=6.1.4 --assembly=development --active=tinycat.social --output=.work/releases/release-6-1-4/update
php tools/release/build-update.php --version=6.1.4 --assembly=platform --fresh-install --output=.work/releases/release-6-1-4/install
```

Verify the exact update against published 6.0.10, 6.0.11 and 6.1.3 platform sources
with `--platform-baseline --direct-web --cached-autoload`, including retained Blog
ownership, data preservation and exact rollback. Verify the production package
with a fresh isolated MySQL installation. On 6.0.10, prepare and verify the private
rescue launcher as documented below before updating.

## Upgrading to 6.1.3

Requires completed **6.0.10 or newer** and PHP 8.4. No database migration or
activation change is introduced. Public, administrative and installation pages
share one HTML document. Installation, account and settings UI use owner component
catalogs; redundant Social feed presentation is removed. Legacy bot detail URLs
open through the common dialog lifecycle with filters and history preserved.

Build the retained-owner update and production fresh installation separately:

```sh
php tools/release/build-update.php --version=6.1.3 --assembly=development --active=tinycat.social --output=.work/releases/release-6-1-3/update
php tools/release/build-update.php --version=6.1.3 --assembly=platform --fresh-install --output=.work/releases/release-6-1-3/install
```

Verify the exact update against published 6.0.10, 6.0.11 and 6.1.2 platform sources
with `--platform-baseline --direct-web --cached-autoload`, including retained Blog
ownership, data preservation and rollback. Verify the production package with a
fresh isolated MySQL installation. Existing 6.0.10 installations still require
the rescue launcher preparation documented below.

## Upgrading to 6.1.2

Requires completed **6.0.10 or newer** and PHP 8.4. No database migration or
activation change is introduced. This patch fixes the 6.1.1 Site Health client
startup regression: scheduler run details no longer require a scheduler list
declaration. Navigation, settings dialogs and AJAX health checks initialize normally.

Build the retained-owner update and production fresh installation separately:

```sh
php tools/release/build-update.php --version=6.1.2 --assembly=development --active=tinycat.social --output=.work/releases/release-6-1-2/update
php tools/release/build-update.php --version=6.1.2 --assembly=platform --fresh-install --output=.work/releases/release-6-1-2/install
```

Verify the exact update against published 6.0.10, 6.0.11 and 6.1.1 platform sources
with `--platform-baseline --direct-web --cached-autoload`, including retained Blog
ownership and rollback. Existing 6.0.10 installations still require the rescue
launcher preparation documented below.

## Upgrading to 6.1.1

Requires completed **6.0.10 or newer** and PHP 8.4. Refresh inherited rescue
launchers as documented below. No database migration is introduced; installed
module activation, data and published migration history remain unchanged.

Settings presentation now comes from the owning PHP sections. Administrative
list wiring comes from owner asset manifests; specialized account, RSS and bot
editors and scheduler polling keep their existing responsibilities. Empty optional
Blog URL rules and UTM source/campaign values can be saved without a required-field
error. The signed deletion plan retires fourteen redundant presentation files.

Build the compatibility update with retained Blog sources separately from the
production fresh installation:

```sh
php tools/release/build-update.php --version=6.1.1 --assembly=development --active=tinycat.social --output=.work/releases/release-6-1-1/update
php tools/release/build-update.php --version=6.1.1 --assembly=platform --fresh-install --output=.work/releases/release-6-1-1/install
```

Verify the exact update artifact against published 6.0.10, 6.0.11 and 6.1.0
platform sources with `--platform-baseline --direct-web --cached-autoload`,
including rollback and retained installed Blog ownership. The source preflight
and artifact checks are separate; a local dirty-worktree candidate is not a
published release.

## Upgrading to 6.1.0

Requires completed **6.0.10 or newer** and PHP 8.4. Refresh the inherited rescue
launcher on 6.0.10 as documented below. No database migration is introduced.
Existing configuration, module activation and minification settings are preserved.

Site settings now offer **Login** as the homepage (`tinycat.accounts.login`).
Guests see the existing login form directly at `/`; authenticated accounts use
their permitted landing destination. Accounts without another destination see an
account overview instead of redirecting between `/` and `/login`. Existing
automatic Social and Blog homepage selection remains unchanged. Selecting Login
changes the entry page; authorization of other routes remains their owner's policy.

The shared HTML document now honors HTML minification. Minification remains
configurable; updating does not enable previously disabled options. Apache cache
rules revalidate unversioned assets and reserve yearly immutable caching for
versioned URLs. Gzip also covers the web app manifest. Public error pages use
public navigation and asset bundles even when the failed URL starts with `/admin`.

Build the compatibility update with retained Blog sources separately from the
production fresh installation:

```sh
php tools/release/build-update.php --version=6.1.0 --assembly=development --active=tinycat.social --output=.work/releases/release-6-1-0/update
php tools/release/build-update.php --version=6.1.0 --assembly=platform --fresh-install --output=.work/releases/release-6-1-0/install
```

Verify the exact update artifact against published 6.0.10, 6.0.11 and 6.0.13
platform sources with `--platform-baseline --direct-web --cached-autoload`,
including exact rollback and retained installed Blog ownership.

## Upgrading to 6.0.13

Requires completed **6.0.10 or newer** and PHP 8.4. Refresh the inherited rescue
launcher on 6.0.10 as documented below. No new database migrations are introduced;
published migration checksums and installed module ownership remain unchanged.
Social now shares core navigation, identity administration and HTTP mechanisms.
The signed deletion plan removes eight retired Social adapters.

Build the compatibility update separately from the production fresh installation,
retaining Blog for existing installed owners:

```sh
php tools/release/build-update.php --version=6.0.13 --assembly=development --active=tinycat.social --output=.work/releases/release-6-0-13/update
php tools/release/build-update.php --version=6.0.13 --assembly=platform --fresh-install --output=.work/releases/release-6-0-13/install
```

Verify the exact update artifact against published 6.0.10 and 6.0.12 platform
sources with `--platform-baseline --direct-web --cached-autoload`, including exact
rollback. The 6.0.11 platform baseline additionally covers its default-active Blog.

## Upgrading to 6.0.12

Requires a completed **6.0.10 or newer** installation and PHP 8.4. From 6.0.10,
refresh and verify the private rescue launcher as documented below before updating.
There are no new database migrations or changes to published migration checksums.

The module manager discovers manifest files without executing inactive module PHP.
Removing a never-activated module no longer breaks administration. Missing active
modules and their dependents are suspended while their stored state and data remain
intact. Manually copied modules appear inactive and use the existing lifecycle on
explicit activation. Social's internal callback registries and compatibility wrappers
are simplified; eight retired Social files are deleted by the signed update plan.

New production installations omit the development Blog module. Public 6.0.11
packages included and enabled Blog by default, so the compatibility update retains
its files and metadata for existing installed owners. It does not remove retained
Blog data or silently change activation. Build the release variants separately:
The build-only `development` hint is omitted from packaged runtime manifests so
already-loaded supported updater classes can read them.

```sh
php tools/release/build-update.php --version=6.0.12 --assembly=development --active=tinycat.social --output=.work/releases/release-6-0-12/update
php tools/release/build-update.php --version=6.0.12 --assembly=platform --fresh-install --output=.work/releases/release-6-0-12/install
```

Verify updates against both the minimum supported baseline and the last public
platform release, with the original updater classes cached:

```sh
php tools/release/release-update-rehearsal.php --artifact=.work/releases/release-6-0-12/update --baseline-version=6.0.10 --platform-baseline --direct-web --cached-autoload
php tools/release/release-update-rehearsal.php --artifact=.work/releases/release-6-0-12/update --baseline-version=6.0.11 --platform-baseline --direct-web --cached-autoload
```

## Upgrading to 6.0.11

Requires a completed **6.0.10 or newer** installation and PHP 8.4. Before updating
from 6.0.10, prepare and verify the private rescue launcher using the commands
below. Older sites must first apply the unchanged signed 6.0.10 release through
its supported predecessors. There are no new database migrations.

This release removes `App/autoload.php` and the manual source inventory
`App/available-modules.php` through the signed deletion plan. Platform builds
discover source manifests directly; standalone module builds select their
transitive dependencies. Discovery reads JSON without executing module PHP.
Installed module selection and activation remain explicit, so adding a source
directory does not enable a module on a running site. Update and rollback preserve
data, journal history, installed inactive owners, configuration and uploads.

## Retiring the autoload facade

Sources after 6.0.10 remove `App/autoload.php`. HTTP and public CLI entry points
already use `App/Core/Runtime/autoload.php` directly. External scripts must switch
their `require_once` to that loader; call `Application::start()` explicitly only
when the script needs the application runtime.

An update preserves `storage/`, including private rescue launchers generated by
older releases. Being on 6.0.10 alone does not refresh those launchers. Before
installing a successor package, run these commands from each installed site's
root while it is still on the completed 6.0.10 release:

```sh
php App/Console.php rescue
php storage/updates/console.php help
```

The existing 6.0.10 command replaces the inherited launcher with one requiring
the core loader. If either command fails, resolve the error before applying the
update. This is an operator prerequisite; the published 6.0.10 web updater does
not enforce it or rewrite private launchers automatically. The prepared launcher
continues working after the facade is removed and after rollback to 6.0.10.
The normal signed deletion inventory backs up the removed facade and restores
its original bytes on rollback. No database migration is needed for this cleanup.

## Upgrading to 6.0.10

Requires a completed **6.0.8 or newer** installation and PHP 8.4. Sites on 6.0.9
can update directly through **Admin > Updates**. There are no new database
migrations; existing journal records, data, configuration, uploads and module
activation are preserved.

The release makes core API, validation and form lifecycle contracts authoritative.
Social forms use the shared AJAX lifecycle, source previews use its submit guard,
and public HTTP/AJAX navigation derives from one route declaration. Editor limits
distinguish Unicode characters from UTF-8 bytes. Overlong Blog comments now return
a field error without changing the saved comment or truncating the draft.

HTTP and CLI start explicitly through the runtime. The deletion plan removes six
additional retired paths, including the bootstrap wrapper and unused presentation
partials. The public autoload facade remains for private rescue launchers created
by published versions. Signed update, cached-code recovery and standard rollback
preserve those launchers and restore removed files when rolling back.

## Upgrading to 6.0.9

Requires a completed **6.0.8 or newer** installation and PHP 8.4. Older supported
sites must first apply the unchanged signed 6.0.8 reader bridge. Update through
**Admin > Updates** using the normal signed package.

The distribution uses format 4 and archives all 24 existing built-in migrations
with their original IDs, sequences and checksums. The updater removes 32 obsolete
PHP files, including historical DDL helpers and data converters. All eleven
schema owners retain independent current fresh baselines; fresh installation
does not replay archived migrations. No new schema migration runs on a completed
6.0.8 installation, and existing journal rows and timestamps remain unchanged.

Standard snapshots and rollback restore deleted PHP and exact database state.
Configuration, uploads, runtime storage and module activation are preserved.
Checking the last published signed release also remains possible after the
source update floor advances; incoming updates still enforce their required
runtime baseline and archival reader.

## Upgrading to 6.0.8

Requires a completed **6.0.7 or newer** installation and PHP 8.4. Apply the signed
update through **Admin > Updates**. This is the reader bridge: it retains
format 3, every published built-in migration PHP file, and the current database
journal. It introduces support for archival format 4 and independent module
ZIP format 2, which both require at least the 6.0.8 reader.

No built-in schema migration or deletion is introduced. Existing migration IDs,
checksums, timestamps, domain data, configuration and activation are preserved.
Physical retirement of built-in historical PHP belongs to a later release.

## Upgrading to 6.0.7

Requires a completed **6.0.6 or newer** installation and PHP 8.4. Apply the signed
update through **Admin > Updates**. Older supported installations first use the
unchanged published 6.0.6 bridge. The 6.0.7 package retains both Social and Blog
sources; installed and active module selections remain installation-owned.

Task-schema step 5 retires the journal writer view while preserving all physical
migration history. Published applied migration bytes, IDs and checksums remain
unchanged. The standard update snapshot and rollback preserve configuration,
media, deployment state and database contents.

## Upgrading to 6.0.6

Use the signed 6.0.6 update from a completed 6.0.4 or 6.0.5 installation.
Historical version labels up to 190 characters are preserved without changing
their values, checksums or application times. A new schema step widens journals
created by 6.0.5, including when the running updater retains its old PHP classes.
Published migrations remain unchanged. If 6.0.5 is in a failed maintenance state,
first verify its backup and perform the normal rollback, then apply 6.0.6.

## Upgrading to 6.0.5

**Withdrawn from stable updates.** Do not apply 6.0.5: its journal conversion
rejects historical version labels longer than 32 characters. A completed 6.0.4
installation can retain those labels from earlier upgrades. If this failure
leaves a site in maintenance mode, verify its original database/file snapshot
and use the normal update rollback before reopening it. Do not clear the
maintenance marker or rewrite historical migration records to bypass the error.
The original signed 6.0.5 assets remain available unchanged for recovery.
The corrected signed successor is 6.0.6.

This release
reduces startup work, compiles server component catalogs, unifies migration
journals and derives scheduler presentation from registered tasks. Configuration,
uploads and runtime storage stay in place. The updater snapshots the database
before journal conversion and supports restoring the original 6.0.4 installation.

The published 6.0.5 and 6.0.6 packages retained `SchemaInspection` for immutable
migrations and a running 6.0.4 updater with its original schema class loaded.
Current sources use `TinyCat\Database\Schema` and require completed 6.0.6;
the runtime adapter is removed. Applied SQL sources and checksums are unchanged.
Older installations must first install the published signed 6.0.4 release.

## Upgrading to 6.0.4

Release 6.0.4 improves core startup and shared UI asset selection. It adds no
schema migration over 6.0.3 and preserves the signed administration upgrade
from stable 5.16.0 and newer, including 5.22.2. Earlier migrations still run when
upgrading an older installation. Use the installation ZIP only for a new site.

The development workspace change affects source checkouts and tools only.
Existing application storage, uploads, configuration and backups stay in place.

```bash
php tools/release/build-update.php --version=6.0.4 --assembly=platform --both --legacy-upgrade --output=dist/release-6.0.4
php tools/release/release-update-rehearsal.php --artifact=dist/release-6.0.4/update --baseline-version=5.22.2 --direct-web --cached-autoload
```

## Upgrading to 6.0.3

Release 6.0.3 supports the normal signed administration update from stable
5.16.0 and newer, including 5.22.2 and 6.0.2. Use the installation ZIP only for
a new site. The update migrates notification subjects through explicit core
and Social schema migrations while preserving IDs, read state, keys and payloads.
Inactive installed owners retain their data for later activation and migration.
Language names move into Support catalogs; only the declared old root language
files are removed. Configuration, uploads and runtime storage remain unmanaged.

```bash
php tools/release/build-update.php --version=6.0.3 --assembly=platform --both --legacy-upgrade --output=dist/release-6.0.3
php tools/release/release-update-rehearsal.php --artifact=dist/release-6.0.3/update --baseline-version=5.22.2 --direct-web --cached-autoload
```

## Upgrading to 6.0.2 from stable 5.x

Release 6.0.1 can stop before conversion when OPcache executes the old autoloader after file replacement. Keep maintenance enabled after such an interruption and preserve both backup directories. Do not infer database readiness from the displayed application version.

An installation upgraded through older releases can retain flat email catalogs
`lang/emails-cs.json` and `lang/emails-en.json`. If 6.0.2 completes conversion but
the request fails with "expected locale metadata with a nonempty name" for one
of these files, archive both outside `lang/` and retry the request. These are old
translation resources, not database records. Preserve their contents for any local
customizations. The source deletion manifest includes both paths for subsequent
release packages; the published 6.0.2 assets are unchanged.

Use **Admin → Updates** normally from TinyCat 5.16.0 or newer. In particular,
5.22.2 does not need a manually installed dev runtime. The same release also
updates 6.0.0. New installations must use the separate installation ZIP.

Release 6.0.0's main update manifest required dev.9.2, so the stable web updater
could not offer it as compatible. A manually applied bridge bundle was not an
equivalent replacement for the ordinary update flow. Use 6.0.2 instead.

Build the public Social/platform release with:

```bash
php tools/release/build-update.php --version=6.0.2 --assembly=platform --both --legacy-upgrade --output=dist/release-6.0.2
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
php tools/release/release-update-rehearsal.php --artifact=dist/release-6.0.2/update --baseline-version=5.22.2 --direct-web --cached-autoload
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
`.work/keys/update-signing.key` file and prints the public key. The public key is
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
php tools/release/build-update.php --version=6.0.9 --assembly=platform
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
dist/tinycat-6.0.9.zip
dist/tinycat-update.json
dist/tinycat-update.sig
```

Create a GitHub release for the matching `v6.0.9` tag and upload all three files
as release assets. Asset names `tinycat-update.json` and `tinycat-update.sig` are
fixed within each release; the package name is declared by the manifest.

Only managed application and end-user documentation files are packaged.
Configuration, uploads, storage, tests, tools, Git metadata, benchmark results,
internal release reports and the repository-level changelog are excluded. A
new top-level managed file requires a prior updater bridge release. Removed runtime files are
listed by target version in `tools/release/update-deletions.json`; this prevents legacy
PHP files from surviving an overlay update.

Release tools use **6.0.10** as the minimum supported update source, defined by
`TinyCat\Support\Version::MINIMUM_UPDATE`. Production update rehearsals start
from the exact `v6.0.10` tag and verify signed installation, protected files,
migration checksums and rollback. Older releases and upgrade instructions remain
available in Git history. Historical performance comparisons against 2.0.25 are
benchmark references, not supported upgrade paths.

For a fresh installation, build into a separate output directory:

```bash
php tools/release/build-update.php --version=6.0.9 --assembly=platform --fresh-install --output=dist/fresh
```

The fresh-install outputs are `tinycat-6.0.9-install.zip`, `tinycat-install.json`
and `tinycat-install.sig`; they cannot overwrite or be mistaken for updater assets.
Verify them with `php tools/release/verify-update.php dist/fresh --fresh-install`.
This package excludes `migrations/` and update deletions. The installer creates
all current tables and indexes directly. Use this ZIP only for new installations;
publish the normal signed update package as the updater release asset.
The shipped operational documentation includes `docs/reference/api.md`,
`docs/operations/updates.md`, `docs/operations/module-deployment.md`,
`docs/operations/debugging.md` and `docs/contracts/error-handling.md`.

To prepare both variants with a single validated compilation:

```bash
php tools/release/build-update.php --version=6.0.9 --assembly=platform --both --output=dist/release-6.0.9
php tools/release/verify-update.php dist/release-6.0.9/update
php tools/release/verify-update.php dist/release-6.0.9/install --fresh-install
```

The update and install subdirectories each contain their ZIP, manifest and
signature. Preparation uses private temporary directories and publishes files
only after archive completion, hashing and signing. A `.build.lock` coordinates
replacement of previous outputs; it is not a release asset. Upload only the six
named ZIP/JSON/SIG assets for each product; a standard core/Social release has twelve. The output directory must be writable; interrupted
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

### Product update channels

Every installation selects its update assets using the `product` and `artifact`
from its installed signed distribution. A repository may publish several products
in one GitHub release, but each needs its own update ZIP, manifest and signature.
The updater never substitutes another product when its assets are missing.
An app may configure `updates.repository` and `updates.public_key` in its local
configuration for its own GitHub release channel and signing authority.

The standard TinyCat release must include both the core-only and production Social
variants. Build each selection with the existing builder, from the same clean
release commit and version, and keep their outputs separate:

```sh
php tools/release/build-update.php --version=X.Y.Z --assembly=core --both --output=.work/releases/release-X.Y.Z/core
php tools/release/build-update.php --version=X.Y.Z --assembly=platform --both --output=.work/releases/release-X.Y.Z/social
php tools/release/verify-update.php .work/releases/release-X.Y.Z/core/update --product=tinycat.core
php tools/release/verify-update.php .work/releases/release-X.Y.Z/core/install --product=tinycat.core --fresh-install
php tools/release/verify-update.php .work/releases/release-X.Y.Z/social/update
php tools/release/verify-update.php .work/releases/release-X.Y.Z/social/install --fresh-install
php tools/release/artifact-preflight.php --artifact=.work/releases/release-X.Y.Z/core/update --product=tinycat.core
php tools/release/artifact-preflight.php --artifact=.work/releases/release-X.Y.Z/core/install --product=tinycat.core --fresh-install
php tools/release/artifact-preflight.php --artifact=.work/releases/release-X.Y.Z/social/update
php tools/release/artifact-preflight.php --artifact=.work/releases/release-X.Y.Z/social/install --fresh-install
```

Publish all twelve ZIP/JSON/SIG files in the same matching release. Core files use
`tinycat.core` and Social files use `tinycat`; names do not collide. Never upload
`.build.lock`, extracted installations, keys or evidence reports. Existing signed
releases remain immutable; add the core channel in the next release rather than
replacing or extending a published release retroactively.

Custom applications declare their artifact stem in their distribution profile.
Verification uses `--product=vendor.application` and discovers that product's local
manifest, verifies its signature and archive, and checks the signed artifact name.
No product-name map in the verifier or updater needs editing. Missing or ambiguous
manifests fail verification. An artifact cannot discard an installed owner. Blog has been removed from current
sources; historical Blog installations require an explicit separate retirement
procedure and are rejected by the new distribution without changing their data.


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
checksum in the global stream of `migrations`. A previously applied migration whose checksum
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

MySQL snapshots include `database.index.jsonl`. Restoration validates the entire
index before dropping any current objects: entries must cover the SQL file
contiguously, end at statement boundaries, and include the opening and completion
markers. Empty, truncated and structurally incomplete snapshots are rejected
before changing data or foreign-key enforcement. This is structural validation;
SQL semantics are still checked by MySQL during restoration, and deployment's
snapshot hashes remain the integrity check. DDL restoration is not atomic.

Export reads table data without PDO buffering and emits INSERT batches of at
most 250 rows, targeting 256 KiB per statement and respecting the source server's
packet limit. A single large row can exceed the target size. Restore groups
INSERTs in bounded transactions and commits before DDL; historical single-row
INSERTs and two-field statement indexes remain supported. The caller's buffering
option and foreign-key enforcement are restored by their respective operations.

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
