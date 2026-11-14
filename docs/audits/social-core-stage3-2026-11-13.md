# Etapa 3: společné diskuse a reakce

Datum: 13. 11. 2026. Navazuje na [plán](../plans/social-core-capabilities.md)
a [druhou etapu](social-core-stage2-2026-11-13.md).

Stav: dokončeno, ověřeno a nasazeno na tinycat.test jako `6.0.0-dev.7`.

## Implementace

Core/Publishing vlastní `Comments`, `Reactions`, diskusní cache, transakční
`Interactions`, kanonický `DiscussionResource` a společné komponenty komentáře,
reakce, formuláře i zobrazení historie. Historická jména zachovává `LegacyNames`.
Social poskytuje vlastní statusové vstupy a úzkou obsahovou politiku; Blog
registruje článkové vstupy nad stejnou službou. Rozdíl není pouhé odstranění
filtru `kind = post` ze sociálního endpointu.

`InteractionPolicy` registruje lazy rozhodnutí vlastníka. Cíl obsahuje vlastníka,
druh, ID, kanonickou URL, oprávnění a schopnosti. Zápis zamyká publikaci,
znovu kontroluje přístup a vazbu komentáře i rodiče. Opakovaný požadovaný stav
reakce nic nepřidává. Historie, změny a doménové callbacky jsou transakční.
Social drží cooldowny, reputační limity, příjemce a typy notifikací; články
nevstupují do sociálních statistik ani sociálního přehledu posledních komentářů.
Úklid sociálních notifikačních předmětů při odstranění účtu se účastní i tehdy,
když je nainstalovaný Social neaktivní.

Záznam notifikace a kvóty zůstává v transakci; e-mail a transportní cooldown
se odloží přes společné `Transactions::afterCommit` až po vnější commit.
Rollback zahodí i tyto efekty. Čtení uvnitř transakce nepřenáší nepotvrzené
komentáře nebo reakce do request cache. Veřejné odpovědi a lajky odmítají také
neviditelný komentář nebo rodiče od neaktivního autora.

Blog nabízí SSR diskusi, AJAX komentáře a reakce a autorizovaný editor na vyžádání.
Prvotní HTML a dynamické přírůstky používají stejné komponenty. Aktualizace
komentáře nebo reakce nepřepisuje jiný rozepsaný text. Social připojuje svou
nabídku přes volitelné rozšíření společné komponenty. Článkový klient je
samostatný scope `/articles`; archiv Blogu kvůli němu nenačítá diskusní editor.

## Vlastnictví a migrace

Publishing přebírá aktuální vlastnictví `content_comments`, `content_likes`
a `comment_likes` i jejich původní stream `tinycat.status` verze 1. Vlastní
stream `tinycat.publishing` přechází z verze 2 na 3. `schemaOwners` descriptoru
předání výslovně zaznamenává; Social už stream ani tabulky nedeklaruje.
Historické ID se nepřejmenovalo a nestalo se aktivovatelným core modulem.
Původní migrační soubor v Socialu je nedotčený; jeho identická kopie je pod core,
se stejným ID migrace a checksumem. Existující instalace migraci nereplayuje.
Fresh i upgrade používají totožné DDL s původními názvy a vazbami; existující
řádky a historie se nepřepisují. Vývojový runtime je `6.0.0-dev.7`.

Kontrola přímého upgradu odhalila, že dev.6 odmítá prázdný seznam tabulek ve
schématu. Předání proto používá existující více-streamový kontrakt a stabilní
historii, nikoli prázdný stream nebo novou kopii tabulek. Validátor schémat se
kvůli změně neuvolňuje. Blog-only instalace bez společných tabulek při upgradu
explicitně instaluje převzatý stream; Social není potřebný.

Nový checkpoint `tinycat.publishing.interaction-ownership` ověří připravenost
původního streamu a přítomnost tabulek; žádné jejich řádky ani DDL nemění.
Jeho vlastní nové ID nenahrazuje původní historii. Posun hlavního streamu zajistí
databázový snapshot při standardním updatu, aby rollback obnovil přesně i stav
registru a původní vlastnictví. Blog-only upgrade bez historického streamu jej
instaluje samostatně přes existující synchronizaci schémat.

Živá tinycat.test pochází z globální instalace (`legacy: true`), nikoli ze
samostatného modulového instalačního schématu. Její `schema_migrations`
proto zůstává globální a nedoplňuje se vymyšlený historický stream.
`20261113_001_shared_interactions` kontroluje existující sloupce bez přepisu
tabulek či řádků a zajišťuje standardní databázový snapshot pro rollback.
V tomto režimu descriptor nevypisuje managed frameworkové streamy; změnu
aktuálního vlastníka ověřuje registr poskytovatelů frameworku.

## Ověření a nasazení

Přesun stylů zachoval přesně všech 760 pravidel včetně jejich počtů; změnilo se
pořadí skupin ve prospěch společné prezentace a odpovídající reference kaskády.
Nový scope `/articles` má samostatný rozpočet pro společný diskusní editor.
Ve společné platformě měl při kontrole 371 819 B raw JS a 78 931 B gzip;
limit je 376 832 / 81 920 B. Pro `/` se limit raw JS zvýšil o 4 096 B
na 369 152 B kvůli společným komponentám a jejich vlastnickým vazbám. Gzip limit
se po oddělení obecného transportu zvýšil o 512 B na 78 464 B; kontrola naměřila
77 977 B, což překročilo předchozí limit o 25 B. Ostatní
stávající limity se nezvyšovaly.

Audit zdrojových assetů nově rozlišuje dostupnou pojmenovanou schopnost od
skutečně doručovaného balíčku. Deklarované exporty frameworku kontroluje i bez
aktivního konzumenta, ale do stránek je tím nepřidává. To umožňuje výchozí
Social sestavě projít kontrolou bez načítání článkového diskusního controlleru;
per-scope rozpočty stále měří pouze skutečný výběr. Nezařazený soubor zůstává chybou.

Regrese zachytila také chybu tvorby účastníka úklidu z neaktivního modulu:
factory nyní běží v katalogu příslušných dostupných vlastníků; callbacky se
nadále vyhodnocují až při explicitním úklidu. Dvě doménové fixture nově odmítají
předčasné ukončení HTTP chybou s procesovým kódem 0, takže ho runner nemůže
zaměnit za dokončený úspěšný scénář. Počet hlavních skupin ani frontendových
scénářů se nezvýšil; souběh MySQL používá malý worker ve stávající skupině.

Závěrečný souhrn pokrývá všech 88 hlavních skupin, 38 frontendových scénářů
a 7 vstupních kontrol preflightu. Přeskočil se celý test Memcached kvůli
nedostupnému místnímu serveru; dílčí negativní symlink scénář na Windows také
nemohl vytvořit symlink. PHPStan, lint, styl, překlady, hranice, API, UI,
oba assetové výběry a instalační/update/rollback scénáře prošly.

Jde o preflight a navazující měřené běhy po konkrétních opravách, nikoli jeden
nepřerušený zelený preflight. Úspěšné nezasažené skupiny se neopakovaly;
po změnách transportního kontraktu se zopakovaly příslušné nativní scénáře
a Blog fixture. Součet posledních úspěšných měření je **14 min 37,627 s**;
součet všech měřených pokusů včetně oprav **32 min 47,704 s**. Nejde o celkovou
dobu práce; samostatné příkazy mimo runner nejsou v tomto součtu.

Největší zaznamenané skupiny a scénáře:

| Rozsah | Poslední úspěšný čas |
| --- | ---: |
| Podepsaný životní cyklus modulů | 164,876 s |
| Update a rollback rehearsal | 116,004 s |
| Nativní touch regrese popoverů | 100,547 s |
| HTTP API kontrakt | 85,971 s |
| Blog izolovaně, souběh a nativní UI | 72,335 s |

Frontendových 38 scénářů má dohromady 231,647 s. Další optimalizaci má smysl
zaměřit na tyto velké existující skupiny a práci jejich fixture; samotná délka
není důvod odstranit odlišnou bezpečnostní nebo rollback záruku. Nové nezávislé
úplné integrační kopie nevznikly. Podrobné časy, rozsahy, pokusy a výsledky
jsou v `storage/social-core-stage3-20261113/verification.json`, surové reporty
v `storage/test-runs/`.

## Živé ověření

Pro tinycat.test vznikl soukromý podepsaný aktualizační balíček dev.7 s minimem
dev.6. Jeho podpis a manifest ověřil ještě skutečný původní runtime dev.6.
Standardní updater provedl přechod se společným zámkem, údržbou, databázovým
snapshotem a obnovitelnou zálohou:
`E:/laragon/www/tinycat/storage/updates/backups/6.0.0-dev.6-to-6.0.0-dev.7-20261113-213536-7098dc74`.
Veřejný release se nevydával a commit nebyl součástí této etapy.

Prošlo **86 HTTPS kontrol** s ověřováním TLS: článková diskuse, anonymní
oprávnění, odmítnutí článku přes sociální vstup, RSS/oEmbed, jazyky, chyby,
soukromá administrace a společné i článkové assety. Živý smoke byl anonymní;
autorizované mutace, modaly, drafty a nezávislou aktivaci ověřily izolované
instalace. Správce modulů je použitelný a Blog i Social zůstaly aktivní.

Ověřeny byly hashe všech 1 143 souborů balíčku a odstranění jeho deklarovaných
zastaralých souborů. Všech 27 schémat tabulek zůstalo identických. Byteové hashe
řádků ostatních 26 tabulek se nezměnily ani po HTTPS smoke; migrační registr
má právě jeden nový kontrolní bod, všechny původní záznamy zůstaly stejné.
Zachovalo se 11 633 publikací, 38 účtů a inventář 9 142 mediálních souborů,
konfigurace i aktivace. Registr poskytovatelů potvrzuje předání `tinycat.status`
ze Socialu do Publishing. Režim údržby je vypnutý.

Zároveň se ověřily přesné původní bajty 49 publikovaných migračních souborů
a zachování všech historických položek mazacího manifestu. Výsledky jsou
v `deployment-verification.json`, `live-package-verification.json`,
`live-smoke-combined.json` a příslušných snapshotech v ignorovaném adresáři etapy.

Zbývá etapa 4: importní mechanismus s více explicitními publikačními cíli
a závěrečné posouzení celkové struktury a nákladů testů.
