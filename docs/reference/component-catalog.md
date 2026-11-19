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
pojmenovanou definici s explicitní mapou `data`. `each` vyžaduje `children`.

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
