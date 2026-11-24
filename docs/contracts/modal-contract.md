# Jednotný kontrakt modalů

Tento dokument je závazné pravidlo pro všechny modaly v aplikaci.
Podrobnosti vzhledu určuje společné UI, nikoli jednotlivé moduly.

Samostatné vstupní URL editoru mohou po autorizaci přesměrovat na kanonickou
stránku a resource fragment, například `/admin/articles#modal=articles/7/management`.
Počáteční otevření, reload a Back/Forward pak zpracuje společná historie.
Vlastník nepřidává skrytý marker, který při hydrataci sám načítá a otevírá editor.

## Druhy a načítání

| Druh | Vstup | Kdy vzniká | Životní cyklus |
| --- | --- | --- | --- |
| Resource editor nebo detail, včetně vytvoření záznamu | `data-modal-open` + `data-modal-url`, případně `dialog.openResource(id, url)` | Po explicitním otevření a autorizovaném GET resource; totéž platí pro obnovení URL/historie | Po zavření se odstraní, další otevření načte aktuální data |
| Lokální potvrzení, CAPTCHA nebo náhled výsledku právě provedené akce | `dialog.confirm()` nebo společná UI factory | Na vyžádání, z již dostupných dat příslušné interakce | Krátkodobý a odstranitelný; bez vlastní bookmark URL |
| Filtry seznamu | `ui.filterPanel` a sdílený popover | Mohou se připravit při hydrataci seznamu | Jsou stavem seznamu, nikoli modalem; nemají `.modal` rám ani dialogovou historii |

Viditelný inline formulář stránky není modal. Pravidla nezakazují serverové
vykreslení běžného obsahu, composeru ani inline formulářů.

## Resource modaly

### Významový typ a rozměr

`data-modal-kind` určuje účel a chování, `data-modal-size` pouze rozměrovou variantu.
Nejsou to další implementace dialogu; všechny používají `dialog.create` a `ui/modal`.

| Typ | Účel | Společná pravidla |
| --- | --- | --- |
| `content` | Čtecí detail, historie, náhled nebo informace; může mít explicitní akce | Výchozí typ, společný fokus, Escape, backdrop a odstranění při zavření |
| `editor` | Formulář včetně přihlášení, CAPTCHA nebo editace účtu | Form factory označí koncept, primární akci umístí do patičky; mobilní režim je fullscreen |
| `confirm` | Krátké lokální potvrzení právě vyvolané akce | Společná confirm factory, žádná vlastní resource historie, potvrzení či zrušení |

Rozměry jsou `default`, `compact`, `wide`, `reading` a `workspace`; potvrzení
používá kombinaci `kind=confirm`, `size=confirm` a vlastní existující token šířky.
`workspace` slouží pracovnímu editoru nebo rozsáhlému detailu, `reading` delšímu
čtení. `data-modal-mobile=fullscreen` může explicitně rozšířit čtecí detail na
mobilu. Neodvozovat významový typ z velikosti ani přidávat lokální modal rám.

Pro celý modal platí jediná hranice: mobile do 899 px, desktop od 900 px.
Záložky, fullscreen, potvrzovací tlačítka i JS zámek stránky používají stejnou
hranici; hover ani typ ukazatele layout nepřepíná. Změna šířky za otevřeného
dialogu synchronizuje viewport i zámek stránky, také u vnořených dialogů.
Factory odmítá neznámý typ, velikost či mobilní režim před vytvořením DOM.

1. Stránka obsahuje pouze spouštěč. Nevkládat dopředu modal, jeho formulář,
   hodnoty ani `<template>` s formulářem. Nevytvářet jej v `init`, `hydrate`
   ani při obnovení seznamu.
2. Každý spouštěč `data-modal-open` má `data-modal-url` směřující na API stejného
   originu. `AdminViews::modalAction` vyžaduje API URL. Klikací spouštěče
   neotevírají lokálně předpřipravené DOM podle samotného ID.
3. GET resource vrací pouze JSON data, oprávnění a potřebná omezení.
   Popisky, rozložení a komponenty vlastní klientská prezentační vrstva.
   Server kontroluje přístup při každém čtení i zápisu. Hesla se nevracejí.
4. Modul poskytuje `modal(url, response, id)`. Vrátí právě jeden dialog vytvořený
   společnou factory, nebo `null`, pokud URL neobsluhuje. Načítání, vložení,
   hydrataci a otevření řídí `ui/dialogs.js`.
5. Nový záznam má také resource pro získání aktuálních možností a omezení.
   Nezískává je z permanentního skrytého formuláře nebo z kopie seznamu.
6. Resource ID odpovídá kanonické cestě (`modal-users-creation` pro
   `/api/users/creation`). Aktivní záložku řídí společná historie přes `tab`.
   Modul nepřidává vlastní `pushState`, drafty do storage ani obnovu záložek.

Příklady:

| Povrch | GET resource | Zápis |
| --- | --- | --- |
| Vytvoření uživatele | `/api/users/creation` | `POST /api/users` |
| Vytvoření botího účtu | `/api/bots/accounts/creation` | `POST /api/bots/accounts` |
| Nastavení | `/api/settings/editor` | `PATCH /api/settings` |
| Připojení a plánování úloh | `/api/scheduler/configuration` | Obnova tokenu: `POST /api/scheduler/tokens` |
| Poznámky k aktualizaci | `/api/updates/release-notes` | Pouze čtení z již dostupné cache |

## Koncepty a zavírání

- Uživatelské zavření, Escape, backdrop a Back procházejí společným guardem.
  Odmítnutí zavření zachová DOM, hodnoty a aktuální pozici historie.
- Obnova seznamu smí měnit seznam a jeho filtry. Nesmí zavřít, odstranit ani
  znovu sestavit samostatně otevřený editor, ať obsahuje nový nebo existující záznam.
- Vynucený refresh resource respektuje neuložené změny. Pokud uživatel začne
  upravovat obsah nebo změní záložku během čtení, pozdní odpověď se zahodí.
- Úspěšný zápis používá společné označení uloženého formuláře a případně
  `data-modal-close-on-success`. Dílčí uložení nesmí zahodit jiné formuláře.
- `dialog.close()` je nízkoúrovňové dokončení již schváleného zavření nebo
  úspěšné operace. Není náhradou `requestClose()` při uživatelském odchodu.
- Zavření ruší související čtení a disposuje dialog. Pozdní odpověď jej nesmí
  znovu otevřít. Moduly neposkytují vlastní zásobník, focus trap ani zamykání stránky.

## Automatické vynucení

- `dialog.create()` odmítá tvorbu dialogů během inicializace a hydratace.
  Lifecycle vrací fázi zpět také při výjimce.
- Resource loader odmítá chybějící URL, cizí origin i ne-API adresy.
- `tools/quality/modal-contracts.php` kontroluje PHP stránky i klientské moduly:
  odmítá předem vykreslené rámy, formulářové šablony a obcházení společné factory.
  Běží při sestavení assetů i v `tests/quality/ui-contracts.php`.
- Nativní regresní test ověřuje vytvoření na vyžádání, zachování konceptu po
  obnově seznamu, ochranu refreshů, pozdní odpovědi, úspěšné uložení a odstranění DOM.

Při změně modalů spustit `php tests/quality/ui-contracts.php`,
`node tests/frontend/form-validation.cjs` a příslušné HTTP kontrakty.
Změny společného loaderu ověřit také pomocí
`node tests/frontend/dialog-history.cjs` a `php tools/assets/asset-report.php --check`.

## Sectioned resource editors

Account management and Settings use the same `sectionedDialog` factory. Independently
saved sections own separate forms. The shared footer submits the active section;
validation, successful-save state and canonical values apply only to that form.
Other section drafts remain unchanged. Sections remain available without an extra
resource request on every tab switch.

The common resource loader adds Refresh to editor footers by default. Owners may
pass `refresh: false` through the shared dialog factory for forms such as login
and registration where reloading the resource is not useful. The loader then
omits Refresh and `dialog.refresh(modal)` does not reload that dialog.
`dialog.refresh(modal)`
and `openResource(id, url, {refresh: true})` use the same guarded loader as explicit
resource triggers. Refresh preserves the selected tab, opener and history entry.
It asks before discarding any draft and does not run during a pending save. Editing,
submitting, switching tabs or closing during the GET invalidates the pending replacement.
Read failures retain the current editor. There is no owner-specific refresh engine.

Settings PATCH accepts only submitted keys, validates before persistence and commits
the batch atomically. Cache invalidation follows commit. Its response contains the same
canonical, password-safe `sections` resource as GET; the client renders only the saved
section through the original component definitions. This also resets write-only secrets
and image controls without rebuilding other forms.

Changed section forms mark their shared desktop tab and mobile option with an
asterisk. The marker uses the existing form snapshot comparison; saving a section
or restoring its original values clears its marker without changing other drafts.
