# Implementační plán modulární platformy

Aktuální navazující zadání je [jedna rebuild etapa](module-rebuild.md):
odstranění Composition, jednotné samostatné moduly a závěrečné měřené testy.
Níže uvedené A–D zachycují předchozí implementaci a ověření; jejich označení
„hotovo“ neznamená splnění nově upřesněné cílové architektury.

Výchozí verze: 5.22.2. Navazuje na [návrh](modular-platform.md) a
[historický audit](modularity-6.0.0.md). Etapy jsou samostatné kontrolovatelné
řezy; další etapa začíná po ověření předchozí. Nejde o hromadné přestěhování
adresářů ani současné zavedení všech produktových sestav.

## Ucelené etapy oddělení produktu

Po dohodě s uživatelem se jednotlivé servisní přesuny neuzavírají samostatným
preflightem. Během etapy se používají cílené kontroly, úplné ověření proběhne
až po dokončení jejího rozsahu. Selhání se opravuje v téže etapě.

| Etapa | Rozsah společného dodání | Akceptace |
| --- | --- | --- |
| A — dokončení Accounts (hotovo) | Explicitní modul, vlastnictví tabulek a instalačního schématu, API/routy, stránky, read modely, frontend, komponenty a 12 překladových katalogů. | Ověřen samostatný start, instalace, API, HTML i build bez Users/Status/Bots; zachované stock URL a kontrakty; preflight a rozšířený test 395 kontrol. |
| B — sociální funkce a Publishing (hotovo) | Oddělený Publishing, veřejný autor, sledování, obsah, reakce, komentáře a jejich závislosti. | Preflight 84 skupin, samostatný Publishing 351 kontrol a sociální hranice 332; zachované URL, viditelnost, textová pravidla a query budgets. |
| C — blogová sestava (hotovo) | Produktová kompozice, dlouhé články, stránky, API a instalace bez povinných sociálních/botích tabulek. | Izolovaný Blog 380 kontrol, MySQL 8; ověřen rozsah všech 86 skupin včetně balíčků, updatu a rollbacku. Podrobnosti přerušených běhů a cíleného dokončení níže. |
| D — distribuce a lifecycle (hotovo) | Varianty balíčků, modulové instalace/migrace, aktivace, neaktivní moduly, podpisy a obnova. | Podepsaná matice 52 kontrol, SQLite/MySQL snapshoty 16; ověřen rozsah všech 88 skupin včetně aktualizace z 5.16.0, přerušení a rollbacku. |

Etapy A až D jsou dokončené a ověřené. Následující starší záznamy zachycují již provedené
servisní řezy a jejich ověření, nejsou novým plánem drobných etap.

### Postup a ověření každé etapy

1. Dokončit celý rozsah daného řádku včetně volajících, kompatibility,
   dokumentace a příslušných regresních scénářů. Dílčí přesun není předání etapy.
2. Během implementace používat cílené kontroly pro změněnou část. Úplnou sadu
   nespouštět po každém přesunu nebo úpravě souboru.
3. Po dokončení rozsahu spustit `composer preflight`. Zaznamenat výsledek,
   log v ignorovaném `storage/` a případné přeskočené integrace. Opravy selhání
   patří stále do stejné etapy; opakování kontrol se řídí jejich dopadem.
4. Předat souhrn výsledného chování a omezení. Teprve po úspěšném ověření
   pokračovat další etapou. Commit ani vydání balíčku nejsou automatické.

### Etapa A: hranice dokončení

- Přihlášení, registrace, obnova hesla, správa vlastního účtu, administrace
  účtů a avatary mají společného vlastníka Accounts od routy po UI a schéma.
- Sociální profil autora, sledování a feed zůstávají v sociální části.
  Accounts získává produktovou politiku přes explicitní kompozici;
  neimportuje Users, Status ani Bots.
- Přesuny zahrnují manifesty, frontendové vstupy, komponentové definice,
  překlady, instalační schéma, volající a mazací manifest aktualizace.
  Publikované migrace se nepřepisují.
- Izolovaný integrační scénář ověří instalaci, start, API, HTML a sestavení
  assetů bez zdrojových adresářů Users, Status a Bots. Dosavadní izolované
  testy jednotlivých služeb tuto akceptaci nenahrazují.
- Stock sestava zachová současné URL, API obálky, oprávnění, CSRF, dialogové
  kontrakty a chování aktualizace. Závěrečný preflight ověří celou sestavu.

### Etapa B: hranice dokončení

- Publishing vlastní obsah, viditelnost, kurzory, textovou přípravu, tagy,
  odkazy, metadata, média, publikační zápisy a jejich instalační schéma.
  Nepotřebuje sociální tabulky ani zdroje Status/Users/Bots/Notifications.
- Status vlastní sociální produkt: jeho feed, reakce, komentáře a současné
  stránky a HTTP adaptéry. Nezavádí se druhá implementace stejných zápisů.
- Users vlastní autora a sledování; skládání autorovy sociální stránky
  a statistik dodává Status přes explicitní kompozici. Závislosti jsou
  acyklické: Accounts → Security, Publishing → Accounts, Users → Publishing,
  Status → Users/Publishing. Produktové politiky zůstávají v Composition.
- Stock URL, textové chování, viditelnost, query budgets, média a transakce
  zůstávají kompatibilní. Publikované migrace se nemění. Prázdné registrační
  metody servisních modulů nezavádějí nové endpointy bez produktového důvodu.
- Izolovaný integrační scénář musí skutečně instalovat, publikovat, číst,
  stránkovat, upravit a smazat obsah bez sociálních zdrojů a tabulek;
  ověří rollback a výběr vlastníků. Stock sestavu ověří závěrečný preflight.
- Blogové stránky a jejich produktové endpointy zůstávají celou etapou C;
  distribuce a řízené změny sestavy etapou D.

### Etapa C: hranice dokončení

- Explicitní blogová sestava Security/Accounts/Publishing/Blog používá stejný
  výběr pro bootstrap, překlady, UI a assety. Výchozí sociální výběr se nemění.
- Blog vlastní titulek a dlouhý prostý text jako specializaci publikace;
  Publishing vlastní autora, datum, viditelnost a náhled. Zápis je atomický.
- Veřejný archiv/detail, chráněný editor a kanonické API musí projít skutečným
  HTTP vstupem bez fyzických zdrojů sociálních modulů, Settings a Site.
- Fresh vývojová instalace odmítá neprázdnou DB. Blog migrace používají
  existující ModuleSchemas: opakování, checksumy a obnovení stavu před prvním
  DDL ověří SQLite i MySQL. Publikované globální migrace se nemění.
- Vývojová sestava není podporovaná konverze ani distribuční varianta.
  Blog balíček build odmítne do dokončení D. Instalační průvodce, konfigurátor
  doručování a podporovaný produktový upgrade nejsou rozsahem C.

### Etapa D: hranice dokončení

- Podepsaná distribuce deklaruje produkt, dostupné moduly, jejich závislosti,
  schémata a výchozí aktivní výběr. Balí se pouze zdroje dostupných vlastníků.
  Runtime, build, překlady a assety používají tentýž výběr; lokální deaktivace
  nesmí znovu aktivovat PHP, úlohy nebo staré compiled katalogy.
- Produkční CLI používá stejné služby instalace, migrací, podpisů, záloh
  a obnovy jako updater. Instalace a změny aktivace jsou explicitní operace,
  bootstrap nikdy neprovádí DDL. Deaktivace zachovává data; blokují ji aktivní
  závislí a moduly vyžadované produktovou sestavou.
- Sociální produkt zachová publikovanou globální historii a update z 5.16.0.
  Nové produkty mají vlastní modulovou historii včetně Accounts, Publishing
  a Security. Podpis a produktová kompatibilita se ověřují před změnami.
- Nový modulový formát vyžaduje runtime 6.0.0-dev.1 nebo novější, aby jej
  nemohl aplikovat starý updater bez spuštění potřebných migrací. Tato etapa
  přechází do vývojové verze; commit ani veřejné vydání nejsou automatické.
- Přerušení zanechá údržbu a dohledatelnou zálohu. Ověří se obnova souborů,
  databáze i aktivního výběru, včetně nových tabulek a neaktivních modulů.
  Převod sociální databáze na blog a destruktivní odinstalace nejsou podporované
  automatické operace.
- Matice pokryje fresh blog/manifest modul, update stejného produktu, odmítnutí
  jiného produktu, aktivaci/deaktivaci, aktualizaci neaktivního modulu, chybu
  migrace, přerušení a rollback. Nakonec proběhne composer preflight.

Etapy B–D se před zahájením zpřesní podle výsledku předchozí etapy. Jejich
hranice zůstávají výše: sociální funkce a Publishing, funkční blogová sestava,
a nakonec distribuce s lifecycle. Přesun souborů ani test samotného katalogu
nejsou splněním jejich akceptace.

| Etapa | Výstup | Podmínka dokončení | Stav |
| --- | --- | --- | --- |
| 1. Katalog a legacy adaptér | Stabilní ID dnešních modulů, čisté definice, validátor manifestů, deterministické řešení závislostí; bootstrap přes adaptér. | Offline katalog, negativní testy metadat/závislostí/cest, zachované routy a vlastnictví tabulek; preflight. | Dokončeno; omezení lokálního ověření níže. |
| 2. Společný výběr zdrojů | Překlady, komponenty a assety vybrané katalogem včetně explicitních technických zdrojů. | Stejné výstupy sociální sestavy; vyřazený testovací modul zmizí ze všech zdrojů; žádné nezávislé zapínání globem. | Dokončeno; preflight 73 skupin. |
| 3. Registry a start jádra | Předaná identita/připravenost, oddělení Config/Settings, registrované úlohy, navigace a health. | Načtení registrací bez SQL/session/efektů; konzumenti registrů neznají doménové seznamy; minimální technická sestava. | Dokončeno; preflight 74 skupin. |
| 4. Svislý pilot | Modul s vlastním schématem, API, překladem, assetem a úlohou přes skutečné registrační kontrakty. | Přidání jen adresáře a výběru sestavy; test fresh/upgrade schématu, závislostí a deaktivace bez ztráty dat. | Dokončeno; 75 skupin ověřeno ve dvou bězích. |
| 5. Oddělení produktu | Accounts bez sociálních vztahů; Publishing a blogová sestava bez povinného sociálního obsahu. | Zachované sociální chování, URL a query budgets; blog bez sledování a botích tabulek; jasní vlastníci zápisů. | Dokončeno etapami A–C; distribuční varianty a lifecycle čekají na D. |
| 6. Distribuce a lifecycle | Evidence nainstalovaných/aktivních modulů, řízené změny sestavy, kompatibilní migrace a podpisy. | Matice instalace/upgradu/rollbacku, neaktivní moduly a přerušení; ochrana před nesprávnou variantou balíčku. | Čeká. |

## Rozsah prvního řezu

- `App/modules.php` zůstane jediným explicitním výběrem vestavěných modulů,
  nyní jako mapa stabilní ID → dnešní PHP třída. ID zatím odpovídají dnešním
  vlastníkům (`tinycat.users`, `tinycat.status`), nikoli dosud neodděleným Accounts/Publishing.
- `App/Core/Modules/` poskytne čisté definice a katalog. Nové manifesty formátu 1
  lze načíst a validovat offline; automatická aktivace PHP poskytovatelů zatím
  není implementovaná. Neexistuje instalační UI pro externí moduly.
- Legacy adaptér přemostí dnešní `Module`/`StorageModule`. Zkontroluje vlastnictví
  tabulek před registrací rout a zachová původní registrační pořadí.
- Graf nových deklarací bude acyklický a řazený stabilně. Dnešním legacy modulům
  se nebudou přisuzovat vymyšlené `requires`: jejich současné cykly se řeší
  v dalších etapách. Prázdné deklarace nejsou potvrzením izolovanosti domén.
- Žádné změny schématu, migrací, doménových operací ani formátu aktualizací.
  Připojení překladů a frontendu je samostatný druhý řez popsaný níže.

## Ověření a předání

Nová testovací skupina pokryje neznámé/duplicitní ID, chybějící a cyklické
závislosti, stabilní pořadí, chybný manifest, únik cest a nezaregistrované
moduly. Offline test musí prokázat, že se nenačte konfigurace, PDO ani doménové
třídy. Regrese legacy adaptéru ověří pořadí a duplicity tabulek.

Po cílených testech proběhne `composer preflight`, protože se mění bootstrap
celé aplikace. Výsledek a případné přeskočené integrace se zapíší sem. Commit
a nové vydání nejsou automatickou součástí každé etapy; verze se nemění při
rozpracovaném architektonickém řezu.

### Výsledek první etapy z 11. 11. 2026

Implementované třídy: [Definition](../../App/Core/Modules/Definition.php),
[Catalog](../../App/Core/Modules/Catalog.php) a
[LegacyModules](../../App/Composition/LegacyModules.php). Bootstrap používá
adaptér; nové jádro nesmí podle tokenové kontroly importovat kód mimo `Core`.
Veřejný formát manifestu se zatím ověřuje jako metadata. Načítání poskytovatelů,
jejich registrační rozhraní a nové autoload mapy jsou součástí dalších řezů.

- `composer preflight` prošel: 72 skupin včetně 38 frontendových scénářů,
  5 349 API kontrol, veřejných rout, query budgets a MySQL testů.
- Nová skupina katalogu prošla 75 kontrolami: offline načtení bez domén,
  konfigurace a DB vrstvy, deterministické závislosti, neplatná metadata,
  hranice cest, původní pořadí legacy registrací a vlastnictví tabulek.
- Prošly oba podepsané testovací balíčky (854 update / 826 install runtime
  souborů), upgrade z 5.16.0, opakování a přerušení aktualizace, přesný rollback
  a fresh-install rehearsal. MySQL instalátor prošel 65 kontrolami.
- Memcached integrace se přeskočila kvůli nedostupnému lokálnímu serveru.
  Symlink fixture se přeskočila kvůli nemožnosti vytvořit symlink na tomto
  Windows. Doplňkový příkaz pro Windows junction byl automatickou kontrolou
  odmítnut jako „blocked by policy“; tato varianta nebyla ověřena.
- Windows úklid některých dočasných release adresářů vypsal „Directory not
  empty“. Nešlo o selhání kontrol; nejde ani o tvrzení, že nezůstaly žádné
  dočasné soubory. Log běhu je v ignorovaném `storage/modular-platform-preflight.log`.

Nezměnily se databázové migrace, API adresy, doménové operace ani výběr
frontendových zdrojů. Legacy moduly stále obsahují původní vazby; zelený
katalog neprokazuje jejich izolovanost ani hotovou možnost je vypínat.

## Vstupy druhé etapy

Při kontrole 5.22.2 mají katalogy navíc technické vlastníky `App/Support/lang`
a `App/Mail/lang`; Mail používá vlastní mailový katalog, neslučuje se slepě
do běžného slovníku. Assety mají navíc `assets/ui` a `assets/modules/admin`.
Installation naopak nemá vlastní `App/Installation/lang`. Mapování proto
musí explicitně zachytit technické zdroje, ne předpokládat vztah 1:1 mezi
backendovou třídou a každým jazykovým/frontendovým adresářem.

Před přepojením zachytit inventář a hashe zdrojů i sestavených výstupů. Shoda
se ověří u všech scopes a jazyků; v izolované sestavě se navíc doloží vyřazení
nevybraného modulu. Samotný přesun globu do nové pomocné třídy tuto etapu neuzavírá.

### Provedený druhý řez

- [Resources](../../App/Composition/Resources.php) sdílí katalog bootstrapu
  se čtením překladů, komponent, kontrolami deklarací a kompilátorem assetů.
  Legacy adaptér deklaruje 13 backendových a 4 technické definice. Žádný nový
  adresář se sám neaktivuje.
- Překlady používají glob pouze uvnitř vybraného vlastníka. Mail zůstává
  oddělený a má explicitně vybraný zdroj i fallback.
- In-process cache nesou revizi výběru. Vnořené výběry i výjimky obnovují
  rodiče; jiná sestava nepřebírá kompilované cache původního nasazení.
  Persistentní build cache se nezavádí.
- AssetGraph odmítá zdroje nevybraných modulů i jejich nepřímé JS importy.
  CSS načítá pouze vybrané položky historické kaskády; nové styly připojuje
  podle deklarací. Vnořené CSS `@import` se odmítá, závislosti patří do manifestu.
- Srovnání se stavem před druhou etapou potvrdilo přesnou shodu všech 165
  generovaných souborů, 193 vstupních manifestů a katalogů, devíti scopes
  a dvanácti jazyků.
  Inventář a hashe jsou v ignorovaném `storage/module-resources-baseline.json`.
- Nová skupina `tests/quality/module-resources.php` prošla 52 cílenými
  kontrolami včetně vlastního JS/CSS, katalogu a komponent, starých kompilovaných
  cache, vyřazení zdrojů Bots, výjimek a návratu k přesně shodnému buildu.

První úplný běh odhalil starý testovací setup nahrazující slovníky přímo
v cache bez resetu revize výběru. `Localization::reset()` nyní resetuje data
v kontextu aktuálního katalogu a fixture reset výslovně používá. Následně
prošlo 32 lokalizačních, 137 zdrojových a 161 kompilovaných překladových kontrol.
Závěrečný úplný `composer preflight` prošel: 73 skupin včetně 38 frontendových
scénářů, 5 349 API kontrol, obou podepsaných balíčků (855 update / 827 install
runtime souborů), aktualizace z 5.16.0, přesného rollbacku a MySQL instalátoru
(65 kontrol). Log je v ignorovaném `storage/module-resources-preflight-final.log`.
Memcached se přeskočil kvůli nedostupnému serveru a symlink fixture kvůli
nemožnosti vytvoření symlinku. Opakovala se známá Windows varování při úklidu
některých dočasných release adresářů; kontroly prošly.

Tento řez neposkytuje produkční lifecycle modulů. Nové profily se nenabízejí
v administraci, tabulky se neodinstalovávají a legacy závislosti domén nejsou
označené za vyřešené. Nový externí modul stále vyžaduje další registrační
kontrakty a autoload mapu z následujících etap.

## Třetí etapa: start a registry

[Context](../../App/Core/Runtime/Context.php) a hodnotová konfigurace oddělují
technický start od konkrétního produktu. [Application](../../App/Composition/Application.php)
přijímá identitu, připravenost a locale jako odložené resolvery a předává
konfiguraci technickým službám. [BuiltInApplication](../../App/Composition/BuiltInApplication.php)
zachovává dnešní Users a Settings politiky. Cache i překladač přestaly
importovat Settings; databázový jazykový překryv zůstává explicitní politikou
dnešní sestavy.

Moduly přes `ProvidesContributions` poskytují typované `ScheduledTask`,
`MaintenanceTask`, `NavigationItem` a `HealthProbe`. Registry kontrolují
jedinečnost ID v každé kategorii, vlastníka a stabilní pořadí. Sběr metadat
nevyhodnocuje callbacky, nevykresluje texty a neregistruje HTTP routy.
Konzumenti používají stejný výběr modulů a při jeho změně obnoví své registry.

Parametry importu včetně administrator-only `source_id` a jeho validaci
vlastní Bots. Health sleduje intervaly dodané úlohami; bez Bots nečte jeho
tabulky. Navigaci přispívá každý vlastník a labely překládá až při renderu.
Úklidové tabulky a podmínky se přesunuly k vlastníkům dat; `Database/Retention`
je pouze technický adaptér pro omezené smazání a kontrolu dalších řádků.
Scheduler drží historii, střídání a dávkování, nikoli seznam cizích tabulek.
Zachovaly se původní identifikátory, pořadí, retenční lhůty a počáteční kurzor.

### Ověření třetí etapy

- Nová skupina `tests/quality/runtime-registries.php` prošla 38 cílenými
  kontrolami včetně kolizí, pořadí, vlastníků, vyřazení, změny jazyka,
  parametrů nové testovací úlohy a scheduler diagnostiky bez botích tabulek.
- Samostatný proces minimálního startu prošel 19 kontrolami: konfigurovaná
  SQLite v paměti, prázdné schéma, vlastní routa a předaná identita/připravenost.
  Nenačetl místní `config.php`, Users, Status, Bots, Settings ani session.
- Prošlo 56 kontrol úklidu souborů, 116 retenčních a 24 health kontrol,
  PHPStan a styl. Všech 165 generovaných souborů i 193 vstupních manifestů
  a katalogů se stále shoduje s výchozím stavem druhé etapy.
- Úplný `composer preflight` prošel: 74 skupin včetně 38 frontendových
  scénářů, 5 349 API kontrol, obou podepsaných balíčků (870 update / 842 install
  runtime souborů), aktualizace z 5.16.0, přesného rollbacku a MySQL instalátoru
  (65 kontrol). Log je v ignorovaném `storage/runtime-registries-preflight.log`.
  Memcached se přeskočil kvůli nedostupnému serveru, symlink fixture kvůli
  nemožnosti vytvořit symlink. Známá Windows varování při úklidu dočasných
  release adresářů nezpůsobila selhání kontrol.

### Hranice hotového řezu

Minimální technický start není nová blogová či obchodní distribuční sestava.
Kořenový `index.php` stále obsluhuje dnešní produkt. Zachované prezentační
integrace, například zdrojové detaily Bots v administraci historie scheduleru,
nejsou vydávané za univerzální API jádra. Jejich oddělení patří do integračních
řezů páté etapy. JSON poskytovatelé, vlastní autoload, modulová instalační
schémata a kompletní lifecycle jsou nadále následující etapy.

## Čtvrtá etapa: svislý pilot

Implementován 11. 11. 2026. Explicitní výběr přijímá projektovou cestu
k manifestu. `ManifestModules` aktivuje rozhraní `routes`, `contributions`
a `schema`; `ModuleAutoload` mapuje pouze vybrané namespace do jejich `src/`.
Ostatní druhy poskytovatelů se odmítají. Nový modul nepotřebuje úpravu
centrálních seznamů rout, úloh, překladů ani assetů.

[Notes fixture](../../tests/fixtures/modules/notes/README.md) vlastní tabulku,
aktuální schéma verze 2, dvě migrace, JSON API, serverovou stránku se sdílenou
komponentou, JS/CSS, překlady a dávkovou úlohu. Výchozí produkt ji neaktivuje.
Společné API poskytuje autorizovanou identitu a skládá podmínky připravenosti;
nepřipravené modulové schéma vrací 503 bez automatického DDL.

`Database/ModuleSchemas` odděluje aktuální instalaci od historických kroků,
ověřuje checksumy a eviduje verzi/stav. Instalační operace je explicitní;
standardní instalátor ji volá pouze pro vybraná modulová schémata. SQLite
vrací DDL při chybě transakčně. MySQL používá modulový zámek a stav `failed`,
ale jeho DDL nelze vydávat za transakční rollback.

### Ověření čtvrté etapy

- `tests/quality/module-pilot.php`: 55 cílených kontrol. Fresh/upgrade na
  SQLite i MySQL zachovává data a dosahuje stejného schématu; ověřeno opakování,
  odmítnutí změněné historie, downgradu a kolizí i selhání SQLite migrace.
- Skutečné HTTP ověřuje 401/403/422/503, společné CSRF a identitu vlastníka
  odolnou proti podvržení vstupem; stránka escapuje obsah. Úloha běží přes
  existující scheduler a zapisuje stabilní historii. Vyřazení v novém procesu
  odstraní routy, autoload a úlohy, zachová řádky i historii schématu.
- Modulové assety procházejí stejnou kontrolou modalového kontraktu.
  Výchozích 165 generovaných souborů a 193 vstupů se shoduje s baseline.
  PHPStan a styl prošly.
- Celkový preflight prošel kontrolami metadat, závislostí, stylu, překladů,
  lintu, PHPStan a security a prvními 72 z 75 testovacích skupin, včetně
  38 frontendových scénářů, 5 349 API kontrol a podepsaných balíčků
  (876 update / 848 install runtime souborů). Běh skončil bez závěrečného
  výsledku u update rehearsal. Poslední tři skupiny následně prošly samostatně:
  podepsaný upgrade z 5.16.0, opakování a obnova po přerušení, přesný rollback,
  instalační API (22 kontrol) a restart-safe MySQL instalátor (65 kontrol).
  Všech 75 skupin je tedy pokryto, nejde o jeden dokončený běh preflightu.
  Logy: `storage/module-pilot-preflight.log` a
  `storage/module-pilot-release-final.log`. Po poslední úpravě ochrany balíčků
  byl cílený pilot zopakován: 55 kontrol prošlo.
- Memcached integrace se přeskočila kvůli nedostupnému serveru a symlink
  fixture kvůli nemožnosti vytvořit symlink na Windows. Známá varování
  `Directory not empty` při úklidu release fixture nezpůsobila selhání kontrol.

### Zbývající hranice

Pilot nepřesouvá tabulky třinácti legacy modulů ani nedokončuje oddělení
Accounts/Publishing. Produkční blogová sestava patří do páté etapy.
Publikování jakékoli sestavy s JSON manifestem build odmítá, dokud šestá etapa
nevyřeší modulové zdrojové cesty, migrační export, podpisy variant a rollback.
Dosavadní distribuční cesta sociální sestavy zůstává podporovaná.

## Pátá etapa: oddělení produktu po ověřitelných řezech

Kontrola kódu 11. 11. 2026 potvrdila několik různých vazeb, které nelze vyřešit
přejmenováním složek:

- `Users/Accounts` při odstranění účtu přímo četl `content_tags`,
  `content_links` a `content_images` a volal úklid Status. Při deaktivaci
  botího účtu přímo měnil `bot_sources`.
- `Users/Module` současně vlastní účty, `user_followers`, veřejného autora
  a jeho feed. `Users/Api` registruje správu účtu i sledování.
- `Users/Pages::author()` skládá příspěvky, editor, sociální statistiky
  a sledování. `Users/Profiles` obsahuje i sociální operace a URL feedu.
- `Status` dnes zahrnuje publikování, reakce, komentáře a sociální feed.
  Instalační schéma těchto legacy domén je dosud společné.

Pořadí implementace:

| Řez | Rozsah | Důkaz dokončení |
| --- | --- | --- |
| 5a. Životní cyklus účtu — dokončeno | Samostatné zápisy účtu a vybrané integrace vlastníků dat. | Účty fungují bez sociálních tabulek; zachované mazání a deaktivace v sociální sestavě, rollback a úklid souborů po commitu. |
| 5b. Identita a sociální vztahy | Oddělit přihlášení, oprávnění a správu účtů od sledování, statistik a veřejného autora. | Účetní API a autentizace bez `user_followers`, Status a Bots; kompatibilní sociální URL a oprávnění. |
| 5c. Publishing | Vymezit publikační zápisy a čtení bez povinných reakcí, komentářů a following feedu. | Stejná pravidla textu a viditelnosti, stabilní URL a query budgets. |
| 5d. Blogová sestava | Explicitní výběr, vlastní skládání stránek a instalační schéma. | Fresh a upgrade bez botích a sledovacích tabulek; čtení, publikování a správa účtu ve skutečném HTTP. |

### Provedený řez 5a

[Accounts/Lifecycle](../../App/Accounts/Lifecycle.php) vlastní transakční
změnu stavu a odstranění řádku účtu. Závisí pouze na Accounts a Database,
neobjevuje moduly a nepřistupuje k sociálním tabulkám. Účastníky dostává
explicitně od volajícího; nejde ještě o úplný samostatný Accounts modul.

Doménové rozhraní [ProvidesLifecycle](../../App/Accounts/ProvidesLifecycle.php)
vrací čistou definici `Participant`. Sestava sbírá účastníky z již vybraných
legacy modulů nebo poskytovatele `contributions` manifestu, v pořadí katalogu.
Nový účastník nepotřebuje centrální seznam domén ani nový druh poskytovatele.
Tento kontrakt patří Accounts, nikoli technickému Core.

Status vlastní přípravu a úklid publikačních dat; Bots reakci zdrojů na zákaz
botího účtu a Users úklid avataru. `Users/Accounts` zachovává staré vstupy
a ochranu hlavního administrátora, konkrétní zápis deleguje nové službě.
Příprava mazání i databázový úklid běží v jedné transakci, soubory až po
commitu. Mazání odmítá vnější otevřenou transakci, jejíž commit nemůže řídit.
Selhání úklidu souboru po commitu nevrací databázové smazání; trvalá fronta
obnovy souborových efektů není součástí tohoto řezu.

Deaktivace modulů ani odstranění jejich schémat není produkční lifecycle.
Výběr vlastníků se nesmí zaměňovat za kompletní obsluhu nainstalovaných
neaktivních modulů; ta zůstává součástí šesté etapy.

### Ověření 5a

`tests/domains/users/account-lifecycle.php` prošel 39 kontrolami: čisté
definice, vybraní vlastníci, minimální schéma účtů, integrační callback
manifestového modulu, zachování sdílených termů/odkazů, rollback a hranice
commitu. Skutečné avatarové a publikační soubory v kopii aplikace zůstávají
zachované při SQL chybě a odstraní se po úspěšném commitu.
Cíleně prošly dependency boundaries, runtime registry, PHPStan a styl.
Úplný `composer preflight` prošel v jediném běhu: 76 skupin včetně 38
frontendových scénářů, 5 349 API kontrol, 55 kontrol manifestového pilotu,
obou podepsaných balíčků (882 update / 854 install runtime souborů), aktualizace
z 5.16.0, opakování a obnovy po přerušení, přesného rollbacku, instalačního API
(22 kontrol) a MySQL instalátoru (65 kontrol). Log je v ignorovaném
`storage/account-lifecycle-preflight.log`.

Všech 165 generovaných souborů a 193 zdrojů má stejné hashe jako baseline.
Publikované migrace ani verze aplikace se nezměnily. Memcached se přeskočil
kvůli nedostupnému serveru a symlink fixture kvůli nemožnosti vytvořit symlink
na Windows. Řez 5a je dokončený; 5b–5d a celá pátá etapa zůstávají otevřené.

### Řez 5b: identita bez sociálních domén

První část provedena 11. 11. 2026. `Accounts/Authentication` vlastní původní
implementaci identity, session, ověřování hesel, přihlašování a zapamatování
přihlášení. `Accounts/Access` vlastní přístupové kontroly a `Accounts/Readiness`
připravenost účtů. `BuiltInApplication` je zapojuje přímo, stejně jako ostatní
produkční konzumenti. Nová identita neimportuje Users ani sociální domény.

Přihlašovací a administrační schopnosti rolí mají jediného vlastníka
`Accounts/Roles`. Users dále vlastní sociální schopnosti a jejich prezentaci;
staré dotazy na přihlašovací schopnosti deleguje. Neznámé role ani Bots
nezískávají právo přihlášení. Veřejná jména, cookie formát, session klíče,
CSRF, přístupové kontroly a přihlašovací URL jsou zachované.

Staré `Users/Authentication` a `Users/Access` jsou tenké delegující adaptéry.
Mají stejnou identitu a reset jako nový vlastník. Nevzniká paralelní cache
nebo kopie přihlašovací implementace. Databázové schéma ani historické migrace
se tímto přesunem nemění.

`tests/domains/accounts/identity.php` prošel 47 kontrolami nad tabulkou
`users` bez sociálního schématu. Samostatný HTTP worker ověřuje session,
CSRF, rotaci session ID, jméno/e-mail, role, remember cookie, kompatibilitu
starého formátu, expiraci, změnu hesla, zákaz účtu a odhlášení. Současně
prokazuje, že nenačetl Users, Status, Bots, Settings ani Notifications.
Další kontroly ověřují společný stav kompatibilních vstupů.

Úplný `composer preflight` prošel: 77 skupin včetně 38 frontendových scénářů,
5 349 API kontrol, 47 kontrol samostatné identity, 39 kontrol životního cyklu
a 55 kontrol manifestového pilotu. Prošly oba podepsané balíčky
(886 update / 858 install runtime souborů), upgrade z 5.16.0, opakování,
obnova po přerušení a přesný rollback, instalační API (22 kontrol)
a MySQL instalátor (65 kontrol). Log: `storage/account-identity-preflight.log`.
Memcached a symlink fixture zůstaly přeskočené kvůli stejným omezením prostředí
jako v předchozím řezu.

Srovnání s původními zdroji potvrdilo, že Authentication a Access zachovávají
přesnou implementaci kromě namespace a odkazů na nového vlastníka.
Všech 165 generovaných souborů a 193 vstupů má stejné hashe jako baseline.
Publikované migrace a verze se nezměnily.

Po prvním neúspěšném testu zůstala izolovaná složka
`storage/account-identity-323d3f3a3af1726c`. Automatická schvalovací kontrola
odmítla její dodatečné odstranění s důvodem `blocked by policy`.
Úspěšné běhy vlastní fixture uklidily.

Zbývá oddělit registraci, obnovu hesla, správu profilu, sledování a veřejného
autora včetně jejich UI zdrojů a vlastnictví schématu. Řez 5b ani pátá etapa
proto nejsou označené za dokončené. Samostatný přihlašovací test není hotová
blogová distribuční sestava.

### Řez 5b: registrace a obnova hesla

Proveden 11. 11. 2026. `Accounts/Registration` vlastní validaci registračních
hodnot a zápis účtu; schválení a locale jsou explicitní vstupy sestavy.
`Accounts/Recovery` vlastní tokeny a transakční změnu hesla, doručení přijímá
od volajícího. `Accounts/Usernames`, `Email` a `Passwords` poskytují jedinou
implementaci identifikátorů a heslových pravidel. Staré metody Users delegují.
Kanonická autentizace nově vynucuje stejný limit délky hesla jako login formulář.

`Composition/BuiltInAccounts` zapojuje Settings, locale, URL a existující
mailové šablony. Úspěšná registrace má stejnou roli, stav, výchozí preference,
welcome zprávu a navazující přihlášení. API zachovává CAPTCHA, rate limits,
CSRF a společné chyby. Doména neaktivuje doručování sama a nezískává druhou
konfiguraci nebo souběžnou implementaci těchto služeb.

Samostatný test odhalil transitivní závislost `Support/Dates` na Settings.
Služba nyní používá předanou politiku z `Core/Runtime/Context` nebo čistou
konfiguraci. `BuiltInApplication` zachovává dosavadní Settings politiku;
čtení je odložené. Dependency gate chrání i tuto technickou hranici.

`tests/domains/accounts/onboarding.php` prošel 54 kontrolami nad tabulkami
`users` a `password_reset_tokens`: společná validace, schválení, ochrana role,
hashování hesla/tokenů, výměna tokenu, expirace, replay, zákaz účtu a rollback
při SQL chybě. Prokazuje běh bez Users, Settings, Mail, Site, Status a Bots;
doručení nahrazuje callback. Ověřuje datumovou politiku a staré adaptéry.
Cíleně prošlo také 47 kontrol identity, 40 kontrol uživatelských jmen,
38 registrů, 19 minimálního startu, 32 lokalizace, PHPStan a styl.

Úplný `composer preflight` prošel: 78 skupin včetně 38 frontendových scénářů,
5 349 API kontrol, samostatných účtových testů (54 onboarding / 47 identita /
39 životní cyklus) a 55 kontrol manifestového pilotu. Prošly oba podepsané
balíčky (892 update / 864 install runtime souborů), update z 5.16.0,
opakování a obnova po přerušení, přesný rollback, instalační API (22 kontrol)
a MySQL instalátor (65 kontrol). Log: `storage/account-onboarding-preflight.log`.
Memcached a symlink fixture se přeskočily kvůli stejným omezením prostředí
jako v předchozích řezech.

Srovnání potvrdilo stejné hashe všech 165 generovaných souborů a 193 vstupů.
Verze, schéma a publikované migrace se nezměnily. Doménová část registrace
a obnovy je dokončená; HTTP/UI integrace zůstává v Users.

Accounts zatím nemá vlastní zaregistrovaný modul, HTTP/UI zdroje, překladové
katalogy ani samostatné instalační schéma. Zbývá správa profilu, oddělení
sledování a veřejného autora a následné rozdělení UI a datových vlastníků.
Pátá etapa proto zůstává otevřená.

### Řez 5b: změny profilu, e-mailu a vlastního hesla

Proveden 11. 11. 2026. `Accounts/Profile` vlastní oprávnění a normalizaci
profilových zápisů, e-mailové preference a změnu vlastního hesla. Přijímá
důvěryhodnou identitu jako vstup a neimportuje Users, HTTP, Settings ani
sociální domény. Podporovaná locale předává sestava. Role, jméno, stav
a další atributy se při profilové aktualizaci nepřebírají z payloadu.

`Users/Actions` zachovává HTTP vstup, dosavadní chyby a public resources.
Interní `Accounts/ValidationException` nese klíče zpráv; překlad a obálka
API zůstávají ve společném transportu. Změnu locale aktuálního requestu
provádí adaptér pouze při editaci vlastního účtu. Pomocné metody oprávnění
a theme v Users delegují společným pravidlům Accounts.

Změna vlastního hesla v doméně vyžaduje vlastníka a současné heslo.
Podmíněný SQL zápis porovnává původní hash a odmítá zastaralé údaje po
souběžné změně. Administrativní reset zůstává původním samostatným postupem.
Avatarové soubory, sociální profil a administrativní role/stavy se nepřesouvají.

`tests/domains/accounts/profile.php` prošel 45 kontrolami nad jedinou
tabulkou `users`: oprávnění, ochrana proti podvrženým polím, částečné změny
a null hodnoty, Unicode bio, locale, notifikace, validační chyby, SQL selhání
a stale-password zápis. Ověřuje také, že doména nepřepne locale a nenačte
HTTP request/response/session, Users ani sociální služby. Prošly také
54 kontroly registrace/obnovy, 47 identity, PHPStan, styl a hranice závislostí.

Úplný `composer preflight` prošel: 79 skupin včetně 38 frontendových scénářů,
5 349 API kontrol, 45 profilových kontrol a předchozích účtových/pilotních
regresí. Prošly oba podepsané balíčky (894 update / 866 install runtime
souborů), update z 5.16.0, opakování a obnova po přerušení, přesný rollback,
instalační API (22 kontrol) a MySQL instalátor (65 kontrol).
Log: `storage/account-profile-preflight.log`. Memcached a symlink fixture
se přeskočily kvůli stejným omezením prostředí jako v předchozích řezech.

Všech 165 generovaných souborů a 193 vstupů má stejné hashe jako baseline.
Schéma, publikované migrace a verze zůstaly beze změny. Servisní řez
profilových změn je dokončený; zachovaná HTTP/UI integrace je v Users.

Zbývá modulové vlastnictví UI a překladů, oddělení sociálních vztahů
a veřejného autora, administrativní správa a samostatné instalační schéma.
Pátá etapa zůstává otevřená.

### Řez 5b: administrativní zápisy a příprava oddělení UI

Proveden 11. 11. 2026. `Accounts/Administration` vlastní vytvoření a změnu
spravovaných účtů, administrativní reset hesla, role/stavy a ochranu nejstaršího
administrátora. Přijímá důvěryhodnou identitu explicitně. Samostatné zápisy
nepotřebují HTTP, Users ani sociální tabulky. Read modely a resources zůstávají
v Users; nejde ještě o přesun celé administrace do nového modulu.

Zachovaná avatarová vrstva vlastní zámek, souborovou zálohu, transakci a obnovu.
Nový volitelný callback volá `Administration::persist()` uvnitř její transakce.
Připravená data se zde znovu omezí a ověří proti aktuálnímu zamčenému účtu.
Role a stav hlavního administrátora nelze změnit ani dříve připraveným payloadem.
Původní botí callbacky mají stejnou cestu jako dříve. Není přidána druhá
implementace avatarového úložiště ani vnější transakce kolem výměny souboru.

`primaryId()` používá dosavadní pořadí `created_at, id`; mutace vždy čtou
aktuální databázový stav. Starý cachovaný helper zůstává jen pro kompatibilní
vykreslování. Deaktivace používá aktuální zamčený řádek. Mazání nejprve zamkne
účet a ověří ochranu v transakci před ostatními účastníky životního cyklu.

`tests/domains/accounts/administration.php` prošel 52 kontrolami: oprávnění,
vytvoření a reset, validace, povolená pole, chybějící transakce, SQL rollback,
změna chráněné identity a odmítnutí zastaralé přípravy. Prokazuje běh bez Users,
avatarů, Settings, HTTP a sociálních služeb nad jedinou tabulkou účtů.
Prošly i předchozí lifecycle kontroly, PHPStan, styl a hranice závislostí.

Úplný `composer preflight` prošel: 80 skupin včetně 38 frontendových scénářů,
5 349 API kontrol a 52 administrativních kontrol. Prošly oba podepsané
balíčky (895 update / 867 install runtime souborů), update z 5.16.0,
opakování a obnova po přerušení, přesný rollback, instalační API (22 kontrol)
a MySQL instalátor (65 kontrol). Log: `storage/account-administration-preflight.log`.
Memcached a symlink fixture se přeskočily kvůli stejným omezením prostředí
jako v předchozích řezech.

Po doplnění API regrese vícebajtového hesla a parametrizované chybové zprávy
prošla API sada znovu: 5 356 kontrol. Při prvním opakování se odhalila časová
chyba testu e-mailového limiteru: test naplnil bucket `10:29`, požadavek už
spotřeboval nový `10:30`. Fixture nyní naplní pozorované a dvě následující
okna, takže zachová kontrolu limitu i na hranici minuty. Produkční limiter
se neměnil. Finální log: `storage/account-administration-api-final.log`.
PHPStan, styl a dependency gate prošly také po posledních změnách.
Neúspěšná Windows API fixture zůstala v ignorovaném storage kvůli hlášenému
SQLite file locku při úklidu; finální běh prošel.

Srovnání potvrdilo stejné hashe všech 165 generovaných souborů a 193 vstupů.
Verze, schéma a publikované migrace se nezměnily. Administrativní servisní
řez je dokončený; přesun UI a jeho samostatné modulové vlastnictví je další práce.

#### Zjištění a pořadí přesunu UI

Aktuální zdroje byly zkontrolované před přesunem:

| Zdroj | Současné smíchání | Navazující řez |
| --- | --- | --- |
| `assets/modules/users/index.js` | Menu, controller, avatar, e-mailové kontroly a registrace seznamu účtů v jednom vstupu. | Oddělit účtový vstup od sociálního; zachovat jedinou registraci loaderů a hydratační lifecycle. |
| `assets/modules/users/public.js` | Importuje celý účtový vstup a přidává following/init. | Samostatná sociální registrace bez přebírání inicializace účtů. |
| `assets/modules/users/components.json` | Osm definic: sedm účtových/admin/avatarových a `users/following-profile`. | Rozdělit vlastníky se zachováním veřejných component ID a všech volajících. |
| `App/Users/templates` a Pages | Login/register/recovery/logout a follow button; author stránka skládá Status a following. | Účtové stránky oddělit od veřejného autora. |
| `App/Users/lang` | Dvanáct katalogů společně vlastní namespaces `auth`, `account`, `users`. | Přesunout účtové texty v paritě všech jazyků, klientských deklarací a plurálů. |
| Avatar služby a `Users/Resource` | Obecná identita/avatar i veřejný autor pod jedním vlastníkem. | Nejdřív oddělit sdílené účtové resources a avatarové služby od sociálního resource; nevracet interní řádky přímo. |
| `Users/Login` | Bezpečná práce s návratovou URL, ale výchozí user landing míří na autora. | Zachovat sanitizaci next a explicitně předat produktovou politiku cílové stránky. |

Před fyzickým přesunem připravit rozdělení controllerů/resources a vlastnictví
avatarů. Potom zavést explicitní modul Accounts a přesunout společně routy,
API/read modely, překlady, šablony a assetové deklarace. Users ponechá sociální
funkce; nezavádět neaktivní kopii stejného UI nebo druhou sadu JSON endpointů.
Zachovat API URL, component ID, modalový/popoverový kontrakt a CSS kaskádu.
Samostatná sestava účtů musí projít skutečným HTTP a buildem bez following,
Status a Bots. Distribuční schéma a produktové sestavy zůstávají dalšími řezy.

### Řez 5b: účtové resources a avatarové služby

Proveden 11. 11. 2026. `Accounts/Resource` vlastní identitu, profilová data,
options, autentizační payload a společný list formatter. Profilu nevymýšlí
URL autora: `Users/Resource` ji dodá a deleguje společnou serializaci.
Autentizační resource dostává transportní politiku jako vstup; sanitizace
next a sociální landing zůstávají explicitní odpovědností dnešní integrace.
Soukromé řádky DB se nesmějí předávat přímo klientovi, list formatter přijímá
již serializované položky. Existující odpovědi a API URL zůstávají kompatibilní.

`Accounts/GeneratedAvatar`, `Avatar` a `Avatars` vlastní generování, options,
URL, nahrávání, odstranění a souborové transakce. `AvatarHttp` neimportuje Site;
současná routa mu dodává původní 404 renderer. Staré PHP třídy Users jsou
tenké adaptéry. Jediný JSON options zdroj se přesunul do Accounts spolu
s frontendovým fixture readerem a API referencí. Původní cesta byla přidána
do kumulativního mazacího manifestu; migrace a uživatelská média se neměnily.

Členský upload odhalil skrytou závislost limiteru na Moderation kvůli výpočtu
časového bucketu. Jediný výpočet nyní vlastní `Security/RateLimiter`; stará
Moderation metoda deleguje. Pravidla, SQL, okna a limity zůstaly stejné.
Dependency gate zakazuje zpětný import sociálních domén z technického limiteru.

`tests/domains/accounts/avatars.php` prošel 44 kontrolami v kopii bez celého
`App/Users`. Ověřuje soukromí resources, explicitní veřejnou URL, options,
deterministické SVG, 304/cache/session chování, lookup, skutečný multipart,
CSRF, členský limiter, SQL selhání s obnovou souboru a následné odstranění.
Upload nenačetl Users, Status, Bots, Moderation, Settings ani Site.
Prošly i lifecycle kontroly, PHPStan, styl a dependency gate.

Zdrojové srovnání potvrdilo přesnou shodu generátoru kromě namespace a stejné
hodnoty JSON options. Všech 165 generovaných souborů a 193 vstupů má shodné
hashe s baseline.

První úplný běh odhalil test, který přes reflexi volal soukromé `resizeSquare`
v původní třídě Users. Odkaz byl aktualizován na skutečného vlastníka Accounts;
samotné kontroly rasteru a průhlednosti se neměnily a prošly 50 kontrolami.
Log prvního běhu: `storage/account-avatars-preflight.log`.

Následný úplný `composer preflight` prošel v jediném běhu: 81 skupin včetně
38 frontendových scénářů, 5 356 API kontrol a 44 kontrol samostatných avatarů.
Prošly podepsané balíčky (900 update / 872 install runtime souborů), update
z 5.16.0, opakování a obnova po přerušení, přesný rollback, instalační API
(22 kontrol) a MySQL instalátor (65 kontrol).
Log: `storage/account-avatars-preflight-final.log`. Memcached a symlink fixture
zůstaly přeskočené kvůli omezením lokálního prostředí.
Schema, publikované migrace a verze zůstaly beze změny.

Users zatím registruje avatarové routy, údržbu a lifecycle se stabilními ID.
Zbývá fyzické rozdělení UI/assetů, překladů, read modelů a explicitní modul
Accounts s vlastním schématem. Samostatný servisní test není hotová distribuční
sestava; pátá etapa zůstává otevřená.

### Ucelená etapa A — samostatný modul Accounts

Dokončeno a ověřeno 11. 11. 2026.

- `tinycat.accounts` je explicitní modul s technickou závislostí Security.
  Vlastní API, stránky, read modely, komponenty, klientský modul, styly,
  12 jazykových katalogů, avatarové routy a údržbu. Dosavadní URL,
  identifikátory komponent a ID registrací zůstaly zachované.
- Users registruje veřejného autora, sledování a feed; jeho staré účtové PHP
  vstupy delegují Accounts. Sociální frontend se nenačítá do administrace.
- Produktovou politiku předává `Accounts/Integration`; stock zapojení vlastní
  `Composition/BuiltInAccounts`. Samostatná sestava nevytváří sociální URL,
  používá společné komponenty a minimální shell. Obnova vyžaduje explicitní
  doručovací callback. Captcha a assety nemají přímou závislost na Settings.
- `Accounts/Schema` vlastní fresh DDL účtů a reset tokenů, `Security/Schema`
  vlastní limiter. Stock instalátor deleguje na stejné schéma. MySQL DDL
  zachovává původní definice; publikované migrace se nezměnily.
- Registry a lifecycle sbírají poskytovatele z předané sestavy, nikoli novým
  načtením výchozího seznamu modulů. Search vlastní svou challenge routu.
- Integrační test `tests/domains/accounts/module.php` prošel 385 kontrolami
  v kopii bez zdrojů Users, Status a Bots. Ověřuje skutečný build, doručení
  assetů, HTML, přihlášení, registraci, obnovu, správu, oprávnění, CSRF,
  ochranu hlavního administrátora a mazání účtu s jeho recovery záznamy.
- Cíleně prošla frontendová sada včetně nativních regresí. Kaskáda zachovala
  759 pravidel; všechny assetové limity prošly bez změny rozpočtů.
  Shodné zůstaly i všechny serverové překladové výstupy (13 souborů), scopes
  a metadata 12 jazyků proti původní baseline. Klientské bundly se mění
  záměrně podle nového rozdělení zdrojů.

Etapa A není vydáním samostatného balíčku. Publishing a blogová sestava
patří do B/C, sjednocený distribuční instalační/upgrade lifecycle do D.
Verze zůstává 5.22.2; commit ani release se v této etapě automaticky neprovádí.

Závěrečné ověření:

- První preflight zastavil test CSS scopes, který ještě očekával staré cesty
  avatar-upload a user-menu v Users. Očekávání bylo přesunuto na Accounts;
  produkční styly ani kontrakt kontroly se kvůli tomu neoslabovaly.
  Log: `storage/accounts-module-stage-preflight.log`.
- Následný `composer preflight` prošel celý: 82 skupin, všech 38 frontendových
  scénářů, 5 356 API kontrol, query budgets, oba podepsané balíčky
  (919 update / 891 install runtime souborů), update z 5.16.0, opakování,
  obnova po přerušení, přesný rollback, instalační API (22) a MySQL instalátor
  (65). Log: `storage/accounts-module-stage-preflight-final.log`.
- Memcached integrace byla přeskočena kvůli nedostupnému lokálnímu serveru;
  symlink fixture kvůli nemožnosti vytvořit symlink na tomto Windows.
- Dodatečný build samotného technického UI odhalil zděděné překladové vazby:
  `auth.forbidden` a `auth.login_required` nyní vlastní Support,
  `auth.invalid_captcha` Security. UI samo deklaruje `common.save`.
  Texty, identifikátory a scopes zůstaly stejné; přesná shoda všech 165
  generovaných výstupů byla ověřena proti stavu úplného preflightu.
- Rozšířený test Accounts prošel 395 kontrolami včetně buildu technického UI
  bez Accounts, s vybraným Security i bez něj. Po této změně prošly také
  PHPStan, styl a syntaxe testu; překladové katalogy validují všech 12 jazyků.
  Změna vlastnictví shodných textů nevyžadovala opakování celé browserové
  a aktualizační sady.


### Ucelená etapa B — Publishing a sociální hranice

Dokončeno a ověřeno 11. 11. 2026.

- Explicitní `tinycat.publishing` vlastní sedm obsahových tabulek, fresh
  schéma, přípravu textu a jediné vytvoření příspěvku, aktualizace, odstranění,
  viditelnost, kurzory, generické čtení, tagy, odkazy, metadata, média a katalogy.
  Stávající sociální API a UI zůstává produktem Status; nevzniká druhý REST
  zapisovač ani skrytě aktivované produktové endpointy.
- Pořadí závislostí je Security, Accounts, Publishing, Users a Status.
  Notifications vyžaduje Status, Bots Publishing a Status. Tyto deklarace
  jsou explicitní a acyklické. Staré servisní názvy Status sdílejí implementaci a cache přes
  kompatibilní podtřídy. Uživatelská média zachovávají původní cesty.
- Autorovu sociální stránku a statistiky vlastní Status a předává je přes
  `Users/Integration`. Doručování je volitelné přes `Status/NotificationHooks`;
  selhání souvisejícího úklidu vrací společnou transakci. Publikační politiky
  dodává `Composition/BuiltInPublishing` podle skutečně vybraného katalogu.
- Stock instalátor deleguje na Publishing/Status/Users schémata. Přesné MySQL
  definice všech 11 přesunutých tabulek byly porovnány s publikovaným stavem;
  publikované migrace zůstaly nedotčené. SQLite schémata používají integrační
  scénáře s reálnými FK, indexy a transakcemi.
- Fyzicky izolovaný test Publishing ověřuje tvorbu, čtení, filtry, stabilní
  stránkování, úpravy, rejekce, vnější transakce, rollback SQL i souborů,
  mazání účtu a build bez osmi produktových domén. Samostatný sociální scénář
  ověřuje vztahy, projekce a volitelné notifikace bez jejich tabulek.
- Cíleně prošly původní API kontrakty (5 356), query budgets, write-path testy
  (211), Accounts (395), veřejné routy a nástroje pro úpravu odkazů a thumbnailů.
  Fixture používající jen autoload nyní předávají stock politiku explicitně.
- Blogový produkt, dlouhý článkový model a distribuční lifecycle nejsou
  implementovány touto etapou; navazují C/D. Verze zůstává 5.22.2.

Závěrečné ověření etapy B:

- Celý `composer preflight` prošel: 84 skupin, 38 frontendových scénářů,
  5 356 API kontrol, query budgets, oba podepsané balíčky (959 update / 931
  install runtime souborů), update z 5.16.0, opakování, obnova po přerušení,
  přesný rollback, instalační API (22) a MySQL instalátor (65).
  Log: `storage/publishing-stage-preflight.log`.
- Factory kompatibilního `Status/PostCreation` zachovává původní typ draftu
  i nový Publishing kontrakt; chrání to nová regrese v 211 write-path kontrolách.
  Po zpřesnění factory znovu prošly PHPStan a cílený lint.
- Samostatný Publishing prošel 351 kontrolami a sociální data 332 kontrolami.
  Původní serverové katalogy (13 výstupů), scopes a metadata locale zůstaly
  přesně shodné s původní baseline.
- Po úplném běhu byla doplněna validace povinných závislostí Bots/Notifications.
  Odmítnutí neúplné sestavy ověřilo 79 kontrol katalogu. Znovu prošly registry
  (38 + 19), account lifecycle (39), sociální scénář (332), PHPStan, styl,
  dependency gate a build snapshot/příprava publikace (28). Všech 165
  generovaných výstupů zůstalo bajtově shodných se stavem úplného preflightu;
  kvůli metadatům se neopakovala celá browserová a aktualizační sada.
- Memcached integrace a symlink fixture zůstaly přeskočené kvůli nedostupnému
  lokálnímu serveru a omezením tohoto Windows. Publikované migrace se nezměnily.

Po etapě B navazují samostatné etapy C a D; aktuální výsledek C je níže.

### Ucelená etapa C — blogová sestava

Dokončeno a ověřeno 11. 11. 2026. Rozsah preflightu byl dokončen s cíleným
opakováním selhaných testů a navazujících skupin; nešlo o jeden nepřerušený
zelený běh příkazu.

- `App/assemblies/blog.php` explicitně vybírá Security, Accounts, Publishing
  a Blog. `BlogApplication` nepřipojuje sociální Site, Settings ani jejich
  politiku. Kořenový HTTP vstup větví podle stejného katalogu jako build.
  Výchozí sociální výběr zůstává zachovaný.
- `Blog/Articles` vlastní titulek a dlouhý prostý UTF-8 text v jedné tabulce;
  společný Publishing vlastní publikaci a náhled. Tvorba i editace jsou
  atomické, selhání specializace vrací publikační zápis. FK zajišťuje úklid
  při odstranění publikace nebo účtu. Text článku se vykresluje escapovaný.
- Veřejný archiv/detail a soukromý archiv/editor používají společnou
  viditelnost a kurzor data/ID. Výpis načítá jen náhledy, detail dlouhý text.
  Editor vyžaduje administrátora a CSRF, při validaci zachová koncept.
  Kanonické JSON API nepřenáší HTML; společný HTTP transport vlastní obálku,
  autorizaci a CSRF. Bezpečnostní hlavičky sdílí `Http/Web` s předanou politikou.
- Fresh instalace vytvoří přesně 13 tabulek včetně module metadata a odmítá
  neprázdnou DB. Blog používá existující ProvidesSchema/ModuleSchemas;
  bootstrap neprovádí DDL. Ověřuje se opakování upgradu, checksum a skutečná
  migrace ze stavu před prvním DDL. Historické globální migrace se nemění.
- Blog vlastní všech 12 jazykových katalogů a vlastní CSS manifest.
  Asset report kontroluje dosažitelnost uvnitř vybraných modulů, aby zdroje
  jiného explicitního produktu neoznačoval za nedosažitelné aktivní assety.
  Rozpočty sociální sestavy se nemění.
- Fyzicky izolovaný test kopíruje zdroje bez osmi sociálních produktových
  domén. Ověřuje reálný `index.php`, zápisy, rollback, hidden/future/ban
  viditelnost, API, editor, HEAD, CSRF, locale, asset delivery a nezavedení
  nepřítomných tříd. MySQL test používá výhradně náhodnou testovací DB.
- Vývojovou instalaci a její omezení popisuje
  [návod](../development/blog-assembly.md). Doručování účtových e-mailů vyžaduje
  explicitního poskytovatele; nevzniká druhý Mail adaptér závislý na sociálním
  produktu. Instalační průvodce a distribuční varianty patří do D.
- Build Blog balíčku je výslovně blokovaný do produktového lifecycle.
  Přepnutí produktu nad existující sociální DB není podporovaná konverze.
  Verze zůstává 5.22.2; commit ani release se automaticky neprovádějí.

Závěrečné ověření etapy C:

- Prošly Composer metadata/advisories, styl (555 souborů), syntaxe PHP 8.4
  (556 souborů), PHPStan level 8, všech 12 překladových katalogů a repository
  security. Po posledních změnách navigace znovu prošly PHPStan a styl;
  po opravách testů také jejich syntaxe a styl.
- Ověřený rozsah zahrnuje všech 86 testovacích skupin, 38 frontendových
  scénářů, 5 356 HTTP API kontrol, Accounts 395, Publishing 351, Blog 380,
  Blog MySQL 8 a sociální hranice 332. Hot-read rozpočty zůstaly
  `feed=3/4`, `status=6/7`, `json-feed=3/4`, `json-status=7/7`, `tag=4/5`,
  `author=6/17`, `search=3/8`; rozpočty se nezvyšovaly.
- První preflight zastavil starý test očekávající všechny adresáře modulů
  ve výchozím katalogu. Očekávání nyní explicitně vyjmenovává původní sociální
  vlastníky a kontroluje neaktivní Blog. Prošlo 53 kontrol zdrojů včetně parity
  výstupů po změnách výběru. Log: `storage/blog-stage-preflight-initial.log`.
- Následující běh prošel browsery, domény, Blog, API a query budgets, ale
  zastavil ho časově citlivý cache test. Ten dovoloval I/O jen sekundové okno.
  Test teď posouvá mtime do stale/expired stavu s rezervou; stále rozlišuje
  čerstvou, povolenou stale a expirovanou hodnotu. Produkční cache se neměnila.
  Samostatně prošlo všech 6 kontrol. Log hlavního běhu:
  `storage/blog-stage-preflight.log`.
- Zbývajících 31 skupin bylo spuštěno samostatně bez opakování browserů a API.
  Prošly podepsané artefakty (985 update / 957 install runtime souborů),
  build příprava (28), updater (13), update z 5.16.0, opakování, přerušení,
  přesný rollback a fresh artifact. Log: `storage/blog-stage-preflight-tail.log`.
  Tento pokračovací běh zastavil ještě timeout instalačního API; poslední
  dvě skupiny proto mají samostatné výsledky níže.
- Instalace opakovaně překročila 30s HTTP limit při tvorbě tabulek. Dočasná
  diagnostika prokázala timeout po 30,15 s při stále běžícím serverovém
  requestu bez zapsané chyby; první HTML trvalo 23 s. Pouze funkční test tvorby
  schématu nyní čeká 120 s, ostatní requesty zůstaly na 30 s. Produkční kód
  instalátoru ani výkonnostní limity se neměnily. Prošlo 22 instalačních API
  kontrol; naměřená tvorba 24,88 s a opakování 3,68 s. Dočasná diagnostika
  byla ze zdrojů odstraněná. Log: `storage/blog-stage-installation-api-final.log`.
- Poslední MySQL instalátor prošel 65 kontrolami včetně deterministického
  importu a jeho opakování. Log: `storage/blog-stage-mysql-installer.log`.
- Memcached integrace byla přeskočená kvůli nedostupnému lokálnímu serveru,
  symlink fixture kvůli nemožnosti vytvořit symlink na tomto Windows.
  `git diff --check` prošel; publikované globální migrace i verze 5.22.2
  zůstaly beze změny. Commit ani release nevznikl.

Další etapa D musí řešit produktovou identitu balíčků, selektivní balení podle
vlastníků, aktivní/nainstalované moduly, podporované změny sestavy, instalace,
migrace, podpisy a obnovu. Dnešní stock balíček stále obsahuje dodané zdroje
neaktivních vestavěných modulů; není to selektivní Blog distribuce.

### Ucelená etapa D — distribuce a lifecycle

Dokončeno a ověřeno 11. 11. 2026. Ověření zahrnuje celý rozsah preflightu
s cíleným opakováním opravené skupiny a dokončením zbývajících skupin;
nešlo o jeden nepřerušený zelený příkaz.

- `Composition/Distribution` deklaruje podepsaný produkt, dostupné, povinné
  a výchozí aktivní moduly, závislosti a neměnné migrační plány. Builder balí
  pouze zdroje dostupných vlastníků; Blog nemá sociální zdroje ani globální
  migrace. Manifestové moduly mají soukromý kořen `App/Modules/`.
- `Deployment` odděluje aktivní a nainstalovaný výběr. `Lifecycle` validuje
  závislosti a zdroje před zápisem, instaluje a aktualizuje explicitně.
  Deaktivace zachová data; úklid odstraněného účtu zapojuje i jejich neaktivní
  vlastníky, zatímco routy a běžné úlohy zůstávají vypnuté.
- Accounts, Publishing a Security mají pro nové produkty vlastní verzované
  baseline. Publikované globální migrace sociálního produktu se nemění.
  Modulové balíčky vyžadují minimum `6.0.0-dev.1`; běžný sociální update dál
  přijímá 5.16.0. Aktuální zdroje jsou vývojová verze, nikoli vydaný release.
- Produkční `App/Console.php` nabízí instalaci, aktivaci, upgrade, úlohy,
  podepsaný update a rollback. Heslo administrátora přijímá jen chráněným
  standardním vstupem přes společný Accounts writer. Soukromý rescue launcher
  dovoluje nahradit samotný spravovaný CLI vstup na Windows.
- Společný engine je v `Support/Updates`, staré vstupy Updates delegují.
  Zámek, maintenance a journal chrání operace i obnovu. Snapshoty DB vlastní
  `Database/Snapshots`; obnova kontroluje checksumy a cílovou DB, vrací soubory,
  schéma, data a přesné bajty lokální aktivace, odstraní zavedené objekty.
  Stará načtená PHP třída poskytovatele neovlivňuje podepsaný migrační plán.
- Compiled assety a překlady mají otisk aktivního výběru a zdrojový fallback.
  Minimální compiled překladový runtime nepotřebuje zdrojový katalog ani DB.
  Společnou nesociální HTTP politiku poskytuje `GenericApplication`.
- Provozní postupy a omezení včetně transakčních MySQL tabulek, externích
  zapisovačů a offline obnovy SQLite virtuálních tabulek popisuje
  [návod](../operations/module-deployment.md). Převod produktu a destruktivní
  odinstalace nejsou součástí dodávky.

Závěrečné ověření:

- Composer metadata a advisories, styl (578 PHP souborů), PHP 8.4 lint
  (579 souborů), PHPStan level 8, 12 překladových katalogů a repository
  security prošly. Po opravě compiled runtime znovu prošly styl a PHPStan;
  poslední změněné PHP vstupy prošly cíleným lintem.
- Pokrytých je všech 88 explicitních skupin hlavního runneru a všech 38
  frontendových scénářů. Prošly samostatné Accounts (397), Publishing (353),
  Blog (382), Blog MySQL (8), sociální hranice (332), API (5 356) i původní
  updater (13). Hot-read rozpočty se neměnily:
  `feed=3/4`, `status=6/7`, `json-feed=3/4`, `json-status=7/7`, `tag=4/5`,
  `author=6/17`, `search=3/8`.
- Nová podepsaná distribuční matice prošla 52 kontrolami: fresh Blog,
  závislosti, aktivace/deaktivace, HTTP routy, překlady a fallback assety,
  úlohy, úklid účtu přes neaktivní vlastníky, update neaktivního modulu,
  migrace při starém poskytovateli v paměti, odmítnutí jiného produktu,
  chybné migrace, rescue aktualizace na Windows, přerušení, rollback,
  poškozená záloha a změněný cíl DB. Snapshoty SQLite i MySQL prošly
  16 kontrolami binárních hodnot, Unicode, bitů, generovaných sloupců,
  views, složených triggerů, odstranění nových tabulek a opakování obnovy.
- Sociální podepsané artefakty prošly inventářem (980 update / 952 install
  runtime souborů) a build přípravou (28). Prošel update z 5.16.0, opakování,
  přerušení a přesný rollback, instalační API (22) i restart-safe MySQL
  instalátor (65). Používají výhradně vlastní kopie a dočasné databáze.
- První preflight odhalil nesprávnou příponu testovací šablony; nyní má
  `.php.tpl`. Další běh zastavila nová závislost compiled překladů na
  zdrojovém katalogu. Oprava zachovává samostatný compiled runtime a odmítání
  katalogů jiného aktivního výběru; skupina prošla 161 kontrolami v
  `storage/modules-stage-compiled-translations.log`.
- Úspěšné browserové/API scénáře se zbytečně neopakovaly. Hlavní průchod je
  v `storage/modules-stage-preflight.log`; navazujících 61 skupin skončilo
  úspěšně v `storage/modules-stage-preflight-tail.log`. Samostatná původní
  distribuční matice je v `storage/modules-stage-lifecycle.log`; finálních
  52 kontrol je součástí pokračovacího logu.
- Přeskočené zůstaly nedostupný lokální Memcached a symlink fixture na tomto
  Windows. `git diff --check` prošel a publikované globální migrace zůstaly
  nezměněné. Runtime je `6.0.0-dev.1`, nejnovější zveřejněný release 5.22.2;
  commit ani veřejný release nevznikly.
