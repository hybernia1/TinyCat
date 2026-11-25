# Jak vytvořit modul TinyCat

Vstupní manuál pro autora funkčního modulu. Začni hranicí jeho odpovědnosti,
pak manifestem, registrací a stránkou. Další schopnosti přidávej podle potřeby.
Závazná pravidla shrnuje [kontrakt modulu](../contracts/module-contract.md).
Podrobnosti doplňují [architektura](core-and-modules.md),
[transport](modules.md) a [instalace a aktivace](../operations/module-deployment.md).
Audity a otevřené plány nejsou referencí aktuálního API.

Debug automaticky zajišťuje core podle konfigurace instalace. Modul neregistruje
vlastní handlery ani lokální debug přepínač. Nečekané výjimky nech projít na
společnou hranici; pokud po výjimce pokračuješ fallbackem, ohlas ji přes
`TinyCat\Core\Runtime\Failure::report($error)`. Očekávaná odmítnutí používají
společnou validaci nebo explicitní veřejnou HTTP chybu podle
[kontraktu chyb](../contracts/error-handling.md).

## Co poskytuje core a co vlastní modul

Společné chování a veřejné vstupy určuje [kontrakt core pro API, UI a validace](../contracts/core-application-contract.md).

Limity a povolené hodnoty pole deklaruj jednou v pravidlech vlastníka. Pro metadata
editoru použij `Validator::constraints($rules)` a pro server stejná pravidla;
výchozí hodnoty a přípravu vstupu vlastní modul. Připravený vstup validuj také
při ukládání mimo HTTP. Komponenty čerpají z `constraints`, nikoli z další kopie
čísel. Znakové limity mapuj na `data-max-length`/`data-min-length`, UTF-8 bajty
na `data-max-bytes`; společné formuláře zajišťují kontrolu a zachování konceptu.
Editor v asset manifestu deklaruje `uses: ["ui.form-constraints"]`.

Pro přechody mezi celými stránkami použij
`TinyCat.navigation.registerPage({ accepts, target, load, apply })`.
Ve svém klientském vstupu importuj `App/Core/Ui/assets/runtime/page-navigation.js`.
`load(url, signal)` načte vlastní data přes společný API klient a vrátí i
kanonické `url`; `apply(target, data)` vykreslí obsah. Historii, scroll,
rušení čtení a guard konceptů řeší core. Běžné seznamy dál používají společnou
navigaci seznamů. Podrobnosti jsou v kontraktu core uvedeném výše.

Formulář připoj ke společnému AJAX submitu; vlastní callback aktualizuje doménové
výsledky. Potvrzení náhledu nebo jinou podmínku připoj přes
`TinyCat.forms.setSubmitGuard(form, callback)`, nikoli dalším submit listenerem.
Veřejnou stránku s AJAX resource registruj přes `Router::page()` a klientovi
předej `Router::pageDefinitions()`; neduplikuj seznam cest v PHP a JavaScriptu.
Core je povinný framework. Modul vlastní konkrétní funkci od registrace přes
data a pravidla až po UI. Vypnutí odstraní runtime příspěvky, zachová data.

| Potřeba | Core poskytuje | Modul dodá |
| --- | --- | --- |
| Účty | Identity, přihlášení, správu a oprávnění | Doménové vazby a pravidla. |
| Doménová data | DB, transakce, parsery textu/odkazů a soubory | Vlastní entity, tabulky, FK, pravidla a repository. |
| Diskuse a reakce | Formátování, editor, revize a AJAX UI | Vlastní tabulky, autorizovaný cíl, transakce a události. |
| Importy | FeedParser pro RSS, Atom a JSON Feed | Vlastní importní průchod, zdroje, historie a publikace. |
| Úlohy | Tasks, zámky, historii a rozhraní | ScheduledTask a pracovní callback. |
| Nastavení | Settings a společný editor | Vlastní klíče, sekce, validaci a překlady. |
| Oznámení a pošta | Notifications a Mail | Událost, příjemce, odkazy a vlastní šablony. |
| Hledání a sitemap | Discovery a skládání zdrojů | SQL, viditelnost a kanonické URL. |
| Prezentace | Rám, komponenty, AJAX, formuláře a dialogy | Stránky, pole, data a akce vlastní funkce. |

Modul nemusí používat všechny schopnosti. Veřejná stránka funguje bez Socialu.
Nevytvářej druhý HTTP klient, DB vrstvu, scheduler, překladač nebo modalový systém.
Závislost na jiném funkčním modulu musí být výslovná a skutečně potřebná.

Pro ASCII segment URL použij `TinyCat\Support\Text::slug($text)`.
Převod používá ICU, případně iconv, a podporuje vlastní oddělovač.
Stabilní identitu, skládání URL a pravidla přesměrování vlastní modul.
Uložené identifikátory se při změnách konzumentů nepřepočítávají.

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

Pokud zachováváš Social, ponech i jeho manifest. Samotný adresář
modul neaktivuje. Dostupnost určuje manifest ve složce modulu, aktivaci spravuje
explicitní stav a lifecycle. Vlastní distribuční profil je potřeba jen pro
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
symbol ve společném [`App/Core/Ui/assets/icons.svg`](../../App/Core/Ui/assets/icons.svg).
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
Core Identity nabízí také `tinycat.accounts.login`: hostovi vykreslí přihlášení
přímo na `/`, přihlášeného pošle na bezpečný cíl vlastníka účtu. Pokud cílem
zůstává `/`, zobrazí přehled účtu bez dalšího přesměrování. Tato volba má
nejnižší prioritu, takže automatický výběr dál preferuje funkční modul.
`EntryPage` může přes `navigation: false` nabídnout homepage bez přidání
další položky do veřejné navigace. Vlastní přihlašovací cíle nesměřuj na
`/login`, `/register` ani externí URL; Identity takový cíl nahradí bezpečnou
homepage. Platné explicitní `next` má nadále přednost.
Vlastní routa modulu zůstává samostatná. Jazykové prefixy veřejných URL deklaruj
přes `LocalizedRoute`, včetně povolených query parametrů. Veřejné rozložení
vybírá core z [témat](themes.md); funkční modul žádné téma neregistruje.

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
nebo skupinu `public` či `admin`. Přes `uses` vybírej pojmenované schopnosti
deklarované jejich vlastníky. `publishing.*` vlastní Social a samostatný modul
na ně nesmí spoléhat bez explicitní závislosti na Socialu; nejde o služby core.
Nekopíruj seznamy interních souborů těchto schopností.

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
implementuj `ProvidesSchemas`, deklaruj provider `schema` v manifestu a z metody
`schemas()` vrať mapu stabilní ID streamu => `ModuleSchema`: aktuální verzi,
vlastněné tabulky, fresh instalaci a úplnou
posloupnost `SchemaMigration` od verze 1. Soubory patří do vlastního
`migrations/`, deklarovaného v `resources`. [Testovací Notes](../../tests/fixtures/modules/notes/src/Module.php)
ověřuje lifecycle modulu; není vzorem úplného CRUD editoru.

Existují dvě cesty čerstvého schématu. Přímá `ModuleSchemas::install` volá
instalační callback `ModuleSchema::install` a zaznamená historii. Podepsaný
deployment používá `ModuleSchemas::synchronize` bez načítání nového provideru:
spustí samostatnou executable `baseline`, je-li deklarována, jinak postupně
všechny migrační soubory. Obě cesty musí vytvořit stejné aktuální schéma a
semena. Archivovaný prefix s `file: null` vyžaduje executable baseline;
neřetěz ji s historickými datovými převody. Publikované migrace neměň.

Používej vlastní prefix, například `notes_entries`. Modul vlastní celý záznam
a jeho vztahy; neodkazuje na univerzální `content` ani nerozlišuje cizí entity
přes `kind`. Komentáře a přílohy podle potřeby odkazují skutečným FK na vlastní
entitu. Tabulky core nepřihlašuj jako vlastnictví modulu.

Publikované migrační soubory, ID a checksumy jsou neměnné. Změna znamená novou
migraci a aktualizaci současného instalačního schématu; fresh a upgrade musí
dosáhnout stejného stavu. Jediný kontrakt `ProvidesSchemas` zachovává i více
historických streamů. `ModuleSchema::tables` je jediný zdroj vlastnictví tabulek;
bezdatový provider žádný prázdný seznam neimplementuje. `repairOnInstall` výslovně
povoluje opakovanou opravu idempotentního DDL při explicitní instalaci a
`gateRoutes` určuje automatickou readiness JSON rout. Běžný nový modul používá
výchozí hodnoty `false` a `true`. Na MySQL má účet `INT UNSIGNED`;
vazby na účet musí mít odpovídající typ. Vlastní entity a jejich vztahy
zapisuj atomicky. Média a metadata připrav před transakcí, staré soubory
maž po commitu.

Povinný framework se vždy inicializuje před funkčními manifesty. `coreApi`
deklaruje jeho kontrakt; `requires` potřebuje uvádět funkční modulové závislosti,
nikoli aktivaci povinných služeb core.

API registruj přes předaný `TinyCat\Http\Api`. Databázové API
obal kontrolou připravenosti schématu přes `whenReady`. Server ověřuje oprávnění
při každém čtení i zápisu; zápisy respektují CSRF a společnou JSON obálku.
Resources vracejí data, oprávnění a omezení, nikoli HTML, komponentové definice
nebo překladové identifikátory. Viz [HTTP API](../reference/api.md).

## 5. Administrativní seznamy a modaly

Navazuj na [administrační kontrakt](../contracts/admin-ui-conventions.md).
Selekci a pravidla
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

Pro běžný CRUD není nutný Social controller. Modul v asset scope deklaruje
`admin` definici s `path`, `endpoint`, `api`, `target`, `component`, `filter`,
`filterId`, `title` a `icon`. Její `editor` může obsahovat `creation` (URL
creation resource), `management` (URL s jedním `{id}`), `fields` (vlastní
komponenta polí), `title`, případně `method` a `submit`. Core `admin-resources`
načte resource při otevření, sestaví společný formulář, pro vytvoření použije
POST na `api` a pro úpravu `method` na `api/{id}`; `item.id` v resource
rozlišuje editaci. Vlastník dodá autorizované endpointy, validaci, data seznamu,
komponenty a jejich assetové a překladové závislosti. Mazání a doménově zvláštní
chování vyžaduje vlastní autorizovaný endpoint a odpovídající deklarovanou akci.

Například položka `admin` uvnitř scope vlastního `assets/module.json` může být:

```json
{
  "example.records": {
    "path": "/admin/records", "endpoint": "/api/records", "api": "/api/records",
    "target": "#records-list", "component": "example/records-list",
    "filter": "example/records-filter", "filterId": "records-filter",
    "title": "example.records", "icon": "list",
    "editor": {
      "creation": "/api/records/creation", "management": "/api/records/{id}/management",
      "fields": "example/record-fields", "title": "example.new_record", "method": "PATCH"
    }
  }
}
```

Tuto mapu vlož pod klíč `admin` do scope, který deklaruje použité komponenty
a překlady. Modul registruje routy a dodá seznamový resource i oba editovací
resources; `Definitions::page()` vykreslí společný seznamový rám. Komponenta
polí dostane data resource a `dialog_id`. `endpoint` řídí čtení a navigaci
seznamu, `api` základní URL zápisu; parametr `target` určuje obnovovaný seznam.

## 6. Další příspěvky a inicializace

`contributions()` vrací `Contributions`. Přidávej pouze používané části;
callbacky se při registraci neprovádějí.

| Pole | Kontrakt / význam |
| --- | --- |
| `navigation` | `NavigationItem`: administrativní menu. |
| `homepages`, `dashboards` | `EntryPage`: kandidáti pro `/` a `/admin`. |
| `widgets`, `localizedRoutes` | `DashboardWidget`, `LocalizedRoute`: dashboard a jazyky URL. |
| `tasks`, `maintenance` | `ScheduledTask`, `MaintenanceTask`: úlohy a údržba. |
| `componentLookups` | `ComponentLookup`: lazy prezentační metadata pro sdílené komponenty. |
| `settings`, `configOverrides` | `SettingsSection` a provozní nastavení vlastníka; prezentace editoru se odvozuje ze stejné deklarace (viz níže). |
| `notificationTypes` | `NotificationType`: typy oznámení, hydratace a odkazy. |
| `search`, `sitemaps`, `embeds` | `SearchSource`, `SitemapSection`, `EmbedSource`: hledání, sitemap a oEmbed. |
| `health`, `temporaryDirectories` | `HealthProbe`, `TemporaryDirectory`: diagnostika a dočasné soubory. |

Úlohy včetně ručního tlačítka a CLI používej přes Core/Tasks, nikoli voláním
pracovního callbacku mimo runner. Core poskytuje `FeedParser` bez publikačních
pravidel. Importní průchod a mapování do domény vlastní modul;
viz [importy Socialu](../operations/feed-imports.md).
`ScheduledTask` používá ID `[a-z][a-z0-9_.-]{0,39}`; `all` je rezervované
pro výběr všech úloh. Výchozí parametry jsou celá čísla, další povolené
parametry jednotlivých transportů deklaruje `modeOptions`. Volitelné
`batchOption` odkazuje na výchozí parametr, který HTTP trigger přijímá také
jako `batch`; core nezná doménové názvy parametrů. Normalizace proběhne jednou
před spuštěním vybraných úloh a stejné hodnoty dostane historie i callback.
Neplatný konkrétní cíl odmítni; nepřeváděj ho na spuštění celé dávky.
`healthAge` a `detail` jsou lazy callbacky vlastníka; registrace nečte DB.
`detail` dostane kanonický resource běhu. Pro společný detail může doplnit
`details`: seznam položek s `title`, volitelným `status` (`name`, `code`) a
`rows` (`name`, `value`). Tasks je zobrazí ve společném modalu a tabulce.
Specifické doménové UI může vlastník připojit přes `componentExtensions`
sdíleného `scheduler/run-detail`. API nevrací HTML ani názvy komponent.
Bez aktivního vlastníka zůstane dostupný základní detail.
Definici úlohy předávej jako `ScheduledTask` v `Contributions::tasks`;
nepřidávej souběžnou registraci přes pole získané z `definition()`.
Tasks vlastní registraci, parametry, spouštění, plánování, souběh, zámky,
historii a společnou administraci. Scheduler je jeho vnitřní mechanismus;
`ScheduledTask` je zachovaný název kontraktu také pro ručně spustitelnou práci.
Nová úloha potřebuje definici a handler, nikoli vlastní aplikaci nebo historii.
Health vlastní sondy, vyhodnocení a upozornění; `site-health` přispívá do Tasks
stejně jako `cleanup` a modulové importy. `Tasks/Health/Checks::scheduler` sleduje stáří a
chyby běhů podle lazy `healthAge`, nespouští diagnostiku.
Nedávné běhy poskytuje `Tasks/History::recent`, s vyřazením zvolených stavů
před omezením počtu. Administrační adaptéry používají `Tasks/Actions::execute`;
nové klienty připojuj na `POST /api/scheduler/tasks/{task}/runs`.
Diskuse a reakce používají `InteractionPolicy` pro explicitní druh publikace.
Podrobné návaznosti rozebírá [transport](modules.md).

Pro runtime rozšíření implementuj `InitializesModule`, deklaruj provider
`services` a v `initialize()` připoj vlastní politiky či callbacky.
Inicializace nenahrazuje instalaci nebo migrace. Životní cyklus účtu rozšiřuje
`ProvidesLifecycle`; cleanup musí fungovat i pro nainstalovaného neaktivního
vlastníka. Jednoduché vazby mohou uklízet databázové kaskády.

Souborový orphan cleanup může výslovně deklarovat
`new MaintenanceTask('owner_file_orphans', $cleanup, whenInactive: true)`.
Výchozí hodnota je `false`; opt-in není zapnutí běžných úloh vypnutého modulu.
Callback musí být opakovatelný, omezený, chránit čerstvé přípravy a před mazáním
vyhodnotit reference všech nainstalovaných vlastníků. Podrobnosti určuje
[kontrakt modulu](../contracts/module-contract.md#zdroje-a-životní-cyklus).

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

Modul může být součástí distribuce, samostatným ZIPem nebo ručně přidanou složkou.
Zkopírování adresáře jej zpřístupní ve správci jako vypnutý, bez spuštění PHP;
instalaci schématu a aktivaci provádí až výslovná operace správce modulů.
CLI a podepsané balíčky popisuje [provozní návod](../operations/module-deployment.md).
Místní živé ověření používá [Laragon prostředí](local-environment.md);
destruktivní scénáře patří do izolovaných fixtures.

## 8. Samostatný instalační ZIP

```powershell
php tools/release/build-module.php --module=App/Modules/Notes --version=1.0.0 --output=.work/releases/modules --minimum-core=6.3.1
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

## Společný inventář dostupných modulů

Build celé platformy a vývojové kontroly načítají manifesty přímo z
`App/Modules/*/module.json`; další centrální seznam se neupravuje. Samostatný
build modulu vybere jeho manifest a tranzitivní závislosti. Inventář pouze čte
JSON a nespouští PHP modulů. Stejný inventář určuje dostupnost ve správci;
samotné nalezení modulu jej neaktivuje. Aktivace zůstává explicitní
v `App/modules.php`, distribučním plánu a registru instalovaných balíčků.

## Nastavení bez klientské registrace

Pro běžné administrační seznamy a editory lze podobně využít deklaraci `admin`
ve vlastním asset manifestu; viz [kontrakt administrace](../contracts/admin-ui-conventions.md#declared-list-and-editor-wiring).
HTTP obsluhy, dotazy, oprávnění a zvláštní UI dál implementuje vlastník.

Modul přispívá lazy `SettingsSection` přes `Contributions.settings`. Callback vrací
sekci s `label`, `icon` a `fields`; každé pole obsahuje `key`, `type`,
`label` a podle potřeby `default`, omezení, `help`, `placeholder` či `span`.
Překládané `label`, `help` a `placeholder` deklaruj jako `['t' => 'owner.key']`.
Core je přeloží pro serverové konzumenty a zachová jako překladové výrazy pro
klientské komponenty. Podporované jsou také doslovné texty. Sekce stejné skupiny
se slučují; klíč pole má jediného vlastníka.

Core Settings z těchto definic odvodí katalogy `ComponentLookup` pro sekce, pole
a ukázky formátů. Společné komponenty je využívají stejně při PHP vykreslení
i v klientském balíku; překladové závislosti se odvodí automaticky. Administrační
HTML neobsahuje atribut `data-settings-presentation`. Hodnoty, výchozí hodnoty,
možnosti a callbacky se do prezentačních katalogů nekopírují. Formulář ani modal
nevzniká před otevřením. Neúplná prezentace identifikuje sekci nebo pole.

`GET /api/settings/editor` nadále vrací autorizovaná data a omezení bez HTML,
překladových klíčů a komponentových definic. Aktuální hodnoty se načítají až při
otevření společným resource loaderem. Modul nepotřebuje `settings-presentation.js`
ani opakování překladových závislostí těchto popisků v asset manifestu; katalogy
a samotné definice dál vlastní modul. Core nezná jeho název ani klíče nastavení.
Vypnutý nebo chybějící vlastník nepřispívá sekcí ani prezentačními metadaty.


### Public search presentation

An existing `SearchSource` can declare named `icon` and `render` arguments.
The optional renderer accepts the selected canonical items and returns HTML
rendered through the owner's shared component catalog. It is lazy and used only
by the public HTML search page, never by registration, suggestions or JSON query.
Do not rerun candidate selection in it; batch-hydrate only the selected visible
IDs if rich cards need additional data. Keep canonical API items free of HTML,
component names and translation identifiers. Sources without a renderer use the
core identity rows. Core derives source navigation and cursor links from the same
active registrations; no extra endpoint or sidebar registration is necessary.
For other public pages, `Page::render` accepts `navigation` entries with `label`,
`icon`, `url`, `active` and renders the same responsive public workspace.


### Dashboard widgets

Add `DashboardWidget` entries to the existing `Contributions.widgets` collection.
No second endpoint, registry or core domain switch is required. For example, a
shop owner can supply a bounded order list lazily:

```php
new DashboardWidget(
    'shop.recent-orders',
    static fn (): array => DashboardData::recentOrders(limit: 5),
    order: 40,
    type: 'list',
    title: static fn (): string => Localization::t('shop.recent_orders'),
    icon: 'file-text',
    visible: static fn (): bool => Access::canViewOrders(),
)
```

Here `DashboardData` and `Access` belong to the example shop module. The callback
returns `['items' => [['label' => '...', 'url' => '/admin/...', 'detail' => '...',
 'time' => '...']], 'url' => '/admin/...']`. The owner controls SQL, permissions,
ordering, formatting and translations; the host controls the frame and responsive
layout. Do not fetch orders during registration or add shop tables to core.
Metrics and status widgets use the same registration. The custom type accepts an
owner component-rendered body where a standard type is insufficient. See the
[dashboard contract](../contracts/admin-ui-conventions.md#registered-dashboard-widgets-and-cache-diagnostics).

Statistics can instead join the core selector through `Contributions(statistics:
[new DashboardStats(...)])`. Supply a stable owner-scoped ID, translated group
label, lazy `load` callback and metadata-only `fields` callback. The loader returns
`['items' => [['key' => 'orders', 'value' => 12]], 'url' => '/admin/orders']`;
`fields` returns `['orders' => ['label' => ['t' => 'shop.orders']]]`. Counts stay
canonical integers; the shared component catalog owns their labels. Registration
and field metadata must not execute SQL. Add `visible` for narrower authorization.

## Provider kinds and translation ownership

The manifest accepts exactly `routes`, `schema`, `contributions` and `services`.
Settings, tasks, navigation and health checks are typed contributions; they are
not separate provider kinds. Metadata and runtime validate the same contracts.

Translation-source ownership uses `<module-id>/translations` and
`<module-id>/emails`, independent of the PHP namespace leaf. Different modules
may both end their namespace with `Billing`. Their actual message keys must
still have distinct ownership and keep the translation-contract locale parity.
This internal source identity does not rename application or email message keys.
