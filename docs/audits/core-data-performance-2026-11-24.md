# Core: schránka, polling a souběh — 24. listopadu 2026

Následné opravy a ověření popisuje [implementační etapa](core-list-performance-fixes-2026-11-24.md). Níže je zachovaný původní auditní stav.

Navazující audit po [opravě bootstrapu a assetů](core-performance-2026-11-24.md#navazující-implementace-bez-preflightu). Největší nový nález je chybějící podpora dvou notifikačních dotazů indexy a opakované načítání plné stránky schránky pro šestipoložkovou hlavičku. V této etapě se neměnil produkční runtime, publikované migrace ani živá instalace. Preflight zůstává podle zadání odložený.

## Prostředí a metoda

- Nová izolovaná databáze `tinycat_perf_4ddd792b5ffd` na existujícím MySQL Laragonu, připravená kanonickým `prepare-performance.php`. Zkopírovány pouze definice 24 tabulek, žádná živá data.
- Pět syntetických účtů, celkem **310 100 notifikací**: schránky po 100, 10 000 a třikrát 100 000 řádcích. Velké schránky mají 0 %, 100 % nebo 1 % nepřečtených. Časy vytvoření nejsou seřazené podle ID.
- Dotazy odpovídají současným metodám [Inbox](../../App/Core/Notifications/Inbox.php): přesný počet nepřečtených, nejvyšší ID příjemce a náhled šesti položek včetně skutečné projekce a JOIN na aktéra.
- Nativní prepared statements, měření execute + fetchAll; tři zahřívací a 25 uchovaných vzorků pro každý dotaz, příjemce a variantu. `EXPLAIN ANALYZE` běží samostatně. Žádné jiné testy nebo benchmarky neběžely souběžně.
- Jde o zahřáté SQL v lokálním MySQL, nikoli HTTP latenci, dobu PHP renderu nebo studený buffer pool. Fixture nemá aktéry ani doménovou hydrataci předmětů; jejich cenu toto měření nepokrývá.
- Raw důkazy a reprodukční skripty: `.work/performance/core-data-audit-20261124/` a `.work/core-audit2-*.php`. Privátní konfigurace obsahuje připojení, nepublikovat.

## 1. Náhled šesti notifikací prochází celou schránku

Současný index `(user_id, read_at, created_at)` nemůže bez filtru `read_at` dodat pořadí `created_at DESC, id DESC`. U schránky se 100 000 položkami plán nejprve načetl všech 100 000 odpovídajících řádků přes `notifications_key_unique`, potom třídil a vybral šest. JOIN na aktéra proběhl až pro šest výsledků; hlavní problém zde nebyl N+1 nebo JOIN.

Experimentální index `(user_id, created_at DESC, id DESC)` změnil plán na čtení šesti řádků přímo v požadovaném pořadí. Kontrolní součty výsledků zůstaly shodné pro všechny příjemce a všechny varianty.

| Dotaz / 100 000 položek, 1 % nepřečtených | Původní p50 / p95 | Oba experimentální indexy p50 / p95 |
| --- | --- | --- |
| šest položek náhledu | 713,148 / 777,000 ms | 0,212 / 0,298 ms |
| nejvyšší ID příjemce | 49,915 / 55,873 ms | 0,079 / 0,114 ms |
| přesný počet nepřečtených | 0,604 / 0,929 ms | 0,506 / 0,555 ms |

Schránka s 10 000 položkami měla náhled 60,636 → 0,250 ms; se 100 položkami 0,471 → 0,207 ms. Efekt tedy závisí na objemu dat. Není to automatická úspora stovek milisekund na malé instalaci.

[DocumentData::prepare](../../App/Core/Ui/DocumentData.php) načítá tento náhled pro přihlášeného uživatele společného veřejného rámu. Cena proto může dopadat i na stránku, která jinak se schránkou nesouvisí.

## 2. Zjištění nejvyššího ID rovněž skenuje všechna data příjemce

`SELECT COALESCE(MAX(id), 0) FROM notifications WHERE user_id = ?` v původním plánu prošel 100 000 záznamů covering indexu `notifications_user_index`. Samotný nový index pořadí podle data tuto operaci nevyřešil: p50 zůstal 46,605 ms.

Druhý experimentální index `(user_id, id)` umožnil optimalizaci MAX bez průchodu celým rozsahem (`Rows fetched before execution`). Nelze jej nahradit předpokladem, že datum vytvoření je vždy seřazené podle ID.

**Doporučená oprava těchto dvou bodů:** nová migrace ve vlastněném notifikačním streamu a odpovídající aktuální fresh schema. Neměnit publikované migrace ani jejich checksumy. Ověřit MySQL i SQLite, upgrade/rollback, existující indexy a stránkování včetně shodných timestampů a starších kurzorů. Stávající unikátní klíč a index nepřečtených mají odlišné účely a nejsou automaticky zbytečné.

### Opačné pořadí a cena zápisu

Po prvním měření byly oba indexy odebrány a znovu vytvořeny. V opakování pro stejnou velkou schránku:

- s indexy před odebráním: náhled 0,405 ms, MAX 0,212 ms;
- po odebrání: náhled 679,307 ms, MAX 49,865 ms;
- po opětovném vytvoření: náhled 0,821 ms, MAX 0,393 ms.

Výsledné řádky opět měly stejné checksumy. To podporuje přiřazení velkého rozdílu plánům, nikoli pouze zahřátí nebo pořadí běhů. Detaily: `notification-plans.json`, `notification-recheck.json`.

Změřeno také 15 uchovaných commitovaných dávek po 100 vloženích po dvou zahřívacích dávkách; příprava parametrů a následný úklid měřených řádků jsou mimo čas. P50 původní varianty byl 9,645 ms, se dvěma indexy 9,503 a 7,671 ms ve dvou kolech; p95 přibližně 26 ms. Tento malý blokový test **neurčuje nulovou cenu indexů** ani vyšší výkon zápisu. Kolísání commitu zakrylo jejich samostatnou režii; větší souběžné zápisy a nároky na disk zůstávají neověřené.

Experimentální indexy byly nakonec opět odstraněny. Databáze a všech 310 100 notifikací zůstaly zachované pro další práci (`restored-schema.json`). Živé schéma se neměnilo.

## 3. Polling hlavičky každých pět sekund načítá celou stránku

[Controller](../../App/Core/Notifications/assets/controller.js) má výchozí interval 5 000 ms a stejnou hodnotu deklaruje [komponenta](../../App/Core/Notifications/assets/components.json). Skrytý dokument a již běžící požadavek se vynechávají, což je správné. Každý další poll ale bez podmínky změny volá `GET /api/notifications` s `cache: no-store`.

[API](../../App/Core/Notifications/Api.php) pro tento požadavek provede stejný stránkovaný průchod jako pro úplný seznam: načte 41 řádků, vrátí až 40 serializovaných položek a spočítá stav. Hlavička pak zobrazí jen `state.items.slice(0, 6)`. To znamená až 35 zbytečně načtených a 34 zbytečně doručených položek na poll hlavičky, navíc jejich hydrataci a serializaci. Platí i při nezměněném obsahu.

Pro 100 viditelných neblokovaných karet odpovídá samotný pětisekundový timer přibližně **20 požadavkům za sekundu**. Je to aritmetický odhad, nikoli provedený zátěžový test; zpomalení, plánování prohlížeče a guard proti překryvu mohou skutečnou frekvenci snížit. Současná implementace nemá společného koordinátora pollingu mezi kartami.

**Doporučení:** oddělit lehké čtení stavu od stránkovaného seznamu, zachovat kanonický API formát a autorizaci. Šestipoložkový náhled načítat jen tam, kde je potřeba, a podle zvoleného UX při změně nebo otevření. Zachovat přesné hodnoty stavu, kurzory a ochranu `mutationRevision`; nezavádět obecnou neinvalidovanou cache uživatelské schránky. Ověřit aktuální stránku schránky, otevřený náhled, více karet, skrytí dokumentu a souběh se změnou/mazáním.

## 4. Přesný počet nepřečtených zůstává lineární

Při 100 000 nepřečtených notifikacích trvá původní `COUNT(*)` přibližně **50,300 ms**, s novými indexy stále **50,038 ms**. Stávající index je použitelný, ale přesná agregace zpracovává všechny nepřečtené položky.

Badge `99+` sice nepotřebuje přesné velké číslo, současný API kontrakt však `unread_count` vrací přesně a používá se i v dalších textech. Nelze tedy bez změny smlouvy vrátit pouze limitovaný počet 100. Případný uložený čítač potřebuje atomické aktualizace při doručení, čtení a mazání, účtový lifecycle a opravu driftu. Není to bezpečná kosmetická změna dotazu.

## 5. Stránka schránky opakuje již připravenou práci

[Pages::page](../../App/Core/Notifications/Pages.php) načte stránku schránky a samostatně počet nepřečtených. Následný společný rám znovu počítá nepřečtené, nejvyšší ID a načítá šest položek náhledu. Na úrovni zdrojového toku jde o pět notifikačních SQL volání před případnou doménovou hydratací, z toho stejný COUNT dvakrát. Prvních šest položek hlavního seznamu se může překrývat s hlavičkou.

Kandidátem je předání již připravených raw dat a stavu důvěryhodným serverovým volajícím. Je nutné zachovat rozdílné délky excerptů a význam hlavičky proti stránkovanému seznamu; nelze bez rozlišení cachovat libovolnou kurzorovou stránku jako poslední náhled. Celá HTTP stránka v této etapě profilována nebyla, uvedený počet vychází z konkrétních volání ve zdrojích.

## 6. Zámky skutečně čekají, ale chrání konzistenci

Kontrolovaný experiment použil vždy jednoho holdera a jednoho čtenáře v oddělených PHP procesech. Holder držel příslušný zámek 100/250/500 ms a signalizoval jeho získání. Nejde o přirozeně pozorovanou dobu držení zámku nebo o kapacitní test.

| Mechanismus | Holder | Kontrolní čtení | Blokované čtení |
| --- | --- | --- | --- |
| zahřátá settings cache | 250 ms | přímé cache čtení 0,348 ms | `Store::setting` 247,250 ms |
| zahřátá settings cache | 500 ms | přímé cache čtení 0,306 ms | `Store::setting` 500,709 ms |
| session stejného ID | 250 ms | jiné session ID 0,382 ms | stejné ID 249,565 ms |
| session stejného ID | 500 ms | jiné session ID 0,440 ms | stejné ID 504,978 ms |

[Store](../../App/Core/Settings/Store.php) při prvním settings čtení koordinuje i zásah cache výhradním zámkem. Přímé čtení cache je v tabulce pouze kontrola mechanismu, **nikoli bezpečná náhrada**: neřeší souběh starého fill s invalidací. Existující konzistenční test tento problém výslovně pokrývá. Primovaný request snapshot je navíc podle stávajícího testu uvnitř transakce úmyslně stabilní; to není nový nález chyby.

[Session](../../App/Core/Http/Session.php) s použitým souborovým PHP handlerem serializuje požadavky téhož ID. Existující `withoutLock` zavírá session během pomalého I/O a znovu ji načítá. Paušální uzavření dříve v kernelu není odůvodněné: CSRF, flash a autentizace mohou zapisovat. Rozumným dalším měřením jsou konkrétní pomalé čtecí endpointy; případné uvolnění musí zachovat souběžné session změny. Experiment jejich uchování ověřil.

Důkaz: `lock-mechanisms.json`; settings experiment měří filesystem cache. Samostatné existující regrese ověřily i Memcached, nejde však o benchmark jeho contention.

## 7. Současné `--user` v profileru nenastavuje platnou session

[Profilovací router](../../tools/performance/lib/profile-router.php) nastavuje jen `auth_user_id`. [Authentication](../../App/Core/Identity/Authentication.php) však vyžaduje také platný `auth_credential`. Na izolovaném skutečném účtu audit potvrdil:

- session pouze s ID: `auth()` vrátí nepřihlášeného;
- session vytvořená `authLogin(1)` a následně znovu ověřená: uživatel 1 je autentizovaný.

Z toho plyne, že současná volba `--user` není spolehlivý způsob měření přihlášené aplikace. Oprava nástroje musí použít kanonickou autentizaci, zachovat ověření identity při skutečném požadavku a oddělit nebo zřetelně vykázat režii přípravy benchmarkové session. Přidat regresi na endpoint vyžadující přihlášení; HTTP 200 z veřejné stránky samo o sobě nestačí.

Všechny předchozí benchmarky provedené v tomto chatu byly anonymní bez `--user`, takže je tento nález nezneplatňuje. Nové SQL výsledky výše jsou přímá měření a nejsou vydávané za autentizované HTTP profily. V této etapě se profiler neopravoval.

## Ověření a doporučené pořadí

Existující runner prošel pro `tests/domains/notifications/inbox.php`, `tests/infrastructure/cache/settings-boot.php` a `tests/infrastructure/cache/settings-consistency.php`. Konzistenční test prošel pro filesystem i Memcached, bez přeskoků. [Report](../../.work/tests/runs/20261124-031233-1672575015/report.json) má `source_changes_during_run: []`. Jde o cílené ověření současných kontraktů, nikoli test dosud neimplementované migrace.

Dokumentace byla ověřena diffem, lokálními odkazy a shodou údajů s raw reporty. Produkční PHP/JS se v této auditní etapě neměnily; neproběhl preflight, nový frontendový průchod, nasazení, migrace ani zátěžový test živé instalace.

Další implementační etapa má podle evidence toto pořadí: opravit autentizaci profileru; dodat indexy a ověřit podporované migrace; zmenšit polling na potřebná data; odstranit duplicitní přípravu schránky; až potom řešit přesné velké čítače a konkrétní dlouho držené zámky. Nové indexy samy nevyřeší pětisekundový plný polling ani velký počet nepřečtených.
