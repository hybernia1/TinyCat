# Společné feedové importy: etapa 4

Datum: 14. 11. 2026. Navazuje na [plán sdílených schopností](../plans/social-core-capabilities.md)
a [etapu diskusí a reakcí](social-core-stage3-2026-11-13.md).

Stav: dokončeno, ověřeno a nasazeno na `https://tinycat.test/` jako **6.0.0-dev.8**.

## Hranice a implementace

`Core/Publishing/FeedPipeline` provádí jeden průchod importem pro Social i Blog.
Nezná konkrétní účty ani tabulky. Úložiště implementuje `FeedSourceStore`:
claim zdroje, úspěšnou GUID historii, URL deduplikaci, atomický commit a diagnostiku.
`FeedTarget` vlastní přípravu a zápis daného druhu publikace; připravený
`FeedEntry` nese sdílený náhled, odkazy, data vlastníka a případné odmítnutí.

Pipeline používá stávající FeedParser, WordPressFeed, HTTP klienta, přípravu
odkazů a ImageStorage. Metadata a obrázky připraví před transakcí. Zápis
publikace, specializace, mapování na zdroj a úspěšného GUID tvoří jednu transakci.
Selhání odstraní nově připravený obrázek; odmítnutí neukládá úspěšný GUID.
Částečně dokončená dávka uchová již commitované publikace a lze ji opakovat.

Social si ponechal botí identity, tabulky, fronty, publikační intervaly,
bounded GUID historii, současnou správu zdrojů i úlohu `feeds`. Doménové SQL
je v `Social/Automation/FeedStore`, statusový cíl ve `FeedPublication`.
Odstraněné soukromé orchestrace v testech nahradily skutečné vstupy pipeline
a úložiště, ne další produkční kompatibilní wrappery.

Blog má nezávislý `FeedStore` a `FeedPublication`, autora vybírá z aktivních
administrátorů. Nevyžaduje botí účet ani přítomnost Socialu. FeedParser vrací
plné `content` odděleně od krátkého `description`; článek uchovává vlastní
celé tělo a společný 2 000znakový náhled. Ruční editor a import sdílejí validaci
celého textu. Výchozí viditelnost importu je skrytá.

## Data, runner a správa

Nová Blog migrace `003-feed-sources.php` zavádí výhradně Blogem vlastněné
`blog_sources`, `blog_feed_history` a `blog_source_articles`. Blogový stream
má verzi 3. Žádná botí tabulka se nestěhuje ani nepřejmenovává; dosavadní
migrace a streamy zůstávají zachované. DDL respektuje rozdílné typy ID účtu
a publikace na MySQL a lze jej zopakovat po částečném provedení.

Zdroj má revizi. Commit pod zámkem znovu kontroluje revizi, identitu,
zapnutí zdroje a aktuální autorovo oprávnění. Úspěšný GUID je unikátní v rámci
zdroje; Blog jej uchovává do odstranění zdroje. URL deduplikace se omezuje
na články stejného autora. Odebrání zdroje ponechá články, odstraní jeho
historii a mapování; odstranění autora uklidí data také při neaktivním modulu.

Úlohu `article_feeds` registruje pouze aktivní Blog. Ruční API a CLI i běžný
plánovaný běh používají Core/Tasks, jeho zámky, validaci voleb a persistentní
`scheduler_runs`. Blog nezavádí druhý runner ani kopii tabulky historie běhů.

Správa na `/admin/article-sources` používá společný seznam, hledání, řazení,
stránkování, popover filtru a resource modaly. PHP i AJAX vykreslují stejné
komponenty. Vytvoření a editace načítají autorizované JSON resources až při
otevření; obnova seznamu zachová koncept. Náhled je čtecí příprava v otevřeném
formuláři. API kontroluje CSRF, role a aktivního autora. Překlady mají všech
12 jazyků. Nový klientský scope má vlastní rozpočet; ostatní rozpočty se nezvyšují.
Obecný tabový workspace se nevytahoval, druhé použití nevzniklo.

Provoz popisuje [návod k importům](../operations/feed-imports.md),
hranice [core a modulů](../development/core-and-modules.md).

## Ověření a nasazení

Celkem 88 stávajících hlavních skupin: **87 prošlo, 1 přeskočena**.
Všech **38 frontendových skupin** a **7 preflight kontrol** prošlo.
Nepřibyla další hlavní skupina ani kopie úplné integrační matice.
Memcached se přeskočil kvůli nedostupné službě; uvnitř katalogového testu
se přeskočil Windows symlink scénář, protože prostředí tvorbu odkazu nepovoluje.

Součet nejnovějších dokončených průchodů je **827,050 s (13 min 47,050 s)**;
všechny zaznamenané pokusy těchto kontrol mají **1 117,310 s (18 min 37,310 s)**.
Součty nezahrnují obalové runnery podruhé a nejsou souvislým časem celé práce.
Nejde o jeden čistý zelený preflight: `composer preflight` se zastavil na novém
testovacím JSON feedu bez povinného titulku. Po opravě pokračovalo zbývajících
50 skupin; již úspěšné kontroly se plošně neopakovaly.

Při dokončování se upravoval fallback plného JSON obsahu, kanonická diagnostika
a uchování původní URL vedle dalších odkazů článku. Dotčený Blog, MySQL,
write-path a PHP kontroly byly znovu ověřeny cíleně. Poslední průchody mají
604 kontrol samostatného Blogu, 88 kontrol souběhu, 43 MySQL kontrol a 217
write-path kontrol. Nativní Blog fixture ověřuje také správu zdrojů, náhled,
AJAX CRUD, koncepty a 16 responzivních pohledů. MySQL ověřuje dva nezávislé
importéry proti jednomu zdroji: vznikne právě jeden článek, GUID a mapování.

Vizuální kontrola navíc odhalila oříznutý prázdný stav tabulky na mobilu.
Společné mobilní CSS prázdné tabulky uvolní minimální šířku a skryje nepoužité
hlavičky; seznam s daty zůstává vodorovně posuvný. Regresi pokrývá stávající
nativní UI fixture, navíc prošly admin viewporty, Blog, UI kontrakty a všech
12 distribuovaných asset scopeů bez zvyšování dosavadních rozpočtů.

Nejdelší jednotlivé průchody: modulový životní cyklus 188,769 s, aktualizační
zkouška 97,124 s, dotykové popovery 91,956 s, Blog 54,726 s, HTTP API 50,497 s.
Tato etapa nic z jejich pokrytí neodstranila.

Podklady jsou oddělené od živé instalace v ignorovaném
`storage/social-core-stage4-20261114/`; reporty runnerů v `storage/test-runs/`.
Souhrn je v `verification.json`, každý pokus zachovává svůj report a fingerprint
zdrojů. PHP kontroly i workers používají **Laragon PHP 8.4.12 NTS**;
MySQL scénáře používají existující službu a vlastní dočasné databáze.

### Živá instalace

Standardní updater ověřil privátně podepsaný platformní balíček důvěryhodným
klíčem instalace a provedl přechod dev.7 → dev.8. Záloha obsahuje původní
soubory a kompletní databázový snapshot s ověřenými checksumy:
`storage/updates/backups/6.0.0-dev.7-to-6.0.0-dev.8-20261114-083418-17a4f7ef`.
Balíček nebyl veřejně publikován.

Ověřeno všech **1 170 spravovaných souborů** proti podepsanému manifestu,
nepřítomnost odstraněných cest, neměnnost **50 dosavadních migračních souborů**
a původního mazacího manifestu. Blogový stream má verzi 3 a stav `ready`.
Přibyly pouze tři prázdné Blog tabulky; všech 27 původních schémat zůstalo
stejných a 25 datových tabulek má shodné otisky celých řádků. Zbylé dvě
tabulky evidence modulů obsahují pouze odpovídající nový migrační záznam
a zvýšení verze Blogu. Dosavadní migrační historie včetně globální a statusové
je zachovaná.

Zůstalo **11 633 publikací, 38 účtů a 9 142 mediálních souborů**. Konfigurace,
mediální inventář, obsah a aktivace Blogu i Socialu mají stejné otisky před
a po aktualizaci. Totéž platí po následném smoke ověření. Údržba je vypnutá,
správce modulů je použitelný a runtime registruje `feeds` i `article_feeds`.

Prošlo **95 HTTPS kontrol s ověřováním TLS**, včetně veřejných stránek,
článků, diskusí, exportů, autorizace nových zdrojových resources a všech pěti
kompilovaných assetů nové administrace. Autorizované zápisy, importy a nativní
UI scénáře proběhly v izolovaných fixtures; živá instalace nedostala testovací
zdroje, články ani účty. Závěrečná provozní část tohoto auditu byla doplněna
po sestavení a nasazení balíčku, produkční kód se poté neměnil.
