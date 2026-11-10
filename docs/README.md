# Dokumentace TinyCat

Platné návody a kontrakty jsou rozdělené podle účelu. Příkazy spouštěj z kořene
repozitáře, pokud konkrétní návod neurčuje jinak.

| Složka | Obsah |
| --- | --- |
| [contracts](contracts/ui-conventions.md) | Závazná pravidla pro změny aplikace. |
| [reference](reference/api.md) | HTTP API a generovaný inventář UI. |
| [operations](operations/updates.md) | Aktualizace a údržba provozních dat. |
| [development](development/partials.md) | Sdílené vykreslování, storage a modulární API. |
| [performance](performance/profiling.md) | Opakovatelné profilování a porovnávání verzí. |
| [plans](plans/modularity-6.0.0.md) | Otevřené návrhy; nejsou popisem aktuální implementace. |
| [audits](audits/repository-cleanup-2026-11-10.md) | Audit repozitáře a [nástrojů](audits/tools-2026-11-10.md). |

## Kontrakty

- [Modaly](contracts/modal-contract.md) a [popovery](contracts/popover-contract.md).
- [UI komponenty a styly](contracts/ui-conventions.md).
- [Administrační seznamy a editory](contracts/admin-ui-conventions.md).
- [Text příspěvků](contracts/post-text-contract.md).
- [Výběr a stránkování dat](contracts/query-selection-standard.md).
- [Překlady, katalogy a prezentační hranice API](contracts/translations.md).

## Reference a vývoj

- [HTTP API](reference/api.md).
- [Inventář UI](reference/ui-inventory.md) a [strojový výstup](reference/ui-inventory.json).
  Obnovují se příkazem `python tools/assets/ui-inventory.py`.
- [Sdílené vykreslování a partialy](development/partials.md).
- [Filesystem storage](development/storage.md).
- [Moduly a HTTP transport](development/modules.md).
- [Přehled nástrojů](../tools/README.md) a [audit jejich stáří a použití](audits/tools-2026-11-10.md).
- [Testovací sada a prostředí](../tests/README.md) a [audit struktury testů](audits/tests-2026-11-10.md).
- [Audit sestavení release balíčku](audits/release-build-2026-11-10.md) a
  [naměřená data](audits/release-build-measurements-2026-11-10.json).
- [Provedené optimalizace sestavení](audits/release-build-optimization.md).

## Provoz a měření

- [Podepsané aktualizace, vydávání a obnova](operations/updates.md).
- [Úklid duplicit botích příspěvků](operations/bot-duplicate-cleanup.md).
- [Jednorázová optimalizace odkazů](operations/link-optimization.md).
- [YouTube náhledy a úklid starých obrázků](operations/youtube-thumbnails.md).
- [Profilování](performance/profiling.md) a [porovnávací benchmark](performance/benchmark.md).

## Údržba dokumentace

Kontrakty aktualizuj spolu s implementací. Generované inventáře obnovuj jejich
nástrojem; měření ukládej do ignorovaného adresáře konkrétního běhu pod
`storage/performance/`, nikoli mezi platné návody. Uzavřené reporty starých
refaktorů a releasů byly odstraněny; jejich původní obsah zůstává v historii Gitu.
Historii vydání udržuje [CHANGELOG](../CHANGELOG.md).

Distribuční balíčky obsahují pouze `reference/api.md` a `operations/updates.md`.
Ostatní dokumentace slouží práci se zdrojovým repozitářem.
