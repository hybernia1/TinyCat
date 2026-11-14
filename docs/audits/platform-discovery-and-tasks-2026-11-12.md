# Dokončení hledání, sitemap, importu a core úloh

Navazuje na [stav dev.3](platform-completion-2026-11-12.md). Tato etapa
uzavírá čtyři tam uvedené mezery. Implementace je v `6.0.0-dev.4`;
pravidla a rozhraní popisuje [reference](../development/core-and-modules.md).

## Výsledek implementace

| Oblast | Core / společná služba | Vlastník konkrétní funkce |
| --- | --- | --- |
| Hledání | Core/Discovery: bounded fan-out, API a společná HTML stránka | Search dodává sociální zdroje; Blog články. |
| Sitemap | Core/Discovery: XML, index, stránkování a robots | Site stránky, Users autory, Status statusy/tagy, Blog články. |
| Import | Publishing: FeedParser, WordPressFeed a FeedImport | Bots: zdroje, historie GUID, fronty a automatické publikování. |
| Úlohy | Core/Tasks: validace, zámky, běh a persistentní historie | Aktivní modul dodává callback; Scheduler HTTP/admin adaptér. |

Společný formulář vede na `/search/all`; JSON je `/api/search/query`.
Našeptávání `/api/search` objevuje kolekce aktivních vlastníků. Sociální
`/search` a `/api/search/results` zůstávají zachované. Články mají vlastní
kanonické URL, viditelnost i kurzor a nevydávají se za statusy. Nové hledání
nepřidává frontendový framework ani samostatnou implementaci sociálních karet.
Blog používá omezené LIKE hledání titulku a těla, nikoli nový FULLTEXT index.

Registrace zdrojů zůstávají lazy, bez SQL/session a bez produktových seznamů
v core. Deaktivace vyřadí příspěvek z dalšího požadavku; katalog sitemap má
cache rozlišenou aktivním výběrem. XML položky vždy používají aktuální
viditelnost. Specializované sociální hledání má dosavadní limity a CAPTCHA;
Blog samostatné IP limity. Editor zachovává doplňování od jednoho znaku
a svůj limit až 12 návrhů, veřejné našeptávání začíná dvěma znaky.

## Historie a kompatibilita

`scheduler_runs` přebírá technické schéma `tinycat.support` s baseline
`App/Core/Tasks/migrations/001-history.php`. Existující tabulku lze převzít
pouze touto konkrétní migrací, po kontrole struktury a případné publikované
historie Scheduleru. ID a řádky se zachovávají. Původní Scheduler migrace se
nemění; při dalších změnách deploymentu se ověřuje i její starý checksum.
CLI už nemá větev s přímým callbackem bez zámku a historie. MySQL používá
stávající advisory lock, SQLite bez jeho testovací náhrady souborový flock.

Legacy instalace převádí nová globální migrace
`20261112_003_core_task_history`. Samostatná modulová distribuce obsahuje
technické schéma v podepsaném plánu a vyžaduje runtime dev.4. Platformní
balíček nad globální historií tento nový druh záznamu v plánu nepotřebuje,
takže zachovává aktualizační baseline dev.1. Výchozí sociální zdrojový balíček
nadále podporuje aktualizaci zveřejněné řady od 5.16.0. Nejde o veřejně vydaný
stabilní release ani o automatickou konverzi mezi identitami distribucí.

Staré názvy parserů v Bots jsou kompatibilní aliasy implementací Publishing.
Obecná importní služba nevytváří nový Blog importní editor; připravuje data
pro vlastníka, který rozhoduje o publikování.

## Ověření a nasazení

Napříč závěrečnými resumovanými běhy prošlo **87 z 88 skupin**, Memcached
se přeskočil kvůli nedostupné službě. Prošlo všech **38 frontendových scénářů**.
Windows navíc nepovolil symlinkový podscénář katalogu. Prošly také všechny
technické preflight kontroly: Composer metadata a advisories, styl, překlady,
PHP lint, PHPStan level 8 a repository security. Platformní asset budget
prošel pro všech devět scopes. PHP je Laragon 8.4.12 NTS, MySQL jeho stávající
služba; mutační fixtures používaly vlastní dočasné databáze a kopie.

Nejde o jeden čistý průchod `composer preflight`. Po chybě se pokračovalo
zbývajícími skupinami; úspěšné výsledky se zachovaly. Opravil se přesun
přípravy URL ze šablon, botí testy přešly z reflexe soukromých metod na
veřejnou službu a health fixture respektuje skutečné závislosti Search.
Jeden omylem příliš široký resume byl zastaven před frontendovou sadou.
Po poslední změně lokalizovaných URL se samostatně zopakovaly tři příslušné
frontendové scénáře. Nevznikl nový univerzální runner ani nová testovací skupina.

Součet posledních výsledků skupin je **975,5 s (16 min 16 s)**. Frontend je
v něm již započtený a jeho 38 scénářů trvalo přibližně **257,5 s (4 min 17 s)**.
Celý závěrečný řetězec od prvního širokého běhu po poslední instalační kontrolu
trval 20 min 24 s včetně zastavení, oprav a obnovení. Předchozí etapa uváděla
27 min 25 s jako součet posledních výsledků; jde o orientační srovnání jiných
otisků zdrojů, nikoli řízený benchmark. Těžké balíčkové a MySQL scénáře nyní
neběžely souběžně. Testy se kvůli časům nevyřazovaly.

| Nejdražší skupina | Sekundy |
| --- | ---: |
| `tests/frontend/run.php` — všech 38 scénářů | 257,7 |
| `tests/release/update-rehearsal.php` | 158,8 |
| `tests/release/module-lifecycle.php` | 122,9 |
| `tests/http/api-contract.php` | 100,3 |
| `tests/quality/module-resources.php` | 44,2 |

Tyto výsledky nepodporují další plošné přidávání balíčkových kopií pro každý
dílčí kontrakt. Nové scénáře této etapy rozšířily existující Blog, Publishing,
registry a lifecycle fixtures. Izolovaný Blog má 416 kontrol, kombinace se
Socialem 46 a podepsaný module lifecycle 54. Ověřují i stránkování při shodném
čase, skryté/budoucí články odpovídající dotazu, neaktivního autora, deaktivaci zdrojů,
uchování ID historie a odmítnutí poškozeného starého checksumu.

Výsledné update/install artefakty vznikly za **8,4 s** v privátním adresáři
`C:/Users/Nikola/AppData/Local/TinyCat/builds/platform-20261112/release4/`.
Podpis i celý inventář update balíčku ověřil také dosavadní živý runtime dev.3
proti vlastnímu důvěryhodnému klíči. Nasazení provedl běžný rescue CLI updater,
bez ručního kopírování souborů a bez změny konfigurace.

`https://tinycat.test/` nyní běží na **6.0.0-dev.4**, maintenance je vypnutá
a všech 17 modulů je aktivních. Proběhlo **141 HTTPS kontrol s ověřováním TLS**:
55 společně, 31 při vypnutém Socialu a 55 po jeho obnovení. Hledání při vypnutí
obsahovalo jen články, sitemap neobsahovala statusy. Po obnovení se vrátily
všechny zdroje. Ověřily se i assety, chráněné endpointy, veřejné stránky,
lokalizovaná společná stránka a připravenost core historie s jejím checksumem.
Přihlášené mutace a editor se testovaly v izolovaných HTTP fixtures, na živém
webu se neobcházelo přihlášení ani nespouštěly importní/úklidové úlohy.

Před/po: **11 631 publikací**, **38 uživatelů**, **9 141 mediálních souborů**,
27 tabulek. Kontrolní hash kanonických polí publikací, hashe lokální konfigurace
a cesty/velikosti/mtime médií jsou stejné. Všech **45 dříve nasazených migračních
souborů** má stejný normalizovaný hash. Změnily se pouze počty migračních
záznamů a rate-limit bucketů použitých při ověřování.

| Operace | Zachovaná záloha |
| --- | --- |
| dev.3 → dev.4 | `6.0.0-dev.3-to-6.0.0-dev.4-20261112-152733-bacb2c42` |
| Vypnutí Socialu | `6.0.0-dev.4-to-6.0.0-dev.4-20261112-152930-a3be1f69` |
| Obnovení Socialu | `6.0.0-dev.4-to-6.0.0-dev.4-20261112-152947-810d0093` |

Úplný seznam testů a jejich časů obsahuje
`storage/module-finish-20261112/verification-union.json`; zdrojové reporty jsou
`20261112-150421-aa03592955`, `20261112-151247-de1f902a32`,
`20261112-151713-ba40ff4ba8`, `20261112-152018-a2d3d42063` a
`20261112-151759-ccca76fcb2`. Technický preflight zachycují reporty
`20261112-150325-c01d251bec` a `20261112-151740-884fa96d22`.
Pomocné důkazy, srovnání dat a jednotlivé živé HTTP výsledky jsou ve stejném
ignorovaném adresáři této etapy. Zálohy se nemažou.
