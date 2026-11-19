# Vlastnictví prezentační struktury — 19. 11. 2026

Navazující úklid sjednocuje produktovou strukturu editorů a stránek do katalogů
vlastníků. Nezavádí další šablonovací jazyk ani soubor pro každou komponentu.

## Provedené změny

- Editor účtu, avataru, administračního uživatele, nastavení a statusové dialogy
  používají komponenty. JavaScript připravuje model, transport a lifecycle.
- Katalogy vlastní také seznam sledovaných účtů, historii komentářů, moderátorský
  náhled, botí workspace, potvrzení importu a formulář Blog zdroje.
- Stránky notifikací, plánovače a Blogu a nabídky účtu a notifikací nahrazují osm
  PHP šablon. Následně byla převedena i veřejná hlavička s navigací, hledáním
  a výběrem jazyka; celkem jde o devět šablon. PHP připravuje data a explicitní
  důvěryhodné sloty. Hlavička je serverová komponenta a nepřibaluje se klientovi.
- Sdílená factory `ui.form(url, method, view)` přijímá hotový komponentový formulář
  a doplňuje společný transport a ochranu neuložených změn.
- Označené controllery `@component-owned` kontroluje existující modalový build
  guard. Zabraňuje návratu běžných ručních DOM builderů; není bezpečnostním
  sandboxem ani úplnou analýzou libovolného JavaScriptu. Technický iframe
  náhledu si ponechává kontrolu stejného originu a disposal observeru.

## Co zůstává

Inventář po změně eviduje 181 komponent, 18 PHP šablon a tři partialy. Zbývající
šablony zahrnují dokumentové a stránkové obálky; samotná přípona PHP neznamená
legacy dialog. Sdílené renderery, popovery, dialogy a technické adaptéry nadále
pracují s DOM. Jejich mechanismy mají vlastníka a nejsou druhou implementací
produktového formuláře. Tato etapa proto netvrdí, že aplikace nemá ruční DOM.

Zbývají také produktové šablony hledání, informačních stránek, diagnostiky
a updateru, nikoli pouze technické obálky. Jejich seznam uvádí
[generovaný inventář](../reference/ui-inventory.json). Tato etapa je nepřevádí.

## Ověření

Cílené kontroly syntaxe, stylu, PHPStan, hranic, render-only šablon, překladů,
komponent a assetů prošly. Nativní regrese ověřily účty a avatary, validaci,
komentářové modaly, historii, dotykové popovery a responzivní administraci.
Klientské rozpočty zůstaly beze změny; administrační formulář a jeho specifické
zprávy se doručují jen příslušné administrační trase.

Živá kontrola používá externí instalaci bez ukládání změn formulářů. Ověřeny jsou
účet, avatar, statusový editor a nastavení; hesla zůstávají prázdná. Anonymní
hlavička byla vizuálně ověřena ve WebKitu v šířkách 390 a 1280 px, včetně SVG
vlajek, hledání a absence horizontálního přetečení. Jde o synchronizaci změněných
runtime souborů, nikoli o vydání nebo úplnou aktualizaci instalace.

Úplný `composer preflight` prošel za **890,18 s**: 137 položek úspěšně, jedna
přeskočená a jedna nevybraná. Všech 39 frontendových scénářů prošlo, stejně jako
izolovaný i kombinovaný Blog, HTTP API, podepsané balíčky, aktualizace z 6.0.10,
obnova přerušené aktualizace a přesný rollback. `source_changes_during_run` je
prázdné. [Měřený report](../../.work/tests/runs/20261119-093736-7c4d0a3ec0/report.json).

Memcached integrace byla přeskočena kvůli chybějícímu rozšíření. Modulový katalog
navíc přeskočil dílčí symlink fixture, protože prostředí nepovoluje vytvoření
symlinku. Archivní benchmark 2.0.25 nebyl vybrán; nejde o měření současné aplikace.
Po běhu byla doplněna pouze výsledková dokumentace.

Porovnání připravených veřejných balíků před touto etapou a po ní, pro největší
jazykovou variantu a gzip úrovně 9:

| Přenos JavaScriptu na veřejné trase | Před etapou | Po etapě |
| --- | ---: | ---: |
| Nezkomprimováno | 365 242 B | 367 461 B |
| Gzip | 78 165 B | 77 701 B |

Jde o čtyři skutečně doručované skripty včetně komponent a překladů. Rozdíl
velikosti přenosu není benchmark renderování ani důkaz rychlejšího běhu.
Podklady a živé kontroly shrnuje
[lokální záznam ověření](../../.work/presentation-ownership/verification.json).
