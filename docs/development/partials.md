# Společné vykreslování UI

HTML struktura komponent, které se zobrazují při prvním načtení i přes AJAX,
má jeden zdroj v `assets/ui/<modul>.json`. PHP ji vykreslí přes
`Support/Components`; prohlížeč přes `assets/core/components.js`.
Veřejný obsah zůstává v serverovém HTML a nevyžaduje JavaScript.

Definice jsou rozdělené mezi `users`, `status`, `notifications`, `site`, `bots`,
`moderation` a společné `ui`. Obsahují elementy, podmínky, opakování, bezpečné
vazby na hodnoty, překlady a skládání komponent. Neobsahují spustitelný PHP/JS kód.
Novou komponentu upravovat v definici, nikoli přidáním druhé HTML implementace.

## Odpovědnosti

- `App/<Modul>/Resource` připravuje kanonická JSON data včetně oprávnění,
  bezpečných URL, popisků a omezení formulářů. Nevrací HTML ani CSS instrukce.
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

Společné služby fungují bez implementací v `tinycat.js`: `core/modal` vlastní
stack dialogů, fokus, viewport, potvrzení a načítání JSON resources. Resolver
dialogu dodává registrovaný doménový modul. `core/navigation` obnovuje historii
podle registrací modulů; nezná endpointy Users ani Status. Ochranu rozepsaných
formulářů poskytuje `core/form-state` přes explicitní potvrzovací callback.

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

`tests/presentation/components.php` ověřuje administraci v izolované SQLite
fixture, zamčení správce, správné akce a odmítnutí nebezpečných atributů/slotů.
`tests/quality/render-only-partials.php` hlídá závislosti všech zbývajících partialů.
HTTP kontrakty hlídají čistý JSON a query budgets počet databázových dotazů.

Nahrazené PHP šablony jsou odstraněné a uvedené v manifestu mazání pro aktualizace.


## Frontend asset ownership

Shared infrastructure lives in `assets/core`, UI primitives in `assets/ui`, and domain controllers, component definitions and styles in `assets/modules/<domain>`. Each `module.json` declares route scopes, entry points, component roots and styles. Local side-effect imports declare JavaScript dependencies; `Support/AssetGraph` validates and orders them, rejects cycles and builds classic script bundles without a browser module waterfall.

`assets/styles/index.css` preserves the global cascade order. The selected module styles are composed into one CSS asset. Pages receive four JavaScript assets: shared runtime, selected component definitions, locale catalog and application bundle. Public, administration and installer scopes include their own dependencies. Release compilation produces immutable content hashes and raw/minified variants; development uses the same graph through the asset cache.

Run `php tools/preflight.php`. Frontend tests exercise source and compiled bundles, minified delivery, scoped ownership and parity of all 662 original CSS rules. `tests/asset-optimizer/graph.php` checks graph ordering and invalid imports.

`php tools/asset-report.php` reports release payload sizes for each scope, using the largest locale and the sum of separately gzipped minified JavaScript responses. Icons are reported separately by the browser and are outside the JS/CSS budgets. `--check` rejects unreachable JS/CSS sources, changed scopes and exceeded limits from `tests/fixtures/asset-budgets.json`. Limits allow approximately 15% growth rounded up to KiB; changing them requires reviewing the size report. This detects unreachable files, not unused branches or selectors, which require behavior-aware review.
