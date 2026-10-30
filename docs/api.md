# TinyCat HTTP API

Built-in modules register their own API and page routes through `App/modules.php`.
`App/Http/Api.php` only receives registrations and handles authentication, CSRF,
request parsing and JSON serialization. There is no filesystem route discovery.

All application APIs use the same JSON transport. `App/Core.php`,
`App/functions.php` and the central domain `App/Api.php` have been removed.
Modules own their routes; the entry point only composes and dispatches the app.

## JSON contract

Public HTML supports explicit language prefixes, for example `/pl/status/123`.
API routes retain their global `/api/...` addresses. Clients on a prefixed page
send `X-TinyCat-Locale: pl` to keep server messages and navigation URLs in that
interface language. The header accepts shipped locale codes and never modifies
profile preferences. `/api/site/pages?path=/pl/status/123` also accepts a prefixed
public path; its explicit language takes precedence over the header. Service URLs
and RSS remain unprefixed, and prefixed API aliases return 404.

```json
{"ok":true,"status":200,"code":"ok","params":{},"message":null,"data":{},"meta":{}}
```

All API endpoints return resource data only. `view=html`, `format=html`, HTML Accept
headers and `X-TinyCat-View` do not change their representation. Responses never
contain HTML fragments, modal markup, DOM selectors or CAPTCHA markup. A user's
literal text may contain `<` and `>`; clients must render it with DOM text APIs.
Messages appear once, in the envelope. Lists use `data.items` and `meta.pagination`.

Every outcome uses top-level `code` and `params`; `message` is a readable fallback.
Domain codes are stable API identifiers, independent of translation identifiers.
Errors add `error.details`. Validation fields live only at
`error.details.errors`, with lists of `{code, params, message}` issues. Issue params
include the field name. The former `error.code` path and string-only field issues
are replaced by this contract; bundled clients are updated together with the API.

Resources return domain states, allowed option values, counts, ISO timestamps and
content, rather than translated labels or translation keys. Shared module
components supply localized labels, icons, accessible names and date formatting
for both initial HTML and AJAX. API serialization rejects presentation fields.
Module-owned code-to-translation maps travel in external hashed assets. Clients
use their current catalog and preserve server fallbacks for unknown codes.

Administration reuses the dashboard's `ui/stat-card`, the shared
`ui/status-badge` and `ui/notice` components, and existing card, badge, alert and
grid styles. Semantic colors come from the theme variables. Keep task-specific
data in module components rather than creating parallel page-specific styles.
Resource details use `ui/modal-trigger` icon buttons and the standard modal
transport; Site health reuses the scheduler run-detail API and modal. PHP and
browser renderers consume the same component definitions.

CAPTCHA failures include `error.details.captcha` with `enabled`, `provider`,
`site_key`, `context` and `refresh`. No secret key is exposed.

## Installation and scheduled execution

All HTTP operations enter the front controller and module-owned routes. The shared
API transport parses requests, applies the selected access policy and serializes
the same envelope. Browser writes require CSRF. Scheduler automation instead uses
the `scheduler` access policy with an `Authorization: Bearer TOKEN` header; cookies,
query tokens and alternate token headers cannot replace it. The scheduler token is
created or rotated explicitly through the administrator-only token API.

| Method | Path | Access |
| --- | --- | --- |
| GET | `/api/installation` | Public safe setup status, no credentials |
| POST | `/api/installation/steps/{step}` | Public, CSRF, installation must be open |
| GET / HEAD | `/api/scheduler/triggers/{task}` | HTTPS HTTP Basic: username `scheduler`, cron token as password; GET executes one task with default options, HEAD never executes |
| GET | `/api/scheduler/health` | Scheduler Bearer token, no execution |
| POST | `/api/scheduler/executions` | Scheduler Bearer token |
| GET | `/api/site-health` | Administrator, latest diagnostic and recent history; no execution |
| POST | `/api/site-health/checks` | Administrator and CSRF, manual inspection through scheduler execution |

`site-health` is a daily scheduler task, distinct from the scheduler connectivity
probe `/api/scheduler/health`. It accepts no task options. Each completed check
records sanitized per-check outcomes (`id`, `status`, `duration_ms`) under the
scheduler result's `summary.health` and sends an in-app system notification to
each active administrator. Ordinary users, bots and inactive administrators are
excluded. Scheduled calls on the same application-local calendar day return
`not_due`; a manual check may run again. Unknown, stale and failed execution
states are distinct from healthy results. Findings use `ok`, `warning` and
`critical`; finding a problem still represents a completed inspection.

Checks also carry `metrics` and `limits` objects (empty for legacy checks).
The `http` check records `ttfb_ms` (null when unavailable), `total_ms`, `dns_ms`,
`connect_ms`, `tls_ms`, `http_status`, `bytes`, `complete` and a sanitized `error`
identifier. Timings are milliseconds. `connect_ms` and `tls_ms` are individual
setup phases; TTFB is cumulative from the start of the request. Each historical
inspection keeps its original limits; `/api/site-health` includes its HTTP
measurement under each history item's `http` field. Older runs may have no HTTP
measurement. No response body, headers, credentials or raw transport errors are
stored. `health.http` configuration controls thresholds and timeout; the target
is exclusively the canonical `app.url`, never a URL supplied by an API caller.

Scheduler findings in health reports retain per-task reason codes (`ok`, `missing`,
`stale`, `failed`, `interrupted`) under `metrics`, with maximum successful-run age
in seconds under `limits`. Historical reports retain their original findings;
reports created before this diagnostic detail was available are not reconstructed
from current scheduler state.


Installation steps are `language`, `db`, `tables` and `admin`. Each accepted step
returns `data.step` and `data.redirect`. Validation stays in the JSON envelope;
passwords and database credentials never appear in resource data. Setup routes
work before the database exists and are exempt from the normal database-readiness
gate. After installation, `/install` and the entire `/api/installation` namespace return
404 for every method, including requests with an administrator session or valid
CSRF token. The successful final step redirects directly to `/admin`. `/install`
only renders the API-backed wizard before setup is complete.

Scheduled execution accepts `task=feeds|cleanup|all` and an `options` object, for
example `{"task":"feeds","options":{"bot_limit":20}}`. Every selected task and
option is validated before any task starts. Existing locks, bounded options and
history recording are shared with manual administration and CLI. Health returns
`data.available_tasks`; execution returns `data.tasks`. Invalid input returns
400/422; failed execution returns 500 with `error.details.execution`.

`php scheduled-tasks.php` is a CLI adapter for the same execution service. It uses
the same JSON envelope, with exit codes 0 (success), 1 (execution failure) and
2 (invalid arguments). Its former HTTP endpoint only delegates to the front
controller and returns 410 `endpoint_retired`, pointing to the canonical API.

Public pages, images, RSS/XML, robots, sitemap and embeds remain registered
read-only representations through the shared router. Their established URLs,
content types, cache validators and HEAD semantics remain intact; these formats
are not embedded in JSON. CLI and development tools call module services directly
and do not implement a second set of operations.

## Users

| Method | Path | Access |
| --- | --- | --- |
| GET | `/api/users/authentication` | Public form configuration |
| POST | `/api/users/sessions` | Public, login |
| DELETE | `/api/users/sessions/current` | Signed-in user |
| POST | `/api/users/registrations` | Public, registration policy and CAPTCHA |
| POST | `/api/users/password-resets` | Public, non-enumerating acknowledgement |
| POST | `/api/users/password-resets/confirm` | Public, single-use token in body |
| GET, PATCH | `/api/users/me` | Own private profile |
| PATCH | `/api/users/me/email` | Own email |
| PUT | `/api/users/me/password` | Own account, current password required |
| GET, POST | `/api/users` | Administrator, list/create |
| GET | `/api/users/{id}` | Public profile whitelist |
| GET | `/api/users/{id}/management` | Owner or administrator |
| PATCH | `/api/users/{id}` | Owner profile; `scope=administration` requires administrator |
| DELETE | `/api/users/{id}` | Administrator, protected administrator rules apply |
| GET, PUT, PATCH, DELETE | `/api/users/{id}/avatar` | Owner or administrator |
| PUT, DELETE | `/api/users/{id}/followers/me` | Signed-in user, desired follow state |
| GET | `/api/users/{id}/following` | Signed-in user, cursor list |

Public profiles contain only `id`, `username`, `bio`, `url`, `avatar_url`,
`avatar_custom` and `avatar_appearance`.
Private representations add email, preferences, role and account status. Password
hashes and recovery tokens are never resource fields. Path IDs cannot be overridden
by body IDs. User list filters retain `q`, `role`, `status`, `updated_from`,
`updated_to`, `page` and `per_page`; pagination includes `total`, `page`, `per_page`,
`last_page`, `has_prev` and `has_next`. Following uses `cursor_at` and `cursor_id`.

Users without a custom image receive a deterministic generated cat portrait.
`PATCH /api/users/{id}/avatar` accepts `{"appearance":{"version":1,"gender":"female"}}`;
allowed genders are `neutral`, `male` and `female`. The creator also accepts `fur`,
`expression`, `accessory`, `outfit` and `background`; `auto` preserves each original generated layer.
The avatar management GET response includes `appearance_options`, with field
defaults and valid values from `App/Users/avatar-options.json`. Omitted
parameters preserve their saved values. Form submissions may send
`appearance` as a JSON string. The server validates the schema and saves the
normalized JSON in `users.avatar_appearance`. Appearance updates require owner or
administrator authorization and CSRF, and are rejected while a custom image is
active. Uploading an image preserves the stored appearance; deleting that image
restores the configured generated portrait. Generated image URLs include the
appearance variant and support immutable caching without a database lookup in
the renderer.

Administration lists accept `sort` and `direction=asc|desc`, alongside their
existing filters. Sorting happens before pagination, with an ID tie-breaker.
Unknown columns retain the list's default order. Supported sort keys are:

| List endpoint | Sort keys |
| --- | --- |
| `/api/users` | `username`, `role`, `status`, `updated_at` |
| `/api/bots/accounts` | `username`, `status`, `source_count`, `post_count`, `updated_at` |
| `/api/bots/sources` | `name`, `bot`, `status`, `interval`, `last_import` |
| `/api/bots/queue` | `bot`, `source`, `body`, `published_at` |
| `/api/scheduler/tasks` | `task`, `started_at`, `status`, `duration_ms` (run history) |
| `/api/moderation/reports` | `content`, `reason`, `status` |

## Notifications

| Method | Path | Operation |
| --- | --- | --- |
| GET | `/api/notifications` | Own inbox, `unread_count`, `latest_id` |
| PATCH | `/api/notifications` | Mark own inbox read with `read=true` |
| PATCH | `/api/notifications/{id}` | Mark own item read |
| DELETE | `/api/notifications/{id}` | Delete own item |

All routes require a session. Items contain identity, type, actor data,
excerpt, URL, read state and timestamps. Cursor pages contain up to 40 items;
`meta.pagination` contains `next_url` and `done`. Equal timestamps are ordered by ID.

## Status

| Method | Path | Operation |
| --- | --- | --- |
| GET, POST | `/api/statuses` | Read feed / create post |
| GET, PATCH, DELETE | `/api/statuses/{id}` | Detail / edit / delete |
| GET, POST | `/api/statuses/{id}/comments` | Read / create comment or reply |
| PATCH, DELETE | `/api/statuses/{id}/comments/{commentId}` | Edit / delete |
| GET | `/api/statuses/{id}/comments/{commentId}/revisions` | Authorized text history |
| PUT, DELETE | `/api/statuses/{id}/likes/me` | Desired post like state |
| PUT, DELETE | `/api/statuses/{id}/comments/{commentId}/likes/me` | Desired comment like state |
| GET | `/api/moderation/report-options` | Report reasons, signed-in user |
| POST | `/api/moderation/reports` | Report a post, signed-in user |

Feed queries accept `context=home|author|tag`, `feed=all|following`, `author_id`,
`tag`, `limit` (1–50), `cursor_at`, `cursor_id` and
`media=images|video|audio|links|text`. The media filter applies to all three
contexts, including both home feeds; omitted or unknown media types show all
content. Images require an attachment, video/audio require a matching stored
link type, links include any stored link, and text excludes attachments and
stored links. Categories can overlap. `meta.pagination` contains `next_url`
and `done`, retaining the filter. Public page navigation accepts the same
`media` parameter through `/api/site/pages`. Posts return author data, literal
and editable body, typed text/mention/tag/link segments, image/link/video
metadata, counts and capabilities.
Details include a comment tree. Comment history contains plain `from`/`to` text.
Nested IDs and reply parents must belong to the status in the path. New posts
are published immediately using the server's current publication time.

Writes retain ownership, moderation, rate limits and transaction checks. Creation
returns 201; reads, updates and deletions return 200. Like/follow PUT and DELETE
request a state, so repeating an accepted request cannot toggle it back.

Signed-in post, comment, like, report and search quotas are per account, independent
of IP address. Public email checks and failed-login CAPTCHA counters remain IP-based.
Quotas use fixed time buckets; existing hourly thresholds are unchanged. Short
cooldowns are session-based and recorded after successful mutations. Publishing,
editing and deleting have separate cooldowns, as do comment mutations; post and
comment likes share the rating cooldown. Repeating an already satisfied like state
does not consume a cooldown. A 429 response includes `Retry-After` and
`error.details.retry_after` in seconds (short cooldowns also include milliseconds).
The legacy `ip_action_limits.ip_address` column holds IP addresses or `user:<id>`
keys, so no schema migration is required. Previous IP action buckets expire through
normal cleanup and are not applied to the new account quotas. These controls cover
selected actions; they are not a global request limiter.

All rate-limit policy values live in `App/Security/RateLimitPolicy.php`. Authenticated
`admin` and `bot` roles are exempt from quotas, request budgets, autocomplete limits
and short cooldowns, and do not consume these counters. Anonymous requests have no
trusted role; submitting an administrator's username does not grant an exemption.
CAPTCHA remains an additional check for public authentication requests.

| Request budget | Scope | Attempts | Window |
|---|---|---:|---|
| Login | IP | 60 | 15 minutes |
| Registration | IP | 20 | 1 hour |
| Request password reset | IP | 20 | 15 minutes |
| Confirm password reset | IP | 30 | 15 minutes |
| Email availability | IP | 60 | 1 minute |
| Image upload (avatar, post, site settings) | Account of actor | 60 | 1 hour |
| Follow/unfollow changes | Account of actor | 120 | 1 hour |

Request budgets count attempts, including failed validation or CAPTCHA, and are
reserved atomically in the database. Rejected attempts do not increase counters
or extend the window. Upload budgets are shared across upload entry points and
checked before image decoding. Avatar deletion consumes no upload budget; already
satisfied follow/unfollow requests consume no follow budget. Content quotas still
count accepted content actions separately.

Authentication pages (`/login`, `/register`, `/recovery`, `/logout`) are GET views
only. Their forms submit to the normalized API, including logout via
`DELETE /api/users/sessions/current`. Legacy POSTs to page URLs return 405.
The same rule applies to settings,
updates, cache resets, notifications, search CAPTCHA and status/tag views. Notification
read-and-open uses `PATCH /api/notifications/{id}?open=1`, returning a safe target
in `data.redirect`; legacy `/notifications/open` links only redirect and do not
change read state.

## Bots and administration

All routes below require an administrator session.

Scheduled executions share `ok`, `status`, `count`, `changed`, `has_more`,
`skipped`, `error`, `summary` and `results`. `count` counts processed work items
(feed sources or cleanup subtasks); `changed` counts created posts or removed
records/files. History stores the prepared summary and error without deriving
them from nested results. Stored options are normalized before execution.
Manual imports of one source use the same feeds task and lock, with
`bot_limit=1` and the selected `source_id` recorded in history.

| Method | Path | Operation |
| --- | --- | --- |
| GET, POST | `/api/bots/accounts` | List / create bot accounts |
| GET, PATCH, DELETE | `/api/bots/accounts/{id}` | Account metadata and aggregate counts / edit / delete |
| PUT | `/api/bots/accounts/{id}/activation` | Set account activation with boolean `enabled`; disabling also disables its sources |
| GET | `/api/bots/accounts/{id}/runs` | Search, filter by `q`, `source`, `status` (one of the returned status choices), sort and paginate source import history |
| GET | `/api/bots/accounts/{id}/posts` | Search, sort and paginate the bot's posts, including scheduled posts |
| GET | `/api/bots/accounts/{id}/posts/{postId}/management` | Administrator payload for the shared post edit modal |
| GET, POST | `/api/bots/sources` | List / create feed sources |
| GET | `/api/bots/sources/management` | Defaults and bot choices for source creation |
| GET | `/api/bots/sources/{id}/management` | Current source fields and bot choices for editing |
| POST | `/api/bots/sources/previews` | Inspect a feed or website and preview its template; administrator and CSRF token required |
| PATCH, DELETE | `/api/bots/sources/{id}` | Edit / delete |
| POST | `/api/bots/sources/{id}/runs` | Run a feed import |
| GET | `/api/bots/queue` | Paginated future bot posts; filter by `q` (text), `bot` (account ID), `status` (account status), and `source` (RSS source ID or `unknown`) |
| POST | `/api/bots/queue/{id}/publication` | Publish immediately and return the refreshed queue; administrator and CSRF token required |
| GET | `/api/moderation/reports` | List moderation reports |
| PATCH | `/api/moderation/reports/{id}` | Review with `decision=remove|dismiss` |
| GET, PUT | `/api/moderation/blocked-urls` | Read / replace blocked URL rules |
| GET, PATCH | `/api/settings` | Read setting resources / update supplied settings |
| POST | `/api/scheduler/tokens` | Rotate the scheduler token |
| POST | `/api/updates/checks` | Check signed release metadata |
| POST | `/api/updates/installations` | Install the verified update |
| DELETE | `/api/updates/maintenance` | Disable maintenance after recovery |
| POST | `/api/site/cache-resets` | Reset OPcache |

The publication queue is available at `/admin/bots/queue`. It includes future
posts belonging to bot accounts, ordered by planned publication time by default.
Publishing requires an active bot and changes only the selected post's publication
time. Repeated requests preserve its first publication time. Other queued posts
keep their schedules. List filters, sorting, and pagination can be included in the
publication URL and are applied to the returned list.

Bot accounts accept `q`, `status`, `page` and `per_page`; sources accept `bot`.
Source resources expose `published_count`, `queued_count` and `post_count`, plus
links to their published and queued lists. Counts include only posts with a stored
source relation. A post moves between published and queued counts according to
its current publication time; deleted posts are excluded.

The bot post list accepts `q`, `source` (RSS source ID or `unknown`),
`publication` (`published` or `queued`), `page`, `per_page`, `sort` (`body`, `source` or `published_at`)
and `direction` (`asc` or `desc`). Its pagination follows `meta.pagination`.
The management endpoint verifies that the post belongs to the selected bot.
Edits and confirmed deletion use the existing status API; editing preserves the
publication time. Public status reads continue to hide future posts.
Bot post and queue resources include `source: {id, name, url}` when the source is
known, otherwise `source: null`. Unknown-source filters retain access to legacy
posts and posts whose source has been deleted; they do not infer attribution.
Source writes accept `bot_user_id`, `name`, `feed_url`, `interval_minutes`,
`publication_interval_minutes`, `post_template` and `enabled`.
Sources use one resource without a stored or manually selected type. FeedParser
handles downloading and parsing; normalized items enter the shared import path
for history, templates, attachments and publication queues. Existing API paths
and `feed_url`/`feed_hash` names remain compatible.
Supported formats are RSS (including RSS 1.0/RDF), Atom and JSON Feed 1.0/1.1,
detected from the document rather than its extension or response MIME type.
JSON Feed IDs, authors, tags, dates, HTML/text content and image attachments
normalize into the same item contract as XML feeds.

When `{{image}}` is enabled, a WordPress generator declaration in RSS/Atom may
trigger optional artwork enrichment for items without feed images. The site
URL must share the downloaded feed's origin. TinyCat verifies the site's REST
API namespace before querying up to two bounded post batches (IDs from GUIDs
or permalink slugs). API responses must match both the expected post ID, when
available, and the article URL through the existing WordPress provider. Feed
images retain priority; item GUIDs, URLs, text and publication scheduling do not
change. Verified post endpoints populate the shared link-provider cache.
Successful API discovery is cached for seven days, failed discovery for fifteen
minutes, per-article image results for five minutes and batch failures for one
minute. Unavailable or unsupported APIs leave ordinary feed processing intact.
Already imported GUIDs and templates without image attachments do not trigger
this enrichment. Previews use the same enrichment for their three sample items.
Normalized items provide a stable `guid`, `title`, `description`, `url`, `author`,
`categories`, `image_urls` (ordered candidates), `image_url` (first candidate)
and `published_at`. The parser bounds parsing to 100 items and eight image
candidates per item. Additional feed formats must preserve stable item identity and apply
equivalent bounds and URL checks before entering the shared import path.

`POST /api/bots/sources/previews` accepts `feed_url`, `name` and `post_template`.
Clients may supply `document` instead of downloading a URL (maximum 2 MiB).
The result contains `format`, `title`, the final `feed_url`, `item_count`, up to
three newest normalized `items` and advertised feed `sources` for website
discovery. Preview items expose plain `body`, `title`, `url`, local thumbnail
`image_url`, `has_image` and an optional rejection code. Thumbnails use the
existing safe local cache; attachment validation still happens during import.
Preview never creates sources, posts, GUID claims or scheduling changes.
Unsupported or unavailable input returns a standard 422 `feed_url` error.
Website discovery lists up to eight RSS/Atom/JSON Feed alternate links for
explicit selection; it does not crawl them automatically. All downloaded
documents are limited to 2 MiB and use the shared URL/redirect checks.
The admin source form gates source saves behind this preview,
invalidates confirmation when fields change, and cancels pending requests on
close. Run now starts the import directly without a preview; previews are
available in source forms. Scheduled imports continue without interaction. No source type or
database migration is needed.
Redirect destinations shown in a preview do not silently replace the saved
source URL or its history identity. Selecting an advertised feed is explicit.
Before persisting each post, import locks and rechecks the source's current
account, feed hash and enabled state. If they changed while downloading, the
post, attribution and GUID claim are rejected together and prepared attachment
files are removed. Retrying uses the current source settings.

`interval_minutes` controls source polling; `publication_interval_minutes` controls
spacing between posts (5–43200 minutes, default 30 for new and migrated sources).
Omitting publication spacing on an update preserves its current value. Each run
saves all new usable parsed items, appending to that RSS source's publication
timeline. Sources sharing a bot account have independent queues; future posts
remain hidden from public readers. Interval changes affect
subsequent imports only. Source run results include `content_ids` and
`imported_count`; scheduler `changed` counts all saved posts, including future posts.
An enabled-only PATCH requests an explicit boolean
state; repeating it never toggles the source back. Path IDs always take precedence.
Lists contain `data.items`; paginated account lists use `meta.pagination`.

Feed imports normalize RSS and Atom, including Media RSS, `content:encoded`,
Atom image enclosures and Seznam image, author and section extensions. Relative
URLs respect the downloaded feed URL and `xml:base`. Feed-provided metadata and
images take precedence; article metadata is fetched when the feed lacks usable
preview data or its image candidates fail. Only successfully decoded images are
stored as local WebP previews.

The optional template marker `{{image}}` attaches the first usable source image
as a regular post attachment; it inserts no text or remote URL. The default
template does not include it. RSS image enclosures, Media RSS and embedded images
use the existing parser candidates. Attachments share upload validation (JPEG,
PNG and WebP, source byte/dimension limits), resizing, WebP conversion and the
configured output byte limit. Fetches use the shared HTTP client with URL,
redirect and blocked-domain checks. Disabled image uploads prevent downloads.
Image-only posts are supported. If no image is supplied, text posts still import;
an otherwise empty post is skipped without claiming its GUID. Failed downloads
or invalid images are retried with later candidates; if all candidates fail, the
source run reports an error and the item remains retryable. Post, image relation,
source relation and GUID history persist atomically; failed writes remove the
prepared attachment file. Deleting a source retains its posts and publication
times but removes source attribution, as before.

Link provider rules live in `App/Status/Providers.php`: URL recognition,
normalization, oEmbed endpoints, video thumbnails and permitted player origins.
Known providers use structured metadata APIs when useful; service cards otherwise
need no remote page fetch. Unknown sites may advertise an oEmbed endpoint in
HTML; it is cached per origin for seven days. WordPress uses its discovered
single-post REST endpoint or a slug lookup with an exact canonical-link check,
requesting only title, excerpt and embedded featured media. Subdirectory and
query-based REST roots are supported. Incomplete API results are supplemented
from HTML; a successful oEmbed response alone never proves article metadata is
complete. Failed discovered APIs fall back to bounded HTML metadata.
Preview images are stored locally before saving. Responses omit legacy remote
preview images until those links are refreshed; displaying a post never fetches
provider metadata. YouTube, Vimeo and Dailymotion players still load only after
an explicit click. Provider-supplied embed HTML and scripts are never rendered.
Facebook public-post previews are extracted server-side from its official post
plugin, selecting message text and the main photo; plugin links normalize to the
original post. Login or unavailable-content pages do not become successful previews.

Link resources expose `link_type` (`web`, `video`, `audio`) separately from
`provider` (for example `wordpress`, `facebook`, `youtube` or `spotify`). An unknown
provider is stored as an empty string and returned as JSON `null`. WordPress is
identified from its declared APIs. The update migration normalizes the old
`link` type to `web` and the generic `web` provider to an empty value, preserving
link IDs and content relations.
Link resources also prepare a `card_kind` (`web`, `social`, `audio`, `video` or
`reel`) and provider label/icon for shared server/client rendering. Social and
video previews use larger local WebP variants. Facebook `/reel/{id}`, `watch?v=`,
`video.php?v=` and page `/videos/{id}` URLs identify video content; validated
video-plugin links normalize to the same source. Reels retain their portrait
layout. Players load only on click, and the original link remains available if
the provider cannot play the video. Signed/share URLs without a public video ID
remain links rather than guessing a player target.
SoundCloud tracks and playlists remain `audio` content and use its official
widget only after a click. Provider rules supply its `auto_play` option and
track/playlist height; previews and artwork remain local until playback starts.
X post metadata first uses the JSON supplied to its official embed widget,
including an attached photo or video poster, with oEmbed as a fallback. Only
media belonging to the requested post is used; avatars and quoted-post photos
are excluded. Text-only results and transient failures have separate retry
windows. Media is stored as a local WebP preview before rendering.
Each post exposes at most one preview in `links`, selecting the first stored
external URL in body order. Only that URL is hidden from body segments; other
URLs remain ordinary clickable links. Additional link records and relations
remain stored and may become the preview when the post's URL order changes.

Link identity is a SHA-256 hash of the canonical URL, recalculated at persistence.
Credentials, control characters and URLs longer than 2048 characters are rejected
instead of silently rewriting or truncating them. Generic URLs preserve scheme,
`www`, path case, trailing slashes, fragments and query order/repeated parameters.
Known tracking parameters are removed; recognized signed queries remain intact.
Provider rules additionally canonicalize supported video/Facebook URLs, X posts
by post ID, and Google Maps sharing parameters (`entry`, `g_ep`, `g_st`,
`entryfrom`, `skid`). Redirects and untrusted HTML canonical tags do not decide
database identity. Short links require a future explicit resolution policy.
Google Maps cards use place names and exact place coordinates from the URL,
never the viewport center as a substitute for a place position. Public static-map
OG previews are cached locally only when a numeric `markers` location matches
the place position within 0.0001 degrees. Repeated marker parameters and multiple
locations are supported; center and zoom do not establish place identity.
The signed image URL is kept intact. Generic descriptions, marker-free viewport
images and thumbnails from other origins are ignored. Cards with no
verified image remain text links, with no map SDK or external iframe on render.
`php tools/audit-links.php` previews existing-record changes without writes.
The update migration normalizes stored identities and merges duplicate relations
transactionally, preserving post associations and complementary cached metadata.

Account activation for regular users uses administrator-only `PUT /api/users/{id}/activation` with boolean `enabled`; protected core administrators cannot be deactivated. Source activation uses `PATCH /api/bots/sources/{id}` with boolean `enabled`. All activation writes require CSRF.

The bot account resource returns `item` with `source_count`, `active_source_count`, `post_count` and `run_count`. Sources, posts and run history are loaded through their separate list resources; the duplicate bot-account `/management` view is retired.

Settings PATCH takes `{ "settings": { "site.name": "Example" } }`. Omitted
settings remain unchanged. Boolean false disables a setting. Password fields
retain their value when empty. Read resources contain `key`, `type`, `value` and
`sensitive`; sensitive values are null. SMTP credentials and raw configuration
are never returned. Blocked URL replacement takes a `blocked_urls` string. The settings modal updates the same rules through `settings[moderation.blocked_urls]`, retaining host normalization and `domain:` rules.
Token rotation returns the new token only in its authenticated administrator response.

## Search, challenges and sidebar

| Method | Path | Access / data |
| --- | --- | --- |
| GET | `/api/search?q=...` | Public search suggestions, guarded by existing limits |
| GET | `/api/search/suggestions` | Signed-in editor suggestions; `q`, `type`, `limit` |
| POST | `/api/security/challenges/search` | Public CAPTCHA verification with CSRF |
| GET | `/api/site/sidebar` | Public tags, author resources and structured footer nodes |

CAPTCHA errors contain public widget configuration under `error.details.captcha`,
never widget markup or provider secrets. The client renders a fresh widget locally.
The sidebar footer uses allowlisted element/text nodes and safe link attributes.
Clients never parse an API response as HTML.

All former `/api/admin/*`, `/api/sidebar`, `/api/status-suggest`,
`/api/search-captcha` and fragment endpoint aliases are removed. Deploy the matching
backend and versioned assets together. An already open older page must be refreshed.

## Transport and verification

Writes, including public login, registration and recovery, require CSRF via
`X-CSRF-Token` or `_csrf`. JSON, form-encoded and multipart bodies are supported.
Multipart PUT/PATCH/DELETE use POST with a body `_method` override; a query parameter
cannot override the method. Unsupported methods return 405 with `Allow` and
`error.details.allowed`; unknown paths return 404. Internal failures return JSON
without exposing exception details in production.

AJAX stays enabled through `assets/core/http.js` and domain renderers in
`assets/modules`. Server pages remain valid initial renders. Tests exercise
the actual front controller with an isolated database, safe DOM rendering,
authentication, authorization, CSRF, state writes, pagination, history, recovery
token reuse, CAPTCHA configuration and rejection of presentation fields.

## Search

- `GET /api/search?q=...` is the public autocomplete: at least two characters,
  up to five tag and five active username prefixes. It does not search posts.
- `GET /api/search/results?q=...&type=content` returns the same collection
  as `/search`. Types are `content` (default), `users`, and `tags`. Batches use
  the configured feed limit; `meta.pagination` contains `next_url` and `done`.
  `data` contains `query`, `type`, `items`, `minimum_length`, and `valid_query`.
- `GET /api/search/suggestions?q=...&type=all&limit=8` remains the signed-in
  editor completion endpoint (`type=all|tag|user`, limit 1-12).

Queries normalize whitespace and accept at most 80 characters. Full post search
requires three characters; profiles and tags require two. Too-short queries
return an empty collection with `valid_query=false`; malformed or overlong API
parameters return 422. `%`, `_`, and `!` are literal characters, not SQL wildcards.

Post search covers the entire published history of active authors, matching all
query words in the post body or in one attached link's stored URL, title and
description. MySQL/MariaDB uses existing FULLTEXT indexes with word prefixes;
short words and punctuation use literal substring conditions. Quotes and angle
brackets separate words; a query containing only those symbols searches them
literally (subject to the same minimum query length). SQLite uses
literal substring conditions for tests. Results are deduplicated and sorted by
publication time, then ID, newest first. No unrelated posts from matching authors
or tags are added. Comments, bios and remote page bodies are not searched.

Profile and tag results rank exact matches, prefixes, then contained matches;
ties sort by name and ID. Profile bios are excerpts only. Autocomplete has a
separate session budget of 120 requests per minute and never consumes the full
search allowance. Full searches retain the account limits and guest CAPTCHA.
Continuation uses the standard feed loader and `next_url`. Posts share the feed
`cursor_at`/`cursor_id` keyset; profiles and tags use `cursor_rank`, `cursor_name`,
and `cursor_id` in rank/name/ID order. There are no numbered pages or SQL offsets.
Post items use the standard status resource consumed by the feed renderer.
Initial HTML uses the shared initial feed limit; continuation uses the configured
regular feed limit. New posts do not shift the continuation boundary.

Search also participates in `/api/site/pages?path=/search&q=...&type=...`.
Initial HTML and JSON navigation render the same public page component, preserve
the existing sidebar and navbar, and support category links, search form submits,
locale-prefixed URLs and browser history without a document reload.


## Embedding public posts (oEmbed 1.0)

TinyCat is an [oEmbed 1.0](https://oembed.com/) provider. Canonical post pages expose
JSON and XML discovery links in the document head, including after client navigation.

- `GET /oembed?url={absolute-post-url}` returns the standard top-level `rich` response.
- `format=json` (default) and `format=xml` are supported. Unsupported formats return 501.
- `maxwidth` and `maxheight` accept positive integers. Defaults are 550 × 600 pixels;
  smaller requested maxima are respected. Invalid parameters return 400.
- `GET /status/{id}/embed` is a standalone read-only HTML card suitable for an iframe.
- `GET /status/{id}/embed-code` shows the card and a selectable copy/paste HTML snippet.
  The **Embed post** action on each post opens the standard application modal with a live preview and selectable code. The page remains a fallback when JavaScript is unavailable.
- `GET /api/statuses/{id}/embed` supplies the modal with a public `frame_url` and `snippet` in the normal JSON envelope.

Only URLs on the configured installation origin are accepted, including supported
localized post URLs. The provider never fetches the supplied URL. Unknown URLs,
missing posts, future publications and inactive authors return 404, including for
signed-in administrators. These public representations have no visitor session,
analytics, comment forms or edit controls. The card shows the author avatar (including the generated cat portrait), post text, mentions,
hashtags, an attached image and up to three stored link previews. Approved video
and audio providers use click-to-load players, including YouTube, Vimeo,
Dailymotion, Facebook video, SoundCloud and Spotify. Unknown sources retain a
plain source link; remote HTML is never inserted. Preview rendering reuses the
normal post provider rules and cached metadata without outbound server fetching.
Provider players may make third-party requests after a visitor clicks them.
Consumers such as WordPress may restrict nested players through their sandbox.
Image and video previews also supply standard oEmbed `thumbnail_url` metadata
(and dimensions when known).

The iframe is responsive up to its declared width and scrolls for longer content.
The optional, small `assets/embed.js` helper in the copy/paste snippet adjusts its
height, checking both message origin and source window. Without that helper the
iframe remains usable at its declared height. The child frame also implements the
WordPress `secret`/`height`/`ready`/`link` message protocol for sandboxed discovery.
Child replies target the exact HTTP(S) parent origin, taken from the referrer
or established by a matching `ready` message from the parent window. The origin
remains pinned for the lifetime of the frame; opaque origins receive no replies.
Generated iframes use `strict-origin` referrers so initial WordPress resizing
works without disclosing the parent page path or query. If a consumer suppresses
referrers, its helper must send `ready`; otherwise the declared height remains.

In WordPress, paste the original post URL into an Embed block, or put the supplied
HTML into a Custom HTML block if your editor permits iframe markup. WordPress
policies may restrict unregistered providers or iframe/script markup; a site owner
can explicitly register their installation with `wp_oembed_add_provider()`:

```php
wp_oembed_add_provider('https://example.com/status/*', 'https://example.com/oembed');
```

See [WordPress oEmbed documentation](https://developer.wordpress.org/advanced-administration/wordpress/oembed/)
for provider policies. A public HTTPS installation is required for normal use from
other sites; a private development hostname is only suitable for local testing.

The iframe alone permits cross-origin framing. Ordinary pages and the copy-code
page retain their existing frame protections. Embeds are noindex and point to the
original canonical post. Responses use ETags and `max-age=0, must-revalidate`: visibility and edits
are checked before responding or returning 304. No persistent result cache can
keep a removed or moderated post available through this endpoint. Consumers may
cache oEmbed metadata independently; the contained iframe still revalidates its
post. HEAD requests expose the same metadata without a body.

## Content-link navigation

`GET /api/statuses/{id}/links/{hash}` and
`GET /api/statuses/{id}/comments/{commentId}/links/{hash}` resolve only links in
currently visible content. The hash uses the existing normalized link identity.
The standard JSON envelope contains `data.url` (the configured UTM destination)
and `data.original_url` (the original published URL). Arbitrary URL parameters
cannot supply or replace the destination. Removed, unpublished or unavailable
content returns the normal JSON 404 response.

Browser anchors use the same endpoint with `navigate=1`. Only this explicit mode
with an HTML Accept header returns HTTP 302; JSON requests always retain the
normal envelope, including when that flag is present. Navigation responses use
`private, no-store`, `noindex, nofollow` and `no-referrer`. There are no click logs
or new per-visitor identifiers. Canonical source URLs remain in resource `url`
fields, while `outbound_url` supplies the browser anchor destination.

Admin settings under Analytics control `content_links.utm.enabled` (default
true), `source` (default the configured website hostname), `medium` (default
`referral`) and optional `campaign`. Missing source configuration leaves URLs
untagged. Existing UTM values, repeated query keys, encoding and fragments are
preserved; metadata normalization and public feed/export source URLs are unchanged.
