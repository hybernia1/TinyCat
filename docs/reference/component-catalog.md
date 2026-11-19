# Zápis komponentových katalogů

Komponenty vlastní příslušná funkce ve svém deklarovaném katalogu.
Jeden katalog může obsahovat více pojmenovaných definic. ID má tvar `namespace/name`;
počet komponent neurčuje počet souborů. Formát zůstává běžný JSON.

PHP a JavaScript vykreslují stejné definice. PHP předem připraví data a oprávnění,
controller prohlížeče doplní chování. Definice nesmějí získávat databázi ani služby.
Vnořené DOM struktury patří do komponent; technický HTML dokument má společnou
serverovou šablonu. Doménové API vrací kanonická data, nikoli definice UI.

## Základní zápis

```json
{
  "example/heading": {
    "tag": "h2",
    "attrs": {"class": "text-lg"},
    "children": [{"get": "title"}]
  },
  "example/list": {
    "tag": "ul",
    "children": [{
      "each": {"get": "items"},
      "as": "item",
      "children": {"tag": "li", "children": [{"get": "item.label"}]}
    }]
  }
}
```

Strukturní instrukce jsou `tag`, `each`, `component`, `icon`, `csrf`, `rich`
a `slot`. `extensions` označuje bod pro příspěvky vybraných vlastníků.
Jeden uzel má jednu strukturní instrukci; `when` je podmínka běžného uzlu.
Textový uzel může být literál nebo hodnotový výraz. `component` skládá jinou
pojmenovanou definici s explicitním kontextem `data`. `each` vyžaduje `children`.

`component.data` a `use.data` přijímají mapu jednotlivých vazeb nebo přesně
`{"get": "field"}` pro předání celého vybraného objektu:

```json
{"component": "settings/field", "data": {"get": "field"}}
```

Podkomponenta nedědí rodičovská data. Předaný objekt je zdroj dat, jeho hodnoty
se znovu neinterpretují jako instrukce. Chybějící kontext, `null`, skalár a
neprázdný seznam jsou chyby. Prázdný objekt i prázdný seznam znamenají prázdný
kontext: PHP při asociativním dekódování JSON nerozlišuje `{}` a `[]`.
Souvisle číslovaná objektová mapa od nuly je ze stejného důvodu seznam.
Zakázané klíče `__proto__`, `constructor` a `prototype` platí i pro předaný objekt.

Samostatný řetězcový klíč `get` je v `data` rezervovaný pro tento odkaz.
Pokud podkomponenta potřebuje parametr pojmenovaný `get`, použij pro jeho
literál výraz, například `{"get": {"string": "literal"}}`. Mapy s více
pojmenovanými vazbami si zachovávají dosavadní význam. Při přejmenování položek,
kombinování zdrojů nebo záměrném omezení kontextu použij explicitní mapu.

Výrazy podporují `get`, `string`, `json`, `t`, `plural`, `count`, `use`,
`lookup`, `options`, `date`, `concat`, `if`, `eq`, `not`, `and` a `or`.
Zdrojový odkaz `admin_ref` s `field` načítá hodnotu z administrační definice.
`get` používá tečkovanou datovou cestu. `if` má dva nebo tři operandy,
`eq` dva. `plural` potřebuje `count`; překlady mohou mít pojmenovanou mapu `values`.
Mapy `lookup` lze získat od vlastníka přes společně deklarované `catalog` a `field`.

Podrobné povolené vlastnosti popisuje [JSON schéma](component-catalog.schema.json).
Build navíc ověřuje existenci referencí, bezpečnost statických elementů a atributů,
duplicitní klíče a definice. Kontrola konkrétních dat a URL zůstává v rendereru.
Obecné objektové hodnoty mohou být datové mapy: jejich neznámé datové klíče
nejsou automaticky překlepem instrukce. Build rozlišuje uzly, vazby a výrazy.

## Editor a diagnostika

Jednotný formát všech devíti zdrojových katalogů spravuje
[component-format.php](../../tools/quality/component-format.php):

```sh
php tools/quality/component-format.php --check
php tools/quality/component-format.php --write
```

Volitelně předej konkrétní JSON soubory za přepínačem. Krátké výrazy a mapy
se vejdou na jeden řádek do šířky 120 znaků; delší struktury se rozepíšou.
Dlouhé nedělitelné řetězce se nemění. Strukturální klíče mají pořadí dané
gramatikou, pořadí potomků a obyčejných datových map se zachovává. Formatter
odstraní prázdné `attrs` a `children` elementu a prázdné překladové `values`;
nemění textové mezery ani typ prázdných datových objektů a seznamů.
Režim kontroly toleruje LF i CRLF a nikdy nezapisuje. Duplicitní klíče odmítne
před případným přepsáním souboru. Kontrola formátu je součástí UI kontraktů;
produkce formatter ani vývojové závislosti nepotřebuje.

VS Code používá verzovanou asociaci v [nastavení workspace](../../.vscode/settings.json).
V ostatních editorech připoj schéma pomocí asociace souborů; `$schema` nepřidávej
jako komponentu do kořene katalogu. Odpovídající nastavení VS Code:

```json
{
  "json.schemas": [{
    "fileMatch": ["/App/**/assets/components.json", "/assets/**/components.json"],
    "url": "./docs/reference/component-catalog.schema.json"
  }]
}
```

Gramatiku vlastní [component-syntax.php](../../tools/quality/component-syntax.php).
Po její změně obnov schéma přes `php tools/quality/component-schema.php`.
Kontrola UI odmítne neaktuální generované schéma. Chyba buildu identifikuje
soubor, komponentu a cestu, například `$.children[0].attrs.onclick`.

## Rozdělení odpovědností

- Handler autorizuje požadavek, získá data a zavolá komponentu.
- Resource vlastní kanonická data, viditelnost a oprávnění; nevrací UI instrukce.
- Komponenta vlastní HTML strukturu, jednoduché prezentační podmínky a překlady.
- Controller vlastní události, požadavky a stav. Dialogy, fokus a koncepty
  používají společný lifecycle a factory.
- `Markup` a DOM sloty jsou hranicí důvěryhodné kompozice. Běžný řetězec
  ani data API nejsou důvěryhodným HTML.

Nevytvářej PHP partial pouze jako další obálku nad komponentou. Složitější
výpočty připrav před renderem; nerozšiřuj výrazový jazyk pro jediný případ.
Při mazání staré renderovací cesty aktualizuj volající, fixture a update manifest.
Technické mechanismy jako RSS převod textu, sanitizace a CSRF mohou vytvářet
svůj vlastní formát; doménové obálky a stránky používají komponenty.

Hotový formulář z katalogu lze předat do `TinyCat.ui.form(url, method, view)`.
Factory ověří kořen `FORM`, doplní společný transport, třídu `stack` a ochranu
neuložených změn. Struktura polí a atributy konkrétního editoru patří katalogu.
Statusové formuláře si zachovávají vlastní existující transport.

Controllery s označením `@component-owned` kontroluje modalový build guard proti
návratu běžných ručních DOM builderů. Jde o cílenou kontrolu konvence, nikoli
bezpečnostní sandbox. Serverové komponenty, například veřejná hlavička, nemusí
být kořeny klientského manifestu; jejich struktura se klientovi neposílá.
