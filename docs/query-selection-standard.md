# Standard výběru stránkovaných dat

Platí pro nové a upravované výpisy. Výchozí pravidlo je **nejprve vybrat omezený počet kandidátů ve správném pořadí, potom načíst jejich detaily**. Nejde o jeden vynucený SQL plán pro všechny domény. Změnu plánu přijímáme podle skutečných dat, správnosti a měření.

## Výběr a stránkování

1. Selekce obsahuje jen klíče a sloupce nezbytné pro filtr, pořadí, viditelnost a kurzor. Těla příspěvků, profily a další zobrazovací data se připojují po konečném limitu, pokud nejsou nezbytnou součástí filtru či řazení.
2. Filtr a viditelnost patří před limit. Řazení má jednoznačný poslední klíč, např. `published_at DESC, id DESC`. Kurzor odpovídá přesně stejnému pořadí a aplikuje se před drahou materializací, nikoli až po sestavení všech výsledků.
3. Různé zdroje se sjednocují bez duplicit před výslednou stránkou. Pro `top N` sjednocení ve stejném striktním pořadí stačí `top N` unikátních položek každého zdroje. Jeho pořadí i filtr musí být shodné. Duplicitní vazby jednoho zdroje se seskupí před jeho limitem; doprovodná data, např. `MAX` odpovídajícího link excerptu, se musí zachovat přesně.
4. LIMIT nesmí zkrátit globální počet, agregaci, ranking, relevance score ani reprezentativní záznam skupiny. Tyto operace potřebují samostatné odůvodnění. Například „vezmi 25 reportů a potom spočítej všechny reporty příspěvku“ by změnilo význam dat.
5. Existující index pro konkrétního vlastníka (autor, příjemce notifikací) má přednost před novým univerzálním oknem, pokud skutečný plán už čte malý počet řádků.

Matematická správnost předčasného limitu sama nestačí k jeho nasazení: `ORDER BY … LIMIT` může u doslovného `LIKE` přimět MySQL procházet celý chronologický index namísto levnějšího sekvenčního výběru. TinyCat proto omezuje jednotlivé větve obsahového hledání pouze při použití FULLTEXT. U doslovných výrazů ponechává původní selekci; konečný limit úzkých ID před připojením detailů platí v obou případech.

## Kdy použít chronologickou sondu

Pouze pro výpis řazený podle stáří, kde hustý filtr přes vztahové tabulky způsobuje zbytečné dohledávání a řazení mnoha kandidátů. Sonda čte omezené chronologické okno, aplikuje všechny potřebné filtry a vrací ID. Plná stránka z tohoto okna je správný globální výsledek. Neúplná stránka **není důkaz konce výpisu**: v takovém případě následuje původní úplná selektivní cesta. Částečná sonda se se zahájením fallbacku zahodí; nekompletní nebo duplicitní výsledky se nevracejí.

Omezení musí platit pro práci, ne jen pro počet vrácených řádků. `LIMIT 50` nad filtrem při vynuceném průchodu všemi 100 tisíci příspěvky tuto podmínku nesplňuje. Materializované okno a jeho join pořadí se kontrolují v `EXPLAIN ANALYZE`. Plošný `FORCE INDEX` na chronologii není standard pro vzácný filtr.

Sonda musí mít omezenou velikost a zdokumentovanou cenu neúspěchu. Levná kontrola nutné podmínky může práci přeskočit (např. tag má méně vazeb než velikost stránky). Počet vazeb není počet viditelných příspěvků; kontrola nesmí nahradit filtr aktivního autora nebo publikace.

V TinyCat mechanismus vlastní `Status\Feed::recent_ids()`: tagy používají okno `max(256, limit × 16)`, mediální home feed `max(256, limit × 32)`, limit je nejvýše 100. Fallback pozitivních home filtrů nevynucuje chronologický index. Tyto velikosti jsou omezený kompromis ověřovaný na datech, nikoli záruka optimálního plánu pro každou distribuci.

Pro feedy, odvozená okna a obsahové hledání existuje jedna definice kurzoru `Feed::public_status_cursor_condition()` s aliasem `c`. `public_status_apply_cursor()` ji aplikuje do query builderu. Nevytváříme další globální správce dotazů ani oddělený cursor parser pro každou větev.

## Podmínky přijetí změny

- Zachovat přesná ID, pořadí, metadata, excerpt, viditelnost a pokračovací URL. Ověřit stejné timestampy, více vazeb, překrytí zdrojů, nové záznamy mezi stránkami, hluboký a neplatný kurzor.
- Ověřit častý, vzácný i prázdný výsledek; skryté a budoucí záznamy; limity mimo rozsah; plnou i neúplnou stránku. SQLite a skutečná MySQL cesta se ověřují zvlášť.
- Před/po uložit plán pro realistické parametry a distribuci dat. Rozlišit počet přečtených kandidátů od počtu výsledků, dohledání detailů, třídění a materializaci.
- HTTP měřit na stejných datech a nastavení, střídavě baseline/candidate/candidate/baseline, bez souběžné sady testů. Reportovat p50, p95, SQL čas a počet dotazů, všechny odpovědi a jejich normalizované hashe. In-process sonda je diagnostika, nikoli náhrada HTTP výsledku.
- Zveřejnit cenu fallbacku a zhoršení jiných scénářů. Nepřidávat index, cache ani denormalizaci bez dalšího důkazu a odpovídající invalidace či write testů.

Příklady a meze implementace: [tagové výpisy](tag-feed-performance-2026-10-31.md), [navazující audit a měření](list-query-standard-results.md). Regresní scénáře: `tests/mysql-tag-window.php`, `tests/search.php` (SQLite a `--mysql`), `tests/archive-filters.php` a query budgets.
