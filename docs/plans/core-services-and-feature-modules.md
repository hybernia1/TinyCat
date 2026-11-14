# Platformní služby a kombinovatelné funkční moduly

Realizační plán z 12. 11. 2026 navazuje na
[audit hranic](../audits/core-module-boundaries-2026-11-12.md).
Etapy 1A a 1B převedly společné mechanismy do core. Navazující dokončení
instalačních adaptérů, nastavení, šablon a souběhu Blogu se Socialem
implementuje stejný runtime a nezávislou aktivaci funkcí. Níže je původní
rozsah návrhu; skutečný kontrakt popisuje [reference architektury](../development/core-and-modules.md).
Implementaci, měřené ověření a nasazení 6.0.0-dev.3 zachycuje
[report dokončení](../audits/platform-completion-2026-11-12.md).

Implementováno v navazujícím celku: samostatné instalační baseline vlastníků,
DDL legacy instalátoru z vybraného katalogu, lazy sekce nastavení a jejich
klientské popisky, vlastněné e-mailové šablony, obecná rozšíření detailu úlohy,
výběr homepage/tématu/dashboardu, druh publikace a bezpečné vypínání funkcí.
Kombinovaný scénář pokračuje nad existující databází izolovaného Blogu,
připojí Social, ověří HTTP/API a vypne i obnoví oba vlastníky bez ztráty dat.

Navazující dev.4 doplňuje Core/Discovery: aktivní vlastníci přispívají hledání
a sitemap; Blog funguje samostatně i vedle Socialu. Obecné feed parsery
a přípravu importu vlastní Publishing. CLI používá společný runner a core
historii také bez Scheduler adaptéru. Původní specializované sociální hledání
zůstává zachované. Implementaci, měření a nasazení dev.4 uzavírá
[navazující report](../audits/platform-discovery-and-tasks-2026-11-12.md).

## Cílový kontrakt

Core poskytuje identitu, transport, DB, konfiguraci, úlohy, mail, schránku
oznámení, diagnostiku a hostitele UI. Služby mají explicitního vlastníka,
lazy inicializaci a konfiguraci. Katalog ani registrace neotevírají DB/session,
nespouštějí callbacky a neprovádějí DDL.

Každý funkční modul má jedno místo a vlastní inicializaci, deklarované závislosti,
API, schéma, překlady, assety a příspěvky do společných služeb. Core neimportuje
jeho třídy a nezná jeho tabulky ani URL. Vlastník dodává konkrétní úlohy,
oznámení, nastavení, vyhledávací zdroje a UI položky přes úzké kontrakty.
Nevzniká Composition, alternativní bootstrap ani obecný event bus bez potřeby.

Publishing zůstává sdílený doménový modul. Social, Blog, importy a moderace
jsou funkce, které se mohou kombinovat. Výběr homepage/tématu je samostatná
konfigurace; instalační preset může být seznam ID a výchozích hodnot, nikoli
další PHP aplikace. Core může mít své technické zdroje ve stejném katalogu.

## Etapa 1: platformní základ a odstranění obrácených vazeb

Realizační členění: nejprve uzavřít **1A – úlohy, store nastavení a SMTP**;
potom **1B – identitu, schránku oznámení, health a společný HTTP/admin tok**.
Původní etapa zahrnuje i změny datových kontraktů, které mají vlastní migrace;
1A je nepředstírá pouhým přesunem souborů. Každý z těchto celků má jednu
závěrečnou měřenou kontrolu. Druhá etapa nadále řeší souběh funkčních modulů.

**1A dokončena a ověřena 12. 11. 2026:** celý preflight prošel za 12 min 52 s;
rozsah, přeskoky a zbývající hranice uvádí [report etapy](../audits/core-services-stage-1a-2026-11-12.md).

V 1A jsou implementace runneru, historie, kontextu, diagnostiky a údržby
v `Core/Tasks`, store v `Core/Settings` a SMTP v `Core/Mail`.
Dosavadní názvy jsou kompatibilní aliasy. Botí detail, řazení seznamů,
doménové config overrides a dočasné adresáře deklarují jejich vlastníci.
Scheduler/Settings/Mail zatím zůstávají registračními a prezentačními adaptéry;
instalační schéma a technické zdroje ještě nepřešly na samostatné core vlastníky.
Nejde o dokončení celé etapy 1 ani odstranění sociálních vazeb z Notifications.

Navazující část 1B přesouvá identitu, obecnou schránku a health do core,
sociální události do Status a společný HTTP tok do kernelu. Zavádí nový
nedestruktivní payload kontrakt; [report části 1B](../audits/core-services-stage-1b-2026-11-12.md)
rozlišuje provedené služby od zbývajících instalačních a prezentačních přesunů.
Tato část je ověřena napříč všemi 88 skupinami v navazujících bězích
(87 úspěšných, Memcached přeskočen). Zbývající instalační adaptéry, nastavení,
šablony a kombinovatelnost funkcí tím nejsou označeny za dokončené.

Jeden souvislý rebuild platformních služeb:

1. Vymezit kontrakty Identity, Settings, Tasks, Notifications, Mail, Health,
   společné administrace a HTTP toku. Zachovat veřejné kompatibilní vstupy.
2. Přesunout technické implementace do odpovídajících oblastí Core; odstranit
   znalost Bots z runneru, Status z obecné schránky a sociálních pravidel
   z obecného řazení či bezpečnostních mechanismů.
3. Vlastníkům předat pravidla akcí, úlohy, retenční cesty, nastavení, šablony
   a typy oznámení. Oznámení podporuje nepovinného aktéra a předmět vlastníka.
4. Připravit nové migrace a backfill pro změněné vlastnictví dat; zachovat ID,
   task historii, přečtení a deduplikaci. Neměnit publikované migrace.
5. Zapojit jednotný hostitel administrace a povinný technický HTTP tok.
   Moduly již nemohou přepsat odpovědnost celé aplikace globálním callbackem.
6. Upravit stávající kontroly hranic a dokumentaci podle skutečné implementace.
   Nepovolovat libovolné schema providery jen kvůli přesunu adresářů.

Hotovo znamená: úloha, systémová health notifikace, přihlášení a obnova hesla
fungují bez Status, Users a Publishing. Sociální výchozí instalace zachovává
API, oprávnění, URL a data. Samotný katalog lze stále načíst bez DB a session.

## Etapa 2: souběh funkcí a vlastnictví publikací

1. Odstranit globální nároky Blogu a Site na rám, root a administraci;
   společný runtime vybírá homepage a téma z konfigurace instalace.
2. Zavést v Publishing explicitní druh/vlastníka a kanonické odkazy publikací,
   včetně zpětně kompatibilního převodu existujících řádků novou migrací.
3. Upravit společně feed, hledání, počty, sitemap, notifikace, přesměrování
   a cache. Blog se nesmí omylem vydávat za status. Sdílené zobrazení musí být
   explicitní volba se správnou URL a oprávněním.
4. Rozdělit Site na platformní služby a sociální stránky/widgety. Převést
   dashboard, navigaci, sitemap a hledání na příspěvky vlastníků.
5. Oddělit obecný import do Publishing od sociálního adaptéru Bots.
   Vyčistit fasády a deklarovat skutečný graf závislostí bez maskování cyklů.
6. Aktualizovat build, instalační výběr, manifesty, assety, překlady a návody
   tak, aby Social a Blog šly zapnout společně i samostatně.

Hotovo znamená: Blog má vlastní stránky a články; Social své statusy a vztahy;
oba používají stejnou identitu, administraci a platformní služby bez kolizí.
Deaktivace funkce nemaže její data ani nerozbíjí cleanup a aktualizace.

## Ověření po etapách

Během práce používat levné cílené kontroly. Na konci každé celé etapy spustit
jeden měřený odpovídající soubor kontrol přes existující runner a preflight;
nevytvářet další univerzální runner ani opakovat plné balíčkové/browser scénáře
po každém přesunu. Opakování odůvodnit opravou chyby nebo změnou od posledního běhu.

| Scénář | Co musí prokázat |
| --- | --- |
| Minimální core/katalog | Bez DB/session a vedlejších efektů při registraci. |
| Identita bez Social | Login, autorizace, obnova a lifecycle bez sociálních tabulek. |
| Tasks + Health + Notifications | Systémová zpráva bez Publishing a bez smyšleného aktéra. |
| Blog | Článek, canonical, oprávnění, admin a vlastní zdroje. |
| Social | Zachované feedy, reakce, moderace, importy a existující kontrakty. |
| Social + Blog | Žádná kolize providerů/rout, správné oddělení a odkazy obsahu. |
| Existující nepublikační fixture | Modul nepotřebuje tabulku content ani sociální runtime. |
| Aktivace a deaktivace | Zachování dat, bezpečný fallback oznámení, cleanup vlastníka. |
| Instalace a podporovaný update | Nové schéma, převod dat, podpisy, rollback a kompatibilita. |

Rozšířit existující scénáře tam, kde lze sdílet instalaci a fixture; negenerovat
samostatný plný instalační test pro každou metodu či každý modul.
Report každé etapy musí uvést spuštěné skupiny, časy, vynechané kontroly,
náklady jednotlivých scénářů a rozhodnutí o duplicitách. Zachovat bezpečnost,
API/UI kontrakty, query budgets a paritu překladů.

Živou instalaci nasadit až po akceptaci příslušné etapy, záloze a ověření
migrací. Použít existující Laragon a externí instalaci `tinycat.test`;
nespouštět vlastní Apache/MySQL ani zaměňovat pracovní strom za nasazený web.
