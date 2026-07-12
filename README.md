# TinyCat

TinyCat is a small open-source PHP social media app built for anonymous, self-hosted communities.

It is designed to run without mandatory email, phone numbers, external accounts, trackers, or cloud services. A username is the primary identity, and users can later add optional recovery contact details if the community wants that workflow.

## What It Does

- Public feed with all posts and a following-only feed.
- Username-only accounts with optional profile bio, website, avatar, and UI language.
- Posts with tags, likes, shares, comments, nested replies, and notifications.
- Profile pages with member stats, followed profiles, and user feed.
- Link previews with Open Graph metadata and searchable linked content.
- Admin area for users, settings, moderation, reports, and domain rules.
- Installer for language, database connection, schema creation, and first admin account.
- Mobile-first UI with lightweight CSS and JavaScript assets.

## Philosophy

TinyCat is intentionally small. It avoids heavy framework structure and keeps the core in plain PHP files that are easy to inspect, copy, deploy, and adapt for small or medium projects.

The project favors:

- Anonymous operation by default.
- Self-hosting and data ownership.
- Simple deployment on standard PHP hosting.
- Clear database tables instead of hidden services.
- Useful defaults without a large dependency stack.

## Requirements

- PHP 8.1 or newer.
- MySQL or MariaDB.
- Apache with rewrite support.
- Writable `storage/` and `uploads/` directories.

## Installation

1. Clone the repository.
2. Point the web server to the project root or route requests through `index.php`.
3. Make `storage/` and `uploads/` writable.
4. Open `/install`.
5. Choose a language, enter database credentials, and create the first admin account.

The installer creates `config.php` after the database and administrator account are configured. The generated configuration, runtime data, uploaded files, and local overrides are ignored by Git.

## Project Layout

- `App/` contains the core, bootstrap, helpers, routing, auth, database, upload, translation, and social helpers.
- `Public/` contains frontend pages, admin pages, install flow, layouts, and modals.
- `assets/` contains the TinyCat CSS, JavaScript, icons, and UI assets.
- `lang/` contains translation JSON files.
- `storage/` contains runtime storage and generated files.
- `uploads/` contains user uploaded files.

## License

TinyCat is released under the MIT License.
