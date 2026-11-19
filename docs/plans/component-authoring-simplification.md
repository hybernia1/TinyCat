# Zjednodušení komponentového zápisu

Původní návrh k 2026-11-19, po commitu `8ea06845`. Implementace níže popsané
etapy je dokončená; výsledky ověření zaznamenává její samostatný testovací běh.
Dokončený úklid JavaScriptu zůstává samostatnou ověřenou etapou.

## Výchozí stav

Devět zdrojových katalogů obsahuje 138 definic, 1 113 elementových uzlů a
20 903 řádků. Zápis je běžný JSON sdílený PHP a prohlížečem. Schéma,
diagnostika, výběr aktivních vlastníků a překladové kanály již existují.

Inventář klíčů obsahuje 1 846 výrazů `get`, 276 volání `component` a 477
elementů s `attrs` obsahujícím pouze `class`. Dále obsahuje 167 prázdných
`values`, 139 prázdných `attrs` a 41 prázdných `children`; o odstranění rozhodne
kontext instrukce, nikoli samotný název klíče v libovolné datové mapě.
U 78 komponentových volání je celé `data` jen mapou výrazů `get`.
To neznamená, že lze všech 78 map nahradit celým objektem: některé přejmenovávají
vlastnosti nebo záměrně skládají několik zdrojů.

## Doporučená etapa

### 1. Jednotný formát bez změny významu

- Krátké hodnotové výrazy psát na jeden řádek: `{"get": "item.title"}`.
- Malé atributové mapy držet pohromadě; strukturální uzly a delší seznamy
  potomků rozepsat podle čitelnosti. Použít jedno pravidlo šířky řádku.
- Sjednotit pořadí strukturálních klíčů a odstranit redundantní prázdné
  výchozí položky pouze tam, kde má jejich vynechání stejný význam.
- Formát zajišťovat jedním vývojovým nástrojem s režimem kontroly a zápisu.
  Neformátovat ručně každý katalog a nevyžadovat Node v produkci.

Přínosem je menší vizuální šum a stabilní diff. Počet řádků není měřením
výkonu; samotné bílé znaky neurychlí již kompilovaný runtime.

### 2. Sjednotit struktury pomocí existujících komponent

Nejdříve prověřit ručně opakované rodiny: pole s popiskem a nápovědou,
selecty, akce, stavové štítky a záhlaví řazení. Použít stávající
`ui/field`, `ui/select`, `ui/api-action`, `ui/status-badge` a `ui/sort-heading`,
pokud mají stejnou sémantiku a chování.

Například [administrace účtů](../../App/Core/Identity/assets/components.json)
obsahuje ruční struktury polí vedle již existujících společných definic.
Speciální skupiny nastavení, uploady, checkboxy a rich editor zachovají své
odlišné kontrakty. Nevytvářet univerzální komponentu se seznamem všech
doménových typů a mnoha přepínači ani wrapper pro každý jeden element.

### 3. Explicitní datový objekt místo opisování jeho vlastností

Současné `attrs` přijímá mapu i hodnotový výraz. Naproti tomu `component.data`
a `use.data` přijímají pouze mapu jednotlivých vazeb. Navržené sjednocení dovolí
také existující výraz `get`, který vyhodnotí celý objekt.

Reálný kandidát je rekurze v
[nastavení](../../App/Core/Settings/assets/components.json):

```json
{
  "component": "settings/field",
  "data": {"get": "field"}
}
```

Tento příklad je implementovaný a nahrazuje předávání třinácti vlastností
téhož připraveného objektu v obou rekurzivních cestách nastavení. Pro
přejmenování, konstanty a kombinování zdrojů zůstává explicitní mapa.

Podkomponenta dostane pouze vybraný kontext, bez automatického dědění rodiče.
Specifikovat chybu pro skalár, seznam nebo chybějící objekt a shodné chování
PHP/JS, včetně prázdné mapy. Prověřit kolize s existujícími datovými mapami,
jejichž klíče odpovídají názvům instrukcí. Upravit společně validátor,
editorové schéma, oba renderery, překladový průchod a testy.

## Hranice návrhu

Zůstane JSON, vlastnictví katalogů a jeden zdroj serverové i klientské struktury.
Nezavádět interpolaci v obyčejných řetězcích, vykonatelné výrazy, selektorový
jazyk v `tag`, implicitní scope ani HTML řetězce. Nepřidávat alternativní
`text`/`children` zápisy nebo několik synonym pro tutéž operaci.

Rozšíření má snížit počet pravidel a opakování. Kompaktnější formát je
autorská vlastnost, nikoli sám o sobě výkonová optimalizace. Případnou změnu
velikosti doručovaných katalogů či času renderování měřit samostatně.

## Ověření implementace

Nejprve pilot na jednom skutečném formuláři a rekurzivním nastavení, potom
jednotná migrace katalogů v jedné etapě. Ověřit neměnnost renderovaného DOM,
PHP/JS paritu, escaping, scope, překlady, resource formuláře a editorové schema.
Formatter musí být idempotentní a zachovat textové whitespace uzly i pořadí
potomků a dat. Změna gramatiky nebo společného renderování vyžaduje na konci
celé implementace úplný preflight; pro tento návrhový dokument stačí diff,
odkazy a věcná kontrola.

## Implementovaná etapa

Všech devět katalogů sjednocuje vývojový formatter s režimy kontroly a zápisu.
Sdílí gramatiku i kontrolu duplicitních klíčů s build validátorem. Prázdné
výchozí položky odstraňuje pouze v odpovídajícím syntaktickém kontextu.
Oba renderery podporují úzký objektový odkaz v `component.data` a `use.data`;
ostatní vazby a překladové parametry zachovávají původní chování.

Pilot konsoliduje uživatelské jméno v administraci účtů přes `ui/field`, včetně
podmíněné nápovědy. Specializované selecty se neliší jen zápisem: mají různé
datové modely a pravidla výběru, proto se bez další vrstvy nepřevádějí.
Statistické karty ponechávají záměrně omezené mapy. Celý kontext přebírají
pouze dvě rekurzivní volání nastavení.

Prázdný seznam se přijímá jako prázdný kontext shodně s prázdným objektem;
PHP při asociativním dekódování JSON nerozlišuje jejich reprezentaci.
Neprázdný seznam, skalár, `null` a chybějící odkaz jsou chyby. Tyto hranice,
rekurzi, doslovná data, escaping a zákaz prototypových klíčů ověřují společné
PHP/JS fixture. Kompaktnější zápis sám o sobě není měřením výkonu.

Při kontrole assetů byl odhalen také zbytečně globální výběr pěti komponent
dialogů, polí a filtrů. Jejich klientská deklarace nyní patří veřejnému a
administračnímu povrchu; instalace dál používá serverové komponenty průvodce
a vlastní formulářový transport. Sdílené styly a základní UI služby zůstávají.
Tím se instalace vejde do stávajících rozpočtů bez jejich zvyšování.
