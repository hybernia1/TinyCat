# Audit UI — 1. listopadu 2026

## Další sjednocení vizuálních komponent

Našeptávač používá společný popover v režimu vstupního pole. Sdílí pozici,
zavírání, klávesnici a úklid; doménová vrstva zachovává debounce, cache a
kontrolu aktuálnosti odpovědí. Zrušení ruší i dosud nezobrazené výsledky a
návrat fokusu po Escape je znovu neotevře.

Prázdné sidebarové seznamy a vyhledávání používají kompaktní `ui/empty-state`.
Náhled i stránka notifikací sdílejí tentýž renderer prázdného stavu. Nápověda
a chyba hledání používají odlišné tóny společného `ui/notice`. Emailové
upozornění přebírá barvy `alert-info` a cookie lišta tokeny rámu a stínu.

Vyhledávání, sidebar a notifikace sdílejí `identity-rows.css` s běžnou a
kompaktní variantou. Feed, notifikace a sledovaní používají `ui/load-more`
se společným umístěním tlačítka a stavem načítání. Nahrazené styly a lokální
ovládání byly odstraněny. Počet CSS pravidel klesl ze 777 na 761.

Nativní test `ui-consistency.cjs` přidává 54 kontrol na skutečných šířkách
320, 375 a 1024 px. Prošly frontendová sada, 4 836 HTTP kontrol, UI kontrakty,
rozpočty všech 9 distribučních scopes a kontrola podepsaného balíčku.
Rozpočty komprimovaného JS, CSS a počtu požadavků zůstaly zachované;
limity nekomprimovaného JS administrace zohledňují nové společné ovládání
vstupního popoveru a katalog ovladače další stránky.

## Sjednocení popoverů

Notifikace, filtry, archiv, účet, jazyk a kontextové akce nyní používají jediný
controller a povrch podle [kontraktu popoverů](popover-contract.md). Samostatné
ovládání notifikací a pozicování komentářů bylo odstraněno. Následující auditní
snímky zachovávají původní stav v době jejich pořízení.

Sdílený controller nově obsluhuje také přístupnost, klávesnici, top layer,
viewport a cleanup. Rozpočty administrace zahrnují tuto společnou vrstvu;
počet distribuovaných skriptů a stylů zůstává stejný. Veřejný a instalační
rozpočet nebyl navýšen.

## Stav po implementaci

Navazující sjednocení převedlo na resource modaly také vytvoření uživatele,
botího účtu a changelog aktualizace. Obnova seznamu už nesestavuje vytvářecí
editor znovu. Závazný [kontrakt modalů](modal-contract.md) doplňují build guard,
zákaz tvorby při inicializaci/hydrataci a regresní ochrana konceptů během refreshů.

Settings byly následně převedeny z předem vloženého serverového formuláře na
disposable resource editor `/api/settings/editor`. Admin shell neobsahuje jeho
DOM ani hodnoty. Původní PHP renderer a partialy jsou odstraněné; načtení,
historii, zrušení požadavků a ochranu změn zajišťuje společný správce dialogů.
Renderer a klientské popisky se nyní distribuují v admin JS; odpovídající
rozpočty byly samostatně přezkoumané. Historická měření níže předcházejí této změně.

Navazující refaktor vyřešil UI-01 až UI-04: společné styly mají názvy podle
komponent, doménové styly vlastní modulové manifesty, nepoužívaný `select-sm`
byl odstraněn a formulářové helpery sdílejí kontrakt dodatečných atributů.
UI-05 zůstává zachovanou samostatnou vizuální variantou embedu.
Nová automatická kontrola katalogů je součástí buildu i testovací sady.

Následný úklid názvů přejmenoval dalších 52 CSS souborů podle jejich obsahu
a breakpointů, včetně `023-notification-empty.css` na
`notification-items.css`. V aktivních CSS nezůstávají číselné prefixy.
Při přejmenování byl ověřen shodný obsah všech přesouvaných souborů a
shodné SHA-256 výsledného CSS ve všech 8 distribučních scopes.

Stejný úklid názvů následně pokryl 28 JS souborů. Oddělil názvem API klienta,
renderer komponent, UI buildery, formulářové požadavky, AJAX bindingy a
doménové controllery. Rejstřík nyní zahrnuje také kompletní JS importy a
vstupní body. Přesuny zachovaly spustitelný kód a pořadí závislostí;
generované komentáře se zdrojovými cestami používají nové názvy.
Po úklidu JS prošla kompletní frontendová sada včetně nativních prohlížečových
testů, kontrola překladových závislostí (125 kontrol), assetového grafu,
distribučních rozpočtů a podepsaného instalačního artefaktu (831 runtime souborů).

Platná pravidla popisuje [UI konvence](ui-conventions.md), aktuální stav
[generovaný rejstřík](ui-inventory.md). Rejstřík nyní rozpoznává také escapované
responzivní názvy tříd; jeho počty proto nelze porovnávat s původním počtem
423 jako počet přidaných nebo odstraněných komponent.

Níže je původní nálezový stav před refaktorem, včetně tehdejších cest a kontrol.

## Ověření refaktoru

Prošla kompletní frontendová sada `php tests/frontend/run.php`, včetně
nativních testů historie modalů, validace, 252 případů responzivních admin
tabulek a 7 viewportů dashboardu. Dále prošly kontroly UI kontraktů
(95 komponent, 180 kontrol), vykreslování komponent (68 kontrol), assetového
grafu, distribučních rozpočtů všech 8 scopes a PHP syntaxe všech 412 souborů.

Parita CSS prošla pro 783 zbývajících pravidel. Baseline se změnila pouze
odebráním jednoho hashe odstraněného `select-sm`; nebyla znovu vygenerována
podle nového CSS. Každá trasa nadále dostává jeden sestavený stylesheet.
Plná backendová testovací sada `tests/run.php` nebyla při tomto refaktoru spuštěna.

## Naměřená změna CSS

Porovnání nekomprimovaného výstupu
`AssetGraph::stylesheet(ClientAssets::sourceStyles($route))` před a po změně,
v bajtech. Gzip znamená stejný zdroj komprimovaný na úrovni 9; nejde o měření
doby načítání v prohlížeči ani o velikost minifikovaného release balíčku.

| Povrch | Zdroj před | Zdroj po | Úspora zdroje | Gzip před → po |
| --- | ---: | ---: | ---: | ---: |
| Veřejná část `/`, `/notifications` | 82 121 | 80 072 | 2 049 (2,5 %) | 13 693 → 13 479 |
| `/admin`, `/admin/users` | 53 859 | 46 634 | 7 225 (13,4 %) | 9 802 → 8 818 |
| `/admin/moderation` | 74 496 | 67 354 | 7 142 (9,6 %) | 12 762 → 11 724 |
| `/admin/bots` | 62 819 | 55 594 | 7 225 (11,5 %) | 11 062 → 9 992 |
| `/install` | 43 167 | 35 049 | 8 118 (18,8 %) | 8 246 → 6 948 |

## Původní audit

## Výsledek

V aktuálním pracovním stromu je 95 deklarativních komponent. Žádné dvě nemají
stejný název ani přesně stejnou JSON definici po normalizaci pořadí klíčů.
Všechny statické odkazy `component` a `use` uvnitř katalogů mají definici.
To nevylučuje funkčně podobné komponenty nebo duplicity v ručně skládaném HTML.

Hlavní problém je dohledatelnost pravidel a rozdílná šíře pomocných API.
Byla nalezena také nefunkční velikostní varianta selectu, aktuálně bez dohledaného
producenta. Audit neprokázal chybu vyžadující okamžitý zásah do chování aplikace.

Kompletní [rejstřík](ui-inventory.md) doplňuje [strojový výstup](ui-inventory.json).
Oba lze obnovit pomocí `python tools/ui-inventory.py`.

## Rozsah a metoda

- 95 definic v `assets/ui/components.json` a `assets/modules/*/components.json`;
  rejstřík uvádí jejich zdroj, závislosti a v JSON také čtené vstupy.
- 423 CSS tříd v 93 souborech importovaných z `assets/styles/index.css`.
  Zvlášť je uveden samostatný `assets/embed.css` s 20 třídami; tento dokument má
  vlastní stylový kontext, nemíchá se do duplicit hlavní stránky.
- 17 JS souborů sdílených UI služeb, 8 PHP souborů rendererů, 36 PHP šablon,
  z toho 17 partialů. Šablony a module renderery pokrývají také ruční skládání UI
  mimo deklarativní katalogy; nejsou automaticky považovány za další komponenty.
- Statické porovnání opakovaných selektorů respektuje obalující `@media` bloky.
  Bylo nalezeno 33 skupin se stejným selektorem a stejným kontextem. Tento počet
  znamená kandidáty k posouzení, nikoli 33 vad nebo 33 identických bloků.
- Jde o audit zdrojů současného pracovního stromu včetně již rozpracovaných změn.
  Není to vizuální audit, kontrola všech tras v prohlížeči ani úplné ověření
  přístupnosti. CSS scanner je lexikální, nevyhodnocuje specificitu, překryv
  odlišných selektorů, shorthandy ani výsledný styl podle trasy. Statický výpis
  metod JS zachycuje syntaxi `name: function`, nikoli všechny možné exporty.

## Nálezy

### UI-01 — Soubory CSS nemají jednoznačné vlastnictví komponent (P2)

`assets/ui/styles/056-tabs.css:24` pokračuje po záložkách tabulkami,
na řádku 105 stránkováním a dále také modaly. Základní tabulka je přitom
v `assets/ui/styles/007-alert.css:74`. Formulářová pole jsou v souboru
`005-field-inline.css`. Název souboru tedy nevystihuje jeho kontrakt.

Dopad: při úpravě jedné komponenty se snadno přehlédne další zdroj jejích
pravidel. Nález je organizační; sám o sobě nedokazuje chybný výsledný styl.

Doporučení: samostatné soubory pro tabs, table, pagination, modal, field,
alert a badge. Při přesunu zachovat pořadí pravidel a synchronizovat importní
index i modulové manifesty. Ověřit paritu výsledného CSS.

### UI-02 — Sdílená UI vrstva obsahuje styly konkrétních modulů (P2)

Globální `assets/ui/module.json` načítá například `019-home-feed-app.css`,
`022-notifications-page.css`, `044-account-security-page.css` a
`045-account-security-card.css`. Vlastnictví je rozdělené: profil má další
styly v modulu users, feed v status/site a notifikace v notifications.

Dopad: hranice mezi obecným UI a modulem není z názvu a umístění zřejmá.
Globální pravidla se načítají i mimo vlastní povrch. Z tohoto samotného
zjištění nelze odvodit konkrétní úsporu velikosti ani bezpečně odstranit import.

Doporučení: určit vlastníka každé rodiny tříd, obecný rám ponechat v UI,
obsahový layout přesunout do modulu. Route manifesty ověřit před změnou načítání.

### UI-03 — `select-sm` přepisuje pozdější základní pravidlo (P3)

`assets/ui/styles/005-field-inline.css:15` nastavuje `.select-sm` na
`min-height: 38px` a `padding-block: 6px`. Pozdější skupina `.input, .select,
.textarea` na řádku 31 nastavuje `min-height: 40px` a `padding: 8px 10px`.
U elementu `class="select select-sm"` mají selektory stejnou specificitu,
takže pozdější základ přepíše obě zamýšlené kompaktní hodnoty.

Aktuální hledání v `App` a `assets` našlo pouze definici této třídy, žádné
použití. Je to vadný nebo zbytkový kontrakt, nikoli doložený incident na stránce.

Doporučení: nepotřebnou variantu odstranit, nebo ji definovat za základem
a zavést vědomé použití. Nerozšiřovat ji bez rozhodnutí o velikostech polí.

### UI-04 — Helper selectu má užší kontrakt než helper textového pole (P2)

`assets/ui/fields.js:22` přijímá u `field()` dodatečné atributy. `select()`
na řádku 26 přijímá pouze label/name/value/choices a pevně sestaví atributy.
Samotná deklarativní komponenta `ui/select` přitom obecné `attributes` podporuje;
například `users/admin-fields` v `assets/modules/users/components.json`
předává `disabled` přímo v katalogu.

Dopad: jednoduchá factory selectu neumí stejnou cestou předat `required`,
`disabled`, ARIA popis nebo datové atributy. Volající musí sáhnout po nižší
vrstvě nebo upravit DOM dodatečně. Nejde o chybu sdílené JSON definice.

Doporučení: sjednotit možnosti předávání atributů helperů, zachovat jejich
výchozí třídy a jasně určit pravidlo jejich slučování.

### UI-05 — Embed používá samostatnou vizuální sadu (P3, rozhodnutí o designu)

`assets/embed.css:1–7` má vlastní barvy, radius 14px a typografii.
Hlavní UI definuje barvy a radius 8px/5px v `assets/ui/styles/tokens.css`.
Embed karta proto nesdílí stejné vizuální tokeny jako hlavní aplikace.

Samostatný embed dokument má opodstatněné vlastní načítání. Rozdílný vzhled
není automaticky chyba. Doporučení: explicitně rozhodnout, zda jde o schválenou
variantu, nebo má přebírat společné designové hodnoty bez závislosti na celém UI.

## Co není duplicita k odstranění

- PHP `App/Support/Components.php` a JS `assets/core/components.js` jsou dva
  renderery stejného katalogu, nikoli dvě nezávislé definice UI. Oba obsahují
  ochranu proti opakované registraci názvu.
- `.field-inline` v `005-field-inline.css` a `082-field-inline.css` má základ
  a desktopovou variantu od 760px. Totéž platí pro `.feed-switch`
  v modulech status `017` a `069`: základ a mobilní varianta do 759px.
- `.pagination-ellipsis` přebírá rozměry z obecné skupiny a následně dostává
  transparentní pozadí a tlumenou barvu. `.textarea` mění obecnou výšku pole.
  Tyto přepisy představují záměrnou specializaci.
- Více bloků `.toast-success` apod. odděluje nastavení tokenů od společného
  vykreslení. `.status-video-copy small` mění barvu pro text nad obrázkem.
  Samotné opakování selektoru není důvod blok odstranit.
- Starší `docs/modal-audit.md` obsahuje původní nálezy i úvodní stav po jejich
  sjednocení. Původní odstavce nejsou automaticky aktuálními závadami:
  nynější PHP wrapper i JS factory používají `ui/modal` a klasifikaci editoru.

## Doporučené pořadí

1. Dohodnout vlastnictví rodin komponent podle rejstříku a oddělit soubory CSS
   při zachování kaskády. Jde o největší zdroj obtížné dohledatelnosti pravidel.
2. Sjednotit kontrakt formulářových helperů a vyřešit nepoužívaný `select-sm`.
3. Rozhodnout o vizuální příbuznosti embedů a hlavního UI.
4. Navázat vizuálním průchodem: veřejný feed, admin seznam, tabbed editor,
   validace, potvrzení, menu, tooltip a toast; desktop/mobil a light/dark.

## Ověření

- `php tests/presentation/components.php` — PASS, 68 kontrol.
- `node tests/frontend/styles.cjs` — PASS, parita 784 CSS pravidel.
- Generátor rejstříku — 0 duplicitních názvů, 0 přesně shodných definic,
  0 nerozřešených statických závislostí v katalozích.

Změny tohoto auditu přidávají dokumentaci a obnovitelný inventární nástroj.
Chování aplikace nebylo upraveno. Výše uvedené testy nepotvrzují vizuální
správnost ani bezchybnost všech toků aplikace.
