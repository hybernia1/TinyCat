# Pravidla UI komponent a stylů

Pro všechny modaly je závazný [jednotný kontrakt modalů](modal-contract.md),
včetně vytváření záznamů, lokálních pomocných dialogů a vymezení filtrů.
Je vynucovaný také při buildu a společným lifecycle/factory API.

Pro interaktivní ukotvené panely platí [jednotný kontrakt popoverů](popover-contract.md).
Notifikace, filtry i kontextové akce používají společný controller a povrch.

Aktuální [rejstřík](../reference/ui-inventory.md) se obnovuje příkazem
`python tools/assets/ui-inventory.py`. Obsahuje definice, závislosti, PHP šablony,
UI služby, CSS třídy i manifesty a trasy jednotlivých stylů.

## Jeden zdroj struktury

Zápis katalogů, editorové schéma a hranice mezi handlerem, resource a komponentou
popisuje [reference komponent](../reference/component-catalog.md). Build odmítá
neznámé instrukce uzlů, neplatné kombinace a chybné operandy s přesnou cestou
ke zdroji. Jedna komponenta nevyžaduje jeden soubor.

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

`tools/quality/ui-contracts.php` se spouští při každém buildu assetů. Odmítne opakovaný
JSON klíč včetně vnořených objektů, opakovaný název komponenty mezi katalogy,
přesnou kopii definice pod jiným názvem a chybějící statický odkaz `component`
nebo `use`. U shodné struktury použít existující komponentu nebo wrapper,
který ji skládá s vlastními vstupy. Rekurzivní struktury jako strom komentářů
jsou přípustné; jejich ukončení závisí na vstupních datech.

## Registrovaná prezentační metadata

Pojmenovaný `ComponentLookup` v `Contributions.componentLookups` dodává lazy
mapu z identifikátorů na prezentační pole. Definice komponenty použije
`catalog` a `field` spolu s `lookup` nebo `options`; společný loader tyto
odkazy rozvine do běžných `cases` před PHP vykreslením i doručením klientovi.
Překladové uzly z katalogu tím vstupují také do klientských překladových závislostí.
Katalog vzniká jen z aktuálně vybraných registrací. Neznámé historické ID
používá deklarovaný `default`.

Plánovač odvozuje ikonu, název a plán ze `ScheduledTask.admin`; volitelný
`cadence` je krátký popis plánu a `optionLabels` vlastní překladové klíče
parametrů. V core komponentách nesmí být seznam konkrétních modulových úloh
ani jejich parametrů. Přidání nebo odebrání modulu mění registrace, nikoli
šablonu plánovače. Sestavení metadat nesmí spouštět SQL, runner, normalizaci,
health callback ani hydrataci detailu úlohy. Doménové API nadále vrací pouze
kanonická data bez ikon, komponent a překladových identifikátorů.

## Vlastnictví CSS

| Vlastník | Obsah |
| --- | --- |
| `assets/ui/styles` | Obecné prvky, layout utility, pole, tlačítka, karty, tabulky, stránkování, modaly, menu, tooltipy a toasty |
| `assets/modules/status/styles` | Feed, composer, statusy, komentáře a metadata autora |
| `assets/modules/users/styles` | Profil, vlastní účet a editace avataru |
| `assets/modules/site/styles` | Veřejná navigace, sidebar a layout stránky |
| `assets/modules/search/styles` | Globální vyhledávání a jeho nabídka |
| `assets/modules/notifications/styles` | Stránka notifikací a notifikační menu |

Zbývající moduly vlastní své dosavadní styly stejným způsobem.
Každý styl deklaruje manifest ve svém adresáři. Kontrola
`php tests/quality/ui-contracts.php` tuto hranici vynucuje.

Sdílený `result-list.css` používá instalace, diagnostika i aktualizace;
proto zůstává v UI. Metadata autora statusu potřebuje veřejný feed i náhled
v moderaci. Editor účtu a avatar jsou dosažitelné z veřejné části i administrace;
jejich styly se nesmějí omezit pouze podle adresy veřejného profilu.

Instalační průvodce používá společné `result-list`, `result-item`, `badge`,
`check-card` a layout utility. Aktuální krok označuje `aria-current="step"`;
volby jazyků zachovávají nativní radio tlačítka. Instalace nemá vlastní CSS.

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
[generovaném rejstříku](../reference/ui-inventory.md).

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
- `php tools/assets/asset-report.php --check` — dosažitelnost assetů a rozpočty distribuce.

Kontrola UI kontraktů je také součástí `php tests/run.php`.

## UI scale

The shared root token `--ui-scale` multiplies the browser's base font size.
Its global default is `1`. The reusable `data-ui-density="compact"` document
profile selects a 0.875 density scale and compact control spacing in core CSS.
Administration chooses that profile through document metadata, without its own
metric overrides. Root text combines the browser base, global `--ui-scale` and
`--ui-density-scale`, so one global scale change also enlarges compact pages.
A larger interface uses one override of the global scale, without rewriting component
sizes or adding viewport variants. No separate settings control is implied.

Use rem for component dimensions, spacing and modal size tokens, and em for
text-relative icons. Shared controls derive their height/padding from control
tokens. Keep border hairlines and viewport breakpoints independent of this scale;
viewport caps still constrain dialogs and scroll containers. Mobile input text
has a 16px floor and touch section selectors retain their minimum target size,
while both grow at larger root sizes. Do not use CSS zoom or a visual transform
as a substitute for normal layout reflow.

Native regressions exercise the same scale token at 125%, 150% and 200% in list
fixtures and enlarged editor dialogs, including draft preservation, local table
overflow and reachable save actions.

## Shared theme surfaces

Core defines each light/dark colour pair once with `light-dark()`. Explicit
`data-theme` and the existing system preference rule select `color-scheme`;
layouts do not duplicate palettes. Scoped dark surfaces use the same tokens.
`--surface` is the main content surface, `--surface-panel` distinguishes navigation
and auxiliary panels, and the muted/soft surface tokens express subdued/active
states. Layout owners select these semantic tokens instead of copying colours.
Theme and density primitives are available to every project using core CSS.

## Form help

Optional explanatory hints use the shared `ui/help` component. `ui/field`,
`ui/select` and settings fields accept their owner's `help` text and place the
info control beside the caption. Direct helpers take `text`, optional `label`
and optional `help_id`. Keep hint translations and constraints with their owner.
Do not hide or transform arbitrary `.help` nodes: that typography class is also
used by live validation/status text and operational metadata.

Help reuses the shared tooltip service. Hover/focus, click/touch and Enter/Space
open or dismiss it; Escape dismisses the hint before the surrounding modal.
The span button preserves valid label content and prevents label activation,
checkbox toggling and form submission. Hidden plain-text descriptions retain
existing IDs, are associated with their controls during hydration and survive
validation feedback. Content is escaped and displayed as text, never HTML.
Long descriptions scroll inside the viewport-constrained tooltip. Empty hints
render nothing. No per-field popup is created during initialization.

Place each info control beside the specific caption, task title or cadence it
explains. Do not mechanically replace an introductory paragraph with an isolated
icon in its former row. Remove redundant introductory prose entirely when the
page already explains itself. Helpers inside disclosure summaries must preserve
the disclosure state on click and keyboard activation.

Keep errors, live statuses, maintenance/staleness warnings and actual task
parameters visible. Introductory prose in the isolated embed/share document is
page content, not a core form hint; it does not load the application UI runtime.

## Typed inputs and validation

`ui/field` and settings fields delegate native inputs to `ui/input`. Its shared
semantic type map uses the existing sprite: search, password, email, URL,
telephone, date/month/week and time/datetime-local. Text and number controls,
checkboxes, radios and file pickers do not receive decorative icons. Keep native
input types, labels, names, attributes and browser picker behavior intact.
Search controls reuse `.input-icon`, including the public suggestion control;
do not add per-page icon offsets or duplicate semantic type maps in controllers.
The shared icon size/gap, padding, focus width and error underline use core tokens
and follow the regular/compact density profile and global UI scale.

Validation uses `aria-invalid="true"` on the native control or the existing
`data-validation-for` editor proxy. Core renders a danger underline and danger
focus ring; inline error text and `aria-describedby` remain visible/associated.
Clearing validation removes only the current error and restores normal styling.
Do not use unconditional `:invalid` styling on untouched required fields.

## Settings presentation

Settings use the same ComponentLookup-backed catalog as other shared editors.
Owners declare label/help/placeholder translation expressions in their existing
SettingsSection fields; Sections resolves them for server consumers and retains
them in client component definitions. Nested fields keep their owner and derive
stable presentation paths from their parent key. Date examples are presentation
lookups. The controller prepares canonical values, constraints and native control
attributes, without reading page-level presentation JSON or duplicating labels.

Admin HTML contains only the settings modal trigger. Its authorized resource
still carries values, options and constraints, with secrets blanked and no
component definitions or translation identifiers. Rendering and translating the
form starts after the existing resource loader opens the editor. No separate
presentation endpoint, inline catalog or manually registered JavaScript fields.

## Flash messages and transient toasts

Use success, danger, warning and info tones. A completed operation with findings
uses warning, not a false success claim. Inline validation describes individual
fields; a toast must not replace those messages. Shared toasts use plain text,
accessible status/alert roles, dismiss controls and viewport-aware positioning.

AJAX actions that stay on the page use the common response message/meta.type
path. Actions that return a full-page redirect must queue the localized message
with Session::flash under success/error/warning/info before returning. The next
page emits the shared flash template, and the toast service reads its content
fragment once. Merely returning a message or success code with a redirect does
not preserve a toast across navigation.
Persistent operation reports, such as updater recovery output, remain page
content; do not confuse them with transient flash feedback.

Modals and toasts use one width boundary: mobile below 900px, desktop from 900px.
Pointer/hover capabilities do not select an overlay layout. Toast width, inset
and action size are core tokens; padding and gaps reuse the control density
profile. Mobile dismiss controls retain the shared touch minimum. Visual viewport
measurements constrain available space through the token-based inset rather than
hardcoded margins. Toasts keep focus in the current task, ignore blank messages,
and treat content as text. Unknown tones fall back to info; error aliases danger.

Input icon positioning and padding apply only to direct children of `.input-icon`.
Its descendants can include an entire search popover; empty-state/action icons
inside that surface must retain their own shared component layout. Search-all
is a standard ghost action link with a search icon, not an identity/avatar row.
Full search pages show one shared empty state if all collections are empty and
omit cards for empty collections when matches exist elsewhere.

## Code presentation and copying

Core normalizes code typography with the shared font-mono token, and pre/code
blocks use the shared code-block surface and control spacing. Inline copy actions
use control-height-inline; block actions use control-height-compact. Both follow
the global scale/density, with a minimum inline action target.

The shared code-copy lifecycle service decorates nonempty readonly code after
rendering, including lazy dialogs and refreshed lists. It places one ordinary
icon button beside the code, never inside its text. Repeated hydration reuses the
control and replacement code is retargeted; editable code and code inside links,
buttons or disclosure summaries are left untouched. No domain-specific copy
implementation or parallel server button renderer is needed.

Copy uses the current textContent unchanged, preserving literal markup, Unicode
and whitespace. Clipboard API is preferred; the local selection fallback stays
inside the owning modal, restores focus/selection and removes its temporary
field. A detached source cannot trigger a late fallback. Copy never submits or
marks a form clean. Success/failure uses the existing localized toast service;
a denied/failed copy must not announce success.

The full search page uses the existing public header as its only query input.
Its catalog contributes content, not a second container/search form: one page
heading, collection headings and shared result-list/identity rows. Core Discovery
passes the normalized query to the common page context. Pagination stays with
its owning collection and no optional module layout is required.

Shared alerts are borderless. Info/success/warning/danger use their semantic text
and soft-background tokens; do not add per-page outline variants.


## Public workspaces and search presentation

`Core/Ui/Page::render` accepts optional `navigation` entries (`label`, `icon`,
`url`, `active`). The shared `ui/public-workspace` wraps the page content before
it reaches the selected theme or the core document. It is not a second route
registry: owners supply links to their existing public routes. Desktop uses a
left navigation column; below 900px the same links form a horizontally scrollable
source selector. Width uses `--public-navigation-width`; spacing and controls
reuse core tokens. Links retain native navigation, keyboard focus and
`aria-current="page"`. The workspace does not add another search form.

`Core/Discovery` derives search navigation from active `SearchSource` entries.
A source may supply an icon and a lazy `render(items): string` callback for HTML
presentation. Registration and JSON search never invoke this renderer. Only the
HTML page passes the already selected, bounded items to their owner, wraps the
returned markup as trusted presentation, and places it in the shared group.
Sources without a renderer retain shared identity rows. Query, visibility,
authorization, stable cursors and canonical URLs remain owned by the source.
No domain IDs or source list belong in the shared workspace or core search host.

Social batches visible selected IDs through its feed hydration and reuses
`status/card`. Blog search and public listing share `blog/article-summary`.
Optional owner assets must cover `/search/all` when that presentation needs them.
Empty results use one `ui/empty-state`; the header remains the only query input.


## Responsive layout boundary

All viewport-dependent UI CSS and client navigation use mobile below 900px
(max-width 899px) and desktop from 900px. The former 600/760px intermediate
layouts are retired across core and Social. The existing sm:grid-2 and md:grid-2
utility names share the desktop rule; they do not introduce separate breakpoints.
User preferences such as reduced motion and colour scheme are independent of
viewport layouts. Touch event handling may differ, but pointer type does not
select a third layout. Confirm button desktop sizing lives with desktop modal
styles; no confirm-mobile stylesheet remains.


### Status decoration

Severity variants of badges, inline alerts and toasts derive their decorative
symbol from shared CSS `::before` masks. Their HTML carries variant classes and
readable text; do not append SVG severity icons or icon wrappers. Info uses
`info`, success uses `check-circle`, warning uses `alert` and danger uses
`x-circle`. General state badges use the information variant when no severity applies. Icon-only indicators and
actual content/action icons keep the shared SVG component where appropriate.

The generated mask section in core UI tokens comes from the canonical sprite
through `php tools/assets/status-icons.php --write`. The UI contract checks its
freshness; do not maintain separate SVG paths. The production stylesheet contains
ordinary CSS and inline SVG masks, with no runtime generation or image fetch.
Masks use current colour and shared size/spacing tokens. Alert masks stay
vertically centred within the whole notice, including multiline text.
Readable text and the existing live-region roles carry accessible meaning;
pseudo-elements have empty content and convey no extra announcement.
