# Platformní služby: identita, schránka, health a HTTP

Datum: 12. 11. 2026. Navazuje na [audit hranic](core-module-boundaries-2026-11-12.md)
a [etapu 1A](core-services-stage-1a-2026-11-12.md). Popisuje provedenou část 1B,
nikoli dokončení všech dříve navržených platformních a doménových přesunů.

## Implementace

| Vlastník | Odpovědnost |
| --- | --- |
| Core/Identity | Autentizace, hesla, registrace, recovery tokeny, profil, role, administrativní zápisy, lifecycle, účastníci mazání a locale účtu. |
| Core/Notifications | Obecné ukládání, deduplikace, přečtení/mazání, kurzory, výběr aktivních poskytovatelů předmětů a neutrální schema helper. |
| Core/Health | Sondy, HTTP měření, vyhodnocení, denní běh, historie a systémové upozornění administrátorům bez aktéra. |
| Core/Runtime/HttpKernel | Bezpečnostní hlavičky, údržba, připravenost, dispatch a zahození částečného výstupu při chybě. |
| Status | Sociální události, historické reference, SQL nad publikacemi/komentáři, viditelnost předmětu a kanonická sociální URL. |
| Stávající technické adaptéry | Manifesty, registrační vstupy a prezentační zdroje Accounts, Notifications, Health, Scheduler, Settings a Mail. |

Interní konzumenti používají core názvy; původní názvy zůstávají aliasy nebo
úzkými kompatibilními fasádami. Prezentační `legacyUser` patří Accounts/Resource,
nikoli core read modelu. Lifecycle stále sbírá i nainstalované neaktivní vlastníky
a provádí nevratný souborový cleanup až po commitu.

Výjimka, Participant, Deletion a lifecycle rozhraní mají kompatibilní typové
aliasy registrované již při načtení core typu. To zachovává i staré `catch`
a `instanceof`, které samy autoload původního názvu nevyvolávají.

`NotificationType` je lazy příspěvek vlastníka: stabilní ID, dávkové doplnění
předmětu a cílový odkaz. Registry odmítají duplicitní typy a více schránek.
Hydratační skupina sdílí jeden dotaz mezi typy stejného vlastníka; neslučuje
callbacky různých vlastníků. Core neJOINuje content ani komentáře a nezná jejich URL.
Neaktivní vlastník vede na obecný fallback bez předmětu, odkazu či starého excerptu.

Social nepotřebuje zapnutou schránku ani poštu. Dostupnost schránky deklaruje
její poskytovatel v katalogu, nikoli hledání adresářů nebo tichá aktivace modulů.
Vypnutá schránka nepřidá sociálním zápisům požadavek na neexistující tabulku.
Mail zůstává volitelným kanálem; samotný core zápis neposílá automaticky e-mail.

Site už nepřebírá celou odpověď přes `Startup::$response`. Poskytuje jednou
vlastněnou `HttpPolicy` pro locale/cesty, instalační výjimky a prezentaci chyb.
Kernel drží společný tok. Oprava zachovává také bezpečné chování při selhání
renderu: částečné HTML se před chybovou odpovědí zahodí.

## Data a kompatibilita

Nová globální migrace `20261112_001_notification_payload.php` přidává nullable
`payload_json` pro neprůhledná data producenta. Nemění ID, klíče, přečtení,
časové údaje ani existující FK. Sociální `content_id` a `comment_id` zatím
zůstávají autoritativními kompatibilními referencemi, nejsou destruktivně převáděny.
Nové předměty jiných domén mohou použít payload bez publikační tabulky.

Fresh schema schránky odkazuje pouze na identitu uživatele; nepotřebuje content
ani content_comments. Modulový provider deklaruje vlastní baseline a migrační
zdroje standardním kontraktem. Historické migrační soubory se neměnily; nové
migrační snapshoty neodkazují na měnitelnou implementaci helperu.

Živá instalace `tinycat.test` nebyla synchronizována a migrace na ní nebyla
aplikována. Není vytvořen commit ani release.

## Ověření

Existující fyzická Accounts fixture bez Socialu byla rozšířena o:

- fresh schránku bez sociálních FK;
- neprůhledný předmět jiného modulu, systémové doručení a nepovinného aktéra;
- deduplikaci se zachováním přečtení a odmítnutí přístupu jiného účtu;
- health doručení bez sociálních tabulek;
- fallback po odebrání poskytovatele;
- opakování nové migrace se zachováním starého řádku.

Nevznikl další úplný instalační runner. Minimální runtime dále ověřuje lazy HTTP
politiky, konflikt vlastníků, dispatch a zahození částečného výstupu.
Původní API kontrakt po opravě bufferování prošel s 5 356 kontrolami.
UI inventář byl obnoven existujícím nástrojem.

Ověření je složené z navazujících běhů, není vydáváno za jeden čistý preflight:

| Běh | Výsledek | Čas |
| --- | --- | ---: |
| [Původní preflight](../../storage/test-runs/20261112-065627-1b1278b3a5/report.json) | Zastaven na prezentační regresi po 43 úspěšných PHP skupinách a frontendové matici. | 440,608 s |
| [Navazujících 45 skupin](../../storage/test-runs/20261112-070659-b65be7bc5f/report.json) | 44 prošlo, Memcached přeskočen; včetně opraveného renderu, API, instalace, update a rollbacku. | 577,577 s |
| [Finální statické kontroly](../../storage/test-runs/20261112-071508-0d5c0240a9/report.json) | Styl, lint, PHPStan a security prošly. | 60,941 s |
| [Finální identita a registry](../../storage/test-runs/20261112-071801-c929a14087/report.json) | Prošly nad posledním autoloaderem, včetně legacy catch a instanceof. | Cílený doplněk |

Metadata Composeru, dependency audit a překlady prošly také v
[statickém běhu](../../storage/test-runs/20261112-071323-47c8aed192/report.json).
Ten se zastavil na statickém vyhodnocení guardu kompatibilních aliasů;
finální verze guardu prošla PHPStanem bez potlačování chyb.

Sjednocené pokrytí je **88/88 skupin: 87 prošlo, jedna byla přeskočena**.
[Strojová mapa pokrytí](../../storage/core-module-audit-20261112/platform-1b-coverage.json)
uvádí úspěšný report každé skupiny. Memcached server není dostupný; symlink
escape fixture také nemohla vytvořit symlink, zbytek dané skupiny prošel.

Dva hlavní běhy dohromady trvaly **16 min 58 s**, včetně neúspěšné kontroly.
Časy rodičovských runnerů se znovu nepřičítají. Cílené opravy a statické
doplňky jsou nad tento součet. Nejde o kontrolovaný benchmark proti 1A.
Nejdražší samostatné scénáře byly update/rollback 174,675 s, lifecycle modulů
113,749 s, dotykové popovery 103,150 s a API kontrakt 64,421 s.

Prezentace odhalila DB dotaz při skládání odkazu. Finální `Types::target` je
čistý callback nad připraveným řádkem; hydrataci provádí Inbox mimo renderer.
Fixture nyní dodává explicitní výsledek viditelnosti. Úspěšná browserová matice
se po této opravě neopakovala; znovu se ověřily renderovací a HTTP scénáře.

Reporty zachycují změny zdrojů během běhů: doplnění fallbacku neaktivních typů,
regresí a kompatibilních aliasů. Dotčené renderovací/API scénáře prošly v
navazujícím běhu; poslední autoloader znovu ověřily finální statické kontroly
a samostatný běh identity/registrů. Nejde o tvrzení, že všechny kontroly
běžely současně nad jedním neměnným snapshotem.

## Co zůstává otevřené

- Technické zdroje a registrační adaptéry ještě nejsou všechny fyzicky v Core.
  Instalační schema plánovače/store a obecný CLI fallback z 1A potřebují
  sjednotit před samostatným nasazením celé technické sestavy bez legacy instalátoru.
- Centrální administrační definice nastavení, e-mailové šablony a staré UI kopie
  sociálních oznámení ještě vyžadují převod na příspěvky jejich vlastníků.
- Blog a Social dál soutěží o rám/root a jejich publikace nemají dokončené
  rozlišení druhu. To je další souvislá etapa; tato změna je sama nekombinuje.
- Identita zámků, úplné odstranění kompatibilních referenčních sloupců a fasád
  není součást této změny. Existující historie a podporované aktualizace mají přednost.
