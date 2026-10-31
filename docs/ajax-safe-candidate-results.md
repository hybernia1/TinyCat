# Kandidát s vybranými AJAX opravami

Historick? z?znam m??en? p?ed vyd?n?m 5.14.0; popsan? stavy a hashe pat?? uveden? etap?.

Navazující serverová optimalizace běžných a textových tagových výpisů je vyhodnocená [samostatně](tag-feed-performance-2026-10-31.md). Níže uvedená čísla popisují klientský výběr před touto serverovou úpravou; její lokální HTTP výsledky se s browser fixture neslučují.

Další etapa zobecnila ověřená pravidla pro mediální home feed a hledání: [standard](query-selection-standard.md), [audit, změny a poslední měření](list-query-standard-results.md). Tagová zpráva zachycuje předchozí zdroj; aktuální source hashe jsou uvedené v navazujícím měření.

[Experiment s kritickými assety](critical-assets-experiment.md) porovnává inline CSS/JS se studenou a teplou cache. Zůstává samostatným prototypem; produkční doručování assetů se nezměnilo.

Datum: 31. 10. 2026. Větev `release/ajax-safe-candidate`, worktree `.`, základ `v5.13.0` (`e73ffb9`). Změny nejsou commitnuté, publikované ani přenesené do hlavního pracovního adresáře. Původní kompletní refaktor zůstává zachovaný v `tinycat` na `main`.

## Výběr

Základ tvoří uložené zdroje po etapě 3. Převzaty jsou CSS tokeny a společné styly, úklid potvrzeného dead code, sdílené menu a styly avatarů, vlastnictví AJAX čtení, ochrana proti starým výsledkům/chybám, zámky mutací a šest definic administračních seznamů. Doplněny jsou oprava AbortError při čtení JSON, omezení cache hledání (32 položek, TTL 60 s, oddělený kontext), botí GET/metadata opravy a lifecycle feedu, editoru a překladače.

Zachované jsou malé dřívější úpravy veřejné navigace pro hledání a společné vlastnictví administračních požadavků v core navigation. Kandidát tedy není pouhé kopírování izolovaných souborů bez závislostí.

Odložené zůstávají nový společný polling, nové řízení sidebaru a jeho layout lifecycle, nová transakce veřejné navigace, centrální zápis historie a odstranění globálního hydrate fallbacku. Zůstává kompatibilní fallback etapy 3 pro dosud nemigrované result handlery. Odložení těchto částí neurčuje příčinu dřívějšího zpomalení; žádný jednotlivý modul nebyl jako viník prokázán.

Přesný seznam overlay souborů a změn: [manifest](ajax-safe-candidate-manifest.json).

## Ověření

Prošla kompletní frontendová sada včetně nativních modalů/historie (70 kontrol), validace (57), administračních viewportů (252), dashboardu (7 viewportů), doménových rendererů (5810), klientských scope (162) a CSS parity (807 pravidel). Samostatně prošly nové testy AJAX závodů/zámků, cache hledání, resetu feedu a obnovy seznamů; jsou přidané do runneru. Prošel graf assetů (6), minifikátor (22), PHP styl (397 souborů) a rozpočty všech osmi scope. Rozpočty kandidáta byly odvozené od v5.13.0 a zvýšené jen tam, kde skutečná velikost překročila původní limit.

Veřejný scope: minifikovaný JS 357 404 → 363 903 B; gzip 75 856 → 77 380 B, tedy +1 524 B (+2,01 %). Kompletní refaktor měl 78 679 B gzip, kandidát je o 1 299 B menší. CSS gzip 13 005 → 12 999 B; čtyři skripty, jeden stylesheet, žádný nedosažitelný JS/CSS soubor.

## Srovnání s v5.13.0

Použit stejný lokální Chrome runner jako u celého refaktoru: skutečné zdroje obou verzí, společné komponenty/CSS/data, JSON server s 20 ms latencí. Pro každý viewport tři páry, střídání pořadí; 50 navigací a 50 modalových cyklů na relaci, 20 rychlých filtrů, 40 hledání a prune feedu. Viewporty se měří postupně, bez souběžné testovací sady. Jde o krátké opakování tohoto kandidáta, nikoli nový desetiminutový polling test. Dřívější desetiminutové výsledky celého refaktoru nelze připsat tomuto výběru.

| Viewport | Medián původní → kandidát | p95 původní → kandidát | Chyby původní → kandidát |
|---|---:|---:|---:|
| Desktop 1280×900 | 52,20 → 50,70 ms | 62,10 → 60,20 ms | 12 → 0 |
| Mobil 390×844 | 48,50 → 51,80 ms | 59,80 → 60,00 ms | 8 → 0 |

Mobilní medián je o 6,8 % vyšší, p95 o 0,3 %. Dřívější zvýšení mobilního p95 o 19,5 % se v tomto běhu výběru neopakovalo. Jde o jiný běh, ne o důkaz, že konkrétní odložený modul způsoboval regresi. Zrychlení není prokázané. Chyby původní verze jsou falešná hlášení neplatného JSON při rušení; nová verze je správně rozlišuje jako AbortError.

Mobil skončil u všech relací na 770 živých DOM elementech; listenery 102 → 100. Feed vždy po prune obsahoval 96 karet s odchylkou kotvy 0 px. Tyto krátké cykly nejsou důkazem absence dlouhodobého paměťového úniku.

Desktopový medián je o 2,9 % nižší a p95 o 3,1 % nižší; jde o malé rozdíly bez statistického průkazu obecného zrychlení. Desktop skončil na 789 živých elementech u obou verzí, listenery 104 → 102. Všech šest relací kandidáta mělo nula chyb a zachovaný scroll po prune. Tento výběr je vhodný k dalšímu release ověření; širší změny pollingu/historie/sidebaru zůstávají samostatnou prací.

Zdroj kandidáta v obou variantách měření má SHA256 `e82dce2ccc367ecf90a8b926c1d2a74c1b0ad7bcdb101bed97b3bb83588d438d`. Surové výsledky a exportované moduly jsou lokálně pod `storage/performance/ajax-final`, souhrn vytvoří `python tools/performance/ajax-summary.py`. Runner vyžaduje předem exportované baseline a společné fixture soubory; nejde o produkční DB/síť ani LCP/INP test.

```powershell
php tests/frontend/run.php
php tools/asset-report.php --check
node tools/performance/ajax-long-run.cjs --minutes=0.02 --rounds=3 --label=safe --mobile
node tools/performance/ajax-long-run.cjs --minutes=0.02 --rounds=3 --label=safe
python tools/performance/ajax-summary.py
```
