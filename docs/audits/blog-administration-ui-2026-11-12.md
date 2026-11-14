# Blog: sjednocená administrace

Datum: 2026-11-12. Implementováno a synchronizováno na `https://tinycat.test/`.
Runtime zůstává `6.0.0-dev.6`; tato etapa nevydává novou veřejnou verzi.

## Zjištění a oprava

Blog používal společný administrační rám, ale vlastní HTML seznam a celostránkový
editor. Nebyl připojen k existujícímu transportu seznamů, filtrům ani resource
modalům. Samotné vizuální obalení přes `AdminViews::listCard` nestačilo.

Administrace nyní používá společné komponenty `ui/list-toolbar`,
`ui/sort-heading`, `ui/pagination`, `ui/modal-trigger`, `ui/api-action`,
prázdný stav a statusové indikátory. PHP i AJAX vykreslují jeden katalog
`App/Modules/Blog/assets/components.json`. Řádkové akce jsou malé ikonové
ovladače s kontextovým přístupným názvem. Výpis má standardní hledání,
filtry viditelnosti a autora, odnímatelné chips, řazení a velikost stránky.

`Blog/src/Administration.php` vlastní výběr dat a SQL allowlist. Hledání
doslova escapuje `%`, `_` a `!`; rozlišuje publikované, skryté a naplánované
články. Stránkuje omezená ID a teprve pak načítá metadata. Dlouhá těla nejsou
součástí seznamu. Každé řazení má jednoznačné ID jako poslední klíč.

Administrátor získává seznam z `/api/articles/management`. Resource
`/api/articles/creation` nebo `/api/articles/{id}/management` dodá editoru
aktuální data, oprávnění a omezení. Nevkládá HTML ani překladové identifikátory
do API. Všechny management resources kontrolují administrátora; zápisy navíc CSRF.

`Blog/assets/index.js` registruje modul a seznam do `TinyCat.modules` a
`TinyCat.lists`. Editor vytváří až resource loader přes `ui.form()` a
`ui.adminDialog()`. Filtry používají `ui.filterPanel()` a společný popover.
Mutace obnovují aktuální seznam se zachováním parametrů; otevřený koncept
přežije refresh pozadí. Potvrzení mazání, validace, fokus, historie a zavírání
zůstávají ve společných službách. Staré šablony editoru a jeho akce jsou odstraněné;
přímé GET odkazy na nový nebo existující článek otevřou tentýž editor nad seznamem.

Blog nemá klientskou závislost na Socialu. Jeho administrační JS a komponenty
se doručují pouze pro `/admin/articles`, nikoli všem administračním stránkám.
Přibyl samostatný měřený assetový scope se čtyřmi skripty a jedním stylesheetem.
Existující rozpočty ostatních stránek se nezvyšovaly. Kontrola zdrojové sestavy
připouští podmnožinu scope volitelných modulů; kombinovaná platforma stále
ověřuje celý inventář rozpočtů.

[Návod Blogu](../development/blog-assembly.md) popisuje i konkrétní vzor pro
další modul. Mobilní část administračního kontraktu byla opravena podle skutečné
implementace a stávajících regresí: nativní tabulky s lokálním vodorovným posunem
a řazením v původních hlavičkách. Neexistující mobilní kartový renderer se již
neuvádí jako platný stav.

## Ověření a měření

Na PHP 8.4.12 proběhl jeden úplný `composer preflight`:

| Kontrola | Výsledek / čas |
| --- | --- |
| Celý preflight | 590,087 s, tedy 9 min 50 s; úspěch |
| Hlavní sada | 87 skupin prošlo, 1 přeskočena |
| Frontendové scénáře | 38/38; 152,024 s za celou frontendovou skupinu |
| Blog: izolace, HTTP, souběh a browser | 40,982 s |
| Podepsaný modulový lifecycle | 106,430 s |
| Aktualizace a přesný rollback | 72,723 s |
| HTTP API kontrakty | 45,702 s |

Memcached integrace byla přeskočena kvůli nedostupnému lokálnímu serveru.
Katalogový test přeskočil také dílčí scénář symlinků, které prostředí neumělo vytvořit.
PHP lint, styl, PHPStan 8, překlady, závislosti a bezpečnostní kontroly prošly.

Během preflightu vizuální kontrola odhalila chybějící `icon_only` na akci úpravy.
Po opravě definice a doplnění browserové kontroly rozměrů proběhl ještě cílený
běh tří ovlivněných skupin: UI kontrakty, Blog a assetové rozpočty. Všechny prošly
za 44,901 s; Blog za 39,146 s. Report úplného běhu tyto dvě změny souborů
pravdivě zaznamenává. Nejde o druhý úplný preflight.

Rozšířena byla existující Blog fixture, nikoli další úplná integrační kopie:
AJAX hledání a prázdný výsledek, chips, automatické filtry a ponechání popoveru,
řazení, velikost stránky, stránkování, vytvoření, úprava, potvrzené smazání,
zachování konceptu při refreshi a samostatné vypnutí Socialu. Ověřuje čtrnáct
responzivních layoutů a rozměry řádkových akcí. Počet hlavních skupin zůstává 88.

Měřené reporty jsou v ignorovaném storage:

- `storage/test-runs/20261112-195752-4986eda316/report.json`
- `storage/test-runs/20261112-200434-ee48c2aff2/report.json`
- `storage/blog-admin-20261112/verification-summary.json`
- `storage/blog-admin-20261112/screenshots/`

Inventář UI byl obnoven: 103 komponent, bez duplicitních názvů a nevyřešených
závislostí. Prošlo všech 10 platformních a 9 zdrojových assetových scope.

## Živá instalace

Do externího rootu `E:/laragon/www/tinycat` se přeneslo 36 konkrétních změn:
12 modulových cest včetně odstranění dvou starých šablon a 24 generovaných assetů.
Publikované migrační soubory se nekopírovaly. Synchronizace držela sdílený zámek
existujícího deployment mechanismu přes `Manager::runTask`, aby se nekřížila
s aktivací modulů či updaterem. Každý měněný cíl prošel kontrolou původního
a výsledného hashe. Záloha původních souborů:
`E:/laragon/backup/tinycat-blog-admin-20261112-210808.zip`.

Výběr modulů nebyl měněn. Při dokončení je aktivní pouze Blog; Social zůstává
nainstalovaný a vypnutý podle aktuální konfigurace instalace. Maintenance je vypnutá.

Prošlo 41 HTTPS kontrol se standardním ověřením TLS: Blog, core, přístupová
pravidla, nové chráněné resources a pět skutečných administračních assetů pro
aktuální aktivní výběr. Osm čtecích scénářů navíc ověřilo nové SQL nad živou MySQL.
Žádné testovací články se na živém webu nevytvářely ani nemazaly.

Před/po přenosu se shoduje hash publikací, všechny počty tabulek, konfigurace
a inventář médií: 11 632 publikací, 2 články, 38 účtů a 9 141 souborů médií.
Konfigurace, aktivace, data, klíče a média se nekopírovaly ani nepřepisovaly.

Kontrolu přihlášené živé stránky přes Computer Use ukončil nástroj, protože
nedokázal dostatečně spolehlivě určit aktuální URL okna. Do okna se poté
nezasahovalo. Browserové interakce a screenshoty proto pocházejí z izolované
fixture; živé ověření tvoří HTTPS a čtecí MySQL kontroly uvedené výše.
