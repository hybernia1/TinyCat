# Opravy čtení účtů a schránky — 24. listopadu 2026

Implementace doložených nálezů z [datového auditu](core-data-performance-2026-11-24.md) a [účtového auditu](core-http-performance-2026-11-24.md). Jde o nevydané změny zdrojů. **Nové schéma ani runtime této etapy se na sdílený tinycat.test nenasazovaly.** Nasazení patří do společného updateru se zámkem, zálohou a journalem. Plný preflight zůstává na výslovné zadání odložený.

## Implementace

- [Notifikace](../../App/Core/Notifications/Schema.php) přecházejí novou migrací a fresh baseline na verzi 3. Indexy `(user_id, created_at DESC, id DESC)` a `(user_id, id)` podporují náhled, stránkování a nejvyšší ID příjemce. Publikované verze 1/2, jejich checksumy i původní fresh soubor zůstaly nezměněné.
- [Účtové schéma](../../App/Core/Identity/Schema.php) má samostatnou migraci verze 3 a fresh baseline. Čtyři smíšené indexy pro oba směry `updated_at` a `status`, vždy s `id DESC`, zachovávají stávající řazení. Obě migrace mohou pokračovat po částečném indexovém DDL. Existující unikátní a jiné indexy se nemažou.
- [Správa účtů](../../App/Core/Identity/Actions.php) používá pro skutečně nefiltrovaný výběr `stats.total` také pro stránkování. Filtrované seznamy dál počítají vlastní přesný rozsah. Nevzniká persistentní cache statistik ani odhad počtu.
- [Readiness](../../App/Core/Identity/Readiness.php) používá pro existenci účtu s heslem omezenou sondu místo celkového COUNT, se zachováním prázdného/chybného stavu.
- [API](../../App/Core/Notifications/Api.php) přidává autorizované vlastní `/api/notifications/state` bez položek a `/api/notifications/preview` s nejvýše šesti kanonickými položkami. Dosavadní stránkované API a mutace zůstávají kompatibilní. Počty jsou stále přesné.
- [Polling](../../App/Core/Notifications/assets/polling.js) pro nezměněný zavřený panel načítá jen stav. Preview získá při změně, otevření společného popoveru a pro otevřený panel. Zachovává `mutationRevision`, zákaz překrývání, skrytí dokumentu a zotavení po síťové chybě. Timer, listener i observer uklízí při dispose/odpojení tlačítka.
- [Renderer](../../App/Core/Notifications/assets/controller.js) nevymění seznam obsahující fokus; po jeho opuštění příští preview obsah dožene. Rám, otevírání, fokus a pozici dál vlastní společný popover controller.
- [Manifest](../../App/Core/Notifications/assets/module.json) doručuje polling jen veřejnému rámci. Access stránka bez hlavičky jej nestahuje. Limit assetů nebyl zvýšen, nevznikla další runtime knihovna.
- [Stránka schránky](../../App/Core/Notifications/Pages.php) předává připravený stav a prvních šest hydratovaných položek [dokumentu](../../App/Core/Ui/DocumentData.php), takže se neopakuje COUNT ani dotaz/hydratace náhledu. Access layout bez hlavičky zbytečně nezískává nejvyšší ID.
- [Profiler](../../tools/performance/lib/ProfileIdentity.php) vytváří kanonickou session a resetuje request cache autentizace, takže dispatch identitu opravdu znovu ověří. Odmítá neplatné, záporné, nečíselné a neinteraktivní aktory. Přípravný SQL dotaz a čas ukládá odděleně jako `authentication_setup`; celkové HTTP trvání tuto režii zahrnuje. Session soubory jsou izolované pro každý běh.

Produkce nepotřebuje Composer ani Node. API vrací společnou datovou obálku bez komponentových definic/HTML. Autorizace a CSRF se neobcházejí.

## Autorizované HTTP měření

Nové kopie auditních dat: `tinycat_perf_342e9b43c5c0` (5 účtů, 310 100 notifikací) a `tinycat_perf_1a44013fa37b` (100 000 účtů). Originální auditní databáze zůstaly zachované. V účtové kopii byl aktivován syntetický administrátor ID 2; převzatý AUTO_INCREMENT znamená, že nelze předpokládat ID 1.

Baseline obsahuje předchozí opravy bootstrapu/assetů, kandidát přidává tuto etapu. Indexy v benchmarkových DB vytvořily skutečné nové migrační closures; tento izolovaný experiment není provozní postup upgradu. Převod dat se samostatně ověřil přes ModuleSchemas a signed lifecycle.

Po prvním požadavku a dvou warmup vlnách následovalo 10 uchovaných fresh HTTP požadavků na cestu/variantu, filesystem cache a OPcache, bez souběžných testů. Middleware chráněných endpointů přijal skutečného člena/admina. Přípravné přihlášení je oddělené od dispatch SQL. Čísla nejsou Apache/TLS TTFB nebo produkční kapacita.

| Cesta | HTTP p50 před → po | Dispatch SQL před → po | Celé JSON shodné |
| --- | --- | --- | --- |
| `/api/notifications` | 839,772 → 44,263 ms | 4 → 4 | ano |
| `/api/users` | 345,460 → 183,595 ms | 5 → 4 | ano |
| `/api/users?sort=updated_at&direction=asc` | 747,792 → 240,485 ms | 5 → 4 | ano |
| `/api/users?sort=status&direction=desc` | 906,842 → 217,962 ms | 5 → 4 | ano |

Shodu potvrzují normalizované SHA-256 celých odpovědí. Nové stavové endpointy nejsou vydávány za stejný obsah jako úplný seznam. Pokusy s neexistujícím syntetickým aktorem 1 správně selhaly a nejsou použitými měřeními. Platná data: `notifications-before.json`, `notifications-after.json`, `users-before-valid.json`, `users-after.json` v `.work/performance/core-list-fixes-20261124/`.

Na notifikační fixture mělo tělo úplného seznamu 9 601 B, state **121 B**, preview 1 535 B v profilovacím prostředí. P50 nových endpointů bylo 42,639/43,749 ms a dispatch SQL 3/4 včetně běžného ověření účtu. Včetně přípravné autentizace je celkový počet o jeden vyšší. Nejde o velikost celé produkční stránky nebo její komprimovanou propustnost.

Kontrola stejného již indexovaného datasetu a stejného `/api/notifications`, 5 warmup a 30 střídajících se párů, měla baseline/kandidát p50 49,379/53,638 ms a medián párového rozdílu −1,953 ms ve prospěch baseline (`runtime-control-paired.json`). **Zrychlení samotného PHP runtime nebo nulová režie nové funkce tedy nejsou doložené.** Dominantní přínos pochází z indexů, menšího výběru/serializace a odstraněného COUNT. Malý rozdíl může zahrnovat metadata práci i kolísání prostředí a nebyl spolehlivě přiřazen jediné metodě.

## Cílené kontroly

Po dokončení zdrojů prošly PHP lint, deterministický styl a PHPStan. Asset report prošel pro 25 scopes bez zvýšení budgetů, UI inventář byl obnoven kanonickým generátorem.

- [Migrace a hranice](../../.work/tests/runs/20261124-041517-d431aeda78/report.json): version 2→3, částečné DDL, opakování, přesné indexové sloupce a pořadí, SQLite/MySQL fresh instalace, zachování dat, modulové a class dependency hranice.
- [API a katalogy](../../.work/tests/runs/20261124-035940-ac6b9b7ccf/report.json): HTTP kontrakt včetně autentizace/state/preview a příjemce, compiled parity, UI kontrakty a současné query budgets.
- [Řazení a identita](../../.work/tests/runs/20261124-035452-612d13fd45/report.json): sdílení COUNT jen bez filtrů, přesné filtrované počty, řazení, skutečné sessions a render-only šablony.
- [Nativní popup/polling](../../.work/tests/runs/20261124-040440-3ad5af11b3/report.json): dotyková matice a nové WebKit kontroly fokusu preview při 390/1024 px. První pokus měl chybnou testovací JSON obálku; po opravě fixture skupina prošla.
- [Další frontendové scénáře](../../.work/tests/runs/20261124-040633-1e2740409c/report.json): komentářové modaly, admin viewporty, source/compiled/minified asset delivery. Předchozí cílený běh ověřil také module lifecycle, polling races, AJAX sorting, obecné popovery a sizing; není vydáván za jeden úplný frontendový průchod.
- [Updater a artifact lifecycle](../../.work/tests/runs/20261124-042156-ac8d34b9e2/report.json): podpisy, chráněné cesty, zálohy/snapshoty, signed instalace/aktivace/upgrade, přerušení, rollback a build invalidace. Lifecycle trval 429,709 s včetně potomků; jejich časy se nepřičítají.
- [Konečná regrese profileru](../../.work/tests/runs/20261124-043137-5877819d20/report.json): canonical session, skutečné opětovné ověření, oddělené setup SQL a odmítnutí neplatného aktora před vytvořením reportu.

Tyto úspěšné reporty mají `source_changes_during_run: []`. Nevybrané skupiny nebyly provedeny. Průběžné neúspěšné pokusy zůstávají uložené a nejsou vydávány za green průchod. Plný preflight ani release neproběhly.

## Zbývající hranice

Přesný COUNT velké nepřečtené schránky zůstává lineární. Interval pollingu zůstává pět sekund, bez koordinace mezi kartami. Nevzniká perzistentní uživatelský čítač/cache bez návrhu invalidace. Hluboké číslované stránky, filtrované statistiky a jiné kombinace filtrů mohou stále zpracovávat více dat. Náklady čtyř účtových indexů na velké souběžné zápisy a disk nebyly kvantifikovány.

Inventář neaktivních modulů, rychlá cesta avatarů a extrémně rozsáhlý routing zůstávají návrhy. Zámky nastavení/session se neodstraňovaly. Opravy nedokládají univerzální subsekundovou odpověď pro libovolná data nebo zátěž.

Před provozním/release uzavřením zbývá odložený preflight a kontrola konkrétního vydávaného balíčku. Nové zdroje/schéma se mají nasadit společně updaterem; živé vizuální a přihlášené smoke této nové verze zatím neproběhly.
