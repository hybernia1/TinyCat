# Translation contracts

TinyCat uses module-owned catalogs, explicit client dependencies,
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

## Ownership and delivery

Each domain owns `App/<Module>/lang/<locale>.json`. Files keep full nested
namespaces, so historic identifiers remain stable: Status owns `account.status_*`
even though Users owns other `account.*` messages. Multiple modules can contribute
to a namespace; duplicate ownership of an individual key is rejected. Support owns
common labels, relative dates and shared action messages. Mail owns its nested
email templates in `App/Modules/Mail/lang/<locale>.json`; these never enter client catalogs.
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

`tools/assets/compile-assets.php` reads validated sources independently of any loaded
runtime catalog. It emits the same four scripts and one stylesheet per page,
including immutable hashed external message files. It also emits
`App/Core/Support/compiled/translations.php` (language metadata and a catalog index) and
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

## API and presentation boundary

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

## Checks

Run `php tools/quality/check-translations.php` for all source catalogs and current client
declarations. `tools/assets/compile-assets.php` validates sources before emitting assets;
its per-scope dependency validation rejects unknown or undeclared recognized UI keys. Preflight
also runs the standalone check. Diagnostics identify the source file and key.

`php tests/presentation/compiled-translations.php` checks the compiled runtime
without source files or a database, email isolation, source parity and build
independence from runtime translation caches.

`php tests/presentation/translation-catalogs.php` exercises invalid catalogs,
duplicate escaped names, schema, missing/extra keys, application/email parameters,
empty strings and real locale/page selections without modifying source catalogs.
