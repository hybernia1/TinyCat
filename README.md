# TinyCat

TinyCat is a small, self-hosted social publishing application written in plain PHP 8.4. It is designed for pseudonymous communities: a username is enough to create an account, while email and recovery details remain optional.

The application runs without Composer packages, a JavaScript package manager, or a frontend build step. PHP, MySQL-compatible storage, and the files in this repository are the complete runtime.

Current release: **1.0.8**. TinyCat uses [Semantic Versioning](https://semver.org/); the runtime version is defined by `Core::VERSION`.

## Features

- Public and following-only feeds with incremental loading.
- Posts with hashtags, mentions, likes, threaded comments, comment likes, and notifications.
- Profiles with avatars, bios, validated profile links, language and appearance preferences, activity statistics, and following lists.
- Search across posts, users, tags, and metadata extracted from linked pages.
- HTML5 link previews with Open Graph, video embeds, and cached metadata.
- Optional email recovery and localized email notifications through PHP mail or SMTP.
- Administration for users, site settings, email templates, maintenance, moderation reports, account muting, and blocked domains.
- Passwordless bot accounts that publish from independently scheduled RSS or Atom sources without duplicating imported items.
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

## Installation

1. Clone or upload the repository.
2. Point the Apache document root to the repository root.
3. Ensure Apache permits the bundled `.htaccess` rules (`AllowOverride All`).
4. Grant the web-server user write access described in the requirements.
5. Open `/install` and select a language.
6. Enter the database connection, create the schema, and create the first administrator account.

The installer creates the complete TinyCat 1.0 schema and writes `config.php`, which contains the database credentials and is ignored by Git. Once installation is complete, the project root only needs to remain writable when web updates are enabled.

Use HTTPS in production. The supplied Apache rules prevent direct web access to `config.php`, `App/`, `lang/`, `migrations/`, and private `storage/` content; equivalent protection is required if the application is adapted to another web server.

TinyCat 1.0 is a clean installation baseline. It contains no pre-1.0 database migrations or compatibility layer for older schemas; an existing installation must already match the 1.0 schema before this code is deployed.

## Updates

Administrators can check and install signed stable releases under **Admin → Updates**. The updater downloads three GitHub release assets: a curated application ZIP, its manifest, and an Ed25519 signature. It verifies the signature, package hash, every managed file, version compatibility, and safe archive paths before changing the installation.

Before applying files, TinyCat enables maintenance mode and creates application and database backups below `storage/updates/backups/`. Updates never overwrite `config.php`, `storage/`, or `uploads/`. The web-server user needs write access to managed application files while an update is installed; deployments that keep source code read-only can continue to deploy release assets manually.

Database changes are versioned PHP migrations recorded in `schema_migrations`. Fresh installations always receive the current schema directly; migrations exist only to move already running installations forward. See [`docs/updates.md`](docs/updates.md) for package creation, signing, publishing, recovery, and migration rules.

## RSS and Atom bots

Create bot accounts under **Admin → Bots → Accounts**, then add one or more sources under **Admin → Bots → Sources**. Each source has its own interval and post template. Bot accounts have no password and cannot sign in.

The recommended scheduler is the command-line runner:

```bash
php cron.php --health
php cron.php --limit=20
```

Run `php cron.php` once per minute. Source intervals decide which feeds are due, and a database lock prevents overlapping runs. Each run publishes at most one new item per due source and retains a bounded GUID history to prevent duplicates.

When command-line scheduling is unavailable, use the protected HTTP endpoint shown in **Admin → Bots → Cron**:

```bash
curl -X POST -H "Authorization: Bearer TOKEN" https://example.test/cron.php
```

An authenticated `POST /cron.php?health=1` checks connectivity without importing anything. Services that cannot send custom headers may use `cron.php?bearer=TOKEN`, but query-string tokens can appear in server access logs and should be treated as a last resort.

## Privacy and security defaults

- Registration does not require an email address, phone number, or third-party identity provider.
- Sessions, CSRF tokens, password hashing, login captcha, and action rate limits are built in.
- Remote link and feed requests reject private and reserved network addresses.
- Google Analytics is optional, disabled until configured, and integrated with the consent UI.
- SMTP, Google Analytics, and external web-cron services are optional integrations.

See `/privacy` on an installed site for the user-facing data and cookie policy generated by the application.

## Project layout

- `index.php` is the HTTP front controller and route registry.
- `cron.php` runs scheduled bot imports from CLI or an authenticated HTTP request.
- `App/` contains the runtime, database layer, routing, authentication, caching, metadata extraction, and administration modules.
- `Public/` contains pages, layouts, modals, reusable view parts, and the installer.
- `assets/` contains the source CSS, JavaScript, and SVG icon sprite.
- `lang/<locale>/` contains each language package: `app.json` for the interface and optional `emails.json` for email templates.
- `storage/` contains private runtime state and generated asset caches.
- `uploads/` contains user and site media.
- `tools/build-update.php` creates signed production release assets.

## License

Copyright (C) 2026 TinyCat contributors.

TinyCat is licensed solely under the [GNU Affero General Public License v3.0 or later](LICENSE) (`AGPL-3.0-or-later`), with no permissive or proprietary alternative license. This strong copyleft license requires modified versions offered as a network service to provide their Corresponding Source to the service's users under the same license.
