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

`tools/assets/compile-assets.php`, `tools/quality/modal-contracts.php`, `tools/quality/ui-contracts.php`,
`tools/quality/translation-catalogs.php` a `tools/quality/client-translations.php` poskytují načítané build
funkce; nejde ve všech případech o samostatné CLI příkazy. Používej jejich
runner nebo release builder.

## Release, data a měření

- [Aktualizace a podpisy](../docs/operations/updates.md): `tools/release/build-update.php`,
  `tools/release/update-key.php`, `tools/release/verify-update.php`. Ruční kontrolu existujícího balíčku
  zajišťuje `tools/release/artifact-preflight.php`; produktový MySQL update scénář má
  `tools/release/release-update-rehearsal.php`. Nejde o běžné bezparametrové kontroly.
- [Import a porovnání verzí](../docs/performance/benchmark.md):
  `tools/data/demo-import.php`, `tools/performance/compare-performance.php`, `tools/performance/scale-benchmark.php`.
- [HTTP a SQL profilování](../docs/performance/profiling.md):
  `tools/performance/prepare-performance.php`, `tools/performance/profile-performance.php`, `tools/performance/instrument-performance.php`.
- [Text příspěvků](../docs/contracts/post-text-contract.md): `tools/maintenance/post-text-cleanup.php`.
- [Odkazy](../docs/operations/link-optimization.md): `tools/maintenance/optimize-links.php`.
  `tools/maintenance/audit-links.php` samostatně kontroluje normalizační migraci bez aplikace změn.
- [YouTube náhledy](../docs/operations/youtube-thumbnails.md): `tools/maintenance/youtube-thumbnail-cleanup.php`.
- [SQL přiřazení RSS zdrojů](maintenance/sql/bot-source-attribution/README.md).

Příkazy pro import, přípravu DB, údržbu dat a produktové rehearsal mají vedlejší
účinky; konkrétní argumenty a předpoklady určuje příslušný návod a implementace.
Nespouštěj celý adresář jako testovací sadu.

For a release, `php tools/release/build-update.php --version=X.Y.Z --both --output=dist/release-X.Y.Z`
prepares signed update/install variants from one compilation. `release/zip-timestamps.php`
and `release/publish-artifact.php` are internal build helpers, not standalone commands.

## Struktura

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
