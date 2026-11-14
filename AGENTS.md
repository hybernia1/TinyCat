# TinyCat: práce v repozitáři

TinyCat je self-hosted modulární aplikace v PHP 8.4 s MySQL/MariaDB,
serverovým HTML a lehkým JavaScriptem. Produkce nepotřebuje Composer balíčky
ani npm; Composer slouží vývojovým kontrolám. Frontendové testy používají Node
a některé také Playwright. Začni [README](README.md),
[dokumentací](docs/README.md), [přehledem nástrojů](tools/README.md)
a [testovací sadou](tests/README.md).

## Core / Modules

`App/Core/` poskytuje mechanismy a kontrakty. `App/Modules/<Name>/` vlastní
konkrétní funkci od registrace po data a UI. Vrstva `Composition` ani
produktové aplikační starty neexistují. Konfigurace instalace vybírá moduly,
nikoli PHP adaptéry propojující domény.

- Povinný framework registruje `Core/services.json` přes `Core/Runtime/Framework`
  bez modulových manifestů a aktivačních přepínačů. Identita, pošta,
  notifikace, nastavení, plánovač, diagnostika, instalace a aktualizace patří do core.
- `Modules/Social` je jeden modul, nikoli skupina zapínatelných částí. Jeho jediný
  manifest a `src/Module.php` vlastní registraci profilů, statusů, moderace,
  hledání, automatizace a veřejné prezentace. Blog má vlastní samostatnou registraci.
- `App/modules.php` vybírá manifesty explicitně. `Core/Modules/Loader` ověřuje
  metadata, pořadí závislostí a vlastnictví poskytovatelů. Neodvozuj aktivaci
  z adresářů a nepřidávej produktové větvení do core.
- Instalované ZIPy doplňuje explicitní `App/module-packages.json`, nikoli glob
  adresářů. Instalace a aktualizace modulu používají společný updater, zámek,
  journal a zálohu; první nahrání modul nezapíná ani nespouští jeho PHP.
- Každý funkční modul má `src/`, `templates/`, `lang/`, `assets/`, migrace
  a registrační vstup v jednom `App/Modules/<Name>/`. Kontrakty jsou
  v `Core/Modules`, technický start v `Core/Runtime`.
- Registrace neprovádí SQL, session, DDL ani provozní vedlejší efekty.
  Provozní callbacky zůstávají lazy; instalace a migrace jsou explicitní.
- Core/Identity včetně účtového API a prezentace funguje bez sociálních zdrojů
  a tabulek. Kontrakt rozšíření vlastní Core/Identity, implementaci přispívající
  modul. Nainstalovaní neaktivní vlastníci
  dat se dál zapojují do úklidu při odstranění účtu.
- Core/Discovery hostuje hledání a sitemap; aktivní vlastníci přispívají
  lazy `SearchSource` a `SitemapSection`. SQL, viditelnost a kanonické URL
  patří vlastníkovi. Nepřidávej seznam domén do core ani glob discovery.
- Core/Tasks vlastní běh, zámky a persistentní historii v `scheduler_runs`.
  HTTP/admin rozhraní plánovače patří také do core; CLI nesmí obejít runner.
- Core nemá univerzální obsah, komentáře, tagy ani média. Blog vlastní celý
  `blog_articles` a jeho vztahy, Social vlastní `social_posts` a vlastní diskuse,
  reakce, tagy, odkazy a média. Domény mají nezávislé číselné prostory a FK.
- `Core/Text`, `Core/Links`, `Core/Feeds` a `Core/Files` poskytují mechanismy
  bez doménového SQL; `Core/Ui` sdílenou prezentaci. Zachovej atomické zápisy,
  viditelnost, stabilní kurzory a mazání souborů až po commitu. Reference souborů
  deklaruje `ProvidesFileReferences`, i pro nainstalované vypnuté vlastníky.

- `Core/Modules/Resources` poskytuje tentýž výběr runtime i buildu. Klientské
  závislosti a překladové kanály deklarují vlastní modulové manifesty.
- Sdílenou prezentaci poskytuje `Core/Ui/Page`. Social vlastní veřejné sociální téma;
  společné UI nesmí vyžadovat jeho layout. Homepage, témata a dashboardy
  přispívají pojmenovanými definicemi; `/` a `/admin` vybírá core.
  Blog a Social musejí fungovat zároveň i po samostatném vypnutí.
- Společný HTML dokument, head, metadata, jazykové a consent UI vlastní Core/Ui;
  HTTP chyby a fallback Core/Runtime, locale URL Core/Http. Vlastníci deklarují
  `LocalizedRoute` včetně povolených query parametrů. Informační stránky a technický
  dashboard hostuje core; moduly připojují lazy `InformationSection` a `DashboardWidget`.
- Článek a status ani jejich komentáře nesdílejí tabulku. Stejné číslo ID
  není stejný objekt. Sociální dotazy, exporty a údržba používají vlastní tabulky.
- Feedový průchod vlastní `Core/Feeds/FeedPipeline`; `FeedSourceStore`
  a `FeedTarget` implementuje vlastník zdroje a publikace. Cíl připravuje i přílohy.
  Náhled neclaimuje GUID ani nemění plánování. Zápis publikace a úspěšného GUID
  je atomický; ruční import používá Core/Tasks.
- Textové renderování rozšiřuj pojmenovanými `Core/Text/Policies` a `TextPolicy`.
  Pravidla URL a UTM vlastní každý modul; core poskytuje parser a aplikaci pravidel.
  Sdílené assety v `assets/ui/content` a ID `publishing.*` jsou UI kompatibilita,
  nikoli modul nebo povinná doménová služba. RSS poskytuje Core/Feeds,
  oEmbed hostuje Core/Discovery pro lazy `EmbedSource` vlastníka.

- Nastavení, jejich klientské popisky a `resources.emails` patří vlastníkům.
  Společný renderer pošty je v core. Volitelný detail komponenty připojuj přes
  `componentExtensions`, ne povinným importem z nepovinného modulu.
- Distribuční profily jsou data vlastněná manifestem. Core deployment, updater
  a snapshoty sdílejí zámek, údržbu, journal a obnovu. Publikované migrační
  soubory a checksumy zůstávají neměnné.
- `Core/Support/Config` je čistý vstup před DB. Cache a překladač neimportují
  Settings; Core/Settings přispívá vlastní provozní politiky.
- Sdílené HTTP, DB a technické služby patří do Core; nevytvářej jejich druhé
  implementace. Zachovej ID úloh a migračních streamů a podporované aktualizace a rollback.
  Historická ID aktivací převádí výhradně `Core/Modules/Upgrade`; nejsou novými moduly.
- Runner, historie, kontext a údržba jsou v `Core/Tasks`, store v
  `Core/Settings` a SMTP v `Core/Mail`. Používej jejich kanonické názvy;
  staré modulové názvy jsou kompatibilní aliasy, nikoli další implementace.
  Doménový detail úlohy, dočasné adresáře, config overrides a definice řazení
  vlastní příslušná funkce; registrace jejich callbacky nevyhodnocuje.
- Obecná schránka je v `Core/Notifications`, diagnostika v `Core/Health`.
  Sociální předměty a události vlastní Social/Posts; core nečte tabulky publikací
  kvůli notifikacím. Typy, hydratace a odkazy jsou lazy příspěvky vlastníků.
  HTTP tok drží `Core/Runtime/HttpKernel`, Social poskytuje úzkou `HttpPolicy`.
- Doménové testy patří do `tests/domains/`, helpery do `tests/support/`
  a fixture/workers do `tests/fixtures/`. Workers nejsou samostatné testy.
  `feeds/` je nadále samostatná aplikace s vlastním README.

Pro nový modul začni [manuálem tvorby modulů](docs/development/module-authoring.md).
Podrobnosti: [core a moduly](docs/development/core-and-modules.md),
[doménová data](docs/development/domain-storage.md),
[transport](docs/development/modules.md) a [storage](docs/development/storage.md).

## Kontrakty změn

- Dodržuj [HTTP API](docs/reference/api.md): společná JSON obálka, serverová
  autorizace a CSRF, kanonická data a oprávnění. Doménové resources nevracejí
  HTML, komponentové definice ani překladové identifikátory.
- Počáteční HTML a AJAX obsah sdílejí komponentové definice; nevytvářej druhou
  ruční implementaci stejného UI. Platí [UI konvence](docs/contracts/ui-conventions.md)
  a [pravidla administrace](docs/contracts/admin-ui-conventions.md).
- Pro všechny dialogy včetně nových záznamů a čtecích detailů platí
  [kontrakt modalů](docs/contracts/modal-contract.md). Resource načítej na
  vyžádání společným loaderem z autorizovaného JSON endpointu. Nepřidávej
  skryté modaly ani formulářové šablony; nevytvářej je při inicializaci,
  hydrataci či obnově seznamu. Potvrzení a náhledy používají společnou factory.
  Refresh nesmí přepsat koncept. Rám, historie, validace, fokus a zavírání
  patří společné vrstvě.
- Nabídky a filtry dodržují [kontrakt popoverů](docs/contracts/popover-contract.md):
  `TinyCat.popovers`, povrch `.popover-panel`, společné otevírání, zavírání
  a pozicování. Rozměry variant nastavuj sdílenými CSS proměnnými.
- Text příspěvků respektuje [textový kontrakt](docs/contracts/post-text-contract.md);
  ruční publikování, API a botí import používají společnou přípravu textu.
- Seznamy a hledání respektují [výběr a stránkování dat](docs/contracts/query-selection-standard.md).
  Zachovej viditelnost, filtry, stabilní kurzory a query budgets.
- Překlady vlastní moduly. Zachovej paritu jazyků, parametrů a plurálů i explicitní
  klientské závislosti podle [překladového kontraktu](docs/contracts/translations.md).
  Generované katalogy ani assety neupravuj ručně.

## Data, migrace a aktualizace

`config.php`, `feeds/config.php`, klíče, `storage/` a `uploads/` obsahují lokální
konfiguraci nebo data. Ignorování Gitem není souhlas k odstranění. Při úklidu
rozlišuj pracovní artefakty, aktivní závislosti, zálohy a média. Nepoužívej
plošné mazání ignorovaných souborů. Testovací data a reporty drž odděleně
od provozní instalace. Tajné hodnoty nevypisuj ani necommituj.

Publikované migrace a jejich checksumy neměň; změnu schématu řeš novou migrací
a odpovídajícím aktuálním instalačním schématem. Zachovej podporované aktualizace,
podpisy, manifesty, zálohy a rollback podle [návodu](docs/operations/updates.md).
Staré položky mazacího manifestu mohou být nutné pro update podporované verze.

## Lokální prostředí

Živá testovací instalace je **https://tinycat.test/** v samostatném adresáři
`E:/laragon/www/tinycat`, obsluhovaná existujícím Laragonem. Tento repozitář
obsahuje zdroje, není DocumentRoot webu. Změna zdrojů se na web nepropíše
automaticky: před živým ověřením synchronizuj odpovídající runtime soubory,
nikoli místní konfiguraci, storage, uploads, testy nebo dev závislosti.
Nikdy nezrcadli celý repozitář přes živou instalaci s mazáním cíle.
Živé CLI operace spouštěj v externí instalaci, aby pracovaly s jejími cestami.
Ignorovaný `config.php` v repozitáři je pouze CLI/test odkaz na externí
konfiguraci; vývojový cache jde mimo repo a respektuje izolaci testů.

Pro ruční, browserové a integrační smoke
ověření instalace používej tuto doménu a stávající Apache/PHP/MySQL.
Nespouštěj kvůli tomu vlastní Apache, MySQL, paralelní stack ani náhradní
`php -S`. Při problému nejprve ověř Laragon, vhost, porty a logy.

MySQL testy používají existující službu a vlastní dočasné databáze. Konkrétní
izolované testy mohou přes své stávající runnery vytvořit dočasný HTTP worker,
pokud potřebují oddělený router, zdroje nebo scénář přerušení. To není důvod
zakládat další serverový stack pro ověření běžné instalace.

Testovací doména neznamená prázdnou databázi. Bez zadání reinstalace nemaž
její data, konfiguraci ani účty a nespouštěj na ní destruktivní fresh-install
či rollback scénáře. Postup a ověřené zapojení: [lokální Laragon](docs/development/local-environment.md).

## Ověření

Vol odpovídající kontroly podle změny. Hlavní úplná kontrola je `composer preflight`
po instalaci dev závislostí; nevytvářej alternativní univerzální runner.

Během ucelené etapy používej cílené kontroly; úplný měřený preflight patří
na její konec. Současné runnery zaznamenávají časy, výsledky, přeskoky a rozsah
do `storage/test-runs/`. Počet assertions není měřítkem pokrytí ani důvodem
pro přidání další úplné integrační kopie stejného scénáře.

| Změna | Relevantní kontroly |
| --- | --- |
| PHP a doménová logika | `php tools/quality/php-lint.php`, `composer style`, `composer analyse` a příslušné testy v `tests/`. |
| API a oprávnění | `php tests/http/api-contract.php` a doménové scénáře. |
| Hranice a šablony | `php tests/quality/module-boundaries.php`, `php tests/quality/dependencies.php`, `php tests/quality/render-only-partials.php`. |
| UI a assety | `php tests/quality/ui-contracts.php`, příslušné frontendové testy a `php tools/assets/asset-report.php --check`. |
| Překlady | `php tools/quality/check-translations.php` a testy katalogů v `tests/presentation/`. |
| Release a updater | Testy balíčků v `tests/release/` a `php tests/release/updater/run.php`. |

Zachovej build kontroly `tools/quality/modal-contracts.php` a `tools/quality/ui-contracts.php`.
Při změnách dialogů, popoverů a filtrů spusť také nativní regresní testy uvedené
v jejich závazných kontraktech; neobcházej je lokální implementací.
Browser testy používají `TINYCAT_TEST_PLAYWRIGHT_MODULE`; instalaci ukazuje
`.github/workflows/quality.yml`. U ověření uveď, co prošlo a co nebylo spuštěno
nebo vyžadovalo nedostupné prostředí.

## Dokumentace a nástroje

Pravidla patří do `docs/contracts/`, reference do `docs/reference/`, provozní
návody do `docs/operations/`, vývojové do `docs/development/` a postupy měření
do `docs/performance/`. Otevřené návrhy v `docs/plans/` nejsou popisem
implementovaného stavu; audity zachycují stav k uvedenému datu.

Při přesunech aktualizuj společně odkazy a volající, včetně Composeru, CI,
relativních importů a kopírování nástrojů do testovacích fixture. UI inventář
obnovuj přes `python tools/assets/ui-inventory.py`. Výsledky běhů patří do ignorovaného
storage. Nové nástroje zařaď podle účelu a zdokumentuj jejich vstup. Samotné
stáří ani chybějící interní volající neprokazují nepotřebnost CLI příkazu.
