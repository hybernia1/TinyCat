# Audit výkonu core — 24. listopadu 2026

Největší naměřený problém této etapy je opakovaná příprava zdrojových překladů a assetů při neplatném distribučním inventáři. Na `tinycat.test` je aktivní Social, ale kompilované assety a překlady neodpovídají současné identitě instalace. Core je správně odmítá. Platný build v izolované kopii výrazně snížil čas obou zkoumaných stránek. Také s platným buildem zůstávají konkrétní zbytečné operace v core: opakované hashování registru balíčků a druhé sestavení frameworkových metadat.

Původní audit a měření níže proběhly bez změny runtime. Navazující opravy, nové benchmarky a synchronizaci živého webu popisuje [implementační etapa](#navazující-implementace-bez-preflightu). Rozpracované změny limiterů a Socialu byly zachovány. Nálezy rozlišují prokázanou práci od kandidátů vyžadujících další ověření.

## Rozsah a prostředí

- Výchozí commit `2683cce59b87140c7fa53056b7d3f008468a9e08` s existujícími necommitovanými změnami. Export obsahuje aktuální pracovní zdroje; není totožný s čistým commitem.
- PHP 8.4.12, Windows, filesystem cache, existující MySQL Laragonu. Profilovací HTTP workers hlásily zapnutý OPcache.
- Živé čtení přes ověřené HTTPS a existující Apache: `/login`, `/privacy`, `/api`. Bez účtů, cookies, změn nastavení nebo zápisu aplikačních dat.
- Izolovaná databáze `tinycat_perf_4aba60c1386f`: kanonický přípravný nástroj zkopíroval pouze definice 24 tabulek, žádná živá data. Reporty i privátní konfigurace jsou v `.work/performance/core-audit-20261124/`.
- Izolované exporty přebírají živý distribuční descriptor, aktivaci a registr balíčků. Aktivní sestava obsahuje core a Social. Nejde o měření samotného core bez modulů.
- Nejdříve profil pojmenovaných PHP metod v instrumentované kopii, potom samostatná měření bez instrumentace. Instrumentované časy se nepoužívají jako důkaz zrychlení odpovědi.

## Naměřené výsledky

### Skutečný Apache

Pět zahřívacích a 25 uchovaných anonymních GET na každou cestu, postupně, bez dalšího benchmarku. Bez následování přesměrování; odpověď se zahazovala. Komprese byla povolená.

| Cesta | Stav | TTFB p50 / p95 | Medián TTFB po odečtení přípravy spojení | Přenesené tělo |
| --- | --- | --- | --- | --- |
| `/login` | 200 | 147,957 / 194,878 ms | 122,014 ms | 1 704–1 713 B |
| `/privacy` | 200 | 302,258 / 325,935 ms | 276,063 ms | 4 836–4 844 B |
| `/api` | očekávaná 404 | 66,119 / 75,882 ms | 43,567 ms | 137 B |

Čekání po přípravě spojení stále zahrnuje transport a plánování serveru; není čistý čas PHP. `/api` měří chybovou cestu, nikoli minimální úspěšný API endpoint. Zdroj: `live.json` ve výše uvedeném pracovním adresáři.

### Platný build a zdrojový fallback

Kanonický [kompilátor](../../tools/assets/compile-assets.php) vytvořil v izolovaném exportu 900 souborů pro aktuální výběr. Druhá kopie má stejný runtime a build výstupy, ale přejmenované dva vstupní inventáře; tím používá standardní zdrojový fallback. Živé soubory se nekopírovaly zpět ani neměnily.

Pořadí měření bylo build A, fallback A, fallback B, build B. Každé kolo: jeden loopback worker, první požadavek a dvě zahřívací vlny zvlášť, 25 měřených požadavků na cestu. Cache každého běhu je oddělená. Všechny měřené odpovědi byly 200 a zahřáté požadavky vykonaly **0 SQL**.

| Cesta | Fallback A / B, p50 | Platný build A / B, p50 |
| --- | --- | --- |
| `/login` | 169,020 / 144,087 ms | 47,502 / 55,679 ms |
| `/privacy` | 362,371 / 334,796 ms | 63,148 / 63,026 ms |

Rozdíl reprodukuje náklad fallbacku, není důkazem změny produkčního výkonu nebo plné obsahové parity HTML. Mezi variantami se přirozeně liší URL generovaných assetů. Běhy nejsou jednotlivě prokládané páry a zatížení počítače kolísalo; proto se neslibuje konkrétní procento úspory.

Samostatný běh platného buildu se dvěma workers a 30 měřenými požadavky na cestu: `/login` p50/p95 47,969/65,821 ms, `/privacy` 53,988/63,692 ms, všechny odpovědi 200, zahřáté SQL opět 0. Tento malý test ověřuje souběžnou obsluhu aplikace. Neurčuje kapacitu Apache, chování při stovkách klientů, souběh stejné session ani výkon nad velkou databází. K dispozici byly nejvýše tři procesy benchmarku včetně rodiče.

Raw výsledky: `compiled-a.json`, `fallback-a.json`, `fallback-b.json`, `compiled-b.json`, `compiled-concurrent.json` a sousední request traces. První `calls.json` používá původní export bez platných inventářů; `compiled-calls.json` již používá nově sestavený build.

## Nálezy podle priority

### 1. Neplatný build způsobuje významnou režii na každém požadavku

Živá kontrola přes stávající [matchesSelection](../../App/Core/Support/CompiledTranslations.php) odmítla jak inventář assetů, tak překladů. Přítomnost souborů sama o sobě tedy neprokazuje použití distribuční cesty. Toto není chyba invalidace: použití katalogu jiné sestavy by mohlo vracet nesprávné komponenty nebo zdroje.

V nekompilovaném profilu `/privacy` vyvolalo [language_packages](../../App/Core/Support/Languages.php) 30 volání [TranslationSources::packages](../../App/Core/Support/TranslationSources.php). Každé prochází a plně validuje 12 jazykových JSON včetně detekce duplicitních klíčů. Celkem proběhlo 371 volání `document`: 360 kvůli seznamu jazyků a 11 dalších při čtení katalogu. V posledním instrumentovaném vzorku stálo samotné `document` přibližně 130 ms vlastního času. `/login` mělo 4 průchody seznamu jazyků a celkem 70 parsovaných dokumentů.

Vedle toho zdrojový `/privacy` provedl 561 čtení assetů a 576 jejich validací; platný build měl 15 validací a žádnou potřebu sestavovat klientské balíčky ze zdrojů. To jsou počty volání, nikoli unikátních souborů nebo fyzických diskových operací.

**Doporučení:** nejprve zajistit platný build odpovídající aktivaci a registru balíčků. U vývojového fallbacku načíst seznam dostupných jazyků jednou za požadavek, se zachováním přímého čerstvého čtení pro build/validátory. Pak samostatně prověřit snapshot opakovaně čtených assetů. Neskrývat nesoulad inventáře ani neobcházet kontrolu vlastnictví. Tento audit do živé instalace nový build nenasadil.

### 2. Hash registru balíčků se znovu počítá při každém použití katalogů

[matchesSelection](../../App/Core/Support/CompiledTranslations.php) při každém volání používá `is_file` a `hash_file` nad registrem balíčků. Živý registr má 31 993 B. U platného buildu profil zachytil 15 kontrol na `/login` a 49 na `/privacy`. Poslední vzorek `/privacy` měl přibližně 6,0 ms vlastního času této metody; nejde o neinstrumentovanou úsporu, kterou lze automaticky odečíst od TTFB.

**Doporučení:** jeden explicitní snapshot identity běžného HTTP požadavku sdílený čtečkami, s invalidací při změně výběru, balíčku a lifecycle operaci. Nepoužít globální persistentní cache ani samotný `mtime` jako důkaz nezměněného obsahu. Build a updater mohou změnit registry v témže procesu, což musí zůstat viditelné. Nejdříve doplnit regresi změny registru v témže procesu a pak porovnat neinstrumentované varianty.

### 3. Nezávislý modul vyvolává druhé sestavení frameworku

[PackageRegistry::extend](../../App/Core/Modules/PackageRegistry.php) kvůli kontrole konfliktů tabulek sestavuje `Loader::fromSelection($root, [])->schemas(true)`. Později se framework sestaví znovu pro skutečný runtime výběr. Profil obou stránek potvrzuje dvě volání `Framework::definitions`, dvě `Loader::fromSelection` a 26 `Definition::parse`.

Poslední instrumentovaný `/privacy` vzorek měl přibližně 7,2 ms vlastního času v parsování definic; celé `PackageRegistry::extend` přibližně 7,4 ms včetně potomků. Tyto časy se nesčítají. Platí pro instalaci s nezávislým balíčkem, nikoli automaticky pro každou sestavu TinyCatu.

**Doporučení:** sdílet validované frameworkové definice v rámci jednoho explicitního startu, případně získat frameworkovou část kontrolovaného schématu bez druhého katalogu. Zachovat detekci konfliktů tabulek, streamů, cest a změn metadat. Nezavádět druhý ručně udržovaný seznam core tabulek.

### 4. Zahřátá nastavení stále používají výhradní koordinační zámek

[Store::settings](../../App/Core/Settings/Store.php) při prvním použití v požadavku vstupuje do [Cache::synchronized](../../App/Core/Support/Cache.php), a to i pro zásah cache. Další čtení téhož požadavku již používají request cache. Profil potvrzuje jeden zámek na stránku, nikoli jeden zámek na každou položku nastavení. V posledních instrumentovaných vzorcích byl vlastní čas zámku přibližně 0,27–0,31 ms.

Zámek zajišťuje koordinaci fill/invalidation; prosté odstranění nebo návrat k nechráněnému fill může po souběžném zápisu zveřejnit stará nastavení. **Doporučení:** zvlášť změřit contention při pomalém čtení a invalidaci. Případné rozdělení čtecí/zápisové cesty musí mít regresi souběhu a transakční izolace. Současný krátký test neprokazuje, že tento zámek tvoří praktický limit kapacity.

### 5. Globální HTML rám načítá notifikační náhled přihlášeného uživatele

[DocumentData::prepare](../../App/Core/Ui/DocumentData.php) pro přihlášeného uživatele načítá `Inbox::state` a šest položek náhledu. [Inbox](../../App/Core/Notifications/Inbox.php) pro stav používá samostatný počet nepřečtených a nejvyšší ID; položky mají další dotaz a vlastnickou hydrataci. Jde o základní práci přidávanou konzumentům společného rámu. Náhled je součást současného UI, není automaticky zbytečný.

**Doporučení:** na izolovaných přihlášených fixtures s velkou schránkou změřit počet dotazů, plán a cenu hydratace; teprve poté porovnat slučování agregací nebo načítání náhledu až po otevření. Tento audit neměřil účet návštěvníka ani velkou schránku, proto zde neuvádí latenci nebo údaj o N+1.

### 6. Session a malé opakované operace

[Authentication::auth](../../App/Core/Identity/Authentication.php) otevírá session a [Session](../../App/Core/Http/Session.php) ji standardně drží do konce požadavku. Existuje `withoutLock` pro pomalé operace. Souběh požadavků stejného návštěvníka má proto jiné vlastnosti než benchmark bez cookies. Globální předčasné zavření není bezpečná drobná optimalizace: CSRF, flash a autentizace mohou stále zapisovat.

[Config::get](../../App/Core/Support/Config.php) vytváří nový immutable snapshot při každém čtení. Profil `/privacy` zaznamenal 103 snapshotů, ale jen přibližně 0,21 ms vlastního času. Směrování tam mělo 148 `routeMatch` s přibližně 0,12 ms vlastního času. [Resources](../../App/Core/Modules/Resources.php) opakuje normalizaci kořenové cesty a prohledávání definic pro partialy. Tyto položky jsou kandidáty na jednoduché lokální zjednodušení, nikoli hlavní příčiny naměřených stovek milisekund. Neopravňují k nové obecné cache vrstvě nebo odstranění validací.

## Produkční PageSpeed a velikost stránky

Poskytnutý [mobilní report](https://pagespeed.web.dev/analysis/https-apokalypsa-net/vxh1xef91vyjlcbq6y3fjv?form_factor=mobile) uvádí Performance 100, FCP/LCP/Speed Index 0,9 s, TBT 0 ms a CLS 0. Jde o laboratorní načtení jedné stránky se simulovaným mobilním připojením; report nemá terénní uživatelská data. Nepotvrzuje maximální serverovou latenci při souběžném zatížení.

Report také ukazuje odhady 36 KiB nevyužitého JavaScriptu, 13 KiB u obrázků a 130 ms u blokujících zdrojů. Jsou to podněty k prozkoumání konkrétního výběru assetů a obrázků, nikoli změřené úspory ani důvod zavádět framework nebo rozbíjet současný klientský runtime. Tento audit frontendové bundle ani obrázky neměnil.

## Ověření a hranice závěru

- `php tests/run.php --only=tests/performance/hot-read-query-budgets.php` prošel: feed 3/4, status 6/7, JSON feed 3/4, JSON status 7/7, tag 4/5, author 6/17, search 3/8. Zápis znamená naměřený počet / rozpočet. Report: `.work/tests/runs/20261124-022151-f1dcf77e46/report.json`. Nejde o měření plánů dotazů nad velkou produkční databází.
- Kanonický kompilátor při sestavení izolovaného buildu provedl vlastní kontroly modalů, UI a překladů. Nevytvářel se alternativní build nebo test runner.
- Anonymní HTTP odpovědi, platný build, zdrojový fallback a malý souběh dvou workers byly změřeny. SQL profiler otevírá PDO před dispatch; nula SQL neznamená nulový náklad připojení v běžném Apache requestu.
- Runtime se neměnil; nebyl spuštěn úplný preflight, frontendová sada, migrace, updater, rollback ani vizuální regrese. Nevznikla změna vyžadující jejich závěrečný průchod.
- Neproběhl velkoobjemový import, EXPLAIN ANALYZE nad reprezentativními velkými daty, přihlášený/admin benchmark, souběh se stejnou session, Memcached ani dlouhodobý zátěžový test Apache. Výsledek nelze vydávat za úplný audit všech core služeb nebo záruku subsekundové odpovědi při libovolné zátěži.
- Data, konfigurace, moduly a build živého webu zůstaly beze změny. Izolovaná DB a exporty jsou zachovány pro reprodukci; privátní konfiguraci nepublikovat.

Implementační pořadí podle evidence: platná distribuční cesta; omezení opakovaného načítání jazyků ve fallbacku; snapshot identity katalogů s korektní invalidací; odstranění druhé přípravy frameworkových metadat; teprve potom souběh nastavení, session a velké notifikační schránky. Po každé změně měřit odpovídající cestu a zachovat kontroly vlastnictví, transakcí a invalidace.

## Navazující implementace bez preflightu

Na následné zadání byly implementovány první čtyři položky uvedeného pořadí, včetně omezení opakovaného načítání assetů ve zdrojovém fallbacku. Zámky nastavení, session a notifikační dotazy se neměnily. Úplný preflight nebyl podle výslovného zadání spuštěn.

### Změny a hranice cache

- [Languages](../../App/Core/Support/Languages.php) načítá zdrojové názvy jazyků jednou pro aktuální revizi výběru. Přímé volání `TranslationSources::packages` zůstává čerstvé, takže buildové validátory stále zachytí změnu souboru v témže procesu. Přepnutí a opuštění výběru invaliduje runtime seznam.
- [ClientAssets](../../App/Core/Support/ClientAssets.php) při zdrojovém skládání skriptů a stylů používá existující `AssetGraph::withSnapshot`. Opakované čtení uvnitř daného skládání sdílí zdroje/importy. Po skončení se původní stav obnoví, změna výběru cache vyprázdní. Kompilovaná větev tento snapshot nevytváří.
- [CompiledTranslations](../../App/Core/Support/CompiledTranslations.php) sdílí hash registru balíčků jen uvnitř explicitního čtecího snapshotu. [HttpKernel](../../App/Core/Runtime/HttpKernel.php) jej používá pro GET/HEAD. Zápisové požadavky a přímé buildové čtení zůstávají mimo snapshot; kontrola výběru a jednotlivých manifestů se stále provádí. Změna revize výběru obnoví i identitu balíčků. Vnořené snapshoty a výjimky obnovují předchozí stav.
- [Framework](../../App/Core/Runtime/Framework.php) vlastní také dosavadní tři pomocné definice zdrojů přesunuté z loaderu. Všech 12 core definic sdílí jen uvnitř jednoho [Loader::load](../../App/Core/Modules/Loader.php); druhé získání schémat kvůli nezávislému balíčku je nemusí znovu parsovat. Samostatný další load a build znovu ověřuje metadata i cesty. Aktivní sestava má celkem 13 definic včetně Socialu.

Nezavedla se persistentní cache metadat, nový obecný cache systém ani seznam domén v core. SQL, schéma, doménová oprávnění a publikované migrace se neměnily.

### Nové měření bez instrumentace

Baseline a kandidát používají stejný izolovaný dataset a odpovídající stejné inventáře. Dva nezávislé workers obsluhují požadavky postupně; pořadí variant se obrací pro každý pár. Osm zahřívacích a 40 uchovaných párů na cestu a režim, OPcache zapnutý, bez souběžných testů nebo kompilace. Všechny odpovědi jsou 200 a zahřáté SQL je 0. Jde o náklad aplikace v profilovacích workers, nikoli Apache/TLS nebo kapacitu produkce.

| Režim / cesta | Baseline p50 | Kandidát p50 | Medián úspory uvnitř párů |
| --- | --- | --- | --- |
| Platný build, `/login` | 51,97 ms | 46,25 ms | 4,43 ms |
| Platný build, `/privacy` | 59,08 ms | 52,51 ms | 8,71 ms |
| Zdrojový fallback, `/login` | 143,23 ms | 106,40 ms | 35,63 ms |
| Zdrojový fallback, `/privacy` | 310,14 ms | 104,18 ms | 206,19 ms |

Medián párových rozdílů není rozdíl dvou mediánů. Raw výsledky jsou v `.work/performance/core-fixes-20261124/compiled-paired.json`, `fallback-paired.json` a sousedních traces. Nejde o příslib stejné úspory na jiném hostingu.

Samostatný instrumentovaný profil potvrzuje na `/privacy` pokles `Definition::parse` z 26 na 14, načtení seznamu jazyků ve fallbacku z 30 na 1, parsování dokumentů z 371 na 23 a validací assetů z 576 na 147. Volání `AssetGraph::read` kleslo z 561 na 354; část z nich nyní vrací již načtené zdroje. Tyto počty nejsou počty fyzických diskových operací. U platného buildu zůstává 49 kontrol manifestů, ale hash registru je v běžném čtecím scope sdílený. Attribution evidence: `calls-compiled.json.requests.jsonl`, `calls-fallback.json.requests.jsonl`, `call-summary.json`. Instrumentované časy nejsou další důkaz velikosti latency úspory.

### Synchronizace a skutečný Apache

Build vznikl kanonickým kompilátorem v kopii skutečné živé instalace s její aktivací a registrem balíčků. Do kopie a následně na web se přeneslo jen šest upravených core PHP souborů; všech šest živých původních souborů předtím odpovídalo HEAD repozitáře. Rozpracovaný limiter a Social se na web nekopírovaly. Před synchronizací se znovu ověřily vstupy buildu a aktivace proti živým souborům.

Synchronizovalo se šest PHP souborů a 900 kanonických build výstupů, vstupní inventáře až po jejich závislostech. Přepisované soubory jsou zálohované v `.work/performance/core-fixes-20261124/live-backup/`; `sync.json` obsahuje původní a nové hashe. Staré hashed bundle se nemažou. Konfigurace, deployment stav, registr balíčků, databáze, storage a média se nepřepisovaly.

Čerstvé měření před/po přes stejné ověřené HTTPS: pět zahřívacích a 25 uchovaných anonymních GET na cestu, bez cookies a souběžných testů.

| Cesta | TTFB p50 před → po | TTFB p95 před → po |
| --- | --- | --- |
| `/login` | 142,00 → 95,77 ms | 162,62 → 115,15 ms |
| `/privacy` | 303,27 → 95,13 ms | 309,49 → 122,17 ms |
| kontrolní `/api` 404 | 66,89 → 72,98 ms | 81,03 → 91,44 ms |

Kontrolní 404 měla po odečtení přípravy spojení medián 45,51 → 43,91 ms. Jediná bloková série nedokazuje regresi ani zrychlení této cesty; transport a zatížení kolísají. Velké zlepšení HTML stránek zahrnuje opravu neplatného buildu i úpravy core; přínos samotných úprav odděluje prokládaný benchmark výše. Velikost komprimovaného HTML se mírně změnila kvůli URL build výstupů; snížení velikosti stránky se netvrdí.

Oba živé inventáře nyní procházejí kontrolou identity (`live-after-identity.json`). Nativní prohlížeč ověřil `/login` a `/privacy` při 390/1280 px: bez vodorovného overflow, načtený klient `TinyCat`, správné formulářové prvky a titulky. Login načítá čtyři JS a jeden CSS z `/assets/build/`, jejich dodatečné HTTP kontroly vrátily 200; samostatný curl potvrdil také immutable cache hlavičky. Jde o DOM/resource smoke, nikoli pixelovou vizuální regresi, přihlášení nebo živou administraci. Raw HTTP: `live-before.json`, `live-after.json` v pracovním adresáři implementace.

### Cílené ověření

PHP lint, deterministický styl a PHPStan prošly. Byly spuštěny existující cílené runnery, žádný úplný preflight ani univerzální nový profil. Každý z následujících reportů má `source_changes_during_run: []`:

- [První regrese](../../.work/tests/runs/20261124-024005-d343ccc6e8/report.json): katalog, resources, registrace, hranice, failure recovery, jazyky a kompilované katalogy/assety.
- [Invalidace a čtecí cesty](../../.work/tests/runs/20261124-024211-d34f216819/report.json): nový izolovaný worker ověřuje runtime seznam jazyků proti čerstvým zdrojům; source/compiled parity, snapshoty, veřejné routy a query budgets prošly.
- [Finální metadata a lifecycle](../../.work/tests/runs/20261124-024426-a498ad3b6e/report.json): katalog, resources, lazy registrace, podepsané instalace/aktivace/aktualizace/rollback/obnova a build invalidace prošly. Jednotlivý lifecycle běh trval 434,915 s včetně potomků; jejich časy se nepřičítají.
- [Finální source asset cesta](../../.work/tests/runs/20261124-025336-883f1e5cf5/report.json): UI kontrakty, komponenty, veřejný smoke včetně pěti autentizovaných admin renderů a asset graph prošly po doplnění source snapshotu.
- [Klientské scénáře](../../.work/tests/runs/20261124-025404-a266361ba1/report.json): `client-assets.cjs`, `translation.cjs`, `locale-urls.cjs`, `language-menu.cjs` prošly, včetně odloženého editoru ve zdrojových a kompilovaných/minifikovaných bundles.

Přeskočen byl symlink escape fixture v katalogu, protože systém neumožnil vytvoření symlinku. Nevybrané skupiny nejsou vydávány za provedené. Zbývá úplný preflight před uzavřením celé etapy, velká data, dlouhodobá zátěž, Memcached a souběh stejné session. Poslední tři původní kandidáty (nastavení, notifikace, session/drobnosti) tato implementace neoznačuje za vyřešené.
