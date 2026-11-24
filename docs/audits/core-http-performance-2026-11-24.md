# Core: účty, inventář modulů a technické HTTP — 24. listopadu 2026

Následné databázové a aplikační opravy popisuje [implementační etapa](core-list-performance-fixes-2026-11-24.md); návrhy inventáře a technického HTTP zůstávají otevřené.

Třetí etapa navazuje na [bootstrap a assety](core-performance-2026-11-24.md) a [schránku a souběh](core-data-performance-2026-11-24.md). Nové hlavní nálezy jsou agregace a nepodporovaná řazení správy účtů, cena inventáře i neaktivních modulů a plný start aplikace před deterministickým avatarem. Runtime ani živé schéma se v této etapě neměnily; preflight zůstává odložený.

## Metoda a evidence

Nová izolovaná databáze `tinycat_perf_49c8d5a51403` vznikla kanonickým `prepare-performance.php` nad existujícím MySQL Laragonu. Převzala definice 24 tabulek, žádná živá data. Má 100 000 syntetických účtů se dvěma spravovanými rolemi, třemi stavy, syntetickým bio a nekorelovanými daty vytvoření/aktualizace. Žádný z nich se nepoužil k přihlášení na živý web.

SQL odpovídá současnému výběru a řazení účtů. Každý dotaz a varianta má dvě zahřívací a 15 uchovaných provedení; čas zahrnuje execute a fetchAll. Plány `EXPLAIN ANALYZE` jsou samostatné, instrumentované vysvětlení plánu se nezapočítává do latency tabulky. Všechny varianty vrátily stejné kontrolní součty výsledků. Měřeno postupně, bez souběžných testů nebo jiného benchmarku.

Raw důkazy jsou v `.work/performance/core-http-audit-20261124/`: `users-plans.json`, `mechanisms.json`, `avatar-http.json`, `avatar-calls.json` a sousední traces. Reprodukční skripty jsou `.work/core-audit3-*.php` a `.work/core-audit3-avatar.py`. Privátní konfiguraci z podadresáře `database` nepublikovat.

## 1. Správa účtů zpracovává celou populaci před krátkým seznamem

[Actions::tc_admin_users_api_payload](../../App/Core/Identity/Actions.php) připravuje stránku a samostatně celkové statistiky. [ReadModel](../../App/Core/Identity/ReadModel.php) při výchozím nefiltrovaném výběru používá pro počet a statistiky tentýž rozsah spravovaných rolí. Statistický dotaz už přitom vrací i `total`.

| Operace na 100 000 účtech | P50 původního SQL |
| --- | --- |
| počet pro stránkování | 102,168 ms |
| celkové statistiky stavů včetně počtu | 184,667 ms |
| první stránka 25 účtů podle ID | 0,340 ms |
| stránka s offsetem 99 975 | 319,723 ms |
| první stránka podle `updated_at ASC, id DESC` | 414,623 ms |
| první stránka podle `status ASC, id DESC` | 530,614 ms |
| role pro samostatný dashboard widget | 56,003 ms |

Počet a statistiky skutečně procházejí 100 000 položek covering indexu `users_role_status_index`; malý počet dotazů zde neznamená malou práci. První stránka podle ID naopak přečte 25 řádků přes obrácený PRIMARY scan. Dvě globální agregace nejsou nutné kvůli načtení samotných 25 položek.

**Kandidát:** pro skutečně nefiltrovaný výběr využít `stats.total` i pro stránkování nebo jinak sdílet stejnou agregaci. U filtrovaného seznamu nelze ztotožnit počet vybraných řádků s globálními statistikami. Případné oddělené/lazy načtení či krátká cache statistik musí respektovat změny účtů a význam UI; nejsou to automaticky ekvivalentní změny chování.

Jde o SQL operace konkrétní administrativní funkce, nikoli režii přidávanou každému veřejnému požadavku. V této etapě nebylo změřeno celé autentizované HTTP. Současný problém profileru `--user` z předchozího auditu zůstává otevřený.

## 2. Některá řazení a hluboký offset pracují se všemi účty

Plán řazení podle aktualizace původně přečetl všech 100 000 řádků a třídil, než vrátil 25 výsledků. Experimentální index `(updated_at ASC, id DESC)` změnil plán na indexový průchod s limitem:

| Dotaz | P50 před | P50 s experimentálním indexem |
| --- | --- | --- |
| `updated_at ASC, id DESC`, první stránka | 414,623 ms | 0,580 ms |
| `status ASC, id DESC`, první stránka | 530,614 ms | 533,420 ms |
| hluboký offset podle ID | 319,723 ms | 311,602 ms |

Shoda výsledků byla zachována. Jediný index ovšem neřeší jiné pořadí nebo hluboké číslované stránkování. Offsetový plán stále zpracoval 100 000 řádků, aby prvních 99 975 zahodil.

**Kandidát:** ověřit skutečné kombinace povolených řazení, směrů a filtrů z [AdminSorting](../../App/Core/Support/AdminSorting.php), potom zvolit cílené indexy a případně užší výběr dat. Přidaný smíšený index byl testován jen pro uvedený směr; opačný směr s pevným tie-breakerem ID se musí ověřit zvlášť. Nevytvářet automaticky index na každý sloupec. Číslované admin stránky nelze bez změny UI smlouvy prostě nahradit kurzorem.

Tato etapa neměřila write amplification, velikost indexu ani opakování v opačném pořadí. Velký rozdíl podporuje konkrétní změna plánu, ale není to finální validační sada migrace. Experimentální index byl odstraněn, všech 100 000 účtů zůstalo v izolované DB. Případná implementace vyžaduje novou migraci a odpovídající aktuální fresh schema, zachování publikovaných souborů a ověření podporovaných databází.

## 3. Neaktivní moduly mají stále cenu inventáře

[Loader::availableSelection](../../App/Core/Modules/Loader.php) i pro prázdný výběr volá celý [SourceModules::inventory](../../App/Core/Modules/SourceModules.php). Ten načítá manifesty všech dostupných adresářů a přes `Definition` ověřuje také zdrojové cesty jejich deklarovaných resources. Pro každý požadavek tak existuje práce závislá na dostupných, nikoli jen aktivních modulech.

Experiment použil malé nezávislé fixture se třemi resources (assets, překlady, šablony), bez providerů a bez aktivace. Každá má také PHP soubor, jehož spuštění by vyhodilo chybu. Patnáct samostatných čerstvých CLI procesů na variantu měřilo pouze operaci `availableSelection(root, [])`, ne start PHP procesu:

| Dostupné fixture moduly | Aktivní moduly | P50 operace |
| --- | --- | --- |
| 0 | 0 | 2,060 ms |
| 10 | 0 | 8,523 ms |
| 100 | 0 | 49,597 ms |

Fixture PHP se ani jednou nenačetlo; metadata-only bezpečnost tedy funguje. Čísla nejsou Apache TTFB ani benchmark s webovým OPcache. Čerstvé PHP procesy mají oddělené request/stat/realpath cache, souborový systém může být zahřátý.

**Kandidát:** oddělit levné zjištění dostupnosti/identity od opakované plné validace nevybraných resources nebo využít explicitně invalidovaný datový snapshot inventáře. Musí zůstat zachována společná runtime/build interpretace, detekce změn adresářů, duplicitních ID, chybějících zdrojů a pozastavení závislých modulů. PHP neaktivních modulů se nesmí spouštět. U současné malé sestavy je dopad mnohem menší než u stovky fixture; není to důkaz současné 50ms režie TinyCatu.

## 4. Generovaný avatar prochází plným startem aplikace

[GeneratedAvatar::respond](../../App/Core/Identity/GeneratedAvatar.php) sestavuje deterministické SVG z ID a povolených parametrů, bez dotazu do DB. Jeho route ale přichází až za [Application::start](../../App/Core/Runtime/Application.php).

Čtecí HTTP měření na existujícím `https://tinycat.test/avatar/generated/v1/1.svg`, ověřené HTTPS, pět zahřívacích a 25 uchovaných curl požadavků pro každou variantu:

| Odpověď | TTFB p50 / p95 | Medián po odečtení přípravy spojení | Tělo po kompresi |
| --- | --- | --- | --- |
| běžný GET 200 | 92,742 / 123,343 ms | 61,607 ms | 618 B |
| podmíněný GET 304 | 81,185 / 95,299 ms | 55,117 ms | 0 B |

Cache hlavička je správně `public, max-age=31536000, immutable`, `Set-Cookie` nebyla přítomná. Curl nemá browserovou cache a požadavky skutečně posílal originu. Běžný prohlížeč proto tato čísla neplatí při každém zobrazení již uloženého avataru; význam mají hlavně nové/odlišné URL a skutečné revalidace. Počet HTTP požadavků také závisí na počtu různých autorů, ne přímo na počtu karet obsahu.

Samostatný instrumentovaný profil v izolovaném exportu se zapnutým OPcache potvrdil 0 SQL a žádné otevření session ani čtení zdrojových překladů. Poslední vzorek: samotné `svg()` 0,039 ms inclusive, start aplikace 22,685 ms inclusive; proces zahrnul 171 PHP souborů včetně profilovacích helperů. Tyto instrumentované časy se nesčítají ani neodečítají od živého TTFB.

**Kandidát:** posoudit úzké technické doručení takové čisté reprezentace s menší potřebou modulové registrace. Zachovat společné bezpečnostní hlavičky, validaci parametrů, GET/HEAD, ETag/304 a pravidla instalace/údržby. Cena zde není v kreslení SVG; diskové cachování samotného SVG proto bez řešení startu nemá doloženou velkou úsporu a svévolné ukládání libovolných ID by potřebovalo retenční politiku. Audit takovou rychlou cestu neimplementoval.

## 5. Routing roste s počtem rout, běžný případ je zatím levný

[Router](../../App/Core/Http/Router.php) prochází routy postupně. U dynamických znovu skládá regex; `publicWebEnabled()` vyhodnocuje před porovnáním cesty pro každou public route. `registerModule` navíc drží snapshot existujících polí pro atomický rollback registrace a ověřuje nové klíče. Tyto mechanismy mají účel a při růstu skládání stojí práci.

Syntetický warm in-process benchmark, moduly po 20 routách, 30 % dynamických a 50 % public, hledání poslední routy, tři zahřívací a 40 uchovaných dispatchů. Callback dostupnosti byl pouze levné `true`, bez DB nebo nastavení:

| Routy | Registrace | P50 dispatch poslední routy | Public callbacků na dispatch |
| --- | --- | --- | --- |
| 200 | 0,750 ms | 0,228 ms | 100 |
| 1 000 | 5,150 ms | 1,104 ms | 500 |
| 5 000 | 81,480 ms | 8,693 ms | 2 500 |

Je to stresový model, nikoli velikost či TTFB současné instalace. Kandidáty jsou předpočítaný regex v záznamu routy a jediné vyhodnocení dostupnosti pro jeden dispatch. Případná optimalizace musí zachovat pořadí a překryvy rout, 405/Allow, parametry, disabled public web a rollback registrace. Přímá mapa statických cest by bez těchto podmínek mohla změnit prioritu proti dříve registrované dynamické routě.

Ve stejné inspekci [Api](../../App/Core/Http/Api.php) neautorizuje všechny zaregistrované endpointy: identitu a oprávnění ověřuje až zvolená route. To není kandidát na odstranění serverové autorizace. U běžné malé sestavy má routing nižší prioritu než velké databázové průchody.

## 6. Opakované `Request::input` slučuje pole při každém čtení

[Request::payload](../../App/Core/Http/Request.php) dekóduje tělo jen jednou, ale `input()` opakuje `array_replace_recursive(query, payload)`. Kontrolní experiment porovnal 25 čtení `field0` proti jedinému převzetí vstupů a 25 použitím stejného `Values::get`. JSON byl před měřením dekódovaný, 40 uchovaných warm vzorků:

| Klíče / velikost JSON | Opakované `input`, p50 | Jedno sloučení u volajícího, p50 |
| --- | --- | --- |
| 10 / 441 B | 0,0192 ms | 0,0048 ms |
| 100 / 4 491 B | 0,0746 ms | 0,0076 ms |
| 1 000 / 45 891 B | 0,6533 ms | 0,0318 ms |
| 5 000 / 233 891 B | 4,5726 ms | 0,1625 ms |

Běžný malý formulář má zde malý náklad. U větších nastavení nebo hromadných resources lze zvážit jediné převzetí vstupu v handleru. Globální memoizace musí nejprve vyřešit smlouvu změn nativních query/post polí v témže procesu; tento mikrobenchmark sám neopravňuje ke změně jejich čerstvosti. Nejde o měření celého JSON parseru ani celého HTTP požadavku.

## 7. Readiness COUNT je dražší, ale běžná hotová instalace jej obchází

[Identity\Readiness](../../App/Core/Identity/Readiness.php) pro otázku existence alespoň jednoho účtu s heslem počítá všechny odpovídající účty. Na 100 000 záznamech měl dotaz p50 66,858 ms; ekvivalentní existenční sonda s `LIMIT 1` 0,251 ms. Zachovat se musí i chování prázdné tabulky a chybného připojení.

Tato cena ale není automaticky na každém produkčním požadavku: core [HTTP policy](../../App/Core/Ui/Platform.php) při `install.complete` přeskakuje databázovou readiness a [Installer::isComplete](../../App/Core/Installation/Installer.php) používá krátké vyhodnocení stejného příznaku. Původní anonymní profily neměly readiness SQL. Optimalizace existence dává smysl pro skutečné volající při setup/fallbacku, má však nižší dopad než oprava běžného admin seznamu.

Také [Providers::schemas](../../App/Core/Modules/Providers.php) ve standardní ne-managed kolekci vynechává managed schémata vestavěných vlastníků. Kód `ModuleSchemas::ready` proto nelze pouze podle jeho existence označit za dvě SQL kontroly na každém built-in API. Pro jiné kontrakty modulů může být potřeba explicitní readiness; neodstraňovat ji nebo necachovat neomezeně bez zachování fail-closed chování.

## Ověření a další pořadí

Prošly cílené existující testy dostupnosti modulů včetně izolace neaktivního PHP a řazení administrace včetně pagination/defaultů/invalid inputs: [report](../../.work/tests/runs/20261124-032851-9953449531/report.json), `source_changes_during_run: []`, bez přeskoků. Šlo o kontrolu současných kontraktů, nikoli test nové migrace nebo nové HTTP rychlé cesty.

Dokumentace byla ověřena diffem, lokálními odkazy a raw výsledky. Neproběhl preflight, frontendová sada, podporovaný upgrade nového indexu ani autentizovaný HTTP load test. Předchozí opravy i rozpracované změny zůstaly zachované.

Priorita pro další implementaci: nejprve stále nevyřešené notifikační indexy/polling a oprava auth profileru z předchozí etapy; potom sdílení agregace admin účtů a doložená náročná řazení; následně inventář větších modulových sestav a technické doručení avatarů. Routing, slučování malých formulářů a setup readiness jsou podstatně menší nebo užší kandidáti. Měření neprokazuje, že je třeba přidat další obecnou cache vrstvu.
