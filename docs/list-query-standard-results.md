# Audit výběru kandidátů napříč výpisy

Historick? z?znam m??en? p?ed vyd?n?m 5.14.0; popsan? stavy a hashe pat?? uveden? etap?.

31. 10. 2026. Navazuje na [tagovou optimalizaci](tag-feed-performance-2026-10-31.md) v odděleném kandidátovi `release/ajax-safe-candidate`. Změny zůstávají necommitnuté. Pravidla pro další práci obsahuje [standard](query-selection-standard.md).

## Rozsah a závěry auditu

Byla provedena statická kontrola feedů, hledání, notifikací, uživatelů, moderace a botí administrace a read-only SQL/EXPLAIN sonda třinácti konkrétních scénářů na izolovaném velkém datasetu. Dvě in-process invokace slouží k lokalizaci SQL práce; jejich časy se nevydávají za HTTP latenci nebo kapacitu aplikace. Request-local cache mohou v tomto typu sondy ovlivnit vedlejší preload dotazy.

| Oblast | Zjištění | Výsledek |
|---|---|---|
| Běžný a textový tag | Velká selekce a řazení před limitem | Předchozí oprava zachovaná; nyní používá sdílenou sondu |
| Home: vzácné/prázdné médium | Chronologický index vynucený i při prázdném výsledku | Opraveno: omezené okno, poté úplná selektivní cesta bez vynucení feed indexu |
| Obsahové hledání | Pro časté slovo 27 345 shod, seskupení a opětovné připojení širokých dat před limitem | Opraveno: úzké kandidáty, kurzor uvnitř zdrojů, omezené větve FULLTEXT, konečný limit před detaily |
| Hledání s hlubokým kurzorem | Původní kurzor až za materializací všech shod | Přesunut do obou větví před drahé seskupení/limit |
| Hledání bez shody | FULLTEXT může rychle vrátit prázdný výsledek | Zachováno a měřeno samostatně |
| Doslovné hledání `10%` | Nový limit větve původně vyvolal horší chronologický plán | Zachycená regrese opravena; žádný limit jednotlivých LIKE větví |
| Hledání pouze v odkazech | Je nutné zachovat více shodných odkazů a přesný MAX excerpt | Seskupení před limitem, testováno i při překrytí s textem příspěvku |
| Autor | Používá autorský index a malý výběr | Bez změny |
| Sledovaní | Na seedovaném účtu malá selekce přes autorské indexy | Bez změny; varianta nad 1 000 sledovaných autorů nebyla datově pokryta |
| Notifikace | Index příjemce/času omezuje výsledky před širokým doplněním | Bez změny |
| Správa uživatelů | Levnější indexovaný count a stránkovaný seznam na tomto datasetu | Bez změny |
| Moderace | Načítá detaily přibližně 3 023 skupin před výběrem 25; agregace i filtry mají význam | Další kandidát, neoptimalizováno v této etapě |
| Žebříčky a komentáře | Žebříčky potřebují globální agregaci, komentáře vracejí úplný strom | Nelze bezpečně přidat LIMIT bez samostatného návrhu a změny kontraktu |

Nejde o tvrzení, že byly změřeny všechny kombinace filtrů nebo produkční distribuce. Největší prokázané případy mimo tagy byly prázdné médium a obsahové hledání. Moderace zůstává konkrétní další úkol: oddělit výběr reprezentativních reportů od detailů, přitom zachovat seskupení, count, prioritu, textový filtr a řazení podle obsahu. Není správné omezit vstupní reporty před jejich agregací.

## Společná implementace

`Status\Feed::recent_ids()` nyní slouží tagům i pozitivním mediálním filtrům home feedu. Okno je fyzicky omezené, má shodný kurzor a případný úplný fallback. Tagová kontrola dostatečného počtu vazeb zůstala zachovaná. Full-page odpovědi se teprve poté doplní přes existující `public_status_items_by_ids()`.

`public_status_cursor_condition()` je jediná definice veřejného čas/ID kurzoru pro feed, odvozená okna a obsahové hledání. Builder adaptér `public_status_apply_cursor()` ji pouze aplikuje. Nedochází ke změně URL nebo pravidel pokračování.

Hledání zůstává jeden SQL dotaz: body a link větev mají stejné podmínky viditelnosti i kurzor. U FULLTEXT stačí N unikátních ID každé větve k nalezení N unikátních ID jejich sjednocení ve stejném pořadí. Více shodných odkazů se seskupí před zdrojovým limitem. Až po společném limitu se připojí tělo příspěvku a autor. U čistě doslovných LIKE výrazů se zdrojový limit nepřidává, protože prokázaně způsobil horší plán. SQLite zachovává své LIKE hledání a také doplňuje široké řádky až po konečném limitu.

Nevznikl obecný router dotazů, nová cache ani databázová migrace. Doménová pravidla zůstávají u vlastníků.

## HTTP měření

Stejná MySQL 8.4.3, PHP 8.4.12 + OPcache, čtyři loopback workery, 40 warm requestů na cestu/běh, pořadí baseline/candidate/candidate/baseline. Baseline je přesná v5.13.0. Každá varianta má 80 warm requestů na cestu; tabulky používají medián dvou round-level percentilů, ne percentile spojených vzorků. Regresní testy neběžely souběžně. Časy jsou lokální instrumentované HTTP odezvy, nikoli browser LCP/INP, Apache kapacita nebo příslib produkčního zrychlení.

### Prázdné médium

| Scénář | HTTP p50 původní → nový | HTTP p95 původní → nový | SQL/request |
|---|---:|---:|---:|
| Home video, žádné shody | 1 054,3 → **61,4 ms** | 1 311,0 → **78,5 ms** | 1 → 2 |

Původní plán procházel všech 99 827 příspěvků. Nový nejprve zkusí nejvýše 1 600 a poté selektivní fallback, který prázdný výsledek ověří přes média. Přibyl jeden SELECT, ale objem práce se výrazně snížil. SQL p50 kleslo z přibližně 1 025 na 30,7 ms. Doplňující invokace s jinou distribucí mohou mít jiný náklad fallbacku.

### Naplněná média a kontrola tagů

Samostatné měření s dočasnou syntetickou mediální fixture kontroluje, zda řešení prázdné stránky nezhoršilo běžný případ. Fixture byla vytvořená výhradně v izolované výkonové DB a odstraněná ve `finally`; obnovený stav je ověřený. Každý následující řádek má 80 warm requestů na variantu; celkem 960 warm odpovědí.

| Výpis | HTTP p50 původní → nový | HTTP p95 původní → nový | SQL/request |
|---|---:|---:|---:|
| Home obrázky | 53,8 → 51,7 ms | 79,6 → 67,0 ms | 3 → 3 |
| Home odkazy | 80,1 → 75,4 ms | 95,5 → 93,4 ms | 3 → 3 |
| Home video | 117,8 → 77,5 ms | 145,4 → 101,4 ms | 3 → 3 |
| Home audio | 112,2 → 82,0 ms | 135,9 → 94,9 ms | 3 → 3 |
| Tag běžný | 163,9 → 61,1 ms | 213,8 → 80,7 ms | 4 → 4 |
| Tag text | 240,5 → 61,5 ms | 274,3 → 75,8 ms | 4 → 4 |

V tomto datasetu se regrese naplněných případů neprojevila. Neznamená to nulovou režii sondy pro libovolnou hustotu nebo rozložení starších médií. Původní tagová čísla v předchozí zprávě jsou jiný běh; neslučují se s touto tabulkou.

### Poslední zdroj hledání

Po opravě doslovné větve proběhlo nové samostatné čtyřkolové porovnání pěti případů (800 warm odpovědí). Každý případ má shodné response hashe mezi baseline a kandidátem, všechny odpovědi byly HTTP 200 a validní JSON.

| Hledání | HTTP p50 původní → nový | HTTP p95 původní → nový |
|---|---:|---:|
| Časté slovo `performance` | 643,5 → **389,1 ms** | 903,1 → **438,6 ms** |
| Totéž, kurzor za 50 000 příspěvky | 702,3 → **352,7 ms** | 833,0 → **425,4 ms** |
| Doslovné `10%`, bez shod | 269,1 → 264,6 ms | 318,5 → 320,2 ms |
| Široká shoda v odkazech `https` | 805,4 → **627,8 ms** | 894,4 → **698,2 ms** |
| Nenalezené FULLTEXT slovo | 31,9 → 34,8 ms | 41,2 → 47,0 ms |

Obsahové hledání častého slova je v tomto běhu přibližně o 40 % rychlejší na p50 a pokračování o 50 %. Doslovné hledání má po opravě podobné časy. Nenalezený FULLTEXT výraz má mírně horší HTTP p95; tento výsledek nelze vynechat. Jeho SQL zůstává přibližně v jednotkách milisekund a nejde o prokázanou výkonnostní paritu všech dotazů. U široké linkové shody zbývá značná práce při nalezení a seskupení samotných shod; změna ji neodstraňuje. Další plošná chronologická sonda není automatické řešení.

Počet SQL u hledání se nezměnil: tři při vrácení postů, jeden u prázdného výsledku. Plán běžného hledání ukazuje doplnění těl a autorů již jen pro výsledných 50 ID oproti přibližně 27 tisícům dříve. FULLTEXT stále musí najít a seřadit své kandidáty, takže nezmizí cena velmi častého výrazu.

Finální source SHA256: `App/Status/Feed.php` = `51b8a3eb83f9b1e57e52df705ad9257763bcb0bf72b685e86294e691476f836c`, `App/Search/Queries.php` = `d6d04ebcf96063daca37a3f633e631bdeeeace17e213071ea3ec0a599c7dc76b`. Feed je totožný ve všech třech navazujících HTTP měřeních; hledací tabulka výše používá poslední zdroj po opravě LIKE, starší předběžné hodnoty se s ní neslučují.

## Ověření a artefakty

- 129 kontrol skutečných MySQL home/tag oken a fallbacků, včetně hlubokých a shodných časových kurzorů, neúplných stránek, médií, limitů a viditelnosti; transakční fixture rollbacknutá.
- 89 kontrol hledání na SQLite a 89 na MySQL: více odkazů, překrytí body/link, přesný excerpt, 80 časově shodných výsledků v malých stránkách, nové vložení mezi stránkami, doslovná interpunkce a neplatné limity/kurzory.
- Po stabilizaci zdroje prošly API HTTP kontrakt (4 125 kontrol), query budgets, neměnný query baseline (30), veřejné exporty (11), hranice modulů (803), hranice tříd (1 128 vazeb), PHPStan level 8 a PHP styl (402 souborů). JS/CSS se v této etapě nemění.

Lokální ignorované artefakty: `storage/performance/list-standard-20261031/` (prázdné video a první FULLTEXT porovnání), `list-standard-media-20261031/` (naplněná média a kontrola tagů), `list-standard-search-final-20261031/` (poslední zdroj hledání včetně doslovného výrazu a odkazů). První hledací experiment s horším LIKE plánem je doložený v `ajax-final/list-standard-audit-final.json`, oprava v `ajax-final/list-standard-audit-literal-fix.json`. Zdrojové hashe jsou součástí artefaktů; starší čísla se nevydávají za měření přesně posledního souboru.

```powershell
php tools/performance/list-query-audit.php --config=storage/performance/large-config.php --output=storage/performance/new-audit.json
php tools/performance/list-standard-benchmark.php --config=storage/performance/large-config.php --output=storage/performance/new-standard-run --baseline=../tinycat-release-5.13.0 --candidate=. --search-only
python tools/performance/tag-filter-summary.py storage/performance/new-standard-run
php tests/mysql-tag-window.php --config=storage/performance/large-config.php
php tests/search.php
$env:TINYCAT_TEST_MYSQL_CONFIG='storage/performance/large-config.php'
php tests/search.php --mysql
```

Audit používá pouze SELECT/EXPLAIN; parametrické hodnoty zůstávají v lokálním ignorovaném artefaktu. MySQL search test vytváří vlastní náhodně pojmenovanou testovací DB a po doběhnutí ji odstraní. Žádná z těchto akcí neupravuje běžící vývojový web.
