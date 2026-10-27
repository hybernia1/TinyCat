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
CAPTCHA failures include `error.details.captcha` with `enabled`, `provider`,
`site_key`, `context` and `refresh`. No secret key is exposed.

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
| GET, PUT, DELETE | `/api/users/{id}/avatar` | Owner or administrator |
| PUT, DELETE | `/api/users/{id}/followers/me` | Signed-in user, desired follow state |
| GET | `/api/users/{id}/following` | Signed-in user, cursor list |

Public profiles contain only `id`, `username`, `bio`, `url` and `avatar_url`.
Private representations add email, preferences, role and account status. Password
hashes and recovery tokens are never resource fields. Path IDs cannot be overridden
by body IDs. User list filters retain `q`, `role`, `status`, `updated_from`,
`updated_to`, `page` and `per_page`; pagination includes `total`, `page`, `per_page`,
`last_page`, `has_prev` and `has_next`. Following uses `cursor_at` and `cursor_id`.

Administration lists accept `sort` and `direction=asc|desc`, alongside their
existing filters. Sorting happens before pagination, with an ID tie-breaker.
Unknown columns retain the list's default order. Supported sort keys are:

| List endpoint | Sort keys |
| --- | --- |
| `/api/users` | `username`, `role`, `status`, `updated_at` |
| `/api/bots/accounts` | `username`, `status`, `source_count`, `post_count`, `updated_at` |
| `/api/bots/sources` | `name`, `bot`, `status`, `interval`, `last_import` |
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
`tag`, `limit` (1–50), `cursor_at` and `cursor_id`. `meta.pagination` contains
`next_url` and `done`. Posts return author data, literal and editable body, typed
text/mention/tag/link segments, image/link/video metadata, counts and capabilities.
Details include a comment tree. Comment history contains plain `from`/`to` text.
Nested IDs and reply parents must belong to the status in the path. Scheduled
posts remain visible only to their author and administrators until publication.

Writes retain ownership, moderation, rate limits and transaction checks. Creation
returns 201; reads, updates and deletions return 200. Like/follow PUT and DELETE
request a state, so repeating an accepted request cannot toggle it back.

## Bots and administration

All routes below require an administrator session.

| Method | Path | Operation |
| --- | --- | --- |
| GET, POST | `/api/bots/accounts` | List / create bot accounts |
| GET, PATCH, DELETE | `/api/bots/accounts/{id}` | Detail / edit / delete |
| GET, POST | `/api/bots/sources` | List / create feed sources |
| PATCH, DELETE | `/api/bots/sources/{id}` | Edit / delete |
| POST | `/api/bots/sources/{id}/runs` | Run a feed import |
| GET | `/api/moderation/reports` | List moderation reports |
| PATCH | `/api/moderation/reports/{id}` | Review with `decision=remove|dismiss` |
| GET, PUT | `/api/moderation/blocked-urls` | Read / replace blocked URL rules |
| GET, PATCH | `/api/settings` | Read setting resources / update supplied settings |
| POST | `/api/scheduler/tokens` | Rotate the scheduler token |
| POST | `/api/updates/checks` | Check signed release metadata |
| POST | `/api/updates/installations` | Install the verified update |
| DELETE | `/api/updates/maintenance` | Disable maintenance after recovery |
| POST | `/api/site/cache-resets` | Reset OPcache |

Bot accounts accept `q`, `status`, `page` and `per_page`; sources accept `bot`.
Source writes accept `bot_user_id`, `name`, `feed_url`, `interval_minutes`,
`post_template` and `enabled`. An enabled-only PATCH requests an explicit boolean
state; repeating it never toggles the source back. Path IDs always take precedence.
Lists contain `data.items`; paginated account lists use `meta.pagination`.

Settings PATCH takes `{ "settings": { "site.name": "Example" } }`. Omitted
settings remain unchanged. Boolean false disables a setting. Password fields
retain their value when empty. Read resources contain `key`, `type`, `value` and
`sensitive`; sensitive values are null. SMTP credentials and raw configuration
are never returned. Blocked URL replacement takes a `blocked_urls` string.
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
