# Pravidla UI komponent a stylů

Pro všechny modaly je závazný [jednotný kontrakt modalů](modal-contract.md),
včetně vytváření záznamů, lokálních pomocných dialogů a vymezení filtrů.
Je vynucovaný také při buildu a společným lifecycle/factory API.

Pro interaktivní ukotvené panely platí [jednotný kontrakt popoverů](popover-contract.md).
Notifikace, filtry i kontextové akce používají společný controller a povrch.

Aktuální [rejstřík](ui-inventory.md) se obnovuje příkazem
`python tools/ui-inventory.py`. Obsahuje definice, závislosti, PHP šablony,
UI služby, CSS třídy i manifesty a trasy jednotlivých stylů.

## Jeden zdroj struktury

Opakované odkazy na konkrétní obsah mají přístupný název složený z účelu
a kontextu cíle. Pro akce používat `ui/action-label` a společný kontext
příspěvku; ten obsahuje také ID, aby odlišil shodné autory a úryvky.
Samotné „Komentáře“, „Vložit“ nebo číselná hodnota mezi opakovanými odkazy
nestačí. Veřejný profil a správa účtu jsou odlišné účely.

Viditelný text zůstává součástí přístupného názvu. U číselných odkazů přidat
účel a kontext přes `.sr-only` vedle skutečného počítadla, nikoli přepsat
počet statickým `aria-label`. Název se tak aktualizuje i po AJAXové změně.
Ikonové a obrázkové odkazy mohou používat `aria-label`; datumový odkaz
zahrnuje viditelné datum i kontext příspěvku. Odkaz na komentáře má stejnou
kanonickou URL na stránce i v modalu; posun uvnitř modalu řídí společný loader.

`node tests/frontend/link-labels.cjs` kontroluje PHP i klientské vykreslení,
shodné úryvky, chybějící kontext, změnu počtu a pravidla axe-core pro názvy
odkazů. Běží ve WebKitu na mobilní i desktopové šířce ve třech jazycích.

Prázdné seznamy používají `ui/empty-state`. Pro sidebar a našeptávání se
nastavuje `compact: true`; nevytvářet další vlastní prázdné hlášky.
Prázdný výsledek, nápověda a selhání požadavku jsou odlišné stavy:
`ui/empty-state`, `ui/notice` s tónem `info` a `ui/notice` s tónem `danger`.
`ui/notice` přijímá jednoduchý `label` nebo strukturovaný `title`,
`description` a `detail`. Zachovat oba způsoby použití: zdraví webu a
aktualizace používají strukturovanou variantu. Tóny `critical` a `ok`
jsou ekvivalenty `danger` a `success`.

Řádky s avatarem používají `.identity-row` a `.identity-copy` ze společného
`identity-rows.css`. Varianta `identity-row-compact` má avatar 36 px,
běžná 44 px; `identity-copy-truncate` slouží pro jednořádkové náhledy.
`identity-row-inset` odstraňuje vnitřní odsazení při vložení do většího řádku.
Hover, typografii a rozložení neurčuje znovu každý modul.

Ovladače načítání další stránky používají `ui/load-more` a `load-more.css`.
Modul dodává atributy pro svůj loader a přeložené popisky. Rám obsahuje
tlačítko i stav načítání; po vyčerpání stránek se odstraní celý rám.
Informační upozornění přebírají barvy `alert-info`, cookie lišta sdílené
tokeny zaoblení a stínu.

Komponenty sdílené serverem a prohlížečem mají definici v
`assets/ui/components.json` nebo `assets/modules/<modul>/components.json`.
PHP i JS vykreslují stejný katalog. Specifický obsah se skládá z těchto
komponent; nepřidává se druhá nezávislá implementace stejné struktury.

`tools/ui-contracts.php` se spouští při každém buildu assetů. Odmítne opakovaný
JSON klíč včetně vnořených objektů, opakovaný název komponenty mezi katalogy,
přesnou kopii definice pod jiným názvem a chybějící statický odkaz `component`
nebo `use`. U shodné struktury použít existující komponentu nebo wrapper,
který ji skládá s vlastními vstupy. Rekurzivní struktury jako strom komentářů
jsou přípustné; jejich ukončení závisí na vstupních datech.

## Vlastnictví CSS

| Vlastník | Obsah |
| --- | --- |
| `assets/ui/styles` | Obecné prvky, layout utility, pole, tlačítka, karty, tabulky, stránkování, modaly, menu, tooltipy a toasty |
| `assets/modules/status/styles` | Feed, composer, statusy, komentáře a metadata autora |
| `assets/modules/users/styles` | Profil, vlastní účet a editace avataru |
| `assets/modules/site/styles` | Veřejná navigace, sidebar a layout stránky |
| `assets/modules/search/styles` | Globální vyhledávání a jeho nabídka |
| `assets/modules/notifications/styles` | Stránka notifikací a notifikační menu |
| `assets/modules/installation/styles` | Kroky instalace a volby instalačního průvodce |

Zbývající moduly vlastní své dosavadní styly stejným způsobem.
Každý styl deklaruje manifest ve svém adresáři. Kontrola
`php tests/quality/ui-contracts.php` tuto hranici vynucuje.

Sdílený `result-list.css` používá instalace, diagnostika i aktualizace;
proto zůstává v UI. Metadata autora statusu potřebuje veřejný feed i náhled
v moderaci. Editor účtu a avatar jsou dosažitelné z veřejné části i administrace;
jejich styly se nesmějí omezit pouze podle adresy veřejného profilu.

Veřejné moduly ponechávají své veřejné styly na celém veřejném povrchu:
sdílená navigace a dynamicky otevřené dialogy je mohou potřebovat také mimo
stránku odpovídající jejich názvu. Nový užší scope musí zohlednit tyto cesty.

## Pořadí a varianty

`assets/styles/index.css` je jediný seznam určující globální pořadí CSS.
Manifest modulu vybírá soubory pro příslušné trasy. Při změně souboru je
nutné udržet obě deklarace v souladu.

Soubory pojmenovávat podle rodiny komponent a případně varianty:
`fields.css`, `table-identity.css`, `pagination-desktop.css`,
`modal-workspace-desktop.css`. Číselné prefixy se nepoužívají: pořadí určuje
importní index, nikoli název souboru. Přípona `-mobile` nebo `-desktop`
popisuje skutečný breakpoint v souboru. Název nemá kopírovat první selektor,
pokud soubor obsahuje širší rodinu souvisejících pravidel.

Například `notification-items.css` styluje neprázdný seznam notifikací,
`notification-badge-mobile.css` mobilní odznak a
`profile-layout-desktop.css` desktopové rozložení profilu. `status-content.css`
obsahuje média, reakce a komentáře; `status-editing.css` jejich formuláře,
editor a historii změn. `home-feed-switch-mobile.css` obsahuje mobilní
variantu přepínače feedu.

Jedna komponenta smí mít více bloků pro základ, stav a breakpoint.
Oddělené soubory některých variant zachovávají původní polohu v kaskádě.
Neslučovat je pouze kvůli shodě názvu selektoru. Přesuny musí projít testem
`node tests/frontend/styles.cjs`, který porovnává obsah a pořadí pravidel.
Při pouhém přesunu se baseline nemění. Při záměrné změně vzhledu se upraví
až po kontrole změněných pravidel a ověření jejich vykreslení v prohlížeči.

Barvy a obecné rozměry definovat pomocí `tokens.css`. Rám modalu řídí
společná komponenta a její pojmenované varianty; modul vlastní jeho obsah.
Samostatný embed dokument si zachovává svou stávající vizuální variantu
a samostatný stylesheet. Tento refaktor nemění jeho vzhled ani jej nenutí
načítat CSS celé aplikace.

## JavaScript — názvy a odpovědnosti

JS používá stejné pojmenování podle skutečné odpovědnosti. Umístění rozlišuje
obecné služby v `core`, sdílené ovládání UI v `ui` a doménové chování
v `modules/<modul>`. Importy a manifesty určují načítání i pořadí inicializace.

| Soubor / role | Odpovědnost |
| --- | --- |
| `core/api-client.js` | JSON transport, CSRF a API odpovědi (`TinyCat.api`) |
| `core/request-lifecycle.js` | Vlastnictví požadavků, rušení a odmítání zastaralých odpovědí |
| `core/component-renderer.js` | Vykreslení deklarativního katalogu do DOM |
| `ui/component-builders.js` | Skládání polí, tlačítek, záložek a editorových dialogů |
| `ui/form-requests.js` | Odeslání a navázání formulářových požadavků |
| `ui/ajax-bindings.js` | Delegované AJAX formuláře a odkazy, autosubmit a předání výsledků modulům |
| `ui/form-state.js` | Validace, dirty state a ochrana rozepsaných formulářů |
| `ui/confirm-actions.js` | Potvrzování akcí |
| `ui/list-navigation.js` | Navigace, filtry a obnova seznamů |
| `ui/content-updates.js` | Výměna obsahu se správným dispose/hydrate cyklem |
| `ui/image-uploads.js` | Příprava obrázků pro upload |
| `ui/avatar-rendering.js` | Vykreslení avatarů a jejich fallbacků |
| Modulový `controller.js` | Zpracování dat, obnova DOM, dialogy a životní cyklus modulu |
| `site/views.js` | Samotné skládání DOM sidebaru a rich textu |
| Modulový `index.js` | Vstupní bod, importy a registrace modulu |

Konkrétní dílčí chování pojmenovat konkrétně: `bots/workspace.js`,
`site/sidebar-data.js`, `site/sticky-sidebars.js`, `site/page-navigation.js`,
`search/global-search.js`, `users/avatar-editor.js`, `status/feed-loader.js`,
`status/form-context.js`, `status/form-actions.js` nebo `status/media-preview.js`.
Název `views.js` nepoužívat pro soubor řídící požadavky, polling a změny dat.

Přejmenování souboru samo nemění jméno služby, `TinyCat.*` API, registrace,
DOM atributy ani endpointy. Veřejně vkládané skripty `embed.js` a
`embed-frame.js` si zachovávají své cesty. Historické audity a updater seznamy
odstraněných cest zůstávají záznamem tehdejšího stavu.

Úplný seznam JS souborů, přímých importů a vstupních bodů je v
[generovaném rejstříku](ui-inventory.md).

## Formulářové helpery

Helpery v `ui/component-builders.js`, `field(label, name, value, type, attributes)` a
`select(label, name, value, choices, attributes)` podporují dodatečné HTML
atributy, například `required`, `disabled`, `aria-describedby` a `data-*`.
Dodatečné atributy přepisují výchozí hodnoty; `class` se přidává k základní
třídě `input`, `textarea` nebo `select` a opakované třídy se odstraní.
Vstupní objekt se nemění. Dosavadní volání bez atributů zůstávají platná.

```js
U.select("common.status", "status", "active", choices, {
  required: true,
  "aria-describedby": "status-help",
  "class": "custom-control"
});
```

Validace, přepínání chybných záložek a ochrana neuložených změn zůstávají
ve společných službách. Neimplementovat stejná pravidla znovu v modulu.

## Kontroly

Editory existujících resources načítat přes `data-modal-url` /
`dialog.openResource` a modulový `modal(url, response, id)`. Formulář ani jeho
hodnoty nepřidávat předem do shellu, skrytého DOM nebo `<template>`, ani jej
nevytvářet při hydrataci stránky. Potvrzení a lokální pomocné dialogy používají
společnou factory; nesestavují si vlastní rám ani historii. Settings dodržují
resource kontrakt přes `/api/settings/editor`; jejich původní serverová
renderovací cesta je odstraněná.

Renderer a překlady settings jsou součástí cacheovaného admin JS. Jejich
přesun ze serverového formuláře vyžadoval zkontrolované navýšení admin JS
rozpočtů; veřejné stránky a instalace settings controller nenačítají.

- `php tests/quality/ui-contracts.php` — katalogy, závislosti, vlastnictví a výběr CSS.
- `node tests/frontend/styles.cjs` — parita obsahu a pořadí pravidel.
- `node tests/frontend/infrastructure.cjs` — společné služby včetně atributů polí.
- `php tests/frontend/run.php` — frontendová regrese včetně nativních viewport testů.
- `php tools/asset-report.php --check` — dosažitelnost assetů a rozpočty distribuce.

Kontrola UI kontraktů je také součástí `php tests/run.php`.
