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
| [plans](plans/modular-platform.md) | Otevřené návrhy; nejsou popisem aktuální implementace. |
| [audits](audits/repository-cleanup-2026-11-10.md) | Audit repozitáře a [nástrojů](audits/tools-2026-11-10.md). |

## Kontrakty

- [Modaly](contracts/modal-contract.md) a [popovery](contracts/popover-contract.md).
- [UI komponenty a styly](contracts/ui-conventions.md).
- [Administrační seznamy a editory](contracts/admin-ui-conventions.md).
- [Text příspěvků](contracts/post-text-contract.md).
- [Výběr a stránkování dat](contracts/query-selection-standard.md).
- [Překlady, katalogy a prezentační hranice API](contracts/translations.md).

## Reference a vývoj

- [Jak vytvořit modul — vstupní manuál a příklady](development/module-authoring.md).
- [HTTP API](reference/api.md).
- [Inventář UI](reference/ui-inventory.md) a [strojový výstup](reference/ui-inventory.json).
  Obnovují se příkazem `python tools/assets/ui-inventory.py`.
- [Sdílené vykreslování a partialy](development/partials.md).
- [Dom?nov? data, ?ist? core a p?evod historick?ho ?lo?i?t?](development/domain-storage.md).
- [Filesystem storage](development/storage.md).
- [Moduly a HTTP transport](development/modules.md).
- [Core, vlastnictví modulů a jejich inicializace](development/core-and-modules.md).
- [Audit významových hranic core a modulů](audits/core-module-boundaries-2026-11-12.md)
  a [plán platformních služeb a kombinovatelných funkcí](plans/core-services-and-feature-modules.md).
- [Platformní služby: implementace a ověření etapy 1A](audits/core-services-stage-1a-2026-11-12.md).
- [Identita, schránka, health a společný HTTP tok](audits/core-services-stage-1b-2026-11-12.md).
- [Dokončení adaptérů, nastavení, šablon a nasazení Blog + Social](audits/platform-completion-2026-11-12.md).
- [Dokončení společného hledání, sitemap, importu a core historie úloh](audits/platform-discovery-and-tasks-2026-11-12.md).
- [Správce modulů a sjednocení Blog UI](audits/module-manager-and-blog-ui-2026-11-12.md).
- [Blog: společné AJAX seznamy, filtry a resource editory](audits/blog-administration-ui-2026-11-12.md).
- [Zbývající sdílené schopnosti v Socialu](audits/social-core-capabilities-2026-11-12.md)
  a [plán jejich oddělení do core](plans/social-core-capabilities.md).
- [Veřejný framework: etapa 1](audits/social-core-stage1-2026-11-13.md).
- [Sdílený Publishing a obsahové politiky: etapa 2](audits/social-core-stage2-2026-11-13.md).
- [Společné diskuse a reakce: etapa 3](audits/social-core-stage3-2026-11-13.md).
- [Společné feedové importy pro Social a Blog: etapa 4](audits/social-core-stage4-2026-11-14.md).
- [Správa zdrojů článků a feedové importy](operations/feed-imports.md).
- [Živá testovací instalace a Laragon](development/local-environment.md).
- [Blogová sestava nad Accounts a Publishing](development/blog-assembly.md).
- [Instalace, aktivace modulů a obnova produktové distribuce](operations/module-deployment.md).
- [Samostatné ZIPy a správce modulů](audits/module-packages-2026-11-14.md).
- [Návrh modulární platformy: jádro, moduly a sestavy](plans/modular-platform.md).
- [Implementační plán modulární platformy](plans/modular-platform-implementation.md).
- [Rebuild: odstranění Composition, jednotné moduly a měřené testy](plans/module-rebuild.md).
- [Ověření rebuildu a naměřené náklady testů](audits/module-rebuild-verification-2026-11-11.md).
- [První optimalizace testů a úklid lokálních verzí](audits/test-optimization-and-local-cleanup-2026-11-12.md).
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

Distribuční balíčky obsahují pouze `reference/api.md`, `operations/updates.md`
a `operations/module-deployment.md`.
Ostatní dokumentace slouží práci se zdrojovým repozitářem.
