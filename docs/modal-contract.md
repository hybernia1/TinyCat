# Jednotný kontrakt modalů

Tento dokument je závazné pravidlo pro všechny modaly v aplikaci.
Podrobnosti vzhledu určuje společné UI, nikoli jednotlivé moduly.

## Druhy a načítání

| Druh | Vstup | Kdy vzniká | Životní cyklus |
| --- | --- | --- | --- |
| Resource editor nebo detail, včetně vytvoření záznamu | `data-modal-open` + `data-modal-url`, případně `dialog.openResource(id, url)` | Po explicitním otevření a autorizovaném GET resource; totéž platí pro obnovení URL/historie | Po zavření se odstraní, další otevření načte aktuální data |
| Lokální potvrzení, CAPTCHA nebo náhled výsledku právě provedené akce | `dialog.confirm()` nebo společná UI factory | Na vyžádání, z již dostupných dat příslušné interakce | Krátkodobý a odstranitelný; bez vlastní bookmark URL |
| Filtry seznamu | `ui.filterPanel` a sdílený popover | Mohou se připravit při hydrataci seznamu | Jsou stavem seznamu, nikoli modalem; nemají `.modal` rám ani dialogovou historii |

Viditelný inline formulář stránky není modal. Pravidla nezakazují serverové
vykreslení běžného obsahu, composeru ani inline formulářů.

## Resource modaly

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
- `tools/modal-contracts.php` kontroluje PHP stránky i klientské moduly:
  odmítá předem vykreslené rámy, formulářové šablony a obcházení společné factory.
  Běží při sestavení assetů i v `tests/quality/ui-contracts.php`.
- Nativní regresní test ověřuje vytvoření na vyžádání, zachování konceptu po
  obnově seznamu, ochranu refreshů, pozdní odpovědi, úspěšné uložení a odstranění DOM.

Při změně modalů spustit `php tests/quality/ui-contracts.php`,
`node tests/frontend/form-validation.cjs` a příslušné HTTP kontrakty.
Změny společného loaderu ověřit také pomocí
`node tests/frontend/dialog-history.cjs` a `php tools/asset-report.php --check`.
