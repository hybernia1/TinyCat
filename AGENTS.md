# TinyCat: práce v repozitáři

TinyCat je self-hosted publikační aplikace v PHP 8.4 s MySQL/MariaDB,
serverovým HTML a lehkým JavaScriptem. Produkce nepotřebuje Composer balíčky
ani npm; Composer slouží vývojovým kontrolám. Frontendové testy používají Node
a některé také Playwright. Začni [README](README.md),
[dokumentací](docs/README.md), [přehledem nástrojů](tools/README.md)
a [testovací sadou](tests/README.md).

## Architektura a vlastnictví

- `App/modules.php` je explicitní seznam vestavěných modulů; `App/bootstrap.php`
  skládá aplikaci a `index.php` zajišťuje HTTP vstup. Routy registrují moduly,
  nikoli filesystem discovery. `scheduled-tasks.php` je CLI vstup plánovače.
- `App/<Module>/` vlastní doménovou logiku, API, stránky a soukromé šablony.
  `Api` vrací data, `Pages` autorizuje stránku a připravuje vstupy pro šablony.
  Šablony neprovádějí SQL ani doménové zápisy.
- `App/Http`, `App/Database` a `App/Support` poskytují společný transport
  a infrastrukturu. Nevytvářej paralelní implementace jejich služeb.
- `assets/core/` vlastní klientskou infrastrukturu, `assets/ui/` společné UI
  a `assets/modules/<domain>/` doménové controllery, komponenty a styly.
  Závislosti a scopes patří do modulových manifestů.
- `feeds/` je samostatná feed aplikace s vlastní konfigurací a cache;
  její spuštění a pravidla popisuje [vlastní README](feeds/README.md).
- `tests/domains/` vlastní doménové regrese; společné helpery patří do
  `tests/support/` a sdílená data do `tests/fixtures/`. Routery, workers
  a generátory fixture nejsou samostatné testy; spouštěj jejich runnery.

Podrobnosti: [moduly a transport](docs/development/modules.md),
[vykreslování](docs/development/partials.md), [storage](docs/development/storage.md).

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

## Ověření

Vol odpovídající kontroly podle změny. Hlavní úplná kontrola je `composer preflight`
po instalaci dev závislostí; nevytvářej alternativní univerzální runner.

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
