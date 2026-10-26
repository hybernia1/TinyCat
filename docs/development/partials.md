# Společné vykreslování UI

HTML struktura komponent, které se zobrazují při prvním načtení i přes AJAX,
má jeden zdroj v `assets/ui/<modul>.json`. PHP ji vykreslí přes
`Support/Components`; prohlížeč přes `assets/js/core/components.js`.
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
