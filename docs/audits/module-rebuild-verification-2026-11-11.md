# Ověření rebuildu a náklady testů

Měření jedné rebuild etapy z 11. 11. 2026, runtime `6.0.0-dev.1`.
Stav: etapa uzavřena po měřeném ověření, opravách a navazujících kontrolách.

## Prostředí a omezení

Windows, PHP 8.4.12, Node v24.15.0, PDO SQLite i MySQL. Browserové testy
použily lokální Chromium a WebKit přes Playwright 1.64.1. Při uzavírání
protokolu mají dostupné instalace Chrome verzi 157.0.8103.39 a Edge
157.0.4341.46; jejich cesty jsou zaznamenané také v jednotlivých reportech.

Memcached integrace byla přeskočena: lokální server není dostupný. Katalogový
test prošel, ale přeskočil vytvoření symlinku, které prostředí nepovolilo.
Tyto části nejsou označeny jako provedená integrace.

Jeden běh instalačního API prošel s varováním při úklidu dvou souborů dočasně
držených Windows. Cleanup dostal omezené opakování a neúspěšný úklid nyní selže.
Opakovaný test pak prošel bez varování a nová fixture byla odstraněna.
Automatická kontrola odmítla samostatné odstranění původních dvou souborů
v `storage/install-api-f13275c69ff5` s důvodem `blocked by policy`; při uzavření
této etapy zůstávaly odděleně od provozních dat. Navazující úklid 12. listopadu
je odstranil. [První provedené optimalizace](test-optimization-and-local-cleanup-2026-11-12.md)
zaznamenávají další měření i úklid starých release kopií.

## Metoda

Výchozí `composer preflight` zachoval všechny kontroly. Po nalezené chybě
jsme opravili její příčinu a pokračovali explicitním výběrem přes stávající
`tests/run.php --only=…` nebo `tests/frontend/run.php --only=…`.
Nejde o jeden nepřerušený zelený preflight. Původní neúspěšné pokusy i položky
`not-run` zůstávají v jednotlivých reportech; navazující běh na ně odkazuje.

Reporty v `storage/test-runs/<ID>/` obsahují `report.json`, `events.jsonl`
a log každého příkazu. Zaznamenávají prostředí, revizi, otisk pracovních
zdrojů, plán, skutečný výběr, stav, monotónní čas, návratový kód a důvody
přeskočení. Produkční konfigurace, klíče a média se do otisku nečtou.

Tabulky uvádějí poslední dokončený pokus každé položky. Čas
frontendového runneru ani rodiče celého preflightu se nepřičítá k časům jejich
potomků. Součet vybraných úspěšných pokusů není doba jednoho reálného průchodu
ani doba práce na rebuildu. Opravy mezi běhy znamenají různé otisky stromu;
relevantní opakované kontroly budou uvedeny samostatně.

## Režie měření

První verze zapisovače procházela a hashovala zdroje přes PHP. V pokusu
`20261111-220312-c6ba0b69d7` tím spotřebovala přibližně 256 sekund, zatímco
samotné dvě spuštěné testovací skupiny zabraly 5,77 sekundy. To je chyba
nového měření, nikoli náklad aplikace nebo důkaz pomalých assertions.

Zapisovač nyní používá `git ls-files` a `git hash-object --stdin-paths`.
V pokusu `20261111-221720-5b8be2ca5c` příprava otisku trvala 0,36 sekundy.
Algoritmus je v nových reportech výslovně `git-blob-oid`; staré SHA-256 otisky
a nové Git otisky nelze porovnávat jako stejnou veličinu. Příkazy testů,
jejich výstup a monotónní časy zůstávají zachované.

Pozdější otisk zahrnuje i metadata jazyků a všechny balené dokumenty.
Pokus `20261111-223529-fca61b0c48` měl během deterministického buildu změněný
`docs/operations/updates.md`; jeho balíčkový výsledek není důkaz determinismu
neměnných vstupů. Pokus `20261111-223808-113bc232a7` potom prošel se zmrazenými
zdroji i balenými dokumenty a prázdným seznamem změn během běhu.
První preflight má zvláštní dodatečný záznam změny Scheduler middleware;
u několika pozdějších běhů je změnou pouze dokumentace `tests/README.md`.

## Opravy nalezené ověřením

- `View::part` řeší šablony podle vybraného vlastníka, včetně technických
  šablon Support. Zmizelo skládání původní cesty `App/<Owner>`.
- Unit fixtures vybírají moduly explicitně. Zpřísněný autoloader se neotevřel
  neaktivním adresářům jen kvůli starému testu.
- Users už nedeklaruje prázdný překladový adresář. Release ZIP prázdné
  adresáře nepřenáší a runtime správně odmítá neexistující zdroj manifestu.
- Starý updater dostal popsaný cache loader v update variantě; fresh varianta
  jej nesmí obsahovat. Podpora aktualizace z 5.16.0 zůstává zachovaná.
- Testové odkazy a worker fixtures odpovídají novým umístěním. Build kontroly
  UI a modalů ani nativní browserové scénáře nebyly odstraněny.

## Pravidla vyhodnocení

Počet assertions sám neprokazuje náklady ani duplicitu. Nemazat důkazy
autorizace, CSRF, izolace modulů, transakčního rollbacku, podpisů a migrací
jen kvůli podobným názvům skupin. Nejprve měřit a omezit opakované buildy,
kopírování fixtures a starty browserů. V této etapě se testy neodstraňují.

## Co omezit v další etapě

1. **Opakovanou kompilaci stejných vstupů.** `client-assets.cjs` extrahuje
   PHP fixture zvlášť pro jednotlivé routy a režimy source/compiled/minified.
   Připravit neměnný build jednou pro shodný otisk a znovu používat jeho výstup.
   Zachovat porovnání všech režimů, klientské závislosti i samostatnou
   invalidaci v `build-preparation.php`.
2. **Opakované vytváření browserové stránky a fixture.** Dotykové popovery
   už sdílejí WebKit proces, ale pro každou kombinaci viewportu, kontextu
   a nabídky vytvářejí novou stránku. Ověřit sdílení připraveného dokumentu
   s důsledným resetem. Zachovat skutečný dotyk, formuláře i stránku/modal;
   tyto důkazy nenahradí statický CSS check ani DOM mock.
3. **Stejné release buildy v různých skupinách.** Sdílet lze neměnnou dvojici
   install/update stejné sestavy. Lifecycle s měněnou verzí, chybnou migrací
   nebo jiným aktivním výběrem musí dál vytvářet vlastní artefakty.
   Samostatný opakovaný build potřebný k důkazu determinismu také zůstává.
4. **Proces na každý lintovaný soubor.** Stávající lint spouští `php -l`
   sériově pro každý soubor. Prověřit dávkování podporované používanou verzí
   PHP nebo omezenou paralelizaci při zachování úplného seznamu a chyb.
5. **Plný preflight při každé malé úpravě.** Používat explicitní výběr
   dotčených skupin, celý preflight ponechat pro uzavření etapy a release.
   CI může rozdělit stejné pokrytí do izolovaných jobů. Samotné měření
   neprokazuje, že některá doménová či bezpečnostní skupina může zmizet.

Případné mazání assertions vyžaduje konkrétní dvojici shodných důkazů
a zachovaného vlastníka. Tento běh takovou záměnnost celých skupin neprokazuje.

**Konkrétní kandidát k odstranění:** druhý samostatný update build `dist-b`
v `package-artifact.php`. Jeho jediný důkaz je bytová shoda ZIPu s prvním
buildem; update větev už tutéž shodu ověřuje proti nezávislému kombinovanému
`--both` buildu. Při sloučení zachovat kontrolu samostatný/kombinovaný build,
podpisy, obě varianty a chování při neúspěšné publikaci. Fresh větev potřebuje
svůj nezávislý důkaz determinismu dál. Při uzavření tohoto měření kandidát
odstraněn nebyl; [navazující etapa 12. listopadu](test-optimization-and-local-cleanup-2026-11-12.md)
jej následně odstranila a ověřila obě varianty.

Release skupiny představují 729,35 s z posledních výsledků, všech 38 JS
scénářů 394,46 s a statické kroky 201,85 s. Není důvod začínat mazáním levných
doménových assertions. Další měření má rozdělit build na čtení/hashování,
kompilaci, archivaci, publikaci a úklid fixture, než se určí jejich rozpočty.
Rozptyl je velký: balíčková skupina v této etapě naměřila 38,38 i 152,73 s,
fresh 18,94 i 142,87 s. Kvůli opravám a nekontrolovaným podmínkám nejde
o srovnatelný benchmark před/po ani důkaz konkrétní příčiny zpomalení.

<!-- measured-results -->

## Výsledky měření

Poslední výsledky: **131 passed a 1 skipped** nezávislých položek: 7 statických kroků, 87 PHP skupin a 38 JS scénářů. Frontendový kontejner doplňuje plán 88 skupin; jeho pokrytí je odvozené z 38 dokončených potomků, nikoli z jednoho nepřerušeného zeleného runneru.

Součet časů posledních dokončených položek bez dvojího počítání rodičů: **1734.561 s (28.91 min)**. Součet skutečné délky všech 16 měřených pokusů včetně chyb a režie: **2542.910 s (42.38 min)**. Zahrnuje 10 neúspěšných pokusů jednotlivých kontrol; rodičovské neúspěchy se k nim nepřičítají.

Oba poslední balíčkové a statické běhy mají shodný otisk zdrojů a prázdný seznam změn během běhu. Starší dílčí výsledky pocházejí z předchozích opravovaných stromů; matice není předstíraným výsledkem jediného finálního příkazu.

Strojová data: [JSON](../../storage/rebuild-measurements.json), [CSV](../../storage/rebuild-measurements.csv). Souhrn nepřepisuje původní reporty.

### Nejdražší poslední úspěšné položky

| ID | Sekundy | Report |
| --- | ---: | --- |
| `tests/release/update-rehearsal.php` | 212.360 | R13 |
| `preflight/php-8.4-lint` | 171.885 | R16 |
| `tests/release/package-artifact.php` | 152.726 | R15 |
| `tests/release/fresh-package-artifact.php` | 142.874 | R15 |
| `popover-touch.cjs` | 123.321 | R04 |
| `tests/release/module-lifecycle.php` | 120.643 | R11 |
| `client-assets.cjs` | 112.148 | R04 |
| `tests/http/api-contract.php` | 94.420 | R11 |
| `tests/release/mysql-installer-rehearsal.php` | 52.110 | R13 |
| `tests/quality/module-resources.php` | 50.321 | R01 |
| `tests/release/installation-api.php` | 45.194 | R15 |
| `tests/quality/module-pilot.php` | 32.721 | R01 |

### Reporty všech pokusů

| Report | ID / původní JSON | Celkem s | Neúspěšné jednotlivé kontroly |
| --- | --- | ---: | ---: |
| R01 | [20261111-213531-8e03e55264](../../storage/test-runs/20261111-213531-8e03e55264/report.json) | 259.542 | 1 |
| R02 | [20261111-214414-26faaa4d2b](../../storage/test-runs/20261111-214414-26faaa4d2b/report.json) | 37.685 | 1 |
| R03 | [20261111-214843-c59931727c](../../storage/test-runs/20261111-214843-c59931727c/report.json) | 1.732 | 1 |
| R04 | [20261111-215025-e5d92a7030](../../storage/test-runs/20261111-215025-e5d92a7030/report.json) | 360.051 | 0 |
| R05 | [20261111-215706-5d97c5a1f0](../../storage/test-runs/20261111-215706-5d97c5a1f0/report.json) | 113.317 | 1 |
| R06 | [20261111-220111-55312eeed8](../../storage/test-runs/20261111-220111-55312eeed8/report.json) | 27.780 | 0 |
| R07 | [20261111-220312-c6ba0b69d7](../../storage/test-runs/20261111-220312-c6ba0b69d7/report.json) | 262.684 | 1 |
| R08 | [20261111-221720-5b8be2ca5c](../../storage/test-runs/20261111-221720-5b8be2ca5c/report.json) | 21.287 | 0 |
| R09 | [20261111-221752-fe38409602](../../storage/test-runs/20261111-221752-fe38409602/report.json) | 25.274 | 1 |
| R10 | [20261111-221840-7a2136e832](../../storage/test-runs/20261111-221840-7a2136e832/report.json) | 8.894 | 1 |
| R11 | [20261111-221911-4c2ddeb3ff](../../storage/test-runs/20261111-221911-4c2ddeb3ff/report.json) | 274.529 | 1 |
| R12 | [20261111-222431-8e9f3b45b8](../../storage/test-runs/20261111-222431-8e9f3b45b8/report.json) | 186.839 | 1 |
| R13 | [20261111-222943-184641ab88](../../storage/test-runs/20261111-222943-184641ab88/report.json) | 297.770 | 0 |
| R14 | [20261111-223529-fca61b0c48](../../storage/test-runs/20261111-223529-fca61b0c48/report.json) | 119.291 | 1 |
| R15 | [20261111-223808-113bc232a7](../../storage/test-runs/20261111-223808-113bc232a7/report.json) | 346.781 | 0 |
| R16 | [20261111-224439-04e1ad0d60](../../storage/test-runs/20261111-224439-04e1ad0d60/report.json) | 199.455 | 0 |

### Statické kroky

| ID | Stav | Sekundy | Report |
| --- | --- | ---: | --- |
| `preflight/composer-metadata` | passed | 1.175 | R01 |
| `preflight/dependency-advisories` | passed | 2.975 | R01 |
| `preflight/deterministic-code-style` | passed | 0.572 | R16 |
| `preflight/translation-catalogs-and-client-declarations` | passed | 1.334 | R16 |
| `preflight/php-8.4-lint` | passed | 171.885 | R16 |
| `preflight/phpstan-level-8` | passed | 19.208 | R16 |
| `preflight/repository-security` | passed | 4.705 | R16 |

### PHP skupiny

| ID | Stav | Sekundy | Report |
| --- | --- | ---: | --- |
| `tests/quality/module-catalog.php` | passed (dílčí SKIP) | 1.094 | R14 |
| `tests/quality/module-resources.php` | passed | 50.321 | R01 |
| `tests/quality/runtime-registries.php` | passed | 1.276 | R14 |
| `tests/quality/module-pilot.php` | passed | 32.721 | R01 |
| `tests/quality/module-boundaries.php` | passed | 0.771 | R14 |
| `tests/quality/dependencies.php` | passed | 0.657 | R14 |
| `tests/quality/ui-contracts.php` | passed | 1.114 | R01 |
| `tests/infrastructure/storage.php` | passed | 0.272 | R01 |
| `tests/infrastructure/run.php` | passed | 0.322 | R01 |
| `tests/presentation/admin-sorting.php` | passed | 0.376 | R01 |
| `tests/quality/monolith-inventory.php` | passed | 3.175 | R05 |
| `tests/infrastructure/network/metadata-dns.php` | passed | 6.878 | R05 |
| `tests/infrastructure/network/http-client-reuse.php` | passed | 2.765 | R05 |
| `tests/domains/status/link-parser.php` | passed | 2.187 | R05 |
| `tests/domains/status/audio.php` | passed | 1.904 | R05 |
| `tests/domains/status/content-links.php` | passed | 1.502 | R05 |
| `tests/domains/security/rate-limiter.php` | passed | 1.016 | R05 |
| `tests/domains/security/rate-limiter-mysql.php` | passed | 1.755 | R05 |
| `tests/domains/status/write-path-integrity.php` | passed | 5.463 | R05 |
| `tests/domains/status/post-text.php` | passed | 0.950 | R05 |
| `tests/domains/moderation/moderation-migration.php` | passed | 8.831 | R05 |
| `tests/domains/security/runtime-hardening.php` | passed | 3.837 | R05 |
| `tests/domains/users/language-detection.php` | passed | 2.728 | R05 |
| `tests/presentation/localization.php` | passed | 1.736 | R05 |
| `tests/presentation/translation-catalogs.php` | passed | 9.954 | R14 |
| `tests/presentation/compiled-translations.php` | passed | 28.901 | R05 |
| `tests/domains/status/inline-tokens.php` | passed | 0.406 | R05 |
| `tests/domains/status/numeric-tags-mysql.php` | passed | 5.677 | R05 |
| `tests/domains/users/usernames.php` | passed | 0.648 | R05 |
| `tests/domains/users/account-lifecycle.php` | passed | 17.695 | R06 |
| `tests/domains/accounts/identity.php` | passed | 5.511 | R07 |
| `tests/domains/accounts/onboarding.php` | passed | 2.710 | R08 |
| `tests/domains/accounts/profile.php` | passed | 3.669 | R08 |
| `tests/domains/accounts/administration.php` | passed | 2.594 | R08 |
| `tests/domains/accounts/avatars.php` | passed | 10.140 | R08 |
| `tests/domains/accounts/module.php` | passed | 16.324 | R09 |
| `tests/domains/publishing/module.php` | passed | 4.629 | R09 |
| `tests/domains/blog/module.php` | passed | 4.121 | R10 |
| `tests/domains/blog/mysql.php` | passed | 2.807 | R10 |
| `tests/domains/publishing/social-boundaries.php` | passed | 0.184 | R11 |
| `tests/quality/render-only-partials.php` | passed | 0.242 | R11 |
| `tests/domains/security/html-sanitizer.php` | passed | 0.242 | R11 |
| `tests/presentation/view-templates.php` | passed | 0.980 | R11 |
| `tests/presentation/components.php` | passed | 0.356 | R11 |
| `tests/presentation/dashboard.php` | passed | 0.415 | R11 |
| `tests/domains/status/public-exports.php` | passed | 0.297 | R11 |
| `tests/domains/status/archive-filters.php` | passed | 0.493 | R11 |
| `tests/domains/status/archive-filters-mysql.php` | passed | 1.121 | R11 |
| `tests/http/public-route-smoke.php` | passed | 8.545 | R11 |
| `tests/http/api-contract.php` | passed | 94.420 | R11 |
| `tests/performance/performance-baseline.php` | passed | 0.198 | R11 |
| `tests/performance/hot-read-query-budgets.php` | passed | 8.304 | R11 |
| `tests/tools/performance-profiler.php` | passed | 0.138 | R11 |
| `tests/infrastructure/cache/run.php` | passed | 0.460 | R11 |
| `tests/infrastructure/cache/memcached.php` | skipped | 2.172 | R11 |
| `tests/infrastructure/cache/concurrency.php` | passed | 1.279 | R11 |
| `tests/assets/run.php` | passed | 1.682 | R11 |
| `tests/assets/budgets.php` | passed | 10.847 | R11 |
| `tests/assets/graph.php` | passed | 0.252 | R11 |
| `tests/domains/bots/run.php` | passed | 0.560 | R11 |
| `tests/domains/bots/csfd-premieres.php` | passed | 0.309 | R11 |
| `tests/domains/bots/queue.php` | passed | 0.438 | R11 |
| `tests/domains/bots/feed-queue.php` | passed | 1.385 | R11 |
| `tests/domains/bots/duplicate-cleanup.php` | passed | 3.511 | R11 |
| `tests/domains/bots/publication-migration.php` | passed | 0.396 | R11 |
| `tests/release/updater/run.php` | passed | 0.636 | R11 |
| `tests/release/database-snapshots.php` | passed | 1.161 | R11 |
| `tests/release/module-lifecycle.php` | passed | 120.643 | R11 |
| `tests/release/updater/mysql-registry.php` | passed | 1.211 | R11 |
| `tests/domains/scheduler/files.php` | passed | 0.466 | R11 |
| `tests/domains/scheduler/retention.php` | passed | 4.587 | R11 |
| `tests/domains/health/health.php` | passed | 2.215 | R11 |
| `tests/domains/health/health-http.php` | passed | 2.405 | R12 |
| `tests/domains/scheduler/run.php` | passed | 1.546 | R12 |
| `tests/domains/scheduler/cli.php` | passed | 1.311 | R12 |
| `tests/tools/demo-import.php` | passed | 0.201 | R12 |
| `tests/tools/bot-source-attribution.php` | passed | 0.883 | R12 |
| `tests/tools/link-optimizer.php` | passed | 2.131 | R12 |
| `tests/tools/youtube-thumbnails.php` | passed | 0.465 | R12 |
| `tests/domains/search/search.php` | passed | 0.418 | R12 |
| `tests/domains/search/search-mysql.php` | passed | 3.608 | R12 |
| `tests/release/package-artifact.php` | passed | 152.726 | R15 |
| `tests/release/build-preparation.php` | passed | 0.437 | R12 |
| `tests/release/fresh-package-artifact.php` | passed | 142.874 | R15 |
| `tests/release/update-rehearsal.php` | passed | 212.360 | R13 |
| `tests/release/installation-api.php` | passed | 45.194 | R15 |
| `tests/release/mysql-installer-rehearsal.php` | passed | 52.110 | R13 |

### JS scénáře

| ID | Stav | Sekundy | Report |
| --- | --- | ---: | --- |
| `source-preview.cjs` | passed | 0.172 | R02 |
| `embed.cjs` | passed | 0.203 | R02 |
| `feed-cursor.cjs` | passed | 0.197 | R02 |
| `feed-lifecycle.cjs` | passed | 0.159 | R02 |
| `filtered-home-actions.cjs` | passed | 0.148 | R02 |
| `email-availability.cjs` | passed | 0.136 | R02 |
| `images.cjs` | passed | 0.137 | R02 |
| `youtube-thumbnails.cjs` | passed | 7.219 | R02 |
| `infrastructure.cjs` | passed | 1.296 | R02 |
| `modules.cjs` | passed | 6.476 | R02 |
| `user-menu.cjs` | passed | 0.512 | R02 |
| `toasts.cjs` | passed | 0.279 | R02 |
| `translation.cjs` | passed | 0.190 | R02 |
| `tag-layout.cjs` | passed | 13.497 | R02 |
| `locale-urls.cjs` | passed | 0.365 | R02 |
| `language-menu.cjs` | passed | 0.153 | R02 |
| `sidebar-viewport.cjs` | passed | 0.159 | R02 |
| `sidebar-refresh.cjs` | passed | 0.183 | R02 |
| `scheduler-live.cjs` | passed | 0.182 | R02 |
| `search-navigation.cjs` | passed | 0.180 | R02 |
| `notifications-state.cjs` | passed | 0.187 | R02 |
| `admin-sorting.cjs` | passed | 0.152 | R02 |
| `ajax-races.cjs` | passed | 0.181 | R02 |
| `search-races.cjs` | passed | 0.185 | R02 |
| `list-state.cjs` | passed | 0.244 | R02 |
| `popovers.cjs` | passed | 2.859 | R02 |
| `popover-sizing.cjs` | passed | 27.082 | R04 |
| `popover-touch.cjs` | passed | 123.321 | R04 |
| `comment-modals.cjs` | passed | 11.468 | R04 |
| `ui-consistency.cjs` | passed | 6.711 | R04 |
| `link-labels.cjs` | passed | 8.884 | R04 |
| `dialog-history.cjs` | passed | 21.493 | R04 |
| `form-validation.cjs` | passed | 30.394 | R04 |
| `admin-viewport.cjs` | passed | 13.783 | R04 |
| `dashboard.cjs` | passed | 2.841 | R04 |
| `bot-admin-lists.cjs` | passed | 0.206 | R04 |
| `client-assets.cjs` | passed | 112.148 | R04 |
| `styles.cjs` | passed | 0.474 | R04 |
