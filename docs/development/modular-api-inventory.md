# Modulární API: inventář a závazný kontrakt

Výchozí inventář etapy 1: 47 registrací API a 475 globálních funkcí.
Tabulka níže zachycuje původní stav a cílové přesuny; aktuální kontrakt je v
[`docs/api.md`](../api.md). Strojová mapa označuje převedené registrace a delegace.

Etapy 1–6 jsou implementované. `App/Http` přijímá registrace a zajišťuje JSON
transport. Moduly vlastní API i webové routy; `index.php` obsahuje sestavení,
kontroly připravenosti a dispatch. Pevný seznam je v `App/modules.php`.

| Oblast | Hlavní odpovědnosti |
| --- | --- |
| Users | Authentication, Login, Recovery, Accounts, Profiles, Following, Usernames, Roles, Avatar, Avatars, Repository, Resource, ViewData |
| Notifications | Inbox, Delivery, Resource |
| Status | Posts, Comments, Reactions, Feed, Mentions, Tags, Links, Images, ImageStorage, LinkParser, Metadata, Resource, Segments, ReadCache, ViewData |
| Bots | Importer, Administration, Actions, Api, Module |
| Moderation | Restrictions, Reports, Blocking, Resource, Actions, Api, Module |
| Search | Queries, Guard, Api, Module |
| Settings | Store, Administration, Resource, Actions, Api, Module |
| Site | Identity, Sitemap, Presentation, Api, Module |
| Mail | Templates, Smtp |
| Security | Captcha, Csrf, Images, RateLimiter, Sanitizer, Api, Module |
| Scheduler | Tokens, Cleanup, Api, Module |
| Updates | Package, Manager, MigrationRegistry, Api, Module |
| Support | Config, Values, Localization, Languages, Dates, Text, Html, View, Paths, Assets, ClientAssets, Components, Markup, Cache, Minifier, Pagination, AdminViews, RichText, Version |

### Distribuce klientských komponent a překladů

Moduly deklarují potřebné komponenty, skripty a zdroje překladových klíčů v `assets/client/*.json`. `Support/ClientAssets` podle cesty stránky vybere moduly a uzavře závislosti komponent. Veřejná část tak nenačítá administrační formuláře ani skripty botů. Globální funkce, například vyhledávání a profilové modaly, jsou součástí veřejného balíčku.

Definice komponent a katalog aktuálního jazyka se distribuují samostatnými externími JS soubory v `/cache/assets/`. Název obsahuje hash obsahu; soubory používají dlouhodobou immutable cache. Starší varianty se při vytvoření nové nemažou, aby zůstaly dostupné rozpracovaným stránkám. Balíčky obsahují pouze statické definice a texty, nikdy uživatelská data ani CSRF. HTML obsahuje odkazy na assety s `defer`, bez inline JSON katalogů. Registrace proběhne před načtením modulů a inicializací aplikace.

`Localization` sestavuje katalog s anglickým fallbackem. `TinyCat.i18n` poskytuje klientské `t`, `has`, `locale` a `format`; UI používá tento společný překladač. PHP i JS dosazují `{parametr}` a `:parametr` jedním průchodem, bez opakovaného překládání vložených hodnot. Po uložení jiného jazyka vlastního účtu se stránka znovu načte s odpovídajícím katalogem.

Původní `Core.php`, `functions.php`, centrální `Api.php`, kořenové služby a delegace
jsou odstraněné. Jejich soubory zahrnuje mazací manifest aktualizace. Publikované
migrace se nemění. Serverové stránky nadále vykreslují počáteční HTML; API a jeho
chyby obsahují výhradně data. Neexistuje `wants_partial`, `api_payload`, HTML
vyjednávání ani klientské parsování síťového HTML.

AJAX aktualizace používají společný JSON klient a DOM komponenty v
`assets/js/modules`. Formuláře zachovávají rozepsaná data při chybě, blokují dvojí
odeslání a zobrazují kanonické validační chyby u polí. Filtry účtů i zdrojů botů
zachovávají browser history a lokální modální formuláře.

Etapa 7 zůstává samostatnou kontrolou a vydáním. Průběžný preflight zahrnuje 25
skupin testů, HTTP kontrakty, limity dotazů, podepsaný artefakt, opakovaný update,
přerušení aktualizace, přesný rollback a instalátor na oddělené MySQL databázi.
Browserové kontroly této etapy používají oddělenou SQLite databázi. Běžná lokální
databáze nebyla změněná; chybí v ní tabulky botů. Před vydáním ověřit také upgrade
aktuální produkční verze a úplnou browserovou regresi z hotového artefaktu.

Následující tabulka je historická mapa původních URL, nikoli seznam existujících
aliasů. Aktuální podporované adresy jsou v `docs/api.md`.

## Mapa endpointů

| Současná metoda a cesta | Vlastník | Cílový kontrakt | Vstup | Data pro UI |
| --- | --- | --- | --- | --- |
| GET `/api/search` | Search | GET /api/search | q, type, limit | items, groups |
| GET `/api/status-suggest` | Search | GET /api/search/suggestions | q, type, limit | users, tags |
| POST `/api/search-captcha` | Security | POST /api/security/challenges/search | captcha response | verified, challenge configuration |
| GET `/api/auth-modal` | Users | GET /api/users/authentication | mode, next | registration policy, public CAPTCHA configuration |
| POST `/api/auth/login` | Users | POST /api/users/sessions | username, password, remember | session user |
| POST `/api/auth/register` | Users | POST /api/users/registrations | username, password, email, consents | account, approval status |
| POST `/api/auth/logout` | Users | DELETE /api/users/sessions/current | none | logged_out |
| POST `/api/author/follow` | Users | PUT/DELETE /api/users/{id}/followers/me | id from path; requested state from method | following, followers_count |
| POST `/api/profile/update` | Users | PATCH /api/users/me; PATCH /api/users/{id} | profile fields | profile resource |
| POST `/api/profile/email` | Users | PATCH /api/users/me/email | email, current password where required | email state |
| POST `/api/profile/password` | Users | PUT /api/users/me/password | current_password, password, confirmation | password_changed |
| POST `/api/avatar/update` | Users | PUT/DELETE /api/users/{id}/avatar | multipart image; id from path | avatar_url, updated_at |
| POST `/api/status/{action:create&#124;react&#124;like&#124;comment&#124;comment-like&#124;comment-update&#124;comment-delete&#124;report&#124;update&#124;delete}` | Status | POST /api/statuses; PATCH/DELETE /api/statuses/{id}; /api/statuses/{id}/comments; /api/statuses/{id}/likes/me; /api/moderation/reports | body, images, comment content, path IDs | status/comment resource, reaction state or report receipt |
| GET `/api/notifications` | Notifications | GET /api/notifications | cursor, limit, unread filter | items, unread_count, pagination |
| GET `/api/notifications-page` | Notifications | GET /api/notifications | cursor, limit | items, unread_count, pagination |
| POST `/api/notifications/{action:read&#124;read-all&#124;delete}` | Notifications | PATCH/DELETE /api/notifications/{id}; PATCH /api/notifications | read=true; own inbox scope | notification or bulk read result |
| GET `/api/home-feed` | Status | GET /api/statuses | feed, cursor, limit | items, pagination |
| GET `/api/sidebar` | Site | GET /api/site/sidebar | context | people, tags, counters |
| GET `/api/status-feed` | Status | GET /api/statuses | author_id, tag, cursor, limit | items, pagination |
| GET `/api/status-card` | Status | GET /api/statuses/{id} | path id | status resource |
| GET `/api/status-modal` | Status | GET /api/statuses/{id} | path id | status resource, comments, capabilities |
| GET `/api/status-report-modal` | Moderation | GET /api/moderation/report-options | subject ID/type | reason options and capabilities |
| GET `/api/status-edit-modal` | Status | GET /api/statuses/{id} | path id | editable text, attachments, capabilities |
| GET `/api/status-comment-edit-modal` | Status | GET /api/statuses/{id}/comments/{commentId} | path IDs | comment, editable text, capabilities |
| GET `/api/status-comment-history-modal` | Status | GET /api/statuses/{id}/comments/{commentId}/revisions | path IDs | revision items |
| GET `/api/profile-edit-modal` | Users | GET /api/users/me; GET /api/users/{id}/management | path id, actor policy | editable profile and form options |
| GET `/api/avatar-edit-modal` | Users | GET /api/users/{id}/avatar | path id | avatar_url, upload limits, capabilities |
| GET `/api/author/following` | Users | GET /api/users/{id}/following | path id, cursor, limit | items, pagination |
| GET `/api/admin/users` | Users | GET /api/users | filters, page, per_page | items, pagination, stats, allowed roles/statuses |
| POST `/api/admin/users` | Users | POST /api/users | account fields | private management resource |
| PATCH `/api/admin/users` | Users | PATCH /api/users/{id} | path id, account fields | private management resource |
| DELETE `/api/admin/users` | Users | DELETE /api/users/{id} | path id | id, deleted |
| POST `/api/admin/settings` | Settings | PATCH /api/settings | settings, multipart site images | saved settings; secrets excluded |
| POST `/api/admin/cron-token` | Scheduler | POST /api/scheduler/tokens | none | rotation result; token admin-only |
| GET `/api/admin/moderation/reports` | Moderation | GET /api/moderation/reports | filters, page | items, pagination |
| POST `/api/admin/moderation/reports` | Moderation | PATCH /api/moderation/reports/{id} | path id, decision | report and affected status state |
| GET `/api/admin/moderation/blocking` | Moderation | GET /api/moderation/blocked-urls | none | items |
| POST `/api/admin/moderation/blocking` | Moderation | PUT /api/moderation/blocked-urls | URL rules | items |
| GET `/api/admin/bot-accounts` | Bots | GET /api/bots/accounts | filters, page | items, pagination |
| POST `/api/admin/bot-accounts` | Bots | POST /api/bots/accounts | username, status | bot account resource |
| PATCH `/api/admin/bot-accounts` | Bots | PATCH /api/bots/accounts/{id} | path id, account fields | bot account resource |
| DELETE `/api/admin/bot-accounts` | Bots | DELETE /api/bots/accounts/{id} | path id | id, deleted |
| GET `/api/admin/bots` | Bots | GET /api/bots/sources | bot_id, filters | items |
| POST `/api/admin/bots` | Bots | POST /api/bots/sources | bot_id, source configuration | source resource |
| PATCH `/api/admin/bots` | Bots | PATCH /api/bots/sources/{id} | path id, source configuration | source resource |
| DELETE `/api/admin/bots` | Bots | DELETE /api/bots/sources/{id} | path id | id, deleted |
| POST `/api/admin/bots/{action:run&#124;toggle}` | Bots | POST /api/bots/sources/{id}/runs; PATCH /api/bots/sources/{id} | path id; enabled for state change | run resource or source resource |

## Přenos a UI

- Všechny moduly jsou převedené společně se svými AJAX konzumenty.
- Každý řádek má ve strojové mapě konkrétního vlastníka, současný handler, budoucí vstup, odpověď a vazby na šablony/JS.
- Resource vybírá veřejná, vlastní a administrační pole výslovně. Veřejný profil neobsahuje email, heslo, recovery/remember tokeny ani administrační stav.
- API odpověď: ok, status, message, data, meta; chyby mají error.code a error.details.errors. Nové API nemá top-level errors alias.
- Stránkované seznamy: data.items a meta.pagination; cursor pro feed, page/per_page/total pro administrační seznamy. Žádné skryté COUNT(*) navíc u feedu.
- POST vytvoří zdroj (201); PATCH/PUT aktualizuje (200); DELETE vrátí id a deleted (200). Likes a follows přijímají cílový stav, nikoli toggle.
- Serverové webové stránky smějí používat ViewData a šablony. API handler nesmí volat render, part, layout ani vracet HTML pole.
- Modály vznikají v klientovi z resource dat a capabilities nebo z počátečního serverového vykreslení. Samostatné fragmentové /views endpointy jsou odstraněné.
- CAPTCHA vrací veřejnou konfiguraci a stav widgetu; chybové odpovědi neobsahují captcha_html.
- FormData zůstává pro nahrávání; PATCH/PUT/DELETE multipart používá POST + _method, dokud není k dispozici spolehlivý parser stejného kontraktu.
- Běžný text s doslovným < nebo > je data a v DOM se vkládá přes textContent.

## Inventář přesunů funkcí

Historická mapovací data: `tests/fixtures/module-migration.json`. Soubor eviduje původní funkce, cílové implementace a odstraněné adaptéry. Následující počty popisují výchozí inventář.

- Support: 41 funkcí.
- Settings: 9 funkcí.
- Database: 19 funkcí.
- Site: 15 funkcí.
- Status: 169 funkcí.
- Users: 105 funkcí.
- Support/ViewData: 14 funkcí.
- Security: 11 funkcí.
- Mail: 10 funkcí.
- Moderation: 26 funkcí.
- Search: 21 funkcí.
- Scheduler: 10 funkcí.
- Http: 25 funkcí.

## Hranice dokončené infrastruktury

- Implementace HTTP, DB, session a CSRF mají samostatné vlastníky. Původní Core a globální delegace jsou odstraněné.
- Autentizaci vlastní Users, nastavení Settings a vykreslování Support/View. Http/Api přijímá resolver aktuálního uživatele; neimportuje Users ani původní Core.
- PDO a dotazy vlastní Database/Connection. Testovací fixture při výměně spojení výslovně resetují Authentication, Store a Localization.
- JSON-only kontrola platí pro všechny API odpovědi včetně chyb; neexistuje vypínač pro původní HTML kontrakt.
- App/modules.php obsahuje pevný seznam vestavěných modulů. Třídy vznikají podle skutečných odpovědností.
- Regresní brány: HTTP kontrakty, chyby/oprávnění/CSRF, no-HTML v novém API, JS transport, nested transakce, query budgets, instalace a aktualizace.
- Testy hranic kontrolují závislosti infrastruktury, vlastní registrace modulů, absenci původních centrálních souborů a zákaz renderování šablon v API.

## Další rozhodnutí dokončená inventářem

- API pro reset hesla, CAPTCHA a administraci aktualizací má výše uvedené vlastníky a cílové prefixy; nebudou skryté ve webových stránkách.
- /api/users/{id} je veřejný profil, /api/users/me vlastní účet a /api/users/{id}/management soukromá administrační reprezentace.
- Změny profilů a avatarů ověřují aktéra i cílový účet. Path ID má přednost a body ID ho nesmí přepsat.
- POST /api/moderation/reports přijímá nový report; PATCH /api/moderation/reports/{id} řeší rozhodnutí moderátora.
- Změna endpointů a přepojení UI se vydají společně až po dokončení etap, nikoli jako nefunkční meziverze.
