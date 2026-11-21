# Nástroje TinyCat

Příkazy spouštěj z kořene repozitáře. Hlavní vstup pro vývoj a CI je
`composer preflight`; jeho [runner](preflight.php) skládá jednotlivé kontroly.
Produkční aplikace tyto nástroje nenačítá a distribuční balíčky je neobsahují.
Composer timeout je vypnutý pouze pro preflight, protože plná sada nativních
browser testů může přesáhnout jeho výchozích 300 sekund. Limity jednotlivých
testů a CI zůstávají platné.

## Běžné kontroly a assety

| Účel | Vstup |
| --- | --- |
| PHP syntaxe | `php tools/quality/php-lint.php` |
| Styl | `composer style` |
| Security a závislosti | `composer security` |
| Překladové katalogy a klientské deklarace | `php tools/quality/check-translations.php` |
| UI/modal/popover kontrakty | `php tests/quality/ui-contracts.php` |
| Dosažitelnost a velikosti assetů | `php tools/assets/asset-report.php --check` |
| Generovaný UI inventář | `python tools/assets/ui-inventory.py` |
| Generované schéma komponent pro editor | `php tools/quality/component-schema.php` |
| Jednotný zápis komponentových katalogů | `php tools/quality/component-format.php --check` nebo `--write` |

`tools/assets/compile-assets.php`, `tools/quality/modal-contracts.php`, `tools/quality/ui-contracts.php`,
`tools/quality/translation-catalogs.php` a `tools/quality/client-translations.php` poskytují načítané build
funkce; nejde ve všech případech o samostatné CLI příkazy. Používej jejich
runner nebo release builder.

`component-syntax.php` vlastní autorskou gramatiku komponent používanou build
validátorem i generátorem JSON schématu. Diagnostika obsahuje zdroj, název
komponenty a cestu k chybnému poli. [Reference zápisu](../docs/reference/component-catalog.md)
popisuje rozdělení odpovědností i připojení schématu do editoru.

`asset-report.php --check --assembly=platform` ověřuje kombinovaný výběr
Blog + Social proti stejným rozpočtům. Tuto nadmnožinu kontroluje také
`tests/assets/budgets.php`; nepřidává druhý úplný build do testovací skupiny.

## Release, data a měření

Živá instalace `tinycat.test` má root `E:/laragon/www/tinycat`, nikoli tento
checkout. Provozní CLI patří do externí instalace; u vývojových nástrojů
ověř podporovaný cílový parametr a skutečný root před datovou operací.
Lokální konfigurace checkoutu je jen CLI/test odkaz. [Cesty a synchronizace](../docs/development/local-environment.md).

Lokální release kopie drž jen pro poslední tři publikované verze; shodné
stažené balíčky a pracovní buildy nehromaď. Tato retence nemaže Git historii,
publikované migrace, klíče, média ani rollback zálohy. Auditní buildy nejsou
oficiální release. Provedený [úklid a měření](../docs/audits/test-optimization-and-local-cleanup-2026-11-12.md)
uvádí zachované cesty a výjimky; builder nic nemaže automaticky.

- [Aktualizace a podpisy](../docs/operations/updates.md): `tools/release/build-update.php`,
  `tools/release/update-key.php`, `tools/release/verify-update.php`. Ruční kontrolu existujícího balíčku
  zajišťuje `tools/release/artifact-preflight.php`; produktový MySQL update scénář má
  `tools/release/release-update-rehearsal.php`: předaný artefakt zkouší na samostatné
  MySQL database against published 6.0.10, including data and standard rollback.
  The existing `tests/release/update-rehearsal.php` exercises this supported baseline.
  Nejde o běžné bezparametrové kontroly.
- [Import a porovnání verzí](../docs/performance/benchmark.md):
  `tools/data/demo-import.php`, `tools/performance/compare-performance.php`, `tools/performance/scale-benchmark.php`.
- [HTTP a SQL profilování](../docs/performance/profiling.md):
  `tools/performance/prepare-performance.php`, `tools/performance/profile-performance.php`, `tools/performance/instrument-performance.php`.
- [Text příspěvků](../docs/contracts/post-text-contract.md): `tools/maintenance/post-text-cleanup.php`.
- [Odkazy](../docs/operations/link-optimization.md): `tools/maintenance/optimize-links.php`.
  `tools/maintenance/audit-links.php` používá stejný plán optimalizátoru pro aktuální sociální tabulky, vždy bez zápisu.
- [YouTube náhledy](../docs/operations/youtube-thumbnails.md): `tools/maintenance/youtube-thumbnail-cleanup.php`.
- [SQL přiřazení RSS zdrojů](maintenance/sql/bot-source-attribution/README.md).

Příkazy pro import, přípravu DB, údržbu dat a produktové rehearsal mají vedlejší
účinky; konkrétní argumenty a předpoklady určuje příslušný návod a implementace.
Nespouštěj celý adresář jako testovací sadu.

For a release, `php tools/release/build-update.php --version=X.Y.Z --both --output=dist/release-X.Y.Z`
prepares signed update/install variants from one compilation. `release/zip-timestamps.php`
and `release/publish-artifact.php` are internal build helpers, not standalone commands.

New packages require TinyCat 6.0.10 or newer across all assemblies. The builder
rejects older minimum versions and the retired `--legacy-upgrade` option.
`release-update-rehearsal.php --baseline-version=6.0.10 --direct-web --cached-autoload`
checks a supplied successor artifact against the published 6.0.10 baseline in a disposable
MySQL database. Compatibility bridge builds and old 5.x rehearsals are retired.
Before deployment, refresh inherited rescue launchers with the installed 6.0.10 CLI
(`php App/Console.php rescue`) and verify their `help`; see the
[operator prerequisite](../docs/operations/updates.md#retiring-the-autoload-facade).
Published migrations and local rollback backups remain intact.

`--assembly=blog` sestaví samostatný produkt; `--product=vendor.product` a
`--active=id1,id2` určují vlastní identitu a výchozí aktivaci. Distribuce,
produkční CLI `App/Console.php`, migrace a rescue rollback mají společný
[provozní návod](../docs/operations/module-deployment.md). CLI je součást aplikace,
nikoli vývojových tools.

## Struktura

Samostatný modul sestaví `php tools/release/build-module.php
--module=App/Modules/Blog --version=1.0.0 --output=storage/releases/modules`.
Vstupem je přímý modulový adresář s manifestem; výstupem ZIP s inventářem,
checksumy a migrační historií. `--minimum-core` a `--minimum-php` deklarují
kompatibilitu. Instalaci přes administraci nebo `module-install` popisuje
[manuál autora](../docs/development/module-authoring.md#8-samostatný-instalační-zip).
Core bez volitelných modulů sestaví `build-update.php --assembly=core`;
explicitní `--active=` nastaví prázdný výchozí seznam aktivací.

| Složka | Obsah |
| --- | --- |
| `quality/` | Lint, styl, security a kontrakty UI a překladů. |
| `assets/` | Kompilace assetů, report a generátor UI inventáře. |
| `release/` | Build, podpisy, ověření, rehearsal a mazací manifest. |
| `data/` | Demo importér, jeho knihovny a kompatibilní alias seed. |
| `maintenance/` | Údržba dat a SQL přiřazení RSS zdrojů. |
| `performance/` | Obecné měřicí příkazy a podpůrné `lib/`. |
| `experiments/` | AJAX, kritické assety, dotazové experimenty a jejich společný transport. |

V kořeni zůstává stabilní `preflight.php` a tento rozcestník. Ignorovaný lokální
`captcha-test/` zůstává oddělený a není součástí verzovaných vývojových nástrojů.

[Audit nástrojů](../docs/audits/tools-2026-11-10.md) a
[inventář s daty a cílovými cestami](../docs/audits/tools-inventory-2026-11-10.json)
rozlišují aktivní soubory, historické postupy a experimenty. Přesuny do podsložek
jsou provedené; inventář uchovává původní cesty a doplněné současné umístění.
Historická Stage 8 sada a dokončený jednorázový úklidový skript byly odstraněny.
Staré browser experimenty zatím zůstávají k přezkoumání. `tools/data/seed.php` je
kompatibilní alias importéru.

The release update rehearsal accepts `--snapshot=/path/to/indexed-snapshot` to restore a supplied snapshot into its newly created temporary database instead of synthetic rows. The source snapshot and configured database are never modified. `--cached-autoload` enables CLI OPcache with timestamp validation disabled to exercise cached 5.x code during replacement.

Development outputs use ignored `.work/`, separated from application `storage/`.
See [filesystem storage](../docs/development/storage.md#development-outputs).


### Status symbols

`php tools/assets/status-icons.php --write` refreshes the marked generated CSS-mask
section in the core UI tokens from the four canonical symbols in `assets/icons.svg`.
Without `--write` it checks freshness. The existing UI-contract check also verifies
this output. Do not edit generated SVG mask data by hand. Runtime uses ordinary
CSS and needs no generator, JavaScript hydration or additional image requests.
