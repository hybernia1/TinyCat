# Changelog

## 5.18.0 - 2026-11-01

- Load settings, user and bot-account creation, and release notes through authorized JSON resources on demand. Dispose closed dialogs and preserve drafts through list refreshes, forced reloads and late responses.
- Share one popover controller and surface across notifications, filters, account/language menus, context actions and search suggestions. Keep keyboard focus, touch dismissal and panels within mobile and zoomed visual viewports.
- Unify empty states, notices, identity rows and load-more controls. Preserve structured warning content on site health and updates as well as simple search messages.
- Organize CSS and JavaScript by responsibility, remove unused selectors and unreachable following refresh code, and enforce component, modal, asset ownership and lifecycle contracts during builds.
- Remove 127 retired runtime paths from overlay updates. No new migration or configuration change is required; updates remain supported from 5.16.0.

## 5.17.0 - 2026-11-01

- Fix report submission and review reports through shared tables, previews and tabbed history.
- Give moderated posts independent published/hidden visibility. Preserve content, media, discussion and report history; editing or reporting does not implicitly publish or hide a post.
- Replace legacy edit-lock fields with content.status and allow new reporting cycles after review. Apply visibility consistently to feeds, search, exports, embeds and public links.

## 5.16.1 - 2026-11-01

- Set the release tooling update baseline to 5.16.0 and rehearse signed upgrades and rollback from its exact tag. Replace fixed 2.0.25 cleanup assertions with migration inventory and data-preservation checks.
- Stabilize the browser tooltip probe by keeping its target visible and waiting for preceding focus-induced scrolling; include hover phase diagnostics on failure.

## 5.16.0 - 2026-10-31

- Show posts only on search result pages, replacing profile/tag tabs with the shared RSS and media-filter controls. Preserve queries and filters through AJAX navigation, cursor pagination and public RSS exports.
- Rank matching tag suggestions by published post count, excluding inactive authors and scheduled posts, with deterministic alphabetical ties.
- Save administration settings through the shared AJAX form flow without reloading the page, preserving the normal success notification and editor state.
- Keep native tables and sortable column headers on mobile, with horizontal scrolling contained inside table wrappers. Remove card conversion and the duplicated mobile sorting menu from pages, dashboard and modal lists.
- Extend search, RSS, notification/editor and native-browser viewport coverage. No new database migration or configuration change is required; signed updates remove the retired table-conversion script and styles.

## 5.15.0 - 2026-10-31

- Use Spotify's 152px compact embeds and SoundCloud's 166px classic players, including playlists. Hide duplicate SoundCloud artwork, comments and optional buttons.
- Use one audio card layout and play button for RSS recordings, Spotify and SoundCloud. Load players only after interaction and retain artwork and episode details during playback.
- Play podcast audio from RSS/Atom enclosures, JSON Feed attachments, direct audio links and web audio metadata using native controls. Resolve mujRozhlas episode links through its API. Preserve playback URLs in link storage and show imported episodes in the Audio archive; existing installations require the link audio migration.
- Read iTunes podcast summaries and artwork, use show artwork when an episode has none, and retain feed descriptions in link cards without images.

## 5.14.0 - 2026-10-31

- Share scoped ownership of AJAX reads, reject stale results and errors, and lock duplicate action links before confirmation. Retain list filters and sorting through module-owned definitions and refreshes.
- Dispose replaced feed and list content consistently, cancel obsolete cursor requests and queued loads, hydrate appended cards once, and bound search suggestion caches by size, age and interface context. Preserve AbortError during JSON response cancellation.
- Limit chronological probes for MySQL tag and media feeds, with a complete selective fallback for sparse results. Select and paginate narrow content-search candidates before loading post and author details; preserve literal matching, link excerpts, visibility and cursor ordering.
- Consolidate shared CSS tokens, avatar and menu styles and remove unused helpers and superseded stylesheets. Extend MySQL, SQLite, native-browser, lifecycle and request-race coverage.
- Keep critical CSS and inline JavaScript as measured experiments; production assets remain external and cacheable. No new database migration or configuration change is required. Signed updates remove retired source files.

## 5.13.0 - 2026-10-31

- Rebuild the administration dashboard with shared cards, list containers and empty states. Replace the recent-comment panel with a responsive table showing authors, posts and timestamps; keep recent activity above cache diagnostics. Remove database table names, the hardcoded product title and the unrelated reports link.
- Preserve all cache diagnostics and the protected OPcache reset action. Keep published and scheduled counts, bounded comment queries, escaped text and canonical author/post links.
- Bind filter and sorting activation directly to their triggers, including text and SVG icon clicks, instead of relying on document-level click delegation. Retain explicit dismissal and open-menu restoration after AJAX updates.
- Keep flash notifications inside the mobile visual viewport when editors or the keyboard open. Ignore touch hover events when managing notification duration and clear dismissal timers consistently.
- Extend dashboard, mobile viewport, trigger and notification regression coverage. No database migration or configuration change is required; signed updates remove the superseded dashboard template.

## 5.12.0 - 2026-10-31

- Add shared modal URLs and browser history across the frontend and administration. Back closes the current editor, Forward restores it, tabs replace the current entry, and direct links/reload restore nested editors without serializing drafts. Preserve list state, scroll and focus and guard unsaved changes and stale responses.
- Share native and server validation feedback across tabbed editors, including visible rich-text proxies, field focus, modal scrolling, nested field names and form-level errors. Preserve hidden controls when arranging sections, fixing new-user submissions that lost the required active status.
- Make list sorting and filtering close on repeated trigger clicks. Refresh the bot workspace background only after actual changes, remove module-specific history/session-storage workarounds, and cancel standalone confirmations during page history restoration.
- Extend native-browser, renderer, source-ownership and update rehearsal coverage. No database migration or configuration change is required.

## 5.11.0 - 2026-10-31

- Simplify table sorting to one direction indicator and reuse the filter popover appearance for mobile sorting. Keep sorting menus open across selections and AJAX refreshes, including keyboard focus and scroll position.
- Show active filters as removable chips, remove redundant result-count text and Clear all controls, and shorten filter labels in all 12 interface languages. Preserve search, sorting and page size when removing one filter.
- Normalize unavailable bot/source combinations consistently with the visible choices, share active-filter detection, and remove duplicate page-size selection. Keep scoped source refreshes on the selected page size and prevent chip edits during an in-flight request. No new database migration is required.

## 5.10.0 - 2026-10-30

- Make administration tables readable on mobile with labeled row cards, touch-sized actions and retained sorting. Keep complex comparison tables locally scrollable and preserve table accessibility, empty states and AJAX refresh behavior.
- Keep tabbed modal navigation and bot-list pagination outside the scrolling body. Add a compact mobile section switch that shares tab selection, lazy loading, validation and footer actions with desktop tabs.
- Normalize table identities, activation icons, metadata and action columns. Bound long names, URLs and diagnostic text visually while preserving full values and original links; remove decorative username prefixes from administration identities.
- Replace browser hints with shared, token-based tooltips supporting pointer, keyboard and touch input, safe plain-text rendering and viewport-aware modal placement. Correct filter positioning and reduced-motion behavior.
- Restrict embed replies to a pinned HTTP(S) parent origin while retaining TinyCat and WordPress handshakes. Generated iframe referrers disclose only the parent origin. No new database migration is required.

## 5.9.0 - 2026-10-30

- Unify administration lists, automatic dropdown filters, filtered result summaries, compact table metadata, empty states, status icons and activation actions. Preserve query state, pagination and entity-specific success notifications.
- Replace duplicate bot editors and the standalone settings page with shared tabbed workspaces. Add searchable, filtered and paginated bot run history; reuse post editing in the publication queue. Move blocked websites into Security settings.
- Share account, avatar and modal components across the frontend and administration, with common footer actions, size tokens and mobile keyboard handling. Preserve drafts in other account tabs after partial saves and guard language navigation.
- Remove redundant headings, header controls, help text and obsolete templates/styles. Signed updates explicitly remove retired runtime files. No new database migration is required.

## 5.8.0 - 2026-10-30

- Add the existing archive content filter to the public and following home feeds. Retain the selected type across feed switches, pagination and history navigation, with a clear filtered empty state and reset action.
- Keep feed tabs and the content filter on one row on mobile. Reuse shared components, dismissal behavior, module-owned styles and all 12 interface languages.
- Recheck filtered home results through the shared page navigation after creating or editing a post, using the same server-side media predicate as archives. No database migration is required.

## 5.7.1 - 2026-10-29

- Fill missing WordPress feed artwork through a verified REST API when `{{image}}` is enabled. Batch requests, cache discovery and image results, and retain feed-provided images as the first choice.
- Match API artwork to the expected article URL and post ID through the existing WordPress provider, sharing its per-post endpoint cache. Keep URL, redirect, upload and image validation rules intact.
- Use the same enrichment for source previews and imports. Skip unnecessary requests for known GUIDs and text-only templates, and retain ordinary feed behavior when the API is unavailable. No database migration is required.

## 5.7.0 - 2026-10-29

- Extend unified sources with automatic JSON Feed 1.0/1.1 and RSS 1.0/RDF support alongside RSS and Atom. Discover advertised feeds from website URLs without introducing stored source types.
- Preview source content and the current post template in the source modal before saving. Preserve source identity across redirects, reject stale confirmations and keep Run now as a direct import action.
- Add optional `{{image}}` attachments using the normal upload validation, WebP conversion and configured limits. Keep the default template unchanged and clean up files when persistence fails.
- Lock and recheck source identity, account and enabled state before atomically saving posts, source relations and GUID history. Preserve independent source queues, deletion behavior and retry safety.
- Add review-first SQL attribution tools with default rollback and isolated database tests. Retain shared JSON APIs, CSRF, components, form lifecycle and all 12 interface languages. No new database migration is required.

## 5.6.0 - 2026-10-29

- Show RSS source identity and source filters in the publication queue and bot post lists. Add linked published/queued counts to RSS source tables, and explicit unknown-source labels and filters for unattributed posts.
- Schedule RSS/Atom publications per source instead of sharing the bot account's timeline. Persist source/post relations atomically with posts and GUID claims, with a restart-safe migration and matching fresh-install schema.
- Preserve legacy publication times and deduplication history; unattributed legacy posts do not delay new per-feed queues. Early publication and deletion affect only the associated feed's remaining timeline.

## 5.5.0 - 2026-10-29

- Add a bot publication queue with text search, bot and account-status filters, sortable columns, pagination and a confirmed Publish now action. Preserve other publication times and guard administrator access, CSRF, inactive accounts and repeated requests.
- Move scheduler filters beside history controls, remove the manual refresh button and refresh visible idle views through the existing API every five seconds. Preserve list state and reject stale responses.
- Handle unavailable Memcached version responses without PHP warnings.
- Reuse module-owned JSON APIs, shared presentation components, navigation, forms and all 12 interface languages. No new database migration is required.

## 5.4.0 - 2026-10-29

- Import all new usable RSS/Atom items in a batch and schedule publication on the bot's existing timeline. Separate polling from publication spacing, defaulting new and migrated sources to 30 minutes without changing polling intervals.
- Add the publication-interval migration and matching fresh-install schema. Preserve import history, per-item transactions, retry safety and public visibility rules for future posts.
- Reuse HTTP connections within feed imports while resetting request options and retaining URL, redirect, TLS and download-size checks.
- Add a standard searchable, sortable and paginated bot post list with the shared edit modal and confirmed deletion, including administrator-only access to scheduled posts. Preserve scheduled times during edits.
- Normalize bot run tables, separate published and scheduled dashboard counts, and document template variables with category hashtags in the default source template across all 12 interface languages.

## 5.3.0 - 2026-10-29

- Replace standalone profile and logout controls with a shared avatar menu containing profile, account settings, theme switching and logout in public and administration headers.
- Add deterministic generated cat avatars and an expandable creator for gender, fur, expression, accessories, outfit and background colours. Save validated appearance JSON while preserving custom uploads and the stored fallback appearance.
- Simplify the avatar editor with a live preview and grouped actions; fix missing author avatars in comments and nested replies.
- Add an idempotent avatar appearance migration and the matching fresh-install schema.

## 5.2.1 - 2026-10-28

- Give backup retention and updater work directories independent bounded scan cursors and reserved batch shares. Validate candidate payloads lazily while preserving the newest complete recovery snapshots. Report oversized catalogues explicitly.
- Persist the next cleanup task before starting work so repeated worker interruptions cannot always restart ahead of backup retention.

## 5.2.0 - 2026-10-28

- Move the privacy link into the sticky public-sidebar footer, keep the link desktop-only for now, and use contrast-safe theme tokens. Retain the document footer on pages without a public sidebar.

- Route external post, comment, preview-card and embedded-post navigation through content-bound link APIs. Add configurable UTM source, medium and campaign settings while preserving original query data and existing UTM values. Keep default API responses normalized JSON, with explicit uncached browser navigation redirects.

- Configure the canonical website URL in administration through the normalized settings API. Share the stored address between absolute links and health measurements, validate HTTP(S) targets and retain file-configuration fallback.

## 5.1.0 - 2026-10-28

- Share dashboard statistic cards, semantic status badges and notices across Site health, scheduler overview and updates. Use standard icon-triggered modals for health run details and a named release-notes action; remove page-specific health styling.

- Record per-task scheduler findings, distinguishing failed, interrupted, missing and stale runs from healthy HTTP response times. Explain inspection duration separately from website timing.
- Align page wrappers, topbar actions and history tables across scheduler settings, Site health and updates.

- Simplify Site health administration with a status overview, compact response metrics, prioritized findings, expandable diagnostics and limits, and a responsive numeric history table.

- Measure anonymous homepage HTTP response timing in Site health, retain TTFB, total and connection timings with their applied thresholds, and display historical values without changing past evaluations. Bound transfers, verify TLS, reject credential-bearing targets, and distinguish transport/status failures from slow successful responses.

- Add an administrator-only Site health page and a daily `site-health` scheduler task, sharing API execution, locks and history. Record sanitized local diagnostic outcomes, flag stale/failed inspections and notify every active administrator of each completed result through in-app system notifications.

- Add orphan avatar and abandoned temporary-file cleanup with age guards; preserve avatar recovery copies and unrelated files.
- Checkpoint image and cache directory walks and bound inspected entries as well as deletions. Resume failed batches without advancing their cursor.
- Derive rate-limit bucket retention from the central policy, with a minimum of 24 hours and twice the longest window.
- Mark successfully applied update backups as complete. Retire confirmed backups after 30 days while preserving the three newest complete copies, incomplete and legacy recovery snapshots, and configurable longer retention.
- Share the updater lock for cleanup, protect maintenance mode and recent work directories, and resume backup retirement across bounded batches. No database migration or cron URL change is required.

## 5.0.1 - 2026-10-28

- Support URL-only hosting schedulers such as Webglobe through HTTPS GET `/api/scheduler/triggers/{task}` with HTTP Basic authentication (username `scheduler`, cron token as password). Use the same task execution, locks and history as the Bearer API.
- Authenticate hosting HEAD probes without executing tasks, prevent caching and cross-site browser triggers, and keep credentials out of URLs. Existing POST/Bearer endpoints remain unchanged.

## 5.0.0 - 2026-10-28

- Centralize rate-limit policy and count signed-in content quotas per account, independent of shared IP addresses. Exempt authenticated administrator and bot accounts from quotas and cooldowns.
- Separate creation, editing and deletion cooldowns, record them after successful operations, and include consistent wait details and `Retry-After` headers in 429 responses.
- Bound authentication, registration, password recovery, image uploads and follow changes; reserve request budgets atomically on SQLite and MySQL.
- Route browser writes through normalized module APIs, including authentication, logout, settings, updates, cache resets, notifications and search CAPTCHA. Retired page POST handlers return 405.
- Move installation into its own API-backed module, hide the installer page and API with 404 after completion, redirect the new administrator to administration, and remove `Public/install/index.php` through update metadata. No new database migration is required.
- Share scheduler validation, execution, locks and history between HTTP, CLI and manual bot imports. Keep the installer within its existing asset delivery budget by using the shared API form transport without administration controllers.
- Verify all 50 test groups, including complete isolated MySQL installation, authenticated scheduler transport, signed update/fresh-install artifacts and update/rollback recovery.

### Integration changes

- Replace HTTP calls to `/scheduled-tasks.php` with `POST /api/scheduler/executions`, using `Authorization: Bearer TOKEN`. Pass `task` and an optional `options` object in JSON. The old endpoint returns 410; query-string tokens and alternate token headers are no longer accepted.
- Use authenticated `GET /api/scheduler/health` for health checks. Generate or rotate credentials explicitly through the administrator API; visiting scheduler settings no longer creates a token.
- CLI commands remain unchanged, but machine-readable output now uses the common API envelope: successful task results are in `data.tasks`, and failures are in `error.details` with exit codes 1 (execution failure) or 2 (invalid arguments).
- Update callers of legacy page POST URLs to the canonical endpoints in `docs/api.md`. HTML pages and public feeds, media and discovery documents retain their native read-only formats.

## 4.11.0 - 2026-10-28

- Embed public posts through oEmbed 1.0 JSON/XML discovery and a standalone responsive iframe with HTTP validators and visibility checks.
- Add an icon action opening the standard TinyCat modal with a live preview and selectable embed code; retain a non-JavaScript fallback page.
- Show the author avatar, attached images and stored link previews, with click-to-load approved audio/video players, including Spotify and SoundCloud, and standard thumbnail metadata.
- Keep embed documents independent of visitor sessions and account controls; preserve frame protections on ordinary pages and validate resize message sources and origins.
- Version Memcached entries per release, read disk fallback entries after rejected primary writes, and enforce stale expiry independently of scheduled cleanup.
- Respect the configured cache directory during updates and isolate Memcached test entries from the running installation.
- Add real Memcached failure/recovery and concurrent-refresh checks, oEmbed and modal contracts, and repeatable filesystem/Memcached performance measurements. Warm feed SQL budgets remain unchanged.

## 4.10.0 - 2026-10-28

- Filter author and tag archives by images, video, audio, links or text, preserving the selected type in URLs and cursor pagination.
- Share the RSS and filter toolbar above archive feeds; keep profile follow actions in the author card.
- Reuse one filter label definition across server and browser rendering, menu items and accessible tooltips.
- Let tag archives and RSS use the covering tag index rather than forcing a global chronological scan; ensure the index through restart-safe installation and migration checks.
- Verify media filtering, stable cursors, SQL query budgets, index repair and selective MySQL query plans.

## 4.9.0 - 2026-10-28

- Centralize filesystem storage primitives for validated storage paths, concurrent directory creation and complete temporary-file writes with explicit permissions and cleanup on failure.
- Share file publishing between cache and update state while preserving existing paths, data formats and cache-specific Windows replacement behavior.
- Use shared directory creation for avatars, site images, status images, link previews and update packages.
- Add storage contract tests and developer documentation; retain existing cleanup and retention policies.

## 4.8.1 - 2026-10-27

- Prevent legacy links stored without `www` from appearing both inline and as a card; retain the original post URL and cached RSS metadata in feeds and post detail.
- Add a separate, optional one-off link optimization package with a read-only preview, required verified backup before writes, transactional recovery/merging, orphan-record cleanup and conservative handling of ambiguous URLs.
- Keep the maintenance script outside normal application packages; preserve post bodies, local image files and bot GUID history during optimization.
- Verify legacy-link rendering through the real API and optimizer rollback, repeatability and metadata preservation on isolated SQLite and MySQL databases.

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
