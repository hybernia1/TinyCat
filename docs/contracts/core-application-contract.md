# Autorita core pro API, UI a validace

Core určuje společné chování aplikace a vynucuje jej na hranicích HTTP, renderování
a registrace. Modul přispívá vlastní funkcí prostřednictvím těchto kontraktů.
Rozdělení povinného frameworku a funkčních modulů se tím nemění.

Tento dokument je společný vstup. Podrobné kontrakty odkazované níže zůstávají
autoritativní pro příslušnou oblast; nové paralelní transporty, renderery formulářů
nebo validační obálky nevznikají.

## Vlastnictví a veřejné vstupy

| Oblast | Autorita core | Vstup modulu | Závazný kontrakt |
| --- | --- | --- | --- |
| HTTP API | Parsing, přístupová politika, CSRF, obálka a serializace | Registrace přes `TinyCat\Http\Api`, data nebo `Result` | [HTTP API](../reference/api.md) |
| Validace | Typy, obecná pravidla, struktura chyb a HTTP 422 | Pravidla pro `Request::validated()`, doménové chyby přes `Response::validation()` | Tento dokument a HTTP API |
| Dokument a UI | `Core\Ui\Page`, společné komponenty, vykreslení PHP/JS | Data, vlastní komponentové definice, stránky a pojmenovaná rozšíření | [UI komponenty](ui-conventions.md), [administrace](admin-ui-conventions.md) |
| Dialogy a formuláře | Resource loader, lifecycle, validace, historie a koncept | Autorizovaný resource endpoint a definice formuláře | [Modaly](modal-contract.md) |
| Ukotvené nabídky | `TinyCat.popovers`, otevření, fokus a pozicování | Obsah a deklarovaná varianta panelu | [Popovery](popover-contract.md) |
| Seznamy | Společné stránkování a transport | Vlastní dotaz, viditelnost, filtry a stabilní řazení | [Výběr dat](query-selection-standard.md) |
| Překlady a assety | Katalog, klientské závislosti a sestavení | Vlastní texty, komponenty a manifest zdrojů | [Překlady](translations.md) |

Sdílené mechanismy jsou veřejné kontrakty. Interní cesta k šabloně, soukromá
metoda rendereru nebo náhodný DOM selektor se veřejným API nestává. Rozšíření
jde přes deklarované poskytovatele, příspěvky a `componentExtensions`.

## HTTP a data

Modul registruje API přes `TinyCat\Http\Api` s explicitní přístupovou politikou.
Core autorizuje požadavek a kontroluje CSRF před obsluhou. Modul navíc ověřuje
oprávnění ke konkrétnímu objektu a jeho viditelnost. Skryté tlačítko ani klientská
validace toto oprávnění nenahrazují.

Úspěšný handler vrací data nebo `TinyCat\Http\Result`. Společný transport sestaví
`ok`, `status`, `code`, `params`, `message`, `data` a `meta`. Modul nepíše vlastní
JSON obálku, neukončuje API přes `echo json_encode()` a neposílá HTML ani definice
komponent v doménových resources. Core tato pravidla kontroluje při serializaci.

Výsledkové a validační kódy odpovídají `^[a-z][a-z0-9_]*$`. Jsou stabilními
identifikátory API; překladový klíč určuje vlastník ve svém klientském katalogu.
Nečekaná chyba implementace má HTTP 500 a bezpečný fallback bez interních detailů.

## Serverové validace

Pro obecná pravidla obsluha používá `Request::validated()`. Vrácená data obsahují
pouze deklarovaná přítomná pole; hodnoty se automaticky nepřetypovávají. Kontrola
používá stejnou prioritu query a body jako `Request::input()`. Pro kontrolu mimo
HTTP je dostupný `Validator::validate(..., structured: true)` bez ukončení procesu.

- `required` odmítá chybějící pole, `null`, prázdný řetězec a prázdné pole.
  Hodnoty `0` a `false` jsou přítomné hodnoty. Ořezávání textu vlastní jeho příprava.
- Chybějící nepovinné pole se nevaliduje. `nullable` povoluje `null` a prázdný
  řetězec; při kombinaci s `required` má požadavek na přítomnou hodnotu přednost.
- `integer`/`int` přijímá PHP integer nebo řetězec ověřený `FILTER_VALIDATE_INT`.
  Boolean, float a složené hodnoty nejsou celočíselný vstup.
- `number`/`numeric`/`float` přijímá číselnou hodnotu reprezentovatelnou konečným
  číslem. Nekonečno a nečíselné hodnoty jsou neplatné.
- `boolean`/`bool` přijímá boolean a stávající formulářové hodnoty
  `0`, `1`, `true`, `false`, `on`, `off`, `yes`, `no`. Samotná validace je nepřevádí.
- `in` porovnává skalární hodnotu s deklarovanými možnostmi; pole a objekty
  vrátí validační chybu bez PHP warningu. Požadovaný typ se deklaruje zároveň,
  například `string|in:published,hidden`.
- `min`, `max` a `between` kontrolují číselnou hodnotu u číselných pravidel,
  počet prvků pole nebo délku řetězce. Rozsah musí obsahovat konečné číselné
  meze ve správném pořadí. Délka textu se počítá ve znacích Unicode.
- `max_bytes` kontroluje délku řetězce v bajtech UTF-8. Mez musí být nezáporné
  celé číslo. Vlastník volí jednotku podle skutečného omezení svého úložiště.

Neznámé pravidlo, špatný počet parametrů nebo neplatná mez je chyba definice
v implementaci, nikoli chyba uživatele. Core ji odmítne jako `LogicException`,
i když nepovinné pole ve vstupu chybí; API ji vrátí jako HTTP 500.
Neplatná uživatelská hodnota naopak vede na společnou obálku HTTP 422:

```json
{
  "ok": false,
  "status": 422,
  "code": "validation_error",
  "error": {
    "details": {
      "errors": {
        "title": [
          {"code": "title_invalid", "params": {"field": "title"}, "message": "Invalid title."}
        ]
      }
    }
  }
}
```

Ukázka zkracuje ostatní společná pole obálky. Core vždy doplní skutečný název
pole z mapy `errors`; modul jej nemůže přepsat parametrem jiného pole.
Doménová kontrola používá `Response::issue()` a `Response::validation()`.
Textové chyby stávajících obsluh transport normalizuje do stejného formátu;
nové strukturované chyby mají vlastní stabilní kód.

Core neurčuje povolené stavy článku, délku statusu ani existenci či vlastnictví
záznamu. Tyto podmínky vlastní modul a společně je uplatňuje pro HTTP, CLI a import.
Nová a upravovaná funkce má jeden zdroj svých limitů a možností pro serverovou
kontrolu, resource metadata i UI. Klientská kontrola pomáhá s obsluhou formuláře;
server zůstává rozhodující i při jejím obejití.

### Jedna definice pravidel pro server a formulář

Vlastník deklaruje pole jednou, například `Articles::rules()`. Příprava vstupu
(trim, odstranění HTML, výchozí hodnoty) patří vlastníkovi a nesmí neplatný typ
přetypováním změnit na platný. HTTP může předat připravený payload přímo do
`Validator::validated($rules, $prepared)`; ukládání a import používají stejná
pravidla i bez HTTP. Doménová kontrola nadále ověřuje vlastnictví a provozní stav.

`Validator::constraints($rules)` odvozuje veřejná data pro editor:

| Pravidlo | Metadata |
| --- | --- |
| Typ | `type`: `string`, `integer`, `number`, `boolean`, `array` |
| Přítomnost | `required`, `nullable` |
| `in` | `values` |
| Číselné meze | `min`, `max` |
| Znakové meze | `min_length`, `max_length` |
| Počet prvků | `min_items`, `max_items` |
| Bajtový limit | `max_bytes` |

Metadata vyžadují jeden jednoznačný typ pole. Jsou to data, nikoli HTML atributy,
překladové klíče nebo úplná kopie serverové autorizace a doménových pravidel.
Resource editoru je vrací pod `constraints`; komponenta vybírá prezentaci.
Výchozí hodnoty patří připravenému záznamu. Blog takto poskytuje editor článků
i zdrojů; dřívější `limits` a `statuses` odvozuje ze stejných pravidel.

Sdílené formuláře používají pro znakové limity `data-max-length` a
`data-min-length`, pro bajty `data-max-bytes`. Nativní `maxlength` počítá UTF-16
a není náhradou serverového počítání Unicode. Číselné meze používají nativní
`min` a `max`. `TinyCat.forms.validate(form)` před nativní kontrolou synchronizuje
tyto limity; stejný vstup používá společný AJAX transport. Chyby editoru se zobrazí
přes existující validační proxy. Bajtový limit nesmí zkracovat rozepsaný obsah.
Editor si tuto společnou kontrolu vyžádá v asset manifestu přes
`uses: ["ui.form-constraints"]`; ostatní stránky její implementaci nestahují.

Stejný převod používají zdroje Socialu a diskuse Blogu. Zdroj Socialu zachovává
své bajtové limity; náhled čerpá ze stejné definice. Komentář Blogu má vlastní
znakový limit a při překročení vrací chybu pole bez zkrácení konceptu.

## Registrace stránek a navigace

Serverový vstup zůstává `Router`. `Router::page($group, $path, $handler,
$resource, $query)` registruje veřejnou GET/HEAD stránku a její lazy datový
callback současně. Doménová viditelnost i příprava dat patří callbacku vlastníka.
`Router::pageResource()` řeší cestu stejným matcherem; skupina omezuje výběr
na přispívajícího vlastníka. Kořen `/` respektuje homepage vybranou core.
Neúspěšná registrace poskytovatele vrátí zpět trasy i jejich metadata.

`Router::pageDefinitions()` poskytuje datové regexy a povolené query parametry
pro klientskou navigaci. Modul nedržuje druhý ruční seznam cest v JavaScriptu.
Social deklaruje obsluhy, resources, lokalizované cesty a query parametry z jednoho
seznamu v `PublicPages`. Navigaci, historii a ochranu konceptů nadále řídí
`TinyCat.navigation`; Blog používá tentýž serverový router a společnou navigaci
seznamů, bez vlastního veřejného AJAX rozcestníku.

Klientská navigace stránky registruje
`TinyCat.navigation.registerPage({ accepts, target, load, apply })`.
Její klientský vstup importuje volitelný mechanismus `assets/core/page-navigation.js`;
instalátor a stránky bez této navigace jej nestahují.
`accepts(url)` vymezuje podporované URL, `target` je selektor obsahu,
`load(url, signal)` vrací resource s kanonickým `url` a `apply(target, data)`
synchronně vykreslí obsah vlastníka. Modul při čtení předává `signal`
společnému API klientu. Core vlastní rušení souběžných čtení, busy stav,
ochranu pozdních odpovědí, historii `pageIndex`, scroll, guard konceptů
a fallback na dokument. Odstranění cílového kontejneru přes lifecycle ruší
jeho čtení; dispose během schváleného vykreslení neruší dokončovanou navigaci.
Historie dialogů sdílí tento index a obnovuje scroll pozadí přes core.
Modul nezavádí vlastní `visit`/`restore` ani scroll listener.

Klientská služba modulu má vlastní objekt. Sociální vztahy používají
`TinyCat.socialUsers`, účty `TinyCat.accounts`; modul nerozšiřuje účtový objekt
přes kompatibilní alias. Sdílený editor, média a překlady se používají přímo
z `TinyCat.publishing.controllers`, nikoli kopírováním jeho ovladačů.

Veřejné strojové dokumenty používají `Http/Document::respond()` a pro své
protokolové chyby `Document::jsonError()`. Tento formát není aplikační API:
doménové API nadále používá společnou obálku `Response` a `Result`.
`Localization::defaultLocale()` vrací nakonfigurovaný jazyk instalace a nemění
aktuální jazyk návštěvníka. Feed a embed si tak neodvozují další globální
jazykovou politiku v modulu. CSP podporovaných přehrávačů vlastní společná
HTTP vrstva a katalog core odkazů; moduly nezapisují do globálního seznamu originů.

Status a komentář Socialu deklarují pravidla v `PostCreation::rules()` a
`Interactions::rules()`. HTTP ověřuje typ a limit před přetypováním; příliš
dlouhý ruční vstup vrátí HTTP 422 s chybou pole bez zkrácení konceptu.
Metadata resource a editor používají `Validator::constraints()` a Unicode
`data-max-length`. Feedová příprava nadále zkracuje anotace limitem vlastníka.

## UI a vynucení

Modul dodává význam, pole a data. Core řídí načtení resource, sestavení formuláře,
zobrazení chyb, fokus, zavírání a zachování konceptu. Počáteční HTML a AJAX
vykreslují stejné komponentové definice. Vlastní vizuální obsah se rozšiřuje přes
komponenty a deklarované assety; společný lifecycle se znovu neimplementuje.

Statusy, komentáře, follow formuláře a editory používají společný AJAX submit.
Vlastník při hydrataci nastaví endpoint, explicitní HTTP metodu a chování dialogu;
callback výsledku aktualizuje pouze doménový obsah. Validace, zámky, chybová pole,
loading, označení uloženého konceptu a úspěšný event patří společné vrstvě.
Transport přidává skutečný submitter před uzamčením ovládacích prvků.

Podmínku před odesláním připojí vlastník přes
`TinyCat.forms.setSubmitGuard(form, callback)`; callback vrací boolean nebo Promise.
`false` zachová formulář bez zápisu. `null` hook odstraní. Náhled zdroje Socialu
tím vyžádá potvrzení aktuálních hodnot bez druhého submit listeneru. Dispozice
formuláře během čekání nesmí později zahájit zápis.

Společná obsluha čeká také na Promise z `module.result()`. Modul vrací `false`
synchronně pro cizí výsledky; Promise smí vrátit až pro výsledek, který převzal.
Po dokončení core provede společné dokončení formuláře. Klient čte resource z
`response.data`, zprávu z `response.message` a prezentační typ z `response.meta.type`;
samotný payload ani zprávy ukryté v něm nejsou alternativním API formátem.

API kontrakt ověřují [HTTP scénáře](../../tests/http/api-contract.php), primitiva
a validace [infrastrukturní testy](../../tests/infrastructure/run.php). Strukturu
modulů kontrolují [hranice](../../tests/quality/module-boundaries.php) a
[závislosti](../../tests/quality/dependencies.php); UI a dialogy příslušné build
kontroly a nativní scénáře uvedené v jejich kontraktech. Hlavním společným vstupem
zůstává `composer preflight`. Změna veřejného chování musí současně upravit
kontrakt, jeho obsluhy a odpovídající regresní scénář.
