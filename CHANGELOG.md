# Changelog

All notable TinyCat changes are documented here. TinyCat follows Semantic
Versioning.

## 2.0.29 - 2026-08-09

### Fixed

- Made the email-template migration self-contained so updates run correctly
  while the pre-update runtime is still loaded.

## 2.0.28 - 2026-08-09

### Simplification

- Removed the redundant `content.created_at` column. Post publication is now
  represented solely by `published_at` across the schema, application and UI.
- Removed the redundant `links.embed_url` column. Video iframe URLs are now
  derived safely from the provider and canonical `video_id`.
- Moved email delivery switches into the autoloaded `email.templates` setting
  and removed the redundant `email_templates` table and its timestamps.
- Consolidated SMTP configuration into the sensitive `email.smtp` JSON setting
  and removed the unused `email.welcome_message` setting.

## 2.0.27 - 2026-08-09

### Simplification

- Removed public profile links completely: their UI, validation, API payloads,
  structured-data output, demo-data generation, styles, translations and
  `user_profile_links` schema are no longer part of TinyCat.
- Existing installations remove the obsolete table through a forward migration;
  fresh installations do not create it.

## 2.0.26 - 2026-08-09

### Performance

- Removed repeated image, reaction, permission and comment lookups from the
  main feed, status, tag, author and search paths by reusing selected values
  and bounded batch queries.
- Made public partials render supplied data without issuing database queries.
- Kept the compact 2.0.25 monolithic runtime; the experimental 2.5 class graph
  is not part of this release.

### Correctness

- Made multi-table status, comment, tag, link, profile, recovery-token and
  moderation writes atomic where partial updates could previously survive a
  failure.
- Removed the arbitrary limit of ten unique tags while retaining per-tag and
  total-content size limits.
- Preserved moderation removal notifications and narrowed duplicate handling
  so unrelated database failures are no longer swallowed.

### Security and operations

- Hardened link preview address validation, including credentials, redirects,
  private addresses and IPv4-mapped IPv6 addresses.
- Added stricter uploaded-image byte, MIME, dimension and memory validation.
- Rejected case-insensitive and file/directory collisions in signed update and
  extension packages.
- Added deterministic demo-data import, scale tests, signed update/rollback
  rehearsals and a full local release preflight.

### Compatibility

- Supports an in-place signed update from 2.0.25 on PHP 8.4 or newer.
- Does not introduce a schema change and retains the 2.0.25 extension API.
