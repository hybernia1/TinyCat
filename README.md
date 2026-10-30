# TinyCat

TinyCat is a small, self-hosted social publishing application written in plain PHP 8.4. It is designed for pseudonymous communities: a username is enough to create an account, while email and recovery details remain optional.

The application runs without Composer packages, a JavaScript package manager, or a frontend build step. PHP, MySQL-compatible storage, and the files in this repository are the complete runtime.

Current release: **5.8.0**. TinyCat uses [Semantic Versioning](https://semver.org/); the runtime version is defined by `TinyCat\Support\Version::CURRENT`.

Usernames support 2–32 letters (including accents), numbers, dots, hyphens and underscores, preserving the entered case. They start with a letter or number and cannot end with a dot or hyphen. Numeric-only names are reserved for internal mention IDs. Login and username uniqueness remain case insensitive.

## Features

- Public and following-only feeds with incremental loading.
- Posts with hashtags, mentions, likes, threaded comments, comment likes, and notifications.
- Profiles with avatars, bios, language and appearance preferences, activity statistics, and following lists.
- Interface, administration, installer and email translations in Czech, English, German, Spanish, French, Portuguese, Russian, Italian, Dutch, Polish, Turkish and Ukrainian; browser language detection initializes new account preferences.
- Explicit public language URLs such as `/pl/`, `/pl/status/123` and `/pl/privacy`, with a language selector, localized policy discovery and canonical original posts.
- Search across posts, users, tags, and metadata extracted from linked pages.
- HTML5 link previews with Open Graph, video embeds, and cached metadata.
- Optional email recovery and localized email notifications through PHP mail or SMTP.
- Administration for users, site and email-delivery settings, scheduled tasks, moderation reports, account muting, and blocked domains.
- Built-in passwordless bot accounts with scheduled RSS and Atom imports.
- Author and tag feeds, XML sitemaps, `robots.txt`, `llms.txt`, and a generated web app manifest.
- English and Czech interfaces with mobile-first CSS and lightweight JavaScript.

## Requirements

- PHP 8.4 or newer.
- MySQL or MariaDB with the `pdo_mysql` PHP extension.
- Apache 2.4 with `mod_rewrite` and `.htaccess` overrides enabled.
- The `mbstring`, `gd`, `dom`, and `simplexml` PHP extensions for the full feature set.
- The `curl` and `sodium` extensions, plus either `zip` or `phar`, for signed web updates.
- Outbound HTTPS streams (`allow_url_fopen`) for link previews and remote images; cURL is recommended for feed downloads.
- Write access to `storage/` and `uploads/`. The installer also needs temporary permission to create `config.php` in the project root.

The PHP `exif` extension improves JPEG orientation handling but is optional. Apache modules such as `mod_headers` and `mod_deflate` enable the cache headers and compression rules already included in `.htaccess`.

### Recommended PHP OPcache

Enable OPcache in the PHP configuration used by Apache or PHP-FPM. It caches
compiled PHP bytecode, so every request avoids parsing TinyCat's PHP files
again. A safe baseline for TinyCat is:

```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.max_accelerated_files=10000
opcache.validate_timestamps=1
opcache.revalidate_freq=2
```

TinyCat resets OPcache after a successful web update. Keep timestamp validation
enabled unless the deployment process always reloads PHP after every release.
The administration dashboard reports whether OPcache is active in the web PHP
runtime.

### Optional Memcached cache

TinyCat uses the filesystem cache by default. Hosts with the PHP `memcached`
extension can use Memcached for computed JSON cache entries while generated
assets remain on disk. Add this to the private `config.php` file:

```php
'cache' => [
    'driver' => 'memcached',
    'memcached' => [
        'servers' => [
            ['host' => '127.0.0.1', 'port' => 11211],
        ],
        'prefix' => 'tinycat:example:',
        'timeout_ms' => 100,
    ],
],
```

Memcached keys include the application version, so releases do not reuse older
computed entries. Old entries expire normally without flushing the shared server.
Use a unique prefix for each installation. If the extension or server is not
available, TinyCat safely falls back to its filesystem cache.
Memcached must be bound to loopback or a private network and must never be
exposed directly to the public internet.

## Embed posts

Choose **Embed post** on a public post to open the standard modal with a live
preview and a copyable iframe snippet. TinyCat also provides oEmbed JSON/XML discovery so compatible editors can
embed the original post URL. The card includes the author avatar, text, attached images, link previews and
click-to-load audio/video players, with no visitor session or account controls. See [the embedding API](docs/api.md#embedding-public-posts-oembed-10)
for endpoint details and WordPress configuration.

## Installation

1. Clone or upload the repository.
2. Point the Apache document root to the repository root.
3. Ensure Apache permits the bundled `.htaccess` rules (`AllowOverride All`).
4. Grant the web-server user write access described in the requirements.
5. Open `/install` and select a language.
6. Enter the database connection, create the schema, and create the first administrator account.

The installer creates the complete TinyCat 2.x schema and writes `config.php`, which contains the database credentials and is ignored by Git. Once installation is complete, the project root only needs to remain writable when web updates are enabled.

Use HTTPS in production. The supplied Apache rules prevent direct web access to `config.php`, `App/`, `Extensions/`, `lang/`, `migrations/`, `tests/`, `tools/`, and private `storage/` content; equivalent protection is required if the application is adapted to another web server.

When Apache terminates TLS, the bundled `.htaccess` also sends `Strict-Transport-Security: max-age=31536000; includeSubDomains`. Enable `includeSubDomains` only when every current and future subdomain is available over HTTPS. If TLS terminates at a reverse proxy or CDN, configure HSTS there instead (or ensure Apache receives the original HTTPS state).

TinyCat 2.0 is a clean application baseline. Upgrade existing installations to 1.0.14 first; the 2.x runtime no longer contains schema or extension-adoption compatibility for older releases.

## Updates

Administrators can check and install signed stable releases under **Admin → Updates**. The updater downloads three GitHub release assets: a curated application ZIP, its manifest, and an Ed25519 signature. It verifies the signature, package hash, every managed file, version compatibility, and safe archive paths before changing the installation.

Before applying files, TinyCat enables maintenance mode and creates a rollback snapshot below `storage/updates/backups/`. It contains only application files that will change or be removed; a database backup is created only when the release has migrations that still need to be applied. Updates never overwrite `config.php`, `storage/`, or `uploads/`. The web-server user needs write access to managed application files while an update is installed; deployments that keep source code read-only can continue to deploy release assets manually.

Database changes are versioned PHP migrations recorded in `schema_migrations`. Fresh installations always receive the current schema directly; migrations exist only to move already running installations forward. See [`docs/updates.md`](docs/updates.md) for package creation, signing, publishing, recovery, and migration rules.

## Bots

Bots are included in TinyCat. Create passwordless accounts under **Admin → Bots → Accounts** and RSS or Atom sources under **Admin → Bots → Sources**. Sources run on independent schedules and retain import history to prevent duplicate posts.

TinyCat 2.1 removes custom pages and the extension system, including the store and extension APIs. Existing Bots accounts, sources, posts and import history are adopted in place by the core update migration. Custom pages have no conversion migration. Legacy files under `Extensions/` are never loaded and remain blocked from web access; no extension installation or activation is required.

The 3.0.0 update removes the remaining extension settings and extension migration metadata without changing bot data. API routes now share one explicit route table, access and CSRF checks, and JSON response handling. See [`docs/api.md`](docs/api.md) for the API contract.

## Scheduled tasks

The scheduler provides the built-in `feeds`, `cleanup` and `site-health` tasks.

The recommended scheduler is the command-line runner:

```bash
php scheduled-tasks.php --health
php scheduled-tasks.php --task=cleanup --cleanup-batch=500
php scheduled-tasks.php --task=feeds --bot-limit=20
```

Keep independent tasks in separate scheduler entries. For example, poll feeds every two minutes and run cleanup hourly:

```cron
*/2 * * * * php /path/to/tinycat/scheduled-tasks.php --task=feeds --bot-limit=20
17 * * * * php /path/to/tinycat/scheduled-tasks.php --task=cleanup --cleanup-batch=500
```

Routine database cleanup runs at most once per hour and every task has its own database lock. Bot source check intervals decide which feeds are due. Each run imports all new usable items returned by the bounded feed parser (up to 100 entries), retaining GUID history to prevent duplicates. The separate publication interval defaults to 30 minutes, including existing sources after migration. Posts append to the RSS source’s own publication timeline; sources sharing a bot account do not delay each other. The first post appears immediately if that source’s timeline is empty or its previous interval has elapsed. Future posts stay hidden until their publication time. Changing the interval affects subsequent imports only. Legacy posts without a recorded source keep their existing times and do not delay the new per-feed queues. `--task=all` remains available for simple installations that prefer one scheduler entry.

Cleanup also removes avatars belonging to deleted users after 24 hours, known abandoned upload/cache temporary files after 48 hours, and obsolete update work files under the updater lock. Avatar recovery backups and unrelated files are preserved. Rate-limit buckets are retained for at least 24 hours and twice the longest policy window. Image and cache scans checkpoint their position and bound inspected entries as well as deletions; `has_more` can therefore mean that inspection is still in progress, even when nothing was removed. Directory enumeration can revisit entries after concurrent filesystem changes; subsequent complete passes reconcile them.

Update backup retention keeps at least the three newest confirmed complete backups and all backups less than 30 days old. Maintenance mode and an active updater prevent update-file cleanup. Incomplete, failed and legacy backups without a completion marker are preserved. See [update retention](docs/updates.md) for configuration.

The administrator page **Site health** (`/admin/site-health`) shows the latest diagnostic, freshness, recommendations and the last 20 inspections. Schedule `site-health` once a day, for example `php scheduled-tasks.php --task=site-health` or the HTTPS Basic URL `/api/scheduler/triggers/site-health`. The existing POST/Bearer scheduler API accepts `task=site-health` too. Repeated scheduled calls on the same application-local calendar day are skipped. Manual checks use `POST /api/site-health/checks` with administrator authentication and CSRF, and can run again immediately. Every completed check sends an in-app notification to each active administrator; no email is sent. The new task has no options or database migration.

Checks cover database connectivity, a cache round trip and configured cache availability, storage/upload write-and-read probes, free disk space, required PHP extensions, recent cleanup/feed executions and update maintenance state. A completed inspection can report OK, warning or critical independently of cron execution success. Results older than 36 hours and failed latest inspections are shown as unknown. This is internal diagnostics: a total application/database outage cannot reliably record or send its own notification and requires external uptime monitoring.

An anonymous HTTP GET also measures the configured `app.url` homepage: TTFB, elapsed request time, DNS/TCP/TLS setup, HTTP status, received bytes and transfer completeness. Responses and headers are discarded. No session, cookies or authorization are forwarded; redirects and proxy use are disabled and TLS certificates remain verified. This server-side sample is not a visitor percentile or a browser page-load measurement. Self-requests need another available web worker; prefer running the health task from CLI on a single-worker installation.

Set the canonical website address in **Admin ? Settings ? Site ? Website URL**. This stored `app.url` overrides the server configuration for both absolute links and health probes. An empty override falls back to `config.php`. Only HTTP(S) URLs without credentials, query strings or fragments are accepted.

Configure `health.http` in `config.php`: `enabled` (default `true`), `ttfb_warning_ms` (`800`), `ttfb_critical_ms` (`1800`), `total_warning_ms` (`2000`), `total_critical_ms` (`4000`) and `timeout_ms` (`5000`, clamped to 1000–30000). A threshold is exceeded strictly above its value. Threshold ordering is normalized. HTTP 4xx/5xx, transport failures, timeouts and incomplete oversized responses are critical; unfollowed redirects are warnings. Missing configuration is explicitly unmeasured. Timings and the limits used are stored together in run history, so changing limits never rewrites older results.

When command-line scheduling is unavailable, use the protected HTTP endpoint shown in **Admin → Scheduled tasks**:

```bash
curl -X POST -H "Authorization: Bearer TOKEN" "https://example.test/api/scheduler/executions?task=feeds"
curl -X POST -H "Authorization: Bearer TOKEN" "https://example.test/api/scheduler/executions?task=cleanup"
```

For URL-only hosting schedulers such as Webglobe, use `https://example.test/api/scheduler/triggers/feeds` or `/api/scheduler/triggers/cleanup` with HTTP Basic authentication: username `scheduler`, password equal to the cron token. HTTPS is required. GET runs the selected task with default options; HEAD only verifies availability. The token never belongs in the URL.

An authenticated `GET /api/scheduler/health` checks connectivity without running a task. HTTP calls require the `Authorization: Bearer TOKEN` header. Query-string tokens are not accepted. The old HTTP `/scheduled-tasks.php` endpoint returns 410; the file remains the CLI entry point. CLI output uses the same JSON envelope as the API.

## Privacy and security defaults

- Registration does not require an email address, phone number, or third-party identity provider.
- Sessions, CSRF tokens, password hashing, login captcha, and action rate limits are built in.
- Remote link and feed requests reject private and reserved network addresses.
- Google Analytics is optional, disabled until configured, and integrated with the consent UI.
- SMTP, Google Analytics, and external web-cron services are optional integrations.

See `/privacy` on an installed site for the user-facing data and cookie policy generated by the application.

## Security testing

TinyCat includes dependency-free security regression checks and a strictly
bounded, read-only resilience profile. See
[`tests/security/README.md`](tests/security/README.md) for the threat coverage,
safety limits, and commands.

## Development quality gates

Production does not use Composer or load `vendor/`. Composer installs only the
development analyzer used by the local and CI preflight:

Development checks also require Node.js 24 for the JSON AJAX client tests.
No Node.js runtime or npm dependency is required on production servers.

```bash
composer install
composer preflight
```

The preflight validates Composer metadata and advisories, deterministic source
style, PHP 8.4 syntax, PHPStan level 8, repository secrets and managed update
paths. It then runs the monolith boundary, HTML, view, route, archived query,
cache, minifier, bots, updater, cron, importer, signed artifact, rollback
and restart-safe MySQL installer suites. Module boundaries and the endpoint/function
migration map are documented in `docs/development/modular-api-inventory.md`.

PHPStan covers all production PHP against the frozen 2.0.25 legacy baseline.
New findings fail the build; the baseline must not grow to make a change pass.
Tests and development tools are excluded from signed production packages.

## Project layout

- `index.php` is the HTTP front controller; built-in modules register their own routes.
- `scheduled-tasks.php` is the CLI adapter for the same execution service used by the authenticated scheduler API.
- `App/bootstrap.php` initializes the runtime and the small `TinyCat\` PSR-4 autoloader.
- `App/Bots/` owns feed imports and bot administration.
- `App/Updates/` contains signed application updates and the migration registry.
- Other `App/` directories contain domain modules and shared infrastructure.
- `App/<Module>/Pages.php` owns page handlers; private page templates and partials live in `App/<Module>/templates/`.
- `assets/` contains source CSS, JavaScript, the SVG icon sprite, shared UI definitions and module-owned client manifests.
- `App/<Module>/lang/<locale>.json` contains module-owned translations; `App/Mail/lang/` owns server-only email templates. `lang/<locale>.json` declares language names.
- Module manifests explicitly declare JavaScript translation dependencies; component definitions declare theirs through static `t` references. Release builds emit hashed page catalogs and OPcache-compatible PHP catalogs in `App/Support/compiled/`.
- `storage/` contains private runtime state and generated asset caches.
- `uploads/` contains user and site media.
- `tools/` contains versioned development and release utilities; it is excluded from production packages and blocked from web access.

## License

Copyright (C) 2026 TinyCat contributors.

TinyCat is licensed solely under the [GNU Affero General Public License v3.0 or later](LICENSE) (`AGPL-3.0-or-later`), with no permissive or proprietary alternative license. This strong copyleft license requires modified versions offered as a network service to provide their Corresponding Source to the service's users under the same license.

External content links use content-bound API navigation. Configure UTM source, medium and optional campaign under **Admin ? Settings ? Analytics**. Source defaults to the configured website hostname and medium to `referral`; existing UTM values are preserved. This adds attribution without recording clicks or user identifiers.
