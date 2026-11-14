# Audit hranic core a modulů

Datum: 12. 11. 2026. Audit pracovního stromu nad revizí
`ddc5768d5cfbe75939d1b9b2a6b3ccfbb8f5b7a3`, včetně necommitnutého rebuildu.
Jde o zjištění a návrh vlastnictví, nikoli popis dokončené opravy.

## Závěr

Adresářové oddělení již existuje, ale významové hranice nejsou dokončené.
Technické služby zůstávají mezi volitelnými moduly, doménová pravidla pronikají
do společných služeb a některé manifesty nepopisují skutečné vazby.
Blog a Social zatím nelze bezpečně považovat za současně použitelné funkce.

Core má vlastnit platformní mechanismy: runtime, transport, databázi, identitu
a oprávnění administrace, konfiguraci, plánování úloh, doručování, společnou
schránku oznámení, diagnostiku a hostitele UI. Funkční moduly vlastní význam
publikací, vztahy uživatelů, články, importy a moderaci. Core může mít vlastní
tabulky, endpointy, překlady i administraci. Jeho přítomnost neznamená okamžité
otevření DB či session ani automaticky dostupné veřejné registrační stránky.

Sdílení více moduly samo o sobě není důvod přesunu do core. `Publishing` je
sdílená publikační doména; budoucí objednávky e-shopu nemají být nuceně příspěvky.
Není potřeba vracet `Composition` ani přidávat runtime třídy jednotlivých produktů.

## Rozsah a metoda

Prošel jsem všech 17 modulů, sedm oblastí core, jejich manifesty, registrace,
SQL a hlavní konzumenty služeb; dále assety a instalační vazby. Tokenový inventář
obsahuje 268 PHP souborů modulů, 92 PHP souborů core a 1 587 unikátních statických
referencí soubor/třída. Počty zahrnují šablony a migrace, nejsou počty tříd.

U 13 ze 17 modulů existují odkazy mimo tranzitivní graf deklarovaných závislostí.
Scanner zachycuje také kompatibilní názvy a podmíněné větve; nejde o důkaz,
že každý odkaz nastává při každém spuštění. Statické cykly nejsou důkazem
rekurzivního bootstrapu. Odkazy v SQL, URL a konfiguraci vyžadovaly ruční kontrolu.

Lokální podklady, ignorované Gitem:

- [Inventář a odkazy](../../storage/core-module-audit-20261112/inventory.json).
- [Izolovaná zkouška Social + Blog](../../storage/core-module-audit-20261112/social-blog-probe.json).
- [Výpis kontrol hranic](../../storage/core-module-audit-20261112/current-boundaries.log).
- [Měřený report kontrol](../../storage/test-runs/20261112-001417-65b7217e46/report.json).

Inventář uvádí otisk 710 souborů `App`:
`7ac787063adc3796e06f44a6029e24466f379870615526937a82d7315cb48286`.
Podklady nejsou součást distribuovaných dokumentů. Audit nečetl provozní DB,
neměnil schéma ani živou instalaci `tinycat.test`.

## Mapa všech modulů

| Oblast | Současné promíchání | Doporučený vlastník |
| --- | --- | --- |
| Accounts | Přihlašování, obnova, role, profil a životní cyklus; preference e-mailových oznámení. | Core/Identity jako identita spravované instalace; preference kanálů Core/Notifications. Sociální profil zůstává mimo identitu. |
| Users | Sociální autoři, sledování a kompatibilní fasády Accounts. | Sociální modul; neutrální identity přes kontrakt core. |
| Scheduler | Runner, zámky, historie, údržba, administrace a botí detaily. | Core/Tasks pro mechanismus a historii; úlohy, retence a detailní panely jejich vlastníci. |
| Notifications | Schránka, deduplikace, sociální typy, SQL nad statusy/komentáři a e-mail. | Core/Notifications pro schránku a kanály; producent vlastní význam, příjemce, oprávnění a odkaz na předmět. |
| Health | Technické sondy, denní politika, historie Scheduleru a sociální doručení. | Core/Health nad Core/Tasks a Notifications; vlastníci přispívají sondami. |
| Mail | SMTP, vykreslení, výběr uživatele a všechny doménové šablony. | Core/Mail pro transport a vykreslení; šablony a adresáty poskytuje vlastník události. |
| Security | Limity, CAPTCHA, obrázky a konkrétní sociální kvóty. | Mechanismy Core/Security a media služby; pravidla akcí a výjimky podle vlastníka. |
| Settings | Obecný store a centrální seznam všech doménových polí. | Core/Settings pro ukládání a formulář; definice, výchozí hodnoty a validace podle vlastníka. |
| Site | HTTP pipeline, rám stránky, branding, navigace, sociální homepage, dashboard a sitemap. | Platformní runtime, UI a webové protokoly do core; sociální stránky a příspěvky do sociálního modulu. |
| Installation | Společný průvodce s předpoklady globálního sociálního schématu. | Core/Installation jako orchestrátor; schémata a počáteční data dodávají vybraní vlastníci. |
| Updates | Fasády již existujícího core updateru, administrace a údržba. | Jeden Core/Updates s administrací; zachovat kompatibilní vstupy a podporované aktualizace. |
| Publishing | Publikace, text, média publikací, odkazy, tagy a přesměrování. | Sdílený doménový modul; technické souborové a síťové operace používají core. |
| Status | Statusy, feed, komentáře, reakce, zmínky, export a fasády Publishing. | Sociální doména; případné samostatné komentáře/reakce až podle skutečné potřeby sdílení. |
| Blog | Články nad Publishing, ale vlastní globální rám a vstupní routy. | Souběžně zapnutelný doménový modul bez převzetí runtime celé instalace. |
| Bots | Importy, fronta, parsery a servisní aktéři; vazba na Status. | Importní doména s explicitním publikačním cílem; adaptér Social nesmí být požadavkem core runneru. |
| Moderation | Hlášení, reputace, blokace a přímé změny publikačních i uživatelských dat. | Volitelná doména nad kontrakty vlastníků; základní bezpečnost transportu zůstává core. |
| Search | Pevné SQL pro uživatele/tagy/statusy, sociální URL a feed. | Společná agregace a UI mohou být core; zdroje, SQL, viditelnost a URL dodávají domény. |

Tato mapa nepředepisuje okamžité přejmenování stabilních ID ani sloučení Users
a Status. Nejdříve je třeba opravit vazby a vlastnictví, potom fyzické uspořádání.

## Hlavní zjištění

### 1. Plánovač je platformní služba, jeho úlohy nikoli

[Runner](../../App/Modules/Scheduler/src/Runner.php) zajišťuje validaci, DB zámek,
měření a historii. Kontrakty úloh už jsou v Core/Modules a Core/Support/TaskContext.
Naproti tomu [administrace](../../App/Modules/Scheduler/src/Administration.php)
volá Bots/Resource a vykresluje botí výsledky. Konkrétní panel patří Bots.
FileCleanup zná adresáře statusů, avatarů a odkazů: společný sweeper může být
v core, ale cesty a retenční politiku musí dodat vlastník dat.

Současný mechanismus není obecná trvalá fronta ani kompletní cron DSL; splatnost
řeší také jednotlivé úlohy a spouštění externí cron. Refaktor nemá zavádět další
paralelní engine. Zachovat ID úloh a `scheduler_runs`. Prověřit identitu zámku
odvozenou od fyzické cesty aplikace, zejména při přesunu instalace.

### 2. Oznámení slučují schránku se sociální doménou

[Delivery](../../App/Modules/Notifications/src/Delivery.php) zná obsah, komentáře,
zmínky, uživatele a sociální e-mailové typy; běžné vytvoření vyžaduje aktéra.
Systémový health typ má zvláštní větev bez aktéra.
[Inbox](../../App/Modules/Notifications/src/Inbox.php) skládá sociální URL,
JOINy a typy. [Health](../../App/Modules/Health/src/Service.php) tak kvůli
systémovému upozornění potřebuje modul závislý na Status.

Core schránka musí přijmout i systémové oznámení bez aktéra a předmět jiného
modulu. Producent dodává stabilní typ, referenci předmětu a pravidla prezentace
a dostupnosti. Core nemá JOINovat `content_comments` ani znát `/status/…`.
Při neaktivním vlastníkovi musí existovat bezpečný fallback bez úniku skrytých dat.
Zachovat ID, přečtení, deduplikaci a historii. Změna schématu vyžaduje novou
migraci a převod dat; nestačí přestěhovat PHP.

### 3. Social a Blog soutěží o celou aplikaci

[Site/Module](../../App/Modules/Site/src/Module.php) a
[Blog/Module](../../App/Modules/Blog/src/Module.php) oba poskytují globální rám.
Izolovaná inicializace dnešního Social výběru doplněného o Blog končí
`Conflicting page presentation providers.`, před otevřením PDO i session.
Blog rovněž přebírá vstupní a administrační routy.

Core musí vlastnit společný HTTP tok a hostitele administrace. Výběr homepage
a tématu je konfigurace instalace oddělená od seznamu aktivních funkcí.
Blog může vlastnit `/blog`; Social své stránky; společná administrace jejich položky.
Předání celé odpovědi přes Startup.response dnes přesouvá technické kontroly
do Site. Je to problém vlastnictví a ověřitelnosti, nikoli prokázaná zranitelnost.

### 4. Publikace zatím nemají bezpečný kontrakt pro více druhů

[Blog/Articles](../../App/Modules/Blog/src/Articles.php) ukládá náhled do `content`
a specializaci do `blog_articles`. [Schéma Publishing](../../App/Modules/Publishing/migrations/v1/mysql.php)
nemá rozlišení druhu/vlastníka publikace. [Sociální feed](../../App/Modules/Status/src/Feed.php)
a [hledání](../../App/Modules/Search/src/Queries.php) čtou společné `content`
bez oddělení článků. Staticky tak není zaručeno, že se článek neobjeví jako status.

Publishing potřebuje explicitní druh/vlastnictví, pravidla výběru a řešení
kanonického odkazu. Prověřit současně feed, hledání, sitemap, přesměrování,
počty a notifikace. Stávající řádky musí dostat zpětně kompatibilní význam;
nové filtry a indexy musejí respektovat query budgets. Objednávky ani jiné
nepublikační domény se tím nestávají záznamy v `content`.

### 5. Settings, Mail a Security potřebují rozdělit mechanismus a pravidla

Settings má společný store, ale centrální administrační definice znají moderaci,
obsah, SMTP a šablony. Přidání Blogu nemá vyžadovat editaci tohoto seznamu.
Mail má sdílet SMTP a renderer; uvítání vlastní Identity, reakce Social.
Reset hesla nesmí vyžadovat sociální schránku oznámení.

Security obsahuje vedle technických limitů konkrétní kvóty a role pro publikování,
komentáře či boty. Obecné počítání limitů patří core, jejich význam vlastníkovi.
CAPTCHA pro přihlášení je politika Identity; varianta favicon je branding,
nikoli obecná bezpečnostní operace s obrázky.

### 6. Core obsahuje doménové závislosti i bez importů

[AdminSorting](../../App/Core/Support/AdminSorting.php) obsahuje pevný seznam
botích běhů, zdrojů, hlášení, endpointů, DOM cílů a SQL aliasů. Obecný parser
a validace řazení mohou zůstat core; definici předává vlastník seznamu.
Kontrola namespace tyto řetězcové závislosti neodhalí.

Core/Database, Http a většina Support zůstávají společnou infrastrukturou.
RichText, obecná sanitizace, stránkování a formátování nejsou automaticky
sociální funkce. Core/Modules má zůstat čistý katalog a validátor;
Core/Runtime potřebuje jednoznačné vlastnictví služeb místo volného přepisování
globálních callbacků. Core/Ui má být hostitel, nikoli katalog sociálních widgetů.

### 7. Manifesty a kontroly neodpovídají všem skutečným hranicím

Odkazy mimo deklarovaný tranzitivní graf mají Bots, Health, Installation,
Moderation, Notifications, Scheduler, Search, Security, Settings, Site, Status,
Updates a Users. Například Search deklaruje prázdné `requires`, ale používá
Status, Users, Site, Accounts, Moderation a Security.

Statický graf vytváří velkou skupinu Accounts, Installation, Mail, Moderation,
Notifications, Publishing, Search, Security, Settings, Site, Status, Updates,
Users a samostatný cyklus Bots–Scheduler. Pouhé doplnění všech závislostí by
zafixovalo promíchání; nejdříve rozdělit odpovědnosti, potom zpřesnit manifesty.

## Další průřezové oblasti

- Identita: Accounts je základ platformy, Users sociální profil a sledování.
  Moderace musí měnit účty přes oprávněný kontrakt, nikoli vlastnit jejich SQL.
  Zachovat lifecycle callbacky, cleanup i neaktivních instalovaných vlastníků
  a souborové operace po úspěšném commitu.
- Sitemap, hledání, navigace a dashboard: společný protokol a hostitel do core,
  zdroje dat a viditelnost do domén. Site dnes zná sociální tabulky a sekce.
- Assets a překlady: jeden katalog vybraných vlastníků zachovat. Legacy CSS
  index určuje pořadí, ale AssetGraph filtruje vybrané styly; nelze tvrdit,
  že se vždy načítají všechny moduly. Odstranit centrální znalost jejich pořadí
  až s ověřením kaskády. Technické admin assety nejsou obchodní modul.
- Instalace a aktualizace: společný orchestrátor používá schémata vlastníků.
  Starý globální základ je kompatibilita, nikoli vzor pro nové moduly.
  Zachovat podpisy, manifesty, rollback a validaci vlastnictví schema providerů.
- Storage: společné IO a bezpečnost cesty patří core; doménové adresáře,
  retence a oprávnění vlastníkovi. Ignorované soubory nejsou automaticky odpad.
- Feeds je samostatná aplikace; audit neprokazuje důvod jejího sloučení.
  Nástroje a testovací runnery nejsou runtime moduly. Testy mají ověřovat také
  kombinace funkcí, ne jen namespace a výchozí sociální instalaci.

## Ověření a omezení

Přes existující měřený runner proběhly pouze `module-boundaries.php`
(1 193 kontrol, 0,206 s) a `dependencies.php` (1 592 hran, 0,264 s); obě prošly.
Rozdíl proti počtu referencí inventáře vyplývá z jiné metodiky a rozsahu.
Úspěch těchto kontrol není potvrzením čistého sémantického rozdělení.
Dalších 86 skupin ani celý preflight nebyly pro dokumentační audit spouštěny.
Zkouška Social + Blog potvrdila konflikt inicializace, nikoli chování HTTP či DB.

Navazující práce a akceptace: [implementační plán](../plans/core-services-and-feature-modules.md).
