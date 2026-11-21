# Testy TinyCat

Příkazy spouštěj z kořene repozitáře. `composer test` spouští explicitních
92 skupin přes [hlavní runner](run.php), včetně 40 frontendových scénářů.
`composer preflight` navíc přidává metadata, dependency audit, styl, lint,
PHPStan, překlady a repository security.

Pro výběr kontrol, pořadí cílených běhů, závěrečný preflight a postup po
selhání platí [postup testování během práce](../AGENTS.md#postup-testování-během-práce).
Závěrečný běh vyžaduje stabilní zdroje i vůči jiným vláknům; úpravy během
preflightu mohou znehodnotit výsledek a vyvolat zdánlivou chybu determinismu balíčků.

Runnery měří každou PHP skupinu, JS scénář a preflight krok. Výsledek je
v `.work/tests/runs/<run-id>/report.json`, průběžné události v `events.jsonl`
a výstupy v samostatných logách. Report obsahuje verze prostředí, otisk
zdrojů, časy, exit kódy, přeskoky i neprovedené položky. Čas rodičovské
skupiny zahrnuje její potomky; při součtu se nesmí započítat dvakrát.
`phase_timings` navíc obsahuje operace uvnitř skupiny. Podepsaný lifecycle
zaznamenává jednotlivé buildy, CLI a recovery workers bez ukládání jejich stdin
nebo privátních argumentů. Ani tyto časy se nepřičítají k času rodičovské skupiny.

`php tests/run.php --list` a `php tests/frontend/run.php --list` vypíšou ID.
Cílený běh používá `--only=ID1,ID2`, například
`php tests/run.php --only=tests/release/database-snapshots.php` nebo
`php tests/frontend/run.php --only=embed.cjs`. Zúžený běh není plný preflight.
Stejný výpis a výběr poskytuje `php tools/preflight.php --list` a
`php tools/preflight.php --only=preflight/phpstan-level-8`.
Výchozí rozsah je úplný release průchod aktuálního runtime a podporovaných
aktualizací. Výpis navíc označuje ruční kontrolu archivního reportu:
`php tests/run.php --only=tests/performance/performance-baseline.php`.
Ta ověřuje pouze zachované hodnoty ve statickém reportu 2.0.25, nikoli aktuální
aplikaci, proto není součástí `composer test` ani preflightu. Původní ID,
testovací soubor a historická data zůstávají dostupné. Report ji eviduje jako
nevybranou položku (`selected: false`), nikoli provedenou kontrolu.

PHP lint dávkuje stejné soubory po 16. Klientská assetová fixture sdílí
neměnný build jen uvnitř jednoho běhu; každý scénář má vlastní VM. Update
artefakt ověřuje determinismus proti nezávislému kombinovanému buildu,
fresh artefakt dál používá dva buildy. [Naměřené výsledky a omezení](../docs/audits/test-optimization-and-local-cleanup-2026-11-12.md).

Dotyková popover matice zachovává 21 variant ve třech šířkách a dvou površích
(126 kombinací). Používá tři browserové kontexty podle viewportu. Každý scénář
načte nový dokument, který resetuje JavaScript a event listenery; před fixture
skripty se vyčistí local/session storage a před navigací cookies. Kontrola
čistého dokumentu odhalí únik stavu. Animace, dotykové ovládání, šablony a
všechny kombinace zůstávají součástí běhu.

Lifecycle modulů sestavuje u čtyř hlavních verzí pouze využité varianty:
první install a tři update ZIPy. Nevyrábí jejich čtyři nepoužité protějšky.
Samostatné balíčkové testy dál ověřují determinismus a `--both`; lifecycle
zachovává instalaci, aktivaci, chybné balíčky, cached provider, rollback i přerušení.
MySQL fixture numerických tagů připravuje stejných 502 záznamů dvěma prepared
statements v jedné transakci. Samotná migrace a její transakční scénáře se nemění.

Tyto změny přípravy testů zatím nemají nové měření doby běhu. Dosavadní reporty
ze 17. 11. 2026 ukazují 228 s pro lifecycle, 196 s pro frontend (z toho 90 s
dotyková matice), 130 s update/rollback, 86 s API a 63 s izolovaný Blog.
Jde o dva navazující běhy po opravě testu, nikoli kontrolovaný benchmark nové sady.

Samostatný modul účtů ověřuje `php tests/domains/accounts/module.php`: instalaci,
start, API, HTML a build v kopii bez Users, Status a Bots.
Stejná fixture ověřuje také core schránku, obecný neprůhledný předmět, health
upozornění, fallback po vypnutí vlastníka a zachování dat při payload migraci.
Čisté core (8 tabulek) a doménové úložiště Socialu ověřuje
`php tests/domains/publishing/module.php` v izolované kopii bez Blogu. `social-boundaries.php` ve stejné složce
ověřuje sledování, sociální projekce a transakční úklid bez Notifications.
Blog ověřuje `php tests/domains/blog/module.php` v kopii bez sociálních zdrojů:
fresh instalaci, modulovou migraci, skutečný HTTP vstup, API, editor, CSRF,
locale a assety. Nad stejnou databází pak připojí Social a technické adaptéry:
ověří samostatná schémata, oddělené druhy publikací, kořen a administraci,
vypnutí/obnovení obou funkcí přes skutečné CLI a zachování dat, nastavení
i šablon. `php tests/domains/blog/mysql.php` ověřuje MySQL DDL, dlouhé
UTF-8 články, rollback, FK a obnovu migrace v samostatné dočasné databázi.

`php tests/release/module-lifecycle.php` sestaví podepsaný Blog a volitelné
manifestové moduly; ověřuje fresh CLI, HTTP zdroje, aktivaci, neaktivní
upgrady, starého načteného poskytovatele, chybu migrace, přerušení a rollback.
`php tests/release/database-snapshots.php` ověřuje SQLite/MySQL obnovu dat,
binárních hodnot, generovaných sloupců, FK, views a triggerů.

## Prostředí

Nezávislé cílené testy lze lokálně spouštět současně, nejvýše čtyři procesy
včetně vnořených workerů. Každý běh musí mít vlastní DB, cache, pracovní
adresář a report; HTTP workers také nekolidující porty. Nepředávej více
samostatným běhům společné `TINYCAT_TEST_REPORT` ani `TINYCAT_CACHE_DIRECTORY`.
Kontroly sdílející zapisované assety, balíčky či instalaci spouštěj postupně.
Runnery samy nadále zachovávají své pořadí; více úplných preflightů současně
nespouštěj. Výsledky každého cíleného běhu vyhodnoť samostatně.

Živá instalace pro běžné ruční a browserové ověření je **https://tinycat.test/**
v `E:/laragon/www/tinycat`, mimo tento checkout, nad existujícím Laragonem.
Změnu zdrojů před živým ověřením nejprve vědomě synchronizuj do této instalace;
nepřepisuj její konfiguraci, storage ani média. Ignorovaný checkout `config.php`
slouží jen CLI/testům a načítá údaje z externí konfigurace.
Nezakládej pro ni vlastní Apache/MySQL ani náhradní
server. MySQL fixtures používají stejnou službu, ale vlastní dočasné DB;
izolované HTTP workers vytvářejí jen konkrétní runnery podle potřeby scénáře.
Živá DB není fixture a označení `.test` neopravňuje k jejímu resetu.
Podrobnosti: [lokální prostředí a hranice izolovaných testů](../docs/development/local-environment.md).

- PHP 8.4 a Composer dev závislosti podle kořenového README.
- SQLite pro řadu izolovaných doménových a HTTP fixtures.
- MySQL konfigurace a právo vytvořit izolovanou testovací DB pro MySQL a
  instalační regrese. CI používá [config.ci.php](fixtures/config.ci.php).
  Funkční instalační API test čeká na fresh tvorbu tabulek nejvýše 120 s;
  ostatní jeho HTTP requesty mají limit 30 s. Nejde o výkonnostní rozpočet.
- Node.js pro frontend; nativní scénáře navíc potřebují Chromium executable
  (`TINYCAT_TEST_CHROME`) nebo Playwright/WebKit (`TINYCAT_TEST_PLAYWRIGHT_MODULE`).
  Příklad instalace WebKitu je v [CI workflow](../.github/workflows/quality.yml).
- Další integrační požadavky jako GD/WebP, ZIP/Sodium či Memcached určují
  konkrétní skupiny. SKIP znamená neprovedenou kontrolu, nikoli ověřenou integraci.

Blogový browser scénář lze lokálně spustit ve stejném WebKitu jako CI:
nastav `TINYCAT_TEST_BLOG_BROWSER=webkit` a `TINYCAT_TEST_PLAYWRIGHT_MODULE`
na příslušnou instalaci Playwrightu před cíleným během `tests/domains/blog/module.php`.
Bez této volby zůstává zachován výběr dostupného místního Chromium/Chrome.

## Hlavní oblasti

| Oblast | Vstup nebo umístění |
| --- | --- |
| Architektura a UI kontrakty | `tests/quality/` |
| API a veřejné routy | `php tests/http/api-contract.php`, `php tests/http/public-route-smoke.php` |
| Frontend | `php tests/frontend/run.php` |
| Vykreslování a překlady | `tests/presentation/` |
| Domény | `tests/domains/`: users, status, bots, search, moderation, security, health a scheduler. |
| Cache a síťová infrastruktura | `tests/infrastructure/`, včetně `cache/` a `network/`. |
| Assety | `tests/assets/` |
| Release, instalace a rollback | `tests/release/`, `tests/release/updater/` |
| Vývojové a údržbové nástroje | `tests/tools/` |

V kořeni zůstává pouze hlavní runner a tento rozcestník. Provedené
rozřazení popisuje [audit](../docs/audits/tests-2026-11-10.md) a
[inventář](../docs/audits/tests-inventory-2026-11-10.json).

PHP generátory a workers pro klientské testy jsou v `tests/presentation/fixtures/`,
JavaScript fixture v `tests/frontend/fixtures/`, HTTP router a data v
`tests/http/fixtures/` a zahrnované scénáře v `tests/http/cases/`.
Společná podpora patří do `tests/support/`; sdílená data a konfigurace do
`tests/fixtures/`. Výchozí seznam obsahuje 90 skupin a frontendový 38
scénářů. Nová skupina `tests/release/build-preparation.php` ověřuje životnost
cache, invalidaci, oba ZIP backendy a publikaci připraveného výstupu.
`tests/quality/module-catalog.php` ověřuje offline načtení metadat, závislosti,
hranice zdrojových cest a přechodovou registraci dnešních modulů.
`tests/quality/module-resources.php` ověřuje explicitní výběr překladů,
komponent a assetů, vyřazení nevybraných zdrojů, izolaci cache a shodu
výchozího buildu před a po změně sestavy.
`tests/quality/runtime-registries.php` ověřuje čisté definice úloh, údržby,
navigace a health; jejich vlastníky, vyřazení a minimální technický start.
Fixture `tests/fixtures/modules/minimal-boot.php` běží v samostatném procesu
se SQLite v paměti a bez načtení místního configu nebo sociálních domén.
Historický performance baseline v `tests/performance/` je ruční kontrolou
archivních dat. Současné query budgets nad živými testovacími dotazy ověřuje
`tests/performance/hot-read-query-budgets.php` ve výchozím průchodu.

## Samostatné kontroly a pomocné soubory

`php tests/performance/mysql-tag-window.php --config=/private/performance/config.php`
ověřuje tagové dotazy na připravené izolované `tinycat_perf_*` databázi.
Není součástí hlavního runneru; předpoklady stanoví test a
[standard dotazů](../docs/contracts/query-selection-standard.md).

`tests/security/` je nyní ignorovaná lokální sada, kterou čistý klon neobsahuje.
Má vlastní návod a není součástí hlavního runneru; její starší API scénáře
vyžadují revizi před zapojením do CI.

Nespouštěj každý PHP/JS soubor jako samostatný test. Část souborů tvoří routery,
workers, generátory fixture a zahrnované scénáře; jejich konzument určuje vstupy
i životní cyklus. Zachovej izolaci cache z `support/cache-isolation.php` a oddělené
testovací databáze. Pro změny UI platí také testy uvedené v závazných
[modal](../docs/contracts/modal-contract.md) a
[popover](../docs/contracts/popover-contract.md) kontraktech.

`tests/quality/module-pilot.php` ověřuje svislý pilot z
`tests/fixtures/modules/notes/`: explicitní aktivaci, vlastní schéma a migrace
na SQLite i MySQL, skutečné HTTP/CSRF a identitu, sdílené vykreslování, assety,
překlady, běh úlohy a deaktivaci se zachováním dat. Worker a modul jsou fixture,
ne další produkční aplikace.

`tests/domains/users/account-lifecycle.php` ověřuje minimální schéma účtů,
vybrané účastníky životního cyklu včetně manifestu, vlastnictví publikačního
úklidu, transakční rollback a souborové efekty až po commitu.

`tests/domains/accounts/identity.php` ověřuje autentizaci nad samotnými účty,
skutečné HTTP sessions, CSRF, oprávnění a přihlašovací cookie včetně starého
formátu, expirace a změny hesla. Izolovaný worker je v
`tests/fixtures/accounts/identity-worker.php`; nespouští se jako samostatný test
a nezavádí produkční endpointy.

`tests/domains/accounts/onboarding.php` ověřuje registraci a obnovu pouze nad
účty a resetovacími tokeny, společnou validaci, schvalování, expiraci,
jednorázové použití a rollback tokenu při chybě zápisu hesla. Doručení je
testovací callback; neodesílá skutečné e-maily. Kontroluje i datumovou politiku
bez Settings a kompatibilní vstupy Users. HTTP ochrany dále pokrývá API sada.

`tests/domains/accounts/profile.php` ověřuje profilové zápisy nad samotnou
tabulkou účtů: oprávnění, povolená pole, částečné požadavky, locale, bio,
e-mailové preference, změnu vlastního hesla a odmítnutí zastaralého hashe.
Prokazuje běh bez Users, HTTP requestu/session a sociálních služeb.

`tests/domains/accounts/administration.php` ověřuje administrativní zápisy,
reset hesla, role a stavy, ochranu nejstaršího administrátora a opakovanou
kontrolu dříve připravených dat v transakci. Běží jen nad `users`; integraci
se soubory avatarů a rollback při SQL chybě dále pokrývá HTTP API sada.

`tests/domains/accounts/avatars.php` kopíruje aplikaci bez `App/Modules/Users` do
izolovaného workspace a ověřuje účtové resources, SVG, cache hlavičky,
skutečný multipart upload, CSRF, limiter a souborový rollback. HTTP worker
`tests/fixtures/accounts/avatar-worker.php` používá jen tuto kopii; nespouští
se samostatně a nevystavuje produkční routy. Vyžaduje GD/WebP a cURL.

Development outputs use ignored `.work/`, separated from application `storage/`.
See [filesystem storage](../docs/development/storage.md#development-outputs).

## Update baseline

The update rehearsal starts directly at published `v6.0.4`, builds a signed
successor in an isolated source copy, and checks file updates, repetition,
interruption recovery and exact rollback. It no longer builds pre-6 bridges.
The same group also runs the existing MySQL product rehearsal from 6.0.4 when
local MySQL configuration is available. Older published migrations stay immutable.

The unified migration journal has SQLite preservation, conflicting-history and
interruption tests in `tests/infrastructure/migrations.php`; the existing MySQL
registry group runs the same disposable fixture. The signed 6.0.4 rehearsal
also verifies conversion from the original journals and full rollback.
