# Changelog

## 9.0.0 - 2026-11-27

- Give route-provider modules a stable, metadata-only HTTP namespace. Reject namespace collisions, reserved Core roots and routes outside their owner's API or administration prefix; roll back failed provider registration.
- Move Social administration to `/admin/social/...` and all canonical Social domain APIs to `/api/social/...`. Group its four administration links under one Social menu. Third-party API clients and module manifests must adopt the new contract; old mutation APIs are not redirected.
- Retain only documented admin bookmark redirects, stored modal-history conversion and the two link-resolver GET patterns already published in HTML and feeds. Core APIs, public permalinks, RSS and embed entry points keep their contracts.
- Share scalable component size variants, spacing and geometry across Core and Social. Reuse the existing UI-scale and density tokens instead of adding per-context size exceptions; preserve viewport and accessibility limits.
- Move profile controls and shared notification popovers to the administration sidebar header. Share navigation badge styles and show all registered dashboard statistics together as icon badges.
- Reveal loaded link-preview images in standalone embeds and restore their fallback after load failures.
- Preserve application data, module activation, task IDs and published migration history. No new database migration is introduced. Ship matching signed Core and Social install/update artifacts.

## 8.0.12 - 2026-11-26

- Consolidate administration helpers and move UI, text, deployment and update utilities to their owning core areas. Reuse immutable configuration snapshots and remove redundant helper files.
- Keep public runtime assets independent of the configured computed-cache directory; consolidate publication and retention without changing public asset URLs.
- Load prepared translations through a small index and separate language/channel payloads. Share source validation within build operations, remove the unused language registry and retain all twelve locales.
- Translate health notification messages in the ten additional locales and cache document locale-routing metadata in JavaScript.
- Use browser-local embed preview URLs while retaining canonical sharing URLs and strict preview origin, route and object checks.
- Share HTML-to-text operations, parse link metadata HTML once, reuse URL-preserving text traversal and deduplicate feed image inspection. Bot publication policies and final plain-text storage remain with Social.
- No database migration or configuration change. Controlled measurements confirm fewer loaded files but do not establish an overall response-time improvement.

## 8.0.8 - 2026-11-26

- Share authentication page and modal definitions across login, registration and password recovery; simplify the forms while retaining validation, consent and draft protection.
- Reject ambiguous external login destinations and prevent signed-in sessions from loading login or registration modals.
- Consolidate shared sorting, link-image and moderation fragments. Use core controls and spacing tokens, improve UI scaling and restore Social assets on localized routes.
- Simplify notifications, privacy and error layouts; highlight entire notification rows while keeping delete actions independent. Standardize sidebar and hashtag hover states.
- Generate square initials avatars with cache-sharing v3 URLs. Remove old generated/initials SVG routes; retain custom uploads and their atomic file references.
- Declare the WebP MIME type explicitly, retire obsolete Apache rewrite/cache rules and remove unused translations and styles. No new database migration or configuration change is required.

## 8.0.7 - 2026-11-25

- Reduce repeated Core and Social reads and consolidate internal helpers without changing database schemas or configuration.
- Keep search visibility checks bounded and file-reference cleanup aware of installed inactive owners; preserve feed parsing metadata and content.
- Fix editor mention/tag suggestion ranges and synchronized text values. Use the server's authoritative following count after mutations.
- Remove unused forwarding methods and the internal Database Keyset helper; signed updates delete its obsolete file.
- Preserve accounts, content, uploads, module activation, task IDs and migration history. Publish matching independent signed Core and Social update/install packages.

## 8.0.6 - 2026-11-25

- Simplify core and Social ownership: keep publication, discussion, import policies and media data in Social; share RSS and oEmbed presentation through core contracts.
- Consolidate built-in diagnostics and cleanup under Tasks, retaining task IDs, run history, notification links and the common administration.
- Use one module schema contract and authoritative table metadata. Remove feature groups, unused installation contracts, duplicate registration paths and legacy forwarding methods while retaining signed update and rollback compatibility.
- Simplify Identity integration and primary-administrator protection. Use one policy for presentation and locked writes without stale process caches or per-row queries.
- Replace configurable cat avatars with Unicode username initials in SVG. Retain custom avatar upload/removal and immutable file versions; account schema 4 removes only the obsolete appearance column.
- Preserve editorial wording in manual posts and comments; explicitly clean feed-import boilerplate before the shared publication preparation. Keep import previews read-only and publication/GUID writes atomic.
- Remove SQLite runtime/test support; MySQL/MariaDB is the only supported database backend. Historical migrations and published checksums remain unchanged.
- Use relative Memcached expiry for short-lived entries so clock skew does not expire new cache data immediately; preserve timestamp expiry above the protocol's 30-day boundary.
- Update source, API, native UI, migration and signed-package regressions, including physical retirement of old files and exact rollback with original PHP classes already loaded.

## 8.0.5 - 2026-11-24

- Unify scheduled tasks, run history and lazy tabbed connection details at `/admin/cron`; site-health notifications and dashboard links open the recorded run. Remove the separate health and task-list pages.
- Standardize full-width administrative tables, identity rows, status badges, sorting headers and filter toolbars. Keep row hover/focus feedback without zebra backgrounds or row borders.
- Present badges, flash messages and inline notices on neutral surfaces with shared severity symbols and theme-aware icon foregrounds. Preserve the established button variants and notification lifecycle.
- Reuse core switcher and menu components for theme/feed controls and Social menus. Correct field alignment, cache diagnostics text and the bot avatar translation scope.
- Render embedded Social posts with the canonical post card, including reaction/comment counts and lazy media. Use the shared copy block for embed and scheduler connection details.
- Remove unused page code, styles, translation keys and obsolete embed player attributes. No database migration or configuration change is required.

## 8.0.4 - 2026-11-24

- Reuse request-scoped framework metadata, source language packages and asset graphs; avoid repeated deployment identity hashing for compiled GET/HEAD responses.
- Add resumable account sorting and notification read indexes with version 3 fresh baselines. Reuse the unfiltered account total and prepared inbox state instead of repeating queries.
- Poll notification counters without fetching the full inbox; fetch at most six preview items when needed and preserve focused preview rows and mutation ordering.
- Consume Social write quotas atomically inside their data transactions. Enforce database-backed search and suggestion budgets across sessions, including search feeds, and keep search policy in Social.
- Make authenticated performance profiles use canonical login credentials and report authentication setup separately from request SQL.
- Preserve existing data, configuration, uploads, module activation and published migration checksums. PHP 8.4 and completed 6.0.10 or newer remain required; deploy matching signed Core or Social packages through the normal updater.

## 8.0.3 - 2026-11-24

- Consolidate internal Core handlers, account setup, resources and sorting definitions; remove forwarding methods and preserve shared HTML/API preparation.
- Register Social through its single module entry, consolidate feed publication preparation and outbound-link policy, and call canonical archive, moderation and statistics implementations directly.
- Inline the flash-data partial without changing document output. Preserve the publicFooter contract for external themes and lazy maintenance for installed inactive modules.
- Retire 18 internal classes and one partial through signed update deletions. Custom integrations referencing retired internal helpers must use their canonical owners.
- No new database migration or configuration change. PHP 8.4 and completed 6.0.10 or newer remain required; use matching signed Core or Social packages.

## 8.0.2 - 2026-11-23

- Defer account/avatar and Social dialog editors, their component definitions, translations and styles until a resource dialog or inline editor needs them. Reduce initial asset delivery on home, tag, author and search pages.
- Share deferred asset selection between source fallback and signed builds, retain independent JS/CSS minification settings and language-independent component URLs, and report initial and deferred payload sizes separately.
- Coalesce concurrent editor loads and allow failed requests to retry. Preserve administration extensions, inline reply focus, drafts, resource refresh cancellation and Back/Forward history while assets load.
- Avoid reading scroll position during feed bundle evaluation, removing a source of forced layout on initial page load.
- No new database migration or configuration change. PHP 8.4 and completed 6.0.10 or newer remain required. Deploy matching signed Core or Social runtime and generated assets.

## 8.0.1 - 2026-11-22

- Revoke stale sessions and reset tokens after credential changes; share account validation and preserve server authorization.
- Coordinate scheduled tasks with deployment admission, recover interrupted installation steps and keep rescue paths available with damaged metadata. Protect Windows configuration publication through the bundled private-file helper.
- Make concurrent Social writes and file cleanup transaction-aware. Give avatar uploads immutable versioned URLs and invalidate account/profile/settings caches after committed changes.
- Preserve feed cursors across concurrent changes, paginate discussions and count only visible comments. Keep domain text preparation and form constraints consistent across manual publishing, API and feed imports.
- Preserve form drafts and canonical values through shared editor lifecycle handling. Fix login/registration resource switching and opener focus; omit Refresh from authentication dialogs through an explicit shared option.
- Deliver the existing Social status card components, styles and controller on search pages, including source and compiled asset variants.
- Add the nullable users.avatar_version migration and current fresh-v2 account schema. Keep published migrations, existing media, configuration and module activation unchanged. PHP 8.4 and completed 6.0.10 or newer remain required.

## 8.0.0 - 2026-11-22

- Control public website availability through settings without reinstalling or enabling modules. Preserve existing sites; fresh installations start with the public website disabled.
- Separate public, access and administration layouts and asset profiles. Keep login, recovery, accounts and authorized bot editing available when the public website is closed. Restore notification targets after re-enabling public presentation.
- Keep public themes optional, make the public footer an explicit layout fragment, remove configurable footer HTML and duplicate account/recovery sections from Social privacy.
- Centralize runtime diagnostics in on/off/log modes and classify expected public failures through HttpException. Unexpected exceptions retain HTTP 500 and safe output; preserve atomic writes and report recoverable technical failures.
- Remove retired presentation switches and the unused rich-text footer helper. Preserve published migration files, accounts, uploads, configuration and module activation.
- Breaking extension changes: remove local debug parameters and Router::debug(); expected HTTP errors require HttpException, and page surfaces replace legacy presentation flags. Update custom modules before deployment.
- No database migration. PHP 8.4 and completed 6.0.10 or newer remain required. Deploy matching runtime and generated assets using the signed Core or Social package.

## 7.1.2 - 2026-11-22

- Isolate client lifecycle hook failures, retry unfinished initialization and dispose services in reverse hydration order.
- Preserve native submitter values and repeated/empty GET fields through shared form handling; preserve disabled states while loading.
- Validate only changed controls during input and reuse the UTF-8 encoder, retaining whole-form validation before submission.
- Correct keyboard traversal for negative tabindex, inert ancestors, disabled fieldsets and editable hosts.
- Keep account-menu styles in core Identity, consolidate local CSS duplicates and the independent embed palette, and fix dark-theme skip-link contrast.
- No database migration. PHP 8.4 and completed 6.0.10 or newer remain required. Use matching signed Core or Social packages.

## 7.1.1 - 2026-11-22

- Stream database snapshots, batch inserts and validate restore structure before destructive statements. Preserve legacy snapshots, atomic writes and after-commit effects; coalesce Settings invalidation and remove repeated schema checks.
- Batch Social notification/reaction cleanup, keep sitemap page selection narrow and share canonical database helpers across callers.
- Decode URL paths once, preserve compact/media filters across native and AJAX navigation, include HEAD in GET method metadata, align PHP/client scalar rendering and reuse prepared document metadata.
- Prevent stale Memcached values from returning after a connection outage, isolate installation namespaces and hash complete disk keys. Validate package revisions for all compiled clients/catalogs and avoid reading source payloads for compiled asset hits.
- Require HTTPS for Bearer scheduler credentials, coordinate task locks by database, preserve failed/stale work across skipped invocations, report feed backlog and reject noninteger task defaults.
- Bound duplicate-post cleanup input windows with resumable progress while retaining exact body/relation checks and transactional merge behavior.
- No database migration. PHP 8.4 and completed 6.0.10 or newer remain required. Pause external scheduler invocation while upgrading every worker/node; old and new task-lock namespaces must not run together against shared data. Scheduler Bearer clients must use HTTPS. Cache entries may cold-miss after the key namespace change.

## 7.1.0 - 2026-11-21

- Keep Social publishing/discussion components, controllers, styles and translations with their module; remove retired core presentation classes and duplicate discussion resource preparation. Core public pages no longer depend on these Social resources.
- Make privacy, sitemap, crawler documents and feed discovery reflect registered active owners. Remove duplicate site descriptions, missing translation keys and Social-specific guidance from core-only sites.
- Unify HTTP error presentation, HEAD/error cache policy, generated-avatar delivery and compressed ETag validation. Validate flat oEmbed XML, derive RSS enclosure metadata from its owner and simplify TXT exports.
- Share guarded resource-editor refresh, active-tab/history restoration and independent section drafts. Settings save only their selected section, validate before atomic database writes, invalidate caches after commit and reconcile canonical values through the original components.
- Mark unsaved editor tabs and mobile options with an asterisk, clearing only saved or manually restored drafts.
- Limit update deletion manifests to module roots owned by each distribution. Core updates preserve independently installed modules; runtime ownership protection remains strict even for absent deletion targets.
- No database migration. PHP 8.4 and completed 6.0.10 or newer remain required. Extension authors must update imports of moved Social UI helpers. Settings PATCH now returns the password-safe editor `sections` resource rather than the retired saved/images summary.

## 7.0.0 - 2026-11-21

- Make the core-only framework a complete foundation for independent applications, with explicit installation state, stable translation ownership and product-specific update channels. Publish core and Social installation/update packages together.
- Remove the unused Blog module and its assembly preset. Reject updates that would discard an installed Blog owner before changing files or data.
- Consolidate Social into Posts, Discussion, Profiles, Bots, Moderation, Search and Site; remove forwarding classes, duplicate presentation paths and obsolete providers without altering published migration history.
- Separate Identity account APIs under `/api/users` from Social profiles and follows under `/api/profiles`. Retire the duplicate Social search page/API; use `/search/all`, `/api/search/query` and `/api/search/challenges`.
- Add lightweight, optional public themes with a metadata-only manifest and render-only PHP layout. Keep each theme's CSS, JavaScript and images together; remove module-owned themes and the alternate global page renderer. Saved retired theme IDs fall back to the core layout.
- Move shared client sources into Core/Ui and retain function-specific assets with their core/module owner. Leave the public asset directory for generated outputs, resolve relative CSS assets consistently, and preserve the public embed-script entry.
- Select public, administration and installation JavaScript, CSS, components and translations through owner manifests. Remove the central CSS index and prevent core public pages from loading Social feed controllers or administrative list filters.
- Preserve shared form/dialog behavior, data visibility, cursor ordering, module activation, configuration and uploads. Introduce no database migration; updates require a completed 6.0.10 installation or newer.
- Breaking extension changes: replace `Contributions::themes`/`PageTheme` with standalone themes, update client source imports to their owner paths, and pass owned application-relative source paths to the asset helper. Deploy matching runtime and generated assets together.

## 6.3.1 - 2026-11-21

- Unify links without underlines and remove the retired inline-link stylesheet from update installations.
- Use subtle table striping, neutral hover/keyboard feedback and italic inactive descriptions; keep sticky header cells opaque while scrolling.
- Refine dark mode with graphite surfaces, restrained semantic colors and shared primary-button fill tokens; remove button hover movement.
- Reuse neutral interaction surfaces across disclosures, menus, filters and clickable identity rows. Link bot feed names directly to their source URL.
- Simplify installation with compact step tabs, a native language selector and separate action footers, with Back on the left and the primary action on the right.
- Preserve data, module activation, API contracts and the completed 6.0.10 update baseline; introduce no database migrations.

## 6.3.0 - 2026-11-21

- Replace the administration overview with typed, owner-registered dashboard widgets and a shared statistics selector that loads only the selected group.
- Move cache diagnostics to a dedicated grid showing runtime cache state, asset generation, minification, configured browser policies and anonymously observed HTTP cache/compression headers.
- Share scalable avatar and content-icon rows across user/bot tables, other administration lists and dashboard widgets; display table states as readable badges.
- Generate decorative badge, alert and toast symbols through CSS masks from the canonical icon sprite, reducing repeated SVG markup and notification JavaScript.
- Fix the stylesheet boundary that removed spacing between the homepage feed switch and its first card; remove duplicate discussion-button styling and obsolete dashboard presentation code.
- Preserve module independence, API authorization, form drafts and notification lifecycle. Introduce no database migration and retain the completed 6.0.10 update baseline.

## 6.2.0 - 2026-11-20

- Introduce shared UI scale and density tokens for typography, controls, dialogs, pagination and toasts. Use compact, flat administration with a distinct dark navigation panel and accessible light/dark colours.
- Standardize mobile below 900px and desktop from 900px; remove intermediate layout breakpoints. Keep desktop table headers and pagination fixed while table content scrolls, with normal page scrolling on mobile.
- Unify dialog kinds, sizes, viewport handling and toast tones; preserve lazy resources, draft guards, focus, history and native touch behavior. Restore module-activation and Site Health flash feedback.
- Replace field help blocks with contextual information controls, normalize typed input icons and validation, and add localized copy controls to read-only code.
- Derive settings presentation from lazy owner component lookups instead of embedding a settings catalog in each admin document. Translate only presentation properties and preserve defaults and options as data.
- Add a reusable public sidebar workspace with source navigation from active search registrations. Social results reuse post cards; Blog search and listing share article summaries. Preserve independent owner data, visibility, canonical API responses and stable cursors.
- Simplify bot account editors and dashboard summaries; preserve avatar editing, existing accounts and module activation. Introduce no database migrations and retain the completed 6.0.10 update baseline.

## 6.1.4 - 2026-11-19

- Consolidate product pages, menus and editor bodies in owner component catalogs. Retire seventeen redundant PHP templates while retaining document, layout, error and embed boundaries.
- Standardize component JSON formatting and explicit object contexts, with server/client parity, schema validation and duplicate-key checks. Keep ten owner catalogs without introducing a template language or per-component files.
- Move account, avatar, status, bot and moderation structure out of controllers. Preserve lazy resource dialogs, drafts, focus, password redaction and canonical API transports.
- Render module administration, diagnostics, updates, search, privacy and consent through components; preserve activation guards, CSRF, maintenance recovery and long historical migration labels.
- Clean up client resource disposal and stale reads; decouple notification pagination from Social. Keep existing asset delivery budgets and module independence.
- Preserve configuration, accounts, content, uploads, installed module ownership and activation. Introduce no database migrations; support completed 6.0.10 and newer.

## 6.1.3 - 2026-11-19

- Share one HTML document across public pages, administration and installation; keep owner metadata, consent, assets and existing minification behavior.
- Move installation, account and settings presentation into owner component catalogs shared by server and client. Preserve lazy dialogs, draft guards, password redaction and canonical API data.
- Remove unused Social feed render layers and inline application HTML. Open legacy bot detail URLs through the shared dialog route while preserving filters and history.
- Add component JSON schema completion, grammar validation and precise source/component/path diagnostics; keep catalogs as ordinary JSON without per-component template files.
- Retire ten presentation files through the signed deletion plan. Preserve installed owners, activation, data and published migration history; introduce no database migrations.


## 6.1.2 - 2026-11-18

- Fix administrative UI initialization on Site Health, where scheduler run details are available without a scheduler list declaration. Keep hamburger navigation, settings and run-detail dialogs working.
- Keep health checks on the shared AJAX transport and return to the administration page instead of navigating to raw API JSON.
- Guard scheduler list-only polling, rendering and lifecycle callbacks when no list is present; preserve full scheduler history and token actions.
- Add source, compiled/minified bundle and native browser regressions for this detail-only administration surface. Introduce no database migrations or activation changes.

## 6.1.1 - 2026-11-18

- Derive settings labels and layout from their owning PHP sections. Blog and Social can contribute settings independently without a second JavaScript field registry.
- Fix saving unchanged Blog settings: empty blocked URL rules and optional UTM source/campaign values remain valid, while UTM medium retains required validation.
- Declare administrative lists and standard editors in owner asset manifests. Share navigation, filters and list refresh across Blog articles/sources, Social bot administration/moderation, accounts and scheduler history; preserve specialized editors, polling and draft guards.
- Remove retired presentation adapters through the signed deletion plan. Preserve module activation, owned data and the completed 6.0.10 update baseline; introduce no database migrations.

## 6.1.0 - 2026-11-18

- Offer Login as an Identity-owned homepage, including in core-only installations. Preserve module landing destinations and valid next URLs; terminate authentication/homepage redirects with an account overview when no other destination exists.
- Keep automatic functional-module homepages and public navigation unchanged. Allow homepage candidates to opt out of navigation.
- Apply existing HTML minification to the shared document, revalidate unversioned assets and gzip web app manifests. Preserve existing minification settings on update.
- Keep public error-page navigation and asset selection consistent across public and administrative URLs, with HTML, HEAD and JSON regression coverage for 400–503 responses.
- Preserve the completed 6.0.10 update baseline, published migration history and installed Blog ownership. Introduce no database migrations.

## 6.0.13 - 2026-11-18

- Consolidate Social navigation, account administration, HTTP documents and presentation on shared core mechanisms. Remove redundant adapters while preserving module-owned data and registration.
- Enforce shared Unicode text constraints for posts and comments, preserve drafts on overflow, and normalize account activation consistently.
- Remove eight retired Social PHP files through the signed deletion plan. Preserve published migration history and introduce no new database migrations.
- Reduce repeated browser setup, fixture inserts and unused lifecycle package builds in preflight. Retain all behavioral scenarios; make the archived 2.0.25 report integrity check explicitly opt-in and record lifecycle phase timings.

## 6.0.12 - 2026-11-17

- Discover available modules from manifests on disk without executing inactive PHP. Missing inactive modules no longer break the module manager; missing active owners and their dependents are suspended without erasing activation state or data.
- Support explicit activation of manually copied module directories through the existing package registry, dependency checks, updater lock, backups and migration lifecycle. Report invalid inactive manifests in administration.
- Mark Blog as a development module and omit it from new production installations. The compatibility update retains the previously bundled Blog for installed owners from earlier public releases; fresh installations contain Social only.
- Simplify Social by removing internal callback registries and redundant identity adapters. Consolidate post preparation and tags, and retain transactional writes, file cleanup and notification ownership.
- Remove eight retired Social PHP files through the 6.0.12 deletion plan. Preserve published migration history and introduce no new database migrations.

## 6.0.11 - 2026-11-17

- Remove the public autoload facade and point developer tools, tests and external-script examples directly at the core runtime loader. Require completed 6.0.10 and refresh inherited private rescue launchers with its existing CLI before updating; exercise signed web update and exact rollback through the refreshed launcher.
- Discover available source modules from their manifests during platform builds and development checks, without a manually maintained central list or executing module PHP. Standalone module builds include transitive dependencies; installed runtime selection and activation remain explicit.

## 6.0.10 - 2026-11-17

- Remove the redundant bootstrap wrapper and start HTTP/CLI explicitly through the runtime. Keep autoloading independent of startup and preserve the public autoload facade used by published private rescue launchers, including update and rollback.
- Route Social status and follow forms through the shared AJAX lifecycle, including validation, mutation locks, submitters and field errors. Use a core submit guard for source previews and accept only the canonical API envelope.
- Register Social public HTTP routes, AJAX resources and client navigation patterns from one owner declaration. Share source byte constraints and Blog comment character constraints across server and UI, and retire unused composer/follow partials through the deletion plan.
- Derive Blog article and feed-source editor constraints from their server rules through the core validator. Share Unicode and UTF-8 byte validation across API, persistence, imports and forms, preserving drafts on overflow and deriving translated help from owner limits.
- Define the authoritative core contract for API, UI and validation. Reject malformed validation definitions as server errors, handle invalid input types without PHP diagnostics, and enforce stable field issue codes and field ownership.
- Remove the retired asset optimizer, old cache bridge and transitional distribution target on the next supported update; keep these legacy files out of release artifacts.

## 6.0.9 - 2026-11-17

- Require completed 6.0.8 installations and switch built-in distributions to archival format 4. Preserve all 24 original migration IDs, sequences and checksums without rewriting database journal rows.
- Remove 32 historical migration, auxiliary DDL and legacy conversion PHP files through the signed deletion plan; keep independent current fresh baselines for all eleven schema owners.
- Verify archival metadata against the published baseline and test the signed update with its original updater classes already loaded, including exact rollback restoration of deleted PHP.
- Keep checks of the last published release readable after raising the source update floor; retain the required minimum for incoming updates and archival packages.

## 6.0.8 - 2026-11-17

- Require completed 6.0.7 installations and prepare a checkpoint-aware updater while retaining the format-3 bridge and every published built-in migration file.
- Support an archived immutable migration prefix with checksums and no PHP source, signed distribution format 4 and independent module ZIP format 2. Reject older module schema states before changing live files or data.
- Verify archived history without reading retired PHP, execute only remaining deltas, and install directly from the current fresh schema while preserving journal identities.
- Test archival in SQLite/MySQL and through independent module ZIP updates, inactive owners, cached providers, integrity failures and standard rollback restoring retired files.

## 6.0.7 - 2026-11-17

- Remove historical activation remapping and distribution formats 1/2; completed 6.0.6 deployments retain canonical active and installed owner IDs.
- Move Social image-setting presentation and table-purpose translations into Social, and make installation text independent of selected functional modules in all twelve locales.
- Require explicit text rendering kinds so shared renderers never select the Social policy by default.
- Require a completed 6.0.6 baseline and remove the runtime SchemaInspection adapter, dual-journal copying and compatibility writer.
- Retire the module_migrations view through a new signed schema step while preserving all physical journal records and normal rollback.
- Remove pre-baseline global-table adoption entries from core, Social and Blog and use one current installation/readiness path. Applied migration snapshots and checksums remain unchanged.
- Exercise the published 6.0.6 updater with its original schema, journal and lifecycle classes already loaded, retained opaque version labels and a compatibility view.

## 6.0.6 - 2026-11-17

- Preserve opaque historical migration version labels up to 190 characters when converting the unified journal. Widen existing 6.0.5 storage through a new schema step without changing published migration checksums.
- Reproduce the retained pre-6.0.4 labels in the SQLite/MySQL journal tests and the signed 6.0.4 update and rollback rehearsal.
- Include the startup, scheduler-registration, shared styling and unified-journal changes from 6.0.5, whose signed assets remain unchanged and withdrawn from the stable channel.

## 6.0.5 - withdrawn from stable updates

- This release's unified-journal conversion rejects historical version labels longer than 32 characters. Sites upgraded from older releases can retain these labels even after a completed 6.0.4 update. The published signed assets remain unchanged; use the corrected 6.0.6 successor.

## 6.0.4 - 2026-11-15

- Reduce repeated core startup work with selection-scoped provider validation and immutable distribution snapshots.
- Load administration and module assets only where needed, consolidate shared notifications and filter behavior, and open Blog editors through canonical resource links.
- Separate developer reports, fixtures, measurements, browser tooling and signing keys into a private development workspace. Preserve historical outputs and versioned benchmarks without relocating application storage or uploads.
- Keep signed administration upgrades from stable 5.16.0 and newer, including 5.22.2; no additional schema migration is introduced by this release.

## 6.0.3 - 2026-11-14

- Replace domain-specific inbox columns with an indexed subject ID. Registered core and module notification types own subject requirements, lazy batch hydration, text and canonical links.
- Preserve historical IDs, deduplication keys, read state, timestamps and producer payloads through new core and Social migrations. Unknown historical producer references remain opaque until their owner converts them.
- Reject unregistered notification writes, keep unavailable-owner rows readable with a generic fallback, and keep Social cleanup scoped to its own types.
- Reduce duplicate core initialization, cache catalog fingerprints per selection revision, use named contribution arguments and fix profiling of empty function bodies.
- Unify scheduled task IDs across registration, HTTP, CLI and client updates; normalize parameters once, declare batch aliases with their owners and reject invalid Blog source selectors. Batch scheduler history reads and bound advisory lock names.
- Discover languages from native-name metadata declared once in Support catalogs, exclude metadata from message catalogs and retire the obsolete root language registry through explicit update deletions.
- Correct owner-specific dashboard counts, split users by role and narrow the existing administration sidebar.

## 6.0.2 - 2026-11-14

- Fix stable 5.x web updates with OPcache enabled. The signed conversion entry loads the current autoloader through a new runtime path, instead of executing the cached 5.x autoloader again after files were replaced.
- Run the existing published-updater rehearsal with OPcache and timestamp validation disabled to reproduce the original failure, verify data conversion and test rollback. Allow an indexed snapshot to seed only its isolated temporary database for incident verification.

## 6.0.1 - 2026-11-14

- Restore the normal signed administration update from stable 5.16.0 and newer, including 5.22.2. Release 6.0.0 incorrectly required development bridge runtimes; its attached bridge bundle was not automatically usable by the stable updater.
- Export one signed legacy migration entry and an authenticated final product descriptor in a transport envelope understood by the published updater. Apply pending historical migrations with their original checksums, synchronize owner schemas, preserve activation and publish the final descriptor during the explicit update operation.
- Keep explicit PDO execution usable in the already-running 5.x updater process through a shared statement service. Initialize the mandatory framework before functional modules without redundant activation dependencies.
- Preserve the old updater's original file backups and create a canonical database/activation recovery snapshot before domain conversion. Legacy rollback restores original files and data, conservatively retaining new private files whose previous absence the old updater never recorded.
- Exercise the actual published `check()` and `installLatest()` methods with signed artifacts, seeded MySQL data and rollback; replace only the network transport in the fixture. Cover 5.16.0, 5.22.2 and 6.0.0 update sources.

## 6.0.0 - 2026-11-14

- Separate the mandatory framework from independently selectable Social and Blog modules. Core owns identity, security, settings, notifications, mail, tasks, diagnostics, installation and updates; functional modules own their registration, resources and domain data.
- Give Blog and Social independent publication, discussion, reaction, taxonomy, link and media tables, ID spaces and foreign keys. Preserve existing IDs, public URLs, revision history, attachments and feed GUIDs through explicit owner migrations.
- Add signed product distributions and module ZIPs, administration and CLI lifecycle operations, inactive-owner upgrades, shared locking, snapshots, interruption recovery and rollback. Preserve installed inactive owners during account and file-reference cleanup.
- Share technical text, feed, link, file and UI mechanisms without mandatory publication tables in core. Keep registration lazy, discovery explicit, API authorization and query budgets intact, and Blog and Social usable together or independently.
- Repair fresh-install schema registration and restartable index repair in modular artifacts, and validate release baselines against the signed descriptor format.
- Require the signed 6.0.0-dev.9.1 file bridge followed by the 6.0.0-dev.9.2 migration checkpoint before the 6.0.0 data conversion. Reject the incomplete first hop before applying files. Historical migration files and checksums remain unchanged; configuration, uploads and runtime storage remain outside managed packages.
- Verify the full 88-group preflight, extracted signed artifact installation and representative MySQL conversion/rollback. Local Memcached integration and the symlink-escape fixture require their unavailable environment capabilities.

## 5.22.2 - 2026-11-11

- Reduce release-build work with build-scoped asset/import snapshots, component and translation-key selection, one JS declaration scan per source, and deduplicated minification. Fresh builds still validate all modal, popover, UI and language contracts; caches are discarded after each build, including failures.
- Add `--both` to prepare update and installation packages from one compilation. Preserve standalone commands, generated contents, signatures and reproducible ZIP metadata; optimize timestamp patching for both ZIP backends.
- Hash and package the same captured source bytes, check archive/manifest/signature writes, and publish completed artifacts from private work directories with rollback of previous output on publication failure.
- Organize documentation, tools and tests by responsibility, remove obsolete migration reports and acceptance tooling, update callers and preserve existing regression coverage. Add build-cache invalidation, ZIP backend, combined-package and publication checks.
- Ship updated shared asset/component classes and public documentation in a signed patch release. No database migration or configuration change is required; updates remain supported from 5.16.0.

## 5.22.1 - 2026-11-04

- Skip RSS items whose normalized article URL is already linked from a post by the same bot, across feeds and including queued posts. This protects against changed GUIDs and pruned GUID history before downloading images or preparing link metadata.
- Preserve GUID-based protection for items without URLs. URL protection requires an existing stored post link and does not suppress links posted only by other authors.
- No new migration or configuration change is required. Signed updates remain supported from 5.16.0.

## 5.22.0 - 2026-11-02

- Accept an optional `batch` URL parameter on hosting web-cron triggers: cleanup batches use the existing 500/1000/2500/5000 sizes, and RSS batches select 1?100 sources. Preserve default sizes and task-specific aliases.
- Validate batch values before execution, reject ambiguous aliases and unsupported diagnostic batches, and keep HEAD probes free of execution. Authentication, task locks and due intervals remain unchanged.
- No new migration or configuration change is required. Signed updates remain supported from 5.16.0.

## 5.21.0 - 2026-11-02

- Add scheduled cleanup of identical published posts from the same active bot, matching exact text, link and tag sets, and attachment metadata. Keep the lowest matching ID and permanently delete newer copies in bounded transactions.
- Preserve likes, comments and their history, reports, and notifications when merging; deduplicate reactions and notification keys without sending new events.
- Preserve old public post addresses through 301 redirects backed by the two-column `content_redirects` table. Resolve read-only post and outbound-link resources while keeping edits on deleted IDs unavailable.
- Apply migration `20261102_001_compact_content_redirects`; fresh installations include the table directly. RSS importing, source configuration and GUID history remain unchanged.

## 5.20.1 - 2026-11-02

- Fix missing RSS images for posts that use stored link previews or provider thumbnails instead of attachments. Prefer attached images and avoid invented enclosure sizes or MIME types for previews.
- Preserve media filters in author and tag RSS, page subscription links and feed self URLs. Include canonical author profile URLs in RSS.
- Share metadata preparation between HTML and client navigation, keeping social titles, locales, image alternatives and canonical URLs aligned. Remove invalid title controls and stale properties of absent images.
- Share JSON-LD post descriptions across detail, profile, tag and search pages. Include consistent author identities and types, tags, images and dates; add SearchResultsPage data and site identity on the homepage.
- Keep machine-readable dates in ISO 8601 regardless of display-format preferences. No new migration or configuration change is required; signed updates remain supported from 5.16.0.

## 5.20.0 - 2026-11-02

- Enrich public RSS with normalized tag categories, author names, safe HTML with links and line breaks, inline image alternative text, Media RSS attachments, readable titles, and channel avatars or configured site artwork.
- Correct Open Graph article authors to canonical profile URLs and export article tags. Advertise the author's RSS feed from post details and update metadata during client navigation.
- Export post text through JSON-LD `text`, include visible comments and nested replies, and provide explicit author names, types and profile URLs on profile posts. Retain link-preview images when no attached image exists.
- No new migration or configuration change is required; signed updates remain supported from 5.16.0.

## 5.19.2 - 2026-11-02

- Render hashtags as compact, muted badges. Collapse consecutive runs of five or more to three visible tags and a native disclosure with the remaining count, preserving every tag and destination.
- Separate trailing tags from post text. Keep local translation controls and output with the prose, exclude tags and disclosure counts from translation, and align the overflow badge without the browser marker.
- No new migration or configuration change is required; signed updates remain supported from 5.16.0.

## 5.19.1 - 2026-11-02

- Build scheduler polling and history URLs from an allowlisted, encoded query instead of forwarding the raw page query. Keep the API endpoint fixed and cover crafted filter values and stale responses with regression tests (CodeQL alert #15).

- Give repeated comment, embed, image and administration count links contextual accessible names. Preserve visible dates and live counts in the name, distinguish bot management from public profiles, and keep comment destinations canonical inside dialogs. Add shared label formatting and native axe-core regression checks for PHP and client rendering.

- Treat purely numeric hashtags such as `#1` and `#2026` as plain text while retaining mixed names such as `#1980s`, `#3d` and `#gothic-1`. Remove legacy numeric terms and their relationships through an idempotent migration without rewriting post or comment bodies.
- Protect URL paths, queries and fragments from mention and tag normalization, editing, tag validation and mention notifications. Align inline token boundaries between parsing, rendering and the editor.
- Cancel obsolete autocomplete reads immediately, including transitions to an empty marker. Use canonical tag values from the server instead of a second, inconsistent client transliteration, and remove the unused preload of 300 tags.

## 5.19.0 - 2026-11-01

- Load YouTube thumbnails directly from its image CDN, using small mobile and medium desktop sources in cards and exported players. Stop caching thumbnails or attaching them to imported YouTube posts; provide a backed-up cleanup for existing thumbnail paths.
- Normalize post whitespace, truncation markers and read-more/WordPress boilerplate through the same text preparation for manual posts, edits and imports. Preserve paragraph boundaries and ordinary sentences containing these words.
- Remove read-more anchor labels and preserve HTML block spacing in feed summaries. Add a CLI plan/apply/restore tool for historical text cleanup with exact backups, relation checks and transactional conflict detection.
- Apply the same cleanup when storing link descriptions and displaying cached link cards.
- Add the standalone ČSFD cinema-premieres RSS endpoint with premiere dates, posters, stable film/date IDs and month-specific caching. Standalone feeds remain outside the managed updater package.
- Preserve the 5.16.0 update baseline; no new schema migration or configuration change is required. Historical content and thumbnail cleanup remain explicit, backed-up maintenance operations.

## 5.18.1 - 2026-11-01

- Keep popovers and their rows compact in older WebKit instead of stretching to the available viewport height.
- Preserve touch activation when WebKit focuses a popover's page or modal ancestor before dispatching the click. Restore comment editing and revision history without changing the shared modal loader.
- Constrain date inputs and long selected filter values to their panels. Center the public search icon and keep the notifications page action aligned to the right.
- Preserve native searchbox semantics without unsupported aria-expanded attributes.
- Add WebKit regression checks for all popover families, focusable page and modal ancestors, comment dialogs, mobile viewport changes and long filter values. Run the older affected WebKit in CI.
- No new migration or configuration change is required; signed updates remain supported from 5.16.0.

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
- Update callers of legacy page POST URLs to the canonical endpoints in `docs/reference/api.md`. HTML pages and public feeds, media and discovery documents retain their native read-only formats.

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

- Breaking: normalize API outcomes around top-level `code`, `params` and a readable fallback `message`; replace `error.code` and string-only validation errors with structured field issues. Update external clients using `docs/reference/api.md`.
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

- Breaking: replace legacy API endpoints and HTML fragments with module-owned JSON APIs under `/api/users`, `/api/statuses`, `/api/notifications` and other domain prefixes. Integrations must use the new contract in `docs/reference/api.md`.
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
