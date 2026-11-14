# Jak vytvořit modul TinyCat

Vstupní manuál pro autora funkčního modulu. Začni hranicí jeho odpovědnosti,
pak manifestem, registrací a stránkou. Další schopnosti přidávej podle potřeby.
Praktickým obsahovým vzorem je [Blog](../../App/Modules/Blog/).
Podrobnosti doplňují [architektura](core-and-modules.md),
[transport](modules.md) a [instalace a aktivace](../operations/module-deployment.md).
Audity a otevřené plány nejsou referencí aktuálního API.

## Co poskytuje core a co vlastní modul

Core je povinný framework. Modul vlastní konkrétní funkci od registrace přes
data a pravidla až po UI. Vypnutí odstraní runtime příspěvky, zachová data.

| Potřeba | Core poskytuje | Modul dodá |
| --- | --- | --- |
| Účty | Identity, přihlášení, správu a oprávnění | Doménové vazby a pravidla. |
| Doménová data | DB, transakce, parsery textu/odkazů a soubory | Vlastní entity, tabulky, FK, pravidla a repository. |
| Diskuse a reakce | Formátování, editor, revize a AJAX UI | Vlastní tabulky, autorizovaný cíl, transakce a události. |
| Importy | FeedParser a FeedPipeline | FeedSourceStore, FeedTarget a vlastní zdroje. |
| Úlohy | Tasks, zámky, historii a rozhraní | ScheduledTask a pracovní callback. |
| Nastavení | Settings a společný editor | Vlastní klíče, sekce, validaci a překlady. |
| Oznámení a pošta | Notifications a Mail | Událost, příjemce, odkazy a vlastní šablony. |
| Hledání a sitemap | Discovery a skládání zdrojů | SQL, viditelnost a kanonické URL. |
| Prezentace | Rám, komponenty, AJAX, formuláře a dialogy | Stránky, pole, data a akce vlastní funkce. |

Modul nemusí používat všechny schopnosti. Veřejná stránka funguje bez Socialu.
Nevytvářej druhý HTTP klient, DB vrstvu, scheduler, překladač nebo modalový systém.
Závislost na jiném funkčním modulu musí být výslovná a skutečně potřebná.

## 1. Adresář a manifest

Následující `Notes` je minimální informační modul bez databáze. Je to ukázka,
nikoli předinstalovaný modul. ID, namespace, URL a komponenty mají vlastní prefix.

```text
App/Modules/Notes/
  module.json
  src/Module.php
  src/Pages.php
  assets/module.json
  assets/components.json
  lang/cs.json
  lang/en.json
  … další podporované jazyky
```

Podle potřeby přidej `templates/`, `migrations/`, `emails/`, JS a CSS.
Prázdné zdroje nemusíš deklarovat. `module.json`:

```json
{
  "format": 1,
  "coreApi": 1,
  "id": "tinycat.notes",
  "namespace": "TinyCat\\Notes\\",
  "requires": [],
  "providers": {
    "routes": "TinyCat\\Notes\\Module",
    "contributions": "TinyCat\\Notes\\Module"
  },
  "resources": {
    "translations": "lang",
    "assets": "assets/module.json",
    "components": "assets/components.json"
  }
}
```

Cesty `resources` jsou relativní ke kořeni modulu, PHP třídy patří do `src/`.
Deklaruj pouze skutečně používané frameworkové schopnosti a funkční moduly.
`tinycat.publishing` již není core schopnost ani nový modul. Celou entitu a její
vazby definuj ve vlastním schématu; viz [doménová data](domain-storage.md).

Do výběru zdrojové pracovní kopie v `App/modules.php` přidej:

```php
return [
    'tinycat.notes' => 'App/Modules/Notes/module.json',
];
```

Pokud zachováváš Social či Blog, ponech i jejich manifesty. Samotný adresář
modul neaktivuje. V sestavené instalaci dostupnost určuje distribuční descriptor
a aktivaci spravuje lifecycle. Vlastní distribuční profil je potřeba jen pro
samostatnou instalační sestavu, ne pro každý modul.

## 2. Registrace stránek a menu

`src/Module.php`:

```php
<?php
declare(strict_types=1);
namespace TinyCat\Notes;

use TinyCat\Core\Modules\{Contributions, Module as ModuleContract, NavigationItem, ProvidesContributions};
use TinyCat\Http\{Api, Router};
use TinyCat\Support\Localization;

final class Module implements ModuleContract, ProvidesContributions
{
    public static function register(Api $api): void
    {
        Router::route(['GET', 'HEAD'], '/notes', Pages::listing(...));
        Router::route('GET', '/admin/notes', Pages::administration(...));
    }

    public static function contributions(): Contributions
    {
        return new Contributions(navigation: [
            new NavigationItem('tinycat.notes.admin', static fn (): array => [
                'href' => '/admin/notes',
                'icon' => 'file-text',
                'label' => Localization::t('notes.title'),
            ], 40),
        ]);
    }
}
```

Registrace sestavuje definice. Neprovádí SQL, session, DDL, poštu ani provozní
zápisy. Překlad menu se proto vyhodnotí až v render callbacku. Nižší pořadí
řadí položku výše, podmenu tvoří pole `children`. Ikona musí existovat jako
symbol ve společném [`assets/icons.svg`](../../assets/icons.svg).
Menu nezakládá routu ani nenahrazuje autorizaci její obsluhy.

`src/Pages.php`:

```php
<?php
declare(strict_types=1);
namespace TinyCat\Notes;

use TinyCat\Core\Identity\Access;
use TinyCat\Core\Ui\Page;
use TinyCat\Support\{Components, Localization};

final class Pages
{
    public static function listing(): void
    {
        $title = Localization::t('notes.title');
        $content = Components::render('notes/intro', [
            'title' => $title,
            'description' => Localization::t('notes.description'),
        ]);
        Page::render(['title' => $title, 'current' => '/notes', 'public' => true], $content);
    }

    public static function administration(): void
    {
        Access::require_admin();
        $content = Components::render('ui/empty-state', [
            'label' => Localization::t('notes.description'),
            'icon' => 'file-text',
        ]);
        Page::render(['title' => Localization::t('notes.title'), 'current' => '/admin/notes'], $content);
    }
}
```

Core sestaví dokument, metadata, jazyky a rám. Veřejná ukázka má vlastní nadpis;
v administraci jej poskytuje horní lišta. Složitější stránka může použít vlastní
PHP šablonu přes `View::render()` a výsledek předat stejnému `Page::render()`.
Šablona pouze vykresluje připravená data, obsluha autorizuje a provádí dotazy.

Homepage a dashboard se nabízejí přes `Contributions.homepages` a `dashboards`
pomocí `EntryPage`; obsluhu `/` a `/admin` vybírá core podle nastavení.
Vlastní routa modulu zůstává samostatná. Jazykové prefixy veřejných URL deklaruj
přes `LocalizedRoute`, včetně povolených query parametrů. `PageTheme` nabízí
vlastní veřejné rozložení nad společným dokumentem a headem.

## 3. Komponenty, assety a překlady

`assets/components.json` definuje stejné UI pro PHP i JavaScript:

```json
{
  "notes/intro": {
    "tag": "section",
    "attrs": { "class": "section stack stack-gap-16" },
    "children": [
      { "tag": "h1", "children": [{ "get": "title" }] },
      { "tag": "p", "children": [{ "get": "description" }] }
    ]
  }
}
```

Server volá `Components::render('notes/intro', $data)`, klient
`TinyCat.components.render('notes/intro', data)`. Hodnoty jsou bezpečně
vykreslené jako text. Pro stejný seznam či editor nevytvářej druhé ruční HTML
v JavaScriptu. Společné prvky vybírej z `ui/*` a `publishing/*`.

`assets/module.json` určí doručení zdrojů:

```json
[
  {
    "routes": ["/notes"],
    "entries": [],
    "components": ["notes/intro"],
    "styles": [],
    "translations": []
  }
]
```

JS a CSS cesty zde jsou relativní ke kořeni repozitáře, například
`App/Modules/Notes/assets/index.js`. `routes` vybírá konkrétní URL prefix
nebo skupinu `public` či `admin`. Přes `uses` vybírej pojmenované schopnosti,
například `publishing.presentation`, `publishing.editor`, `publishing.media`
a `publishing.discussion`; nekopíruj jejich seznamy interních souborů.

Ukázka `lang/cs.json`:

```json
{
  "notes": {
    "title": "Poznámky",
    "description": "Informační stránka modulu Poznámky."
  }
}
```

Stejné klíče doplň ve všech 12 podporovaných jazycích, včetně shody parametrů
a plurálů. Klíče volané JavaScriptem deklaruj v `translations` příslušného scope;
komponentové překlady mají deklarativní závislosti. Tato ukázka předává serverem
přeložené texty a vlastní JS nepotřebuje. Generované katalogy ani `assets/build/`
neupravuj ručně. Podrobnosti: [překlady](../contracts/translations.md).

## 4. Data, migrace a API

Modul bez vlastních tabulek nepotřebuje schema provider. Pro vlastní data
implementuj `ProvidesSchema`, deklaruj provider `schema` v manifestu a vrať
`ModuleSchema`: aktuální verzi, vlastněné tabulky, fresh instalaci a úplnou
posloupnost `SchemaMigration` od verze 1. Soubory patří do vlastního
`migrations/`, deklarovaného v `resources`. Vzor: [Blog/Schema](../../App/Modules/Blog/src/Schema.php)
a [Blog/Module](../../App/Modules/Blog/src/Module.php).

Používej vlastní prefix, například `notes_entries`. Modul vlastní celý záznam
a jeho vztahy; neodkazuje na univerzální `content` ani nerozlišuje cizí entity
přes `kind`. Komentáře a přílohy podle potřeby odkazují skutečným FK na vlastní
entitu. Tabulky core nepřihlašuj jako vlastnictví modulu.

Publikované migrační soubory, ID a checksumy jsou neměnné. Změna znamená novou
migraci a aktualizaci současného instalačního schématu; fresh a upgrade musí
dosáhnout stejného stavu. `ProvidesManagedSchemas` řeší historické streamy,
není výchozím vzorem nového modulu. Na MySQL má účet `INT UNSIGNED`;
vazby na účet musí mít odpovídající typ. Vlastní entity a jejich vztahy
zapisuj atomicky. Média a metadata připrav před transakcí, staré soubory
maž po commitu.

Povinný framework se vždy inicializuje před funkčními manifesty. `coreApi`
deklaruje jeho kontrakt; `requires` potřebuje uvádět funkční modulové závislosti,
nikoli aktivaci povinných služeb core. Historický vlastník může implementovat
`ProvidesLegacyUpgrade::prepareLegacyUpgrade(PDO, callable)`: pouze explicitní
aktualizace zavolá tento kontrakt a vlastník rozhodne, zda má spustit předaný
ověřený globální migrační stream. Registrace tento callback nevyhodnocuje.

API registruj přes předaný `TinyCat\Http\Api`. Databázové API stejně jako Blog
obal kontrolou připravenosti schématu přes `whenReady`. Server ověřuje oprávnění
při každém čtení i zápisu; zápisy respektují CSRF a společnou JSON obálku.
Resources vracejí data, oprávnění a omezení, nikoli HTML, komponentové definice
nebo překladové identifikátory. Viz [HTTP API](../reference/api.md).

## 5. Administrativní seznamy a modaly

Navazuj na [Blog/Administration](../../App/Modules/Blog/src/Administration.php)
a [Blog klienta](../../App/Modules/Blog/assets/index.js). Selekci a pravidla
vlastní modul, společné chování poskytuje core:

- PHP a AJAX vykreslují stejnou komponentu seznamu.
- Rám tvoří `AdminViews::listCard`, `ui/list-toolbar`, tabulka a `ui/pagination`.
- Hledání, filtry, řazení a stránkování sdílejí URL stav a `TinyCat.lists`.
- Nový záznam i editace načtou autorizovaný JSON resource až při otevření.
- Klientský `modal(url, response, id)` sestaví formulář přes `TinyCat.ui`
  a společnou dialogovou factory; hydratace nevytváří skryté editory.
- Obnova seznamu zachová koncept. Historii, fokus, validaci a zavírání řídí
  core; filtry používají společné popovery.

Závazné podrobnosti: [administrace](../contracts/admin-ui-conventions.md),
[modaly](../contracts/modal-contract.md), [popovery](../contracts/popover-contract.md)
a [výběr dat](../contracts/query-selection-standard.md).

## 6. Další příspěvky a inicializace

`contributions()` vrací `Contributions`. Přidávej pouze používané části;
callbacky se při registraci neprovádějí.

| Pole | Kontrakt / význam |
| --- | --- |
| `navigation` | `NavigationItem`: administrativní menu. |
| `homepages`, `dashboards` | `EntryPage`: kandidáti pro `/` a `/admin`. |
| `themes`, `widgets`, `localizedRoutes` | `PageTheme`, `DashboardWidget`, `LocalizedRoute`: rozložení, dashboard a jazyky URL. |
| `tasks`, `maintenance` | `ScheduledTask`, `MaintenanceTask`: úlohy a údržba. |
| `settings`, `configOverrides` | `SettingsSection` a provozní nastavení vlastníka. |
| `notificationTypes` | `NotificationType`: typy oznámení, hydratace a odkazy. |
| `search`, `sitemaps`, `embeds` | `SearchSource`, `SitemapSection`, `EmbedSource`: hledání, sitemap a oEmbed. |
| `health`, `temporaryDirectories` | `HealthProbe`, `TemporaryDirectory`: diagnostika a dočasné soubory. |

Úlohy včetně ručního tlačítka a CLI používej přes Core/Tasks, nikoli voláním
pracovního callbacku mimo runner. Importy používají společnou `FeedPipeline`
s vlastními `FeedSourceStore` a `FeedTarget`; viz [importy](../operations/feed-imports.md).
Diskuse a reakce používají `InteractionPolicy` pro explicitní druh publikace.
Podrobné návaznosti rozebírá [transport](modules.md).

Pro runtime rozšíření implementuj `InitializesModule`, deklaruj provider
`services` a v `initialize()` připoj vlastní politiky či callbacky.
Inicializace nenahrazuje instalaci nebo migrace. Životní cyklus účtu rozšiřuje
`ProvidesLifecycle`; cleanup musí fungovat i pro nainstalovaného neaktivního
vlastníka. Jednoduché vazby mohou uklízet databázové kaskády.

## 7. Ověření a nasazení

Dokonči ucelenou funkci včetně API, UI, překladů a lifecycle. Během práce
používej cílené existující runnery, úplný měřený `composer preflight` na konci
etapy. Nepřidávej kopii úplné integrační sady pro každý modul.

| Oblast | Co ověřit |
| --- | --- |
| Registrace | Závislosti, unikátní ID, čisté registry a konflikty rout. |
| Data | Fresh, upgrade, rollback, atomické zápisy a odstranění dat. |
| API | Oprávnění, CSRF, cizí ID a druh, skrytý či budoucí obsah. |
| UI | PHP/JS paritu, lazy modaly, koncepty, mobil a existující ikony. |
| Zdroje | Překlady, asset závislosti a per-scope rozpočty. |
| Kombinace | Modul samostatně, s dalšími moduly a po vypnutí bez ztráty dat. |

Příkazy jsou v [AGENTS.md](../../AGENTS.md#ověření) a [testovací sadě](../../tests/README.md).
Hranice hlídají `tests/quality/module-boundaries.php` a `dependencies.php`,
metadata a zdroje `module-catalog.php`, `module-resources.php` a `runtime-registries.php`.
Inventář obnovuj přes `python tools/assets/ui-inventory.py`.

Modul může být součástí distribuce nebo samostatným ZIPem. Zkopírování adresáře
do sestaveného webu nestačí; instalaci a aktivaci provádí správce modulů.
CLI a podepsané balíčky popisuje [provozní návod](../operations/module-deployment.md).
Místní živé ověření používá [Laragon prostředí](local-environment.md);
destruktivní scénáře patří do izolovaných fixtures.

## 8. Samostatný instalační ZIP

```powershell
php tools/release/build-module.php --module=App/Modules/Blog --version=1.0.0 --output=storage/releases/modules --minimum-core=6.0.0-dev.9
```

Builder ověří manifest, poskytovatele, zdroje a migrace. ZIP obsahuje kořenový
`package.json`, `module.json` a vlastní soubory modulu. Popis zaznamenává ID,
verzi balíčku, minimální PHP/core, checksumy všech souborů a neměnnou historii
migrací. Verze balíčku je nezávislá na verzi core.

Administrátor otevře `/admin/modules`, zvolí **Nahrát modul** a potvrdí přístup
PHP kódu k aplikaci. Nahrávej jen důvěryhodné balíčky: checksumy ověřují
integritu, nikoli autora. Maximální ZIP má 32 MiB; PHP a webserver mohou mít
nižší limit. První nahrání modul pouze zaregistruje jako vypnutý. Teprve
zapnutí instaluje jeho schéma a připojí runtime příspěvky.

CLI ekvivalent, spouštěný z cílové instalace:

```powershell
php App/Console.php module-install C:/packages/vendor-notes-1.0.0.zip
php App/Console.php activate vendor.notes
```

Novější ZIP se stejným ID, adresářem a namespace aktualizuje samostatný modul,
zachová jeho aktivaci a data a provede nové migrace již instalovaného schématu.
Historické migrace nesmí změnit. Aktualizace používá společný zámek, údržbu,
journal a obnovitelnou zálohu jako updater. Vypnutí zachová data; destruktivní
odinstalace ani online katalog zatím nejsou součástí správce.

Lokální registr `App/module-packages.json` doplňuje podepsanou distribuci;
neupravuj ho ručně. Aktualizace core samostatné moduly zachovává a odmítne
kolizi jejich souborů. Modul dodávaný přímo distribucí se v této etapě
aktualizuje distribučním updaterem, nikoli samostatným ZIPem. Pro čisté core
lze sestavit `build-update.php --assembly=core`; `--active=` ponechá všechny
dostupné volitelné moduly vypnuté.
