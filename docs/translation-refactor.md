# Translation contracts and modularization

The completed refactor provides module-owned catalogs, explicit client dependencies,
compiled delivery, shared formatting and the separation of API data from presentation.

## Source contract

- English is the reference catalog. Every shipped locale must contain the same
  keys and parameter names. Runtime English fallback remains a resilience measure;
  it must not hide incomplete translations during a build.
- Catalogs are nested JSON objects with nonempty namespaces and string leaves.
  Empty strings are valid translations. Member names use lowercase snake_case,
  beginning with a letter. Dots separate namespaces in references, never in JSON
  member names. Duplicate members, including escaped equivalents, are errors.
- Existing translation identifiers remain stable. Their prefixes are semantic
  namespaces, not HTTP response field names or a requirement to rename modules.
- Application and email messages use `{name}` with one literal substitution pass.
  `Localization::format()` serves both PHP callers; the browser follows the same
  contract. Colon parameters and double-brace email interpolation are removed.
  Double braces in RSS placeholder documentation are literal examples, not message
  parameters; stored RSS source templates retain their separate feed format.
- Parameter order and repetition may differ across locales; parameter names may
  not. Values are substituted once, as data. Output escaping belongs to the
  destination (HTML text, attributes, email, etc.), not the catalog.
- Count messages use complete `one`, `few`, `many`, `other` objects, with explicit
  `Localization::plural()` / `i18n.plural()` calls or component `plural` expressions.
  Rules cover all twelve shipped locales, negative counts and fractions.
  A new language with different plural rules needs a matching rule and parity cases.
  Relative-time families also contain `single` for exactly one unit (such as
  yesterday); numeric `one` retains `{count}` because this category can include
  21, 101 or zero depending on the language.

The shipped locales are `cs`, `en`, `de`, `es`, `fr`, `pt`, `ru`, `it`, `nl`,
`pl`, `tr` and `uk`. The ten added catalogs start from machine translation with
reviewed core labels and explicit count/time inflections; native-language editorial
review is still recommended. No external translation service is called at runtime.
Generic Portuguese uses Brazilian-style wording; regional browser preferences fall
back to `pt`, while explicit `pt-PT` plural selection follows European Portuguese.

Public HTML routes support explicit locale prefixes (`/pl/`, `/pl/status/123`,
`/pl/privacy`, `/pl/login`, etc.). The URL takes precedence over account and browser
preferences without changing the saved profile language. Bare locale roots redirect
once to a trailing slash, retaining query parameters. Unknown languages and prefixed
administration, API, asset and RSS routes return 404. These services retain their
existing global URLs.

`LocaleRouting` owns the public route policy. Shared renderers and redirects use an
injected `LocalUrls` resolver; templates receive prepared URLs. AJAX sends
`X-TinyCat-Locale` for a prefixed interface, and the public-page API also accepts an
explicit prefixed `path`. Cross-language navigation reloads the document/catalog;
same-language public navigation retains its existing partial rendering. Anonymous
visitors get a navbar flag menu with native language names and ordinary links,
retaining relevant feed/search/form parameters. Signed-in users manage preferences
in their profile. When an anonymous visitor's preferred supported browser language
differs from the interface, a banner offers the equivalent page in that language;
it never redirects automatically. Its text is written in the suggested language,
and dismissal lasts for that interface/preferred-language pair in sessionStorage.
Unavailable storage does not prevent dismissal. HTML varies on Accept-Language.

All localized HTML advertises its actual `lang` and `Content-Language`. Genuine
translated policy pages use self-canonicals, reciprocal `hreflang` (including
`x-default`) and sitemap entries. The unprefixed policy is a stable default-site-
language fallback. Feed, profile, tag and post UI variants consolidate under their
original canonical URLs and structured-data identities because their primary user
content is unchanged. Shared tag caches store language-neutral URLs.

Optional browser-local post translation targets the selected interface language;
it is offered only when the browser, language detector and language pair support
it. It does not create an indexable server translation. See Google's guidance:
https://developers.google.com/search/docs/specialty/international/managing-multi-regional-sites

## Ownership and delivery target

Each domain owns `App/<Module>/lang/<locale>.json`. Files keep full nested
namespaces, so historic identifiers remain stable: Status owns `account.status_*`
even though Users owns other `account.*` messages. Multiple modules can contribute
to a namespace; duplicate ownership of an individual key is rejected. Support owns
common labels, relative dates and shared action messages. Mail owns its nested
email templates in `App/Mail/lang/<locale>.json`; these never enter client catalogs.
Settings owns installation messages. `lang/<locale>.json` contains only the native
language `name`, which declares the set of complete shipped locales.

Every source-owning module supplies every declared locale. The build checks parity
within each module before merging, so moving a key in only one language fails.
There is no central registry mapping domains to namespaces.

JavaScript dependencies are explicit `translations` lists in existing module
manifests. Shared controls declare their common messages in `assets/ui/module.json`;
feature modules do not repeat those shared declarations. Component definitions
contribute static `t` references transitively through their selected components.
For example, a module can declare `"translations": ["users.edit_user"]`; an empty
list is valid. Declare every possible key for computed lookups explicitly.

The selector no longer scans JavaScript or applies prefix filters. A separate
build check recognizes literal UI calls and rejects undeclared messages; it never
adds keys to a bundle. This check cannot prove all computed key paths or arbitrary
aliases, so explicit declarations remain the contract. Unknown declarations fail
even if their manifest currently has no matching page scope.

`tools/compile-assets.php` reads validated sources independently of any loaded
runtime catalog. It emits the same four scripts and one stylesheet per page,
including immutable hashed external message files. It also emits
`App/Support/compiled/translations.php` (language metadata and a catalog index) and
one content-hashed PHP array per locale. Application lookups and language discovery
use those PHP arrays; email templates load the compiled server-only section.
OPcache can cache these arrays without runtime JSON parsing or source discovery.
The signed package includes both compiled outputs and modular source files.

Development checkouts without compiled catalogs read module sources. Localization
memoizes parsed and flattened catalogs within a request. Generated server catalogs
are ignored by Git and excluded from source collection during package builds,
just like generated client assets, so an older generated file cannot contaminate a
new artifact. The updater deletes the four retired monolithic JSON files.

Non-count translation identifiers remain stable. Count families now have explicit
plural variants. The public client catalog
adds three explicit Users dependencies (`notifications.load_more`, `users.edit_user`,
`users.new_user`) referenced in its shared views script; the old prefix filter
excluded them. Other existing page catalogs keep their exact keys and values.
Existing per-scope delivery budgets still pass.

## API boundary target

API resources expose domain codes, counts, timestamps, content and permissions.
Module presentation maps domain codes to translation identifiers; API responses
must not carry component definitions, translation catalogs or translation keys.
Server-rendered views and AJAX-rendered views use equivalent presentation rules.
Keep AJAX, JSON responses, user-generated text, URLs and security decisions.

Errors will carry stable domain `code`, `params` and field errors. Keep a readable
fallback message for other consumers. A code is an API contract, not a catalog
identifier. Success notices need equivalent stable event codes. These changes
belong to block 3, including updates to clients and HTTP contract tests.

## Migration inventory

| Producer | Current presentation fields | Consumers and migration requirement |
| --- | --- | --- |
| `App/Users/Resource.php`, `Accounts.php` | `role_label`, `status_label`, translated roles/statuses and `role_options`/`status_options`, `options.username_hint` | Users component definitions and `users/views.js`; map role/status codes locally, translate hints from shared validation constraints. Preserve allowed option values and protected-role rules. |
| `App/Bots/Resource.php` | Account `status_label`, `updated_label`, translated status options; run `status_label`, `started_label` | Bots components and views; provide raw status and timestamps, preserve run-status aliases and account permissions. |
| `App/Status/Resource.php` | `published_label`, `published_local`, `created_label`, `permalink_label`, `like_label`, `unlike_label` | Status components, editor and thread views; supply context data for accessible names, format dates consistently with server views and preserve timezone semantics of edit inputs. Image alt text and link titles can be content, so must not be removed merely because they are strings. |
| `App/Moderation/Resource.php`, report-options endpoint | `reason_label`, `report_count_label`, `status_label`, `reported_at_label`, `permalink_label`, translated reason options | Moderation components and status report dialog; expose reason code, count, timestamp and required context. Preserve the distinction between report status and displayed content status. |
| `App/Notifications/Resource.php`, inbox state | `message`, `created_label`, localized unread notice | Notification components/views and toast logic; return event type, actor/subject data, counts and timestamp. Preserve privacy filtering and readable fallbacks for unknown event types. |
| Search result producers | `published_label` and potentially localized excerpts/type labels | `search/controller.js`; distinguish content excerpts from generated labels and migrate timestamp formatting with the shared presenter. |
| `App/Http/Response.php`, `Result.php`, module APIs/actions | Localized `message` and validation error strings | Core API/forms/toasts and module handlers; migrate code/params/field errors together, including success notices. Never use human-readable text for program logic. |

The Users account builder also emits `created_at_formatted` and
`updated_at_formatted`; migrate these together with its existing raw/ISO dates.
Notifications additionally select an `icon` server-side; move the icon mapping
beside the event-type presentation. Inspect shared pagination `navigation` and
Bots administration payload builders during block 3: nested presentation data is
part of the boundary even when it is not produced by a `Resource` class.
In particular, `App/Bots/Administration.php` sends `stats[].label` translation
identifiers such as `bots.detail_stat_sources` alongside `key` and `value`.
Move these identifiers to the Bots client mapping and retain the semantic stat
keys/values in JSON. This is an actual translation-key leak, distinct from the
already translated `*_label` fields above.

Language package native names, user content, external metadata and real server
constraints are data. Their presence in JSON is not itself a translation leak.
This inventory defines migration families; block 3 must trace nested resource
builders and handlers as well as the named entry points before removing fields.

## Checks

Run `php tools/check-translations.php` for all source catalogs and current client
declarations. `tools/compile-assets.php` validates sources before emitting assets;
its per-scope dependency validation rejects unknown or undeclared recognized UI keys. Preflight
also runs the standalone check. Diagnostics identify the source file and key.

`php tests/presentation/compiled-translations.php` checks the compiled runtime
without source files or a database, email isolation, source parity and build
independence from runtime translation caches.

`php tests/presentation/translation-catalogs.php` exercises invalid catalogs,
duplicate escaped names, schema, missing/extra keys, application/email parameters,
empty strings and real locale/page selections without modifying source catalogs.

## API contract implemented in block 3

Every outcome has a top-level `code`, `params` object and readable fallback
`message`, alongside `ok`, `status`, `data` and `meta`. Errors retain contextual
`error.details`. The old `error.code` path is replaced by top-level `code`;
external consumers must adopt that path. Field issues are lists of
`{code, params, message}` descriptors under `error.details.errors[field]`.
Their params include the field name. Domain checks use specific codes; reusable
legacy string validators receive the stable `invalid_value` code with their
readable fallback. Rule-based API validation emits `validation_<rule>` codes.

Module manifests own `messages` maps from domain codes to translation identifiers.
These maps travel with external component definitions, never in API responses.
The page catalog includes the referenced messages. The API client applies the
current client locale and parameters and preserves readable fallbacks for unknown
codes; forms consume localized field messages through the shared API helper.
Actions preserve human-readable messages for server-side consumers while Result
moves the machine code and parameters into the response envelope.

Resources for users, bots, reports, notifications, statuses and search expose raw
codes, allowed option values, counts, ISO timestamps and content context. API
serialization rejects `*_label`, `*_formatted` and reserved UI-definition fields.
Capabilities, safe media/author URLs, content excerpts and server validation
constraints remain data. Bot statistic entries contain only semantic keys and
values. Status media descriptions and notification actor/event data are translated
by components, with content rendered as literal text.

Module component definitions own `lookup` and `options` cases and translated
accessible names. Reusable `use` expressions share names between status cards and
moderation views. PHP and JavaScript evaluate the same definitions. Old server
label builders and notification presentation preparation have been removed.
The small client date formatter supports the configured date/time presets and
existing relative-time rules; ISO offsets preserve site calendar fields across
browser timezones. The HTML shell provides only the date format and relative-time
setting. Date code and its catalog dependencies load on pages that use it, so the
installer and unrelated administration pages do not receive them.

Checks cover every shipped date/time preset, both DST offsets, relative-time
thresholds in Czech and English, known/unknown message codes, field issues and
server/client component parity. HTTP tests validate every outcome's code/params,
each returned field issue and rejection of presentation fields. Client delivery
keeps four scripts and one stylesheet. Moderation's raw-JS budget increases from
75 to 78 KiB and Bots from 94 to 96 KiB for shared presentation and plural variants.
Every compressed-JS budget remains unchanged, as do all other page budgets.

## Block status

1. Rules, API inventory and build validation: complete.
2. Module-owned sources, explicit dependencies and compiled catalogs: complete.
3. API data/presentation boundary and structured outcomes: complete.
4. Unified interpolation/fallback and shipped-locale plural rules: complete.
5. Language/cache/browser audit and release preflight: verified before completion.

## Final fixes and verification

The shared `stack` layout now lays out children vertically and honors hidden tab
panels. Three deliberate CSS rules were added to the reviewed cascade baseline;
every prior rule remains in the same order. Login and profile forms share this fix.
Remote dialog replacement checks whether the initiating control still exists before
removing the old dialog, so login/register switches no longer close their own new
dialog. Cancellation of a removed control remains supported. Browser verification
covers the profile email panel, RSS validation text and the open registration modal.

Incorrect question-mark characters in Czech validation text and five bot run labels
were corrected at the source. Catalog checks flag suspicious Czech encoding markers
and incomplete plural groups. Application and mail fallback both merge individual
keys from English through base language to the requested regional locale; empty
localized strings remain intentional. Client locale changes clear prior catalogs,
and changing the current account's language reloads its document and hashed assets.

PHP/JavaScript parity cases cover seven count families, all twelve shipped languages
and six regional locales, as well as date presets, DST offsets and relative-time
thresholds. Runtime checks exercise compiled catalogs without sources or a database,
including regional plural fallback. Build checks ignore stale runtime translation
caches. No new production dependency, inline catalog or additional HTTP request is
introduced.

Measured delivery after the refactor (largest locale, minified JS, gzip):

| Scope | JS bytes after gzip | Scripts / stylesheets |
| --- | ---: | --- |
| Public | 51455 | 4 / 1 |
| Users administration | 27741 | 4 / 1 |
| Bots administration | 24182 | 4 / 1 |
| Moderation | 20448 | 4 / 1 |
| Installer | 8958 | 4 / 1 |

After the twelve-language expansion and explicit locale routing, the largest
per-scope JS deliveries (raw / gzip bytes) are 286893 / 60703 for public pages,
132784 / 30841 for users, 117141 / 26784 for bots, 101609 / 24263 for cron,
88403 / 21857 for moderation, 74751 / 19899 for settings, 70574 / 18771 for the
dashboard and 32597 / 9966 for installation. Each page still loads one locale catalog, four scripts and one
stylesheet. The reviewed JS budgets retain approximately 15% headroom rounded up
to KiB; CSS and request-count budgets are unchanged.

Final verification uses `php tools/preflight.php`, including source validation,
PHP syntax, PHPStan, repository security, behavior/HTTP/frontend tests, signed
artifact checks, update/recovery/rollback and a disposable MySQL installer rehearsal.
