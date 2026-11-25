# Společné vykreslování UI

Volitelné veřejné layouty popisuje [minimalistické téma](themes.md).

HTML struktura komponent, které se zobrazují při prvním načtení i přes AJAX,
má jeden zdroj v `App/Modules/<Name>/assets/components.json` a `App/Core/Ui/assets/components.json`. PHP ji vykreslí přes
`Support/Components`; prohlížeč přes `App/Core/Ui/assets/runtime/component-renderer.js`.
Veřejný obsah zůstává v serverovém HTML a nevyžaduje JavaScript.

Definice jsou rozdělené mezi `users`, `status`, `notifications`, `site`, `bots`,
`moderation` a společné `ui`. Obsahují elementy, podmínky, opakování, bezpečné
vazby na hodnoty, překlady a skládání komponent. Neobsahují spustitelný PHP/JS kód.
Novou komponentu upravovat v definici, nikoli přidáním druhé HTML implementace.

## Odpovědnosti

Současný sociální feed připravuje `Social/Site/PublicPages` a vykresluje
`site/public-page`; tutéž strukturu používá klient. Staré PHP partialy feedu
a `Posts/Views` jsou odstraněné. Příprava kanonických dat proběhne před renderem
přes resources, včetně dávkových preloadů.
HTML obálky dashboardu, seznamu plánovače a doménových exportů vlastní katalogy.
Přímý odkaz na detail bota přesměruje na stránku seznamu s resource fragmentem;
otevření, reload a Back/Forward obstarává společná historie dialogů.

Instalační průvodce používá jeden katalog `Core/Installation/assets/components.json`
pro kroky, pole a obsah stránky. `Installation/Pages::prepare()` sestaví explicitní
vstupy před vykreslením; pouze krok tabulek ověřuje DB. Handler neobsahuje HTML.
Společný `Core/Ui/Document::frame()` přijímá hotový `Markup`, locale a assety
bez závislosti na dokončené instalaci. JavaScript
instalace zajišťuje odesílání formulářů, nikoli druhou definici jejich struktury.
Přístupové stránky, veřejný povrch, administrace i instalace používají jediný HTML dokument
`Core/Ui/templates/document.php`. `head.php` dodává pouze veřejná metadata,
nikoli otevření dokumentu nebo doručování assetů. `Document::frame()` nemá
vlastní načítání nastavení, účtu, session ani databáze. Volající předává
atributy, metadata, assety, flash zprávy a případný consent pro analytics.
Login, recovery a účet používají `Core/Ui/AccessLayout` bez veřejných podkladů;
autentizace má vlastní klientský modul bez editoru účtu a avataru. Dostupnost
a výběr assetů popisuje [kontrakt veřejných povrchů](../contracts/public-surface.md).
Veřejnou hlavičku, patičku a consent připravuje `DocumentData` s explicitními vstupy;
core i Social shell dostávají hotové fragmenty a nepředávají celý PHP kontext.

Nové struktury formulářů a obsahu patří do katalogu vlastníka. PHP šablona
nemá být průchozí obálkou, která pouze volá další render. Vstupy předávej
explicitně; nepřidávej `get_defined_vars()`. Nový operátor rendereru přidávej
až pro doloženou potřebu více konzumentů; složité výpočty patří do přípravy dat.

Účtové stránky volají přímo komponenty `users/auth-content`, `users/logout-page`
a `users/account-home` z katalogu Identity. Přihlášení, registrace a obnova účtu
sdílejí `users/auth-content`, který skládá `users/auth-form` nebo
`users/recovery-form`; stránka i dialog zachovávají tentýž formulář. `Resource::authenticationForm()`
připravuje kanonické vstupy; mezi handlerem a komponentou není PHP partial
ani průchozí `Views::authContent()` / `Views::authForm()`. Obnova hesla používá
společná pole a `ui/captcha`, bez skládání důvěryhodných HTML řetězců.

Editor nastavení vlastní strukturu v `Core/Settings/assets/components.json`.
Controller připravuje hodnoty, atributy a formátované možnosti; HTML polí,
náhledů a skupin vzniká pouze přes komponenty `settings/sections` a `settings/field`.
Formulář a dialog nadále používají společné factory a ochranu konceptu.
Vnořená pole podporovaného typu deklaruje vlastník pomocí `fields` uvnitř pole SettingsSection;
například Mail vlastní podpole SMTP včetně výchozích hodnot a omezení.
Resource rekurzivně odstraní popisky, callbacky a defaults a vynuluje hesla.
Core Settings z registrací odvodí `ComponentLookup` pro popisky a prezentační
metadata včetně podpolí. Překladové výrazy zůstávají v katalogu komponent;
nepřenášejí se jako JSON v HTML atributu. JavaScript nevede druhý seznam SMTP
polí ani jejich popisků.
`fields` je schéma editoru; nemění serverová pravidla ukládání ani validaci
složené hodnoty SMTP.

- `App/<Modul>/Resource` připravuje kanonická JSON data včetně oprávnění,
  bezpečných URL a omezení formulářů. Překládané popisky vlastní prezentace;
  resource nevrací HTML ani CSS instrukce.
- `App/<Modul>/Views` je serverová kompozice komponent. Data získává předem;
  šablona nesmí sama provádět databázové dotazy ani rozhodovat o oprávněních.
- Klientské moduly přijímají stejná data a připojují chování. Pro obsah společný
  se serverem volají `TinyCat.components.render()` místo stavby druhého DOM.
- Zbývající PHP partialy skládají stránky nebo vykreslují části používané pouze
  na serveru. Klientské dialogy bez serverového protějšku mají jedinou klientskou
  kompozici a používají společné pole, avatar a modalový rámec.
- API řeší autorizaci, validaci a zápisy. Skrytí tlačítka není kontrola oprávnění.

`Status/Resource` a `Status/Segments` jsou jedinou přípravou obsahu statusů a
komentářů. Původní `_view` struktury a HTML formátování textu jsou odstraněné.
Serverová kompozice před vykreslením zajistí společné batch preloady.

Klientské moduly registrují `result`, `hydrate`, `init` a případné navigační
chování přes `TinyCat.modules`. Status, profily a sledování, vyhledávání,
notifikace a sidebar vlastní příslušné moduly; společné služby zajišťují dialogy,
fokus, validaci a ostatní UI chování. HTTP formuláře sdílejí životní cyklus přes
`TinyCat.forms.request`, včetně blokace duplicit, obnovení ovládacích prvků a abortu.

Release builder předem sestaví assety do podepsaného `assets/build/manifest.json`.
`CompiledAssets` tento soubor načte jednou za request. `ClientAssets` skládá
definice a prochází zdrojové texty pouze ve vývojovém fallbacku bez manifestu.

Společné služby fungují bez implementací v `tinycat.js`: `ui/dialogs` vlastní
stack dialogů, fokus, viewport, potvrzení a načítání JSON resources. Resolver
dialogu dodává registrovaný doménový modul. `core/navigation` obnovuje historii
podle registrací modulů; nezná endpointy Users ani Status. Ochranu rozepsaných
formulářů poskytuje `ui/form-state` přes explicitní potvrzovací callback.

`core/lifecycle` inicializuje služby jednou a opakovaně hydratuje nové DOM kořeny.
Modulové renderery hydratují jako první, formuláře se snímkují až po vytvoření
ovládacích prvků. Odstranění dialogu volá `dispose`, který zruší práci formulářů.
DOM a ikony mají jednu implementaci v `core/dom` a `core/icons`; renderer komponent
používá tyto primitivy přímo, bez zpětné závislosti na widgetech `ui`.

Sdílené jsou karty, segmenty textu, média, reakce, komentáře, editor statusu,
načítání dalších příspěvků, avatary, sledované profily, přihlášení a CAPTCHA,
notifikace, sidebar, tabulky uživatelů a botů, jejich společná pole, moderace,
historie běhů botů, vyhledávání, stránkování a modalový rámec.

## Bezpečnost a transport

API stále vrací výhradně JSON. Definice komponent jsou statickou součástí stránky,
nikoli HTML odpovědí API. Neexistuje přepínač `?view=html`.
Text se escapuje; klient nevkládá odpovědi přes `innerHTML`. Oba interprety
odmítají spustitelné elementy, událostní atributy a nepovolené URL protokoly.

Modalové sloty přijímají na klientu pouze skutečné DOM uzly. Server pro interní
kompozici vyžaduje explicitní `Support/Markup` z již vykreslené šablony. Běžný
řetězec z API ani uživatelský text se za důvěryhodný slot nepovažuje.

## Ověření

`tests/frontend/modules.cjs` načítá skutečnou serializaci definic dodávanou PHP
stránkou. Nad stejnými fixture porovnává elementy, atributy a text PHP a klienta,
včetně stavů host/přihlášený, avataru, médií, formulářů a modalů.
CSRF token a verze sprite jsou při porovnání normalizované.

`tests/presentation/components.php` ověřuje administraci v izolované MySQL
fixture, zamčení správce, správné akce a odmítnutí nebezpečných atributů/slotů.
`tests/quality/render-only-partials.php` hlídá závislosti všech zbývajících partialů.
HTTP kontrakty hlídají čistý JSON a query budgets počet databázových dotazů.

Nahrazené PHP šablony jsou odstraněné a uvedené v manifestu mazání pro aktualizace.


## Frontend asset ownership

Shared infrastructure lives in `App/Core/Ui/assets/runtime`, UI primitives in `App/Core/Ui/assets`, and domain controllers, component definitions and styles in `App/Modules/<Name>/assets`. Each asset manifest declares route scopes, entry points, component roots and styles. Local side-effect imports declare JavaScript dependencies; `Support/AssetGraph` validates and orders them, rejects cycles and builds classic script bundles without a browser module waterfall.

Owner manifests define stylesheet order; there is no central CSS import index. Selected styles are composed into one CSS asset. Pages receive four JavaScript assets: shared runtime, selected component definitions, locale catalog and application bundle. Public, administration and installer scopes include their own dependencies. Release compilation produces immutable content hashes and raw/minified variants; development uses the same graph through the asset cache.

Owner features may declare `defer: {"resources": ["/api/users"], "selectors": ["[data-avatar-upload]"]}`.
`resources` contains same-origin API path prefixes, matched on segment boundaries;
`selectors` identifies inline controls requiring the feature. Deferred features keep
their own entries, component roots, translations, styles and `uses` dependencies.
They are excluded from the initial selection and combined into one additive editor
payload per delivery scope and locale, plus an optional stylesheet. Sources and
component definitions already delivered initially are excluded from that payload.
Dependencies explicitly selected by an initial feature remain eager.

The locale script declares the immutable editor URLs. The common resource-dialog
loader waits for the authorized JSON response and then for matching editor assets,
while retaining request cancellation and draft guards. Inline editors request the
same payload during hydration. Concurrent requests share one load; failed network
loads can retry. Late modules initialize once and hydrate the existing document,
preserving current field values. JSON resources never carry asset URLs or UI definitions.
The release compiler and development fallback use the same selection. Component
catalog URLs remain independent of language. `ClientAssets`' complete selection is
used by source reachability and translation checks, including deferred code.

Run `php tools/preflight.php`. Frontend tests exercise source and compiled bundles, minified delivery, scoped ownership and parity against the reviewed CSS baseline. `tests/assets/graph.php` checks graph ordering and invalid imports.

`php tools/assets/asset-report.php` reports release payload sizes for each scope, using the largest locale and the sum of separately gzipped minified JavaScript responses. Icons are reported separately by the browser and are outside the JS/CSS budgets. `--check` rejects unreachable JS/CSS sources, changed scopes and exceeded limits from `tests/fixtures/asset-budgets.json`. Limits allow approximately 15% growth rounded up to KiB; changing them requires reviewing the size report. This detects unreachable files, not unused branches or selectors, which require behavior-aware review.

The shared Unicode/byte constraint implementation is exported as
`ui.form-constraints`; editors opt in through their asset manifests. The generic
form transport retains only its validation hook. The initial 6.0.10 constraint-stage
platform report measured `/admin/updates` at 205,023 raw JS bytes, requiring a
256-byte adjustment of that scope's raw budget to 205,056. All other JS/CSS,
compressed-size and request-count budgets remain unchanged.

## Kompozice stránek a testovací data

Modulový `Pages` handler připraví data před vykreslením. `Core/Ui/Page` vybírá
veřejný nebo administrační shell; `Core/Ui/DocumentData` připravuje
metadata, assety a data účtu a `Core/Ui/Document` společný dokument. `Support/View`
zajišťuje vykreslení šablony. `View::part('Module/name', $data)` načítá pouze
partial daného modulu z `templates/parts`; vstupy jsou explicitní.

## Rozsahy klientských assetů

Manifesty rozlišují společné UI, veřejné funkce a administraci. Rozsah `public`
je určen pro skutečně společné veřejné prvky, například hlavičku a hledání.
Konkrétní funkce deklaruje své cesty: Social načítá feed, profily a veřejnou
navigaci pouze na `/`, `/social`, `/status`, `/author` a `/tag`. Přihlášení,
pravidla, oznámení a společné hledání tyto zdroje nepotřebují.

Deklarace konkrétních cest zároveň vytváří samostatné rozsahy release buildu.
Změnu ověřuj ve zdrojové i kompilované/minifikované variantě. Stejný výběr
určuje JavaScript, CSS, komponentové definice a klientské překlady.

Tabulky deklaruje pojmenovaná schopnost `ui.tables`, kterou automaticky používá
administrace a instalátor; jiný vlastník ji může výslovně připojit přes `uses`.
Stránkování seznamů a filtrační chips patří do administračního rozsahu.
Obsluhu administračních filtrů načítá administrační vstup, nikoli společné
formulářové helpery. Sdílený transport formulářů a dialogů zůstává použitelný
na obou površích bez administračních konzumentů.

Pro izolovanou testovací instalaci lze vytvořit vizuální fixture příkazem
`php tools/data/demo-import.php --profile=small --users=120 --prefix=demo --ui-fixtures=1 --anchor="YYYY-MM-DD HH:MM:SS"`.
Vyžaduje GD/WebP a přidá offline avatary, textové i obrázkové příspěvky,
stavy účtů a vypnuté botí zdroje s historií běhů. Zdrojové URL používají
rezervovanou doménu `example.invalid`; importy zůstávají vypnuté.
Obecná pravidla importu a resume popisuje [benchmark](../performance/benchmark.md).
