# Changelog

## 4.8.0 - 2026-10-27

- Centralize bounded HTTP fetching for feeds, link metadata, images and update downloads, validating public destinations at every redirect.
- Parse RSS and Atom through a shared feed parser, including Novinky/Seznam images, Media RSS, enclosures, namespaced authors and relative URLs; use feed metadata before scraping articles.
- Consolidate provider rules for WordPress REST discovery, Facebook posts/reels, X photos, video players and SoundCloud audio. Keep previews local and load permitted players only after a click.
- Display one link card per post; keep remaining URLs as ordinary text links. Social cards have a linked network/account header and a separate, non-clickable caption inside the shared border.
- Normalize provider URL variants and tracking parameters before metadata lookup and storage; calculate link hashes from canonical URLs and merge existing duplicates transactionally without losing post relations.
- Improve Google Maps cards with place names and exact place coordinates; accept public map previews only when a numeric marker matches the place, retaining text cards when Google requires consent or supplies no suitable image.
- Cover bot imports, normalized links, local feed previews, API rendering and duplicate-link migrations with regression checks.

## 4.7.0 - 2026-10-27 — Optimization release #1

- Centralize post creation for users and bot imports, sharing validation, normalization and transactional storage; report rejected feed items without blocking valid items.
- Remove unused post scheduling: new posts publish immediately, and post resources no longer expose scheduled metadata.
- Normalize scheduled-task options, results and history across automatic and manual execution, preserving partial cleanup counts and import diagnostics.
- Share image decoding, resizing and WebP writing; stage avatar changes and restore files when related database writes fail.
- Simplify database access with shared driver detection, literal LIKE escaping, filtered query counts and atomic settings upserts.
- Translate confirmation dialogs, unsaved-change prompts, avatar actions, image errors and search fallbacks consistently across all supported languages.

## 4.6.0 - 2026-10-27

- Separate search into Posts, Profiles and Tags, with full-history post/link matching, predictable ranking and the shared cursor feed loader.
- Preserve the existing navbar and sidebar while searching, switching categories and navigating browser history; handle quotes and angle brackets as plain search text.
- Count tag posts using the shared feed visibility rules and give autocomplete its own request budget.
- Restore sidebar interaction after background refreshes triggered by likes, comments, post changes and follows.
- Synchronize notification previews, inbox rows and counters after reads/deletes, discard stale polling replies, and keep mark-all controls reusable.
- Include relative-time translations on scheduler pages and validate date translation dependencies in every client scope.
- Add a distinct fresh-install package without migration files or internal documentation; create the current scheduler schema directly in the installer.

## 4.5.0 - 2026-10-27

- Add shared SVG sorting controls to administration lists for users, bot accounts, feed sources, scheduler run history and reported content; sort on the server before pagination with allowlisted columns and deterministic ties.
- Preserve sorting through AJAX filters, pagination and item actions, and restore source and report lists through browser history. Retain the open-first default for reports.
- Defer public scroll-position history writes until scrolling settles while saving immediately before navigation and when leaving the page.
- Keep desktop sidebar requests, refresh timers, resize observation and sticky scroll handlers inactive below 900 pixels; restore them when returning to desktop and discard late mobile renders.
- Remove the scheduler overview help paragraph and cover sorting, stale AJAX replies, history restoration and responsive sidebar behavior with regression checks.

## 4.4.0 - 2026-10-27

- Move anonymous language selection into a navbar flag menu; signed-in users retain profile preferences. Offer a dismissible switch in the browser's preferred supported language when it differs from the current interface.
- Add explicit locale-prefixed public URLs, language-preserving HTML/AJAX navigation and a crawlable language selector; URL language takes precedence over account/browser preferences.
- Publish reciprocal policy-page hreflang alternatives and sitemap entries, retain canonical original user content, and align structured data and HTTP language metadata.
- Add initial German, Spanish, French, Portuguese, Russian, Italian, Dutch, Polish, Turkish and Ukrainian interface, administration, installation and email catalogs.
- Apply locale-specific plural rules consistently in PHP and JavaScript, preserving exact-one relative-time phrases separately from numeric plural forms.
- Verify every shipped locale in browser detection, compiled catalogs, CLDR plural selection and server/client rendering parity.
- Preserve the detected language in the F1 policy link and close the navbar menu after selecting a language.

## 4.3.0 - 2026-10-26

- Add optional, on-device post translation in supported browsers on HTTPS, targeting the current interface language without uploading or persistently storing translations.
- Detect source languages and omit translation offers for matching languages, unsupported pairs, uncertain detections and posts containing only numbers, punctuation, links or social references.
- Show translation controls as badges with a translation icon; preserve the original and display collapsible translated text below it with a quote-style border and smaller type.

## 4.2.0 - 2026-10-26

- Retain the public page header and sidebar during JSON navigation between feeds, tags, profiles and posts while updating metadata and preserving browser history, scroll and focus.
- Share public page data and declarative rendering, cache sidebar data and retain normal document loading as a fallback.
- Remove four retired public page templates during overlay updates.

## 4.1.1 - 2026-10-26

- Normalize public RSS, sitemap, robots and LLM documents under module-owned responders with shared HEAD, ETag and conditional GET handling.
- Use lightweight, indexed public export queries; cache sitemap page counts briefly and exclude orphan or non-public tags without caching content visibility.
- Normalize manifest delivery, policy discovery links and WebPage metadata; fix standalone post alignment and compact layout.
- Share HTML, JSON and asset error handling, provide safe database-independent fallback pages and fixed SVG placeholders, and prevent long-lived caching of errors.
- Check email availability while typing in registration and profile forms with debouncing, cancellation, CSRF protection, IP limits and authoritative save-time validation.
- Remove retired Public document handlers during overlay updates.

## 4.1.0 - 2026-10-26

- Add standard administration pages for scheduled tasks, searchable run history, detailed run modals and manual execution through the shared authenticated API.
- Unify manual and scheduled bot imports under one execution lock; link per-source diagnostics to scheduler runs and move bot retention into the common cleanup task.
- Clean expired filesystem cache entries and orphaned link previews in bounded batches while preserving stale-refresh windows, active imports and referenced images.
- Preserve repeated URL query parameters and balanced punctuation; reduce duplicate DNS checks and cache failed metadata lookups without extending stale successful metadata.
- Persist bot posts, tags, links and duplicate claims atomically and report failed imports accurately.
- Fix avatar/video alternative text, follow-button icons, comment editing and report forms, revision diffs and clipped comment menus.
- Normalize administration pagination, right-aligned action columns, icon actions and bot account editing; validate scoped modal-title translations.
- Include repeatable scheduler-history and bot-run linkage migrations for updates and fresh installations.

## 4.0.1 - 2026-10-26

- Avoid repeated ICU transliteration for canonical ASCII slugs; preserve Unicode and custom-separator normalization.
- Coalesce concurrent sidebar ranking refreshes, serve stale values during refresh, and prevent cache-directory races from corrupting JSON responses.
- Extend performance tooling with isolated PHP call profiles, multiple workers, expired-cache bursts and response hashes.

- Add isolated large-data preparation and fresh-request HTTP profiling with executed SQL counts, timings and query traces.
- Index recent dashboard comments and active-user search after validating the access plans against an 11.3-million-row dataset; include installer and repeatable update migrations.

## 4.0.0 - 2026-10-26

- Breaking: normalize API outcomes around top-level `code`, `params` and a readable fallback `message`; replace `error.code` and string-only validation errors with structured field issues. Update external clients using `docs/api.md`.
- Return domain values, counts, timestamps and content from APIs; move translated labels, option text, notification icons and accessible names into shared module-owned presentation components.
- Move translations beside their modules, declare client message dependencies explicitly and compile OPcache-compatible PHP catalogs together with scoped, content-hashed client assets. Keep email templates server-only.
- Share single-pass `{name}` interpolation, per-key English/base/regional fallback and Czech/English plural rules across PHP, JavaScript and email templates.
- Fix profile and login form layout, login/register modal switching and damaged Czech validation and bot-run messages.
- Validate catalog ownership, parameters, plural forms, client declarations and encoding; extend PHP/JavaScript parity, locale/cache, API and release checks.

## 3.2.1 - 2026-10-26

- Fix a fatal error on the home page for signed-out visitors by checking authentication before reading the account mute expiry.
- Cover anonymous home and following feeds in public route smoke tests.

## 3.2.0 - 2026-10-26

- Unify administration action icons and accessible tooltips; add pause/shield-check symbols and remove duplicate SVG symbol IDs.

- Move public page handlers and surviving partials into their modules, remove obsolete wrappers, and enforce render-only template ownership.
- Share bot detail source rendering with AJAX updates, refresh summary counters and localize account/run status labels.
- Update demo import for the current schema and settings; add optional offline UI fixtures, fresh-schema resume tests and immutable asset preservation.

- Split document/head/public/admin layouts, prepare shell data and navigation outside templates, and move the remaining administration pages and dashboard data into module-owned classes and private templates. Remove obsolete settings asset references.

- Move Users and Bots list page composition into module-owned Pages classes and share their list-card headers, modal actions and account status badges.

## 3.1.0 - 2026-10-26

- Enforce frontend source reachability and per-scope raw/gzip delivery budgets in preflight.

- Organize frontend sources into core, UI and domain-owned modules with explicit local import dependencies; split status controllers and keep styles beside their owners.
- Deliver four dependency-ordered JavaScript assets and one scoped stylesheet per page, preserve CSS cascade order, and remove the retired JS/CSS monoliths during updates.

- Remove unused frontend helpers and unify DOM, icon, avatar and form request primitives, including status and follow forms.
- Give dialogs their own focus/stack/viewport manager and resource resolver; extract dirty-form protection, registered history restoration and an idempotent init/hydrate/dispose lifecycle from the shared shell.

- Compile source/minified assets, scoped UI definitions and translation catalogs into a signed release inventory; use content hashes for asset URLs and preserve older immutable variants during updates.
- Skip minification on warm source-cache hits and prepare remote link metadata before database transactions, releasing the session lock during network waits.
- Remove the retired HTML status formatting pipeline and duplicate `_view` preparation; share canonical resources and batch preloads between server and AJAX rendering.
- Separate Users and Status HTTP actions from domain services and compose database requirements through module declarations.
- Move status, profile/follow, search, sidebar and notification behavior into scoped client modules; share form request lifecycle and register result/hydration/navigation handlers through the client module registry.
- Add token-based dependency checks and exercise source and compiled client initialization across public, administration and installer scopes.

## 3.0.0 - 2026-10-26

- Breaking: replace legacy API endpoints and HTML fragments with module-owned JSON APIs under `/api/users`, `/api/statuses`, `/api/notifications` and other domain prefixes. Integrations must use the new contract in `docs/api.md`.
- Share declarative components between server rendering and AJAX rendering, including administration, status dialogs, profile editing, avatars and sidebar content.
- Distribute scoped component definitions and translations as external content-hashed assets instead of inline JSON catalogs; unify PHP and JavaScript translation fallback and literal parameter substitution.

- Complete the modular refactor: built-in modules own their API and page routes, while shared HTTP, database, settings, localization and view services replace the original central files.
- Add a JSON-only AJAX transport with reusable DOM, form and dialog primitives; replace fixed monolith file-count checks with infrastructure dependency boundaries.
- Move Users, Notifications and Status into built-in modules with their own API and web registrations; extract session authentication, recovery, avatars, roles, image storage and link metadata.
- Convert their AJAX forms, modals, lists, feed, comments and notification polling to JSON resources and safe DOM rendering; remove their retired endpoints and HTML negotiation.
- Move Bots, Moderation, Search, Settings, Site, Mail, Security, Scheduler and Updates into their own namespaces; remove all remaining HTML response negotiation and HTML-consuming AJAX branches.
- Convert bot lists, filters, source controls, moderation, sidebar and CAPTCHA to JSON resources and local DOM rendering; preserve modal forms across list updates and display canonical validation errors at fields.
- Add partial settings updates with explicit false values, redact sensitive setting resources, and use path IDs for administration mutations.
- Use canonical resource URLs, explicit private/public account fields, cursor pagination and idempotent follow/like state writes.
- Relax usernames to 2–32 Unicode letters, numbers, dots, hyphens and underscores; preserve display case while keeping case-insensitive login and uniqueness. Numeric-only names remain reserved for internal mention IDs.
- Standardize routing, access policies, CSRF, statuses and JSON serialization; remove `App/Core.php`, `App/functions.php`, central domain `App/Api.php` and temporary delegation classes.
- Separate administration API handlers from page rendering and remove the former standalone bot API controllers.
- Replace implicit filesystem routing with explicit public page routes; templates and controller implementation files are no longer routable.
- Standardize JSON errors for unknown endpoints, unsupported methods, authentication, validation and internal failures. Converted modules no longer return HTML fragments.
- Return 201 when creating bot sources and reject missing or malformed resource IDs instead of selecting ID 1.
- Remove obsolete extension settings and extension migration metadata while preserving core Bots data.
- Add real HTTP API contract coverage and extend the idempotent MySQL migration rehearsal.

## 2.1.0

- Include Bots in core: passwordless accounts, source administration, RSS/Atom import, translations and the `feeds` scheduled task are always available.
- Preserve existing Bots accounts, sources and import history during the update; initialize bot storage on installations that did not use Bots.
- Remove custom pages, the extension store, loader, registration APIs and extension administration. Custom pages have no data conversion migration; legacy extension files remain private and are never loaded.
- Detect interface language from browser preferences when no supported account language is stored; manual account selection keeps precedence.


All notable TinyCat changes are documented here. TinyCat follows Semantic
Versioning.

## 2.0.50 - 2026-08-14

### Fixed

- Reused a single notification and email for repeated likes or follows by the
  same account, including after an unlike or unfollow.
- Treated visually empty status editors as empty drafts, preventing an
  unnecessary unsaved-changes prompt after deleting the text.

### Simplification

- Removed unused creation timestamps from post images, post likes and comment
  likes, including through a migration for existing installations.

## 2.0.49 - 2026-08-12

### Fixed

- Kept future-published posts visible only to their author and administrators
  until publication, including feeds, search, sitemaps, RSS and interactions.
- Unified status-detail and edit modals with the standard status card and
  composer components.

## 2.0.48 - 2026-08-12

### Fixed

- Reverted route-specific CSS splitting and restored the proven single,
  minified TinyCat stylesheet on every route.

## 2.0.47 - 2026-08-12

### Fixed

- Restored the full public-feed width at tablet sizes after splitting route CSS.
- Kept author, timestamp and avatar actions at 48px touch-target dimensions
  without increasing the height of status-card headers.

## 2.0.46 - 2026-08-12

### Fixed

- Restored the fixed site-logo dimensions on public pages, preventing the
  header from expanding after the logo loads and avoiding layout shift.

## 2.0.45 - 2026-08-12

### Performance

- Split first-party styles by page capability, then minify and bundle only the
  groups required by the current route. Public feeds no longer download admin,
  profile or editor styles, while each rendered page still uses one immutable
  CSS request.

## 2.0.44 - 2026-08-12

### Performance

- Reduced the first server-rendered page of home, following, author and tag
  feeds to five posts. Cursor pagination continues with the configured
  twenty-post page size, reducing initial response size without affecting
  continuous scrolling.

## 2.0.43 - 2026-08-11

### Fixed

- Restored avatar URLs when a partial author or actor projection is present in
  a view payload.
- Let a successful avatar-modal submission leave without an unsaved-form
  confirmation.
- Allowed administrators to upload or remove an avatar for another user from
  that user's profile, using the same authorization rule as profile editing.

## 2.0.42 - 2026-08-11

### Simplification

- Replaced avatar JSON configuration and randomized paths with one static
  WebP file per user ID.
- Kept only the `avatar_exists` flag in the user record; avatar URLs use the
  existing update timestamp for cache invalidation.

### Fixed

- Applied JPEG orientation before avatar cropping, so phone photos retain the
  intended centered crop.

## 2.0.41 - 2026-08-11

### Fixed

- Prevented duplicate status cards during long automatic scrolling. Feed loading
  and pruned-feed refreshes now share one request state, and inserted cards are
  deduplicated by status ID as a final safeguard.

## 2.0.40 - 2026-08-11

### Simplification

- Removed the inactive extension `install_schema` hook; verified extension
  migrations remain the single schema-installation path.
- Unified signed GitHub package download and secure archive extraction for
  core updates and extension packages.
- Made the extension administration overview read only installed versions and
  states. Migration checksums are now verified only when an extension is
  installed or updated.

## 2.0.39 - 2026-08-10

### Simplification

- Removed the private privacy-card fragment and rendered its local content from
  the privacy page.
- Replaced profile-sidebar following previews with the existing on-demand
  following modal, opened from the following count.
- Removed public online and last-seen status, including both obsolete user
  activity timestamp columns and their data through a restart-safe migration.

### Performance

- Removed the periodic authenticated-request write to the `users` table and
  the eager following-profile lookup on author pages.

## 2.0.38 - 2026-08-10

### Simplification

- Removed the unused frontend pagination facade and its unreferenced rendering
  implementation.
- Unified JPEG EXIF-orientation handling for avatars and status images through
  the shared image helper.

## 2.0.37 - 2026-08-10

### Fixed

- Applied one consistent unsaved-form rule to comments, authentication and
  dynamically loaded modals. Leaving a draft now asks for confirmation, resets
  it before navigation, and removes stale remote modal DOM.

## 2.0.36 - 2026-08-10

### Simplification

- Removed the unused `content_links.position_index` column, index and offset
  propagation. Link previews continue to render as status-card attachments.

## 2.0.35 - 2026-08-09

### Simplification

- Removed the detailed OPcache source report after confirming that the observed
  large cache came from shared hosting. The dashboard keeps its lightweight
  runtime summary and correctly reported memory limit.

## 2.0.34 - 2026-08-09

### Operations

- Added an administrator-only OPcache source report with the 20 largest cached
  script directories, including script count, bytecode memory and hit count.
  This makes unexpected shared or historical cache entries identifiable without
  rendering thousands of script rows.

### Fixed

- Corrected the OPcache memory-limit display. PHP reports this directive in
  bytes, so the dashboard no longer inflates a 256 MB limit to 256 TB.

## 2.0.33 - 2026-08-09

### Operations

- Added live, read-only OPcache and Memcached diagnostics to the administration
  dashboard: cache memory, hit rate, item and script counts, evictions, uptime
  and relevant runtime configuration.
- Added a CSRF-protected administrator action to reset the active web PHP
  OPcache. Memcached remains read-only; TinyCat does not flush or restart a
  shared cache server.

## 2.0.32 - 2026-08-09

### Simplification

- Removed the unused `Core::select()` compatibility facade and the query
  builder's derived `count()` terminal. The remaining database API stays small
  and direct.

### Operations

- Added OPcache diagnostics to the administration dashboard and reset OPcache
  after a successful signed web update, preventing stale PHP bytecode after a
  release.
- Expanded cache diagnostics to distinguish disabled, unreachable and active
  Memcached, alongside the active OPcache state.

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
