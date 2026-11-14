# Živé lokální prostředí v Laragonu

Společná testovací instalace **https://tinycat.test/** je oddělená od checkoutu
v **`E:/laragon/www/tinycat`**. Repozitář v `C:/Users/Nikola/Documents/tinycat`
obsahuje zdroje a vývojové nástroje, nikoli živá média, cache a stav updateru.
Používej ji pro běžné ruční a browserové ověření aplikace, stránky, API,
assetů a chování za skutečným Apachem. Laragon už poskytuje Apache, PHP,
MySQL a lokální HTTPS. Nezakládej pro stejné ověření další Apache, MySQL,
kontejnerový stack ani náhradní `php -S` na jiném portu.

Toto je nastavení místního vývoje, nikoli doména zakódovaná do aplikace
nebo požadavek na instalace jiných uživatelů.

## Zapojení ověřené 12. 11. 2026

- Vhost `E:/laragon/etc/apache2/sites-enabled/tinycat.test.conf`
  obsluhuje HTTP 80 a HTTPS 443. DocumentRoot je přímo
  `E:/laragon/www/tinycat`. Vlastní konfigurace má odstraněný prefix `auto.`,
  aby ji Laragon zachoval podle [pravidel pro vhosty](https://laragon.org/docs/pretty-urls).
  Původní HTTP LAN alias `192.168.68.103` je zahrnutý ve stejném vhostu.
- Duplicitní `tinycat-lan.conf` a již neplatné `tinycat-release-stage.conf`
  a `tinycat-performance-2025.conf` jsou zálohované a vyřazené z aktivních
  konfigurací. Staré zkušební porty 8097/8098 už neposlouchají.
- Doména při ověření mířila na `127.0.0.1`. HTTPS prošlo ověřením certifikátu.
- Naslouchá Apache z Laragonu; cesta executable odpovídá Apache 2.4.66.
- MySQL naslouchá na portu 3306; executable je z Laragon MySQL 8.4.3.
- CLI `php` je `E:/laragon/bin/php/php-8.4.12-nts-Win32-vs17-x64/php.exe`.
  Přesnou webovou PHP konfiguraci nelze odvozovat pouze z CLI ini.

Tyto cesty popisují ověřený počítač. Při diagnostice zjisti aktuální stav,
nepředpokládej stejné PID nebo navždy stejnou verzi nainstalovaných služeb.

## Cesty, konfigurace a přesun dat

| Účel | Umístění |
| --- | --- |
| Živý runtime, konfigurace a média | `E:/laragon/www/tinycat` |
| Živá cache, updater a Apache logy | `E:/laragon/www/tinycat/storage` |
| Živé feedy, konfigurace a cache | `E:/laragon/www/tinycat/feeds` |
| Výchozí cache vývojového CLI | `E:/laragon/tmp/tinycat-development-cache` |
| Protokol přesunu a záloha vhostů | `E:/laragon/backup/tinycat-relocation-20261112` |
| Původní soubory a historické lokální instalace | `C:/Users/Nikola/AppData/Local/TinyCat/relocation-backup-20261112` |

Konfigurace živé instalace je byte-identická s původní. Připojení ke
stávajícímu MySQL a jeho data se neměnila; počty záznamů všech 24 tabulek
souhlasily před i po přepnutí. Nekonal se fresh install ani migrace schématu.
Kopie aplikace a dat byla ověřena pomocí SHA-256, včetně 9 141 médií.
Původní soubory byly poté přesunuty mimo repo do neveřejné zálohy, nikoli
smazány. V repozitářovém `uploads` zůstává pouze `.gitkeep`.

Lokální ignorované `config.php` a `feeds/config.php` v checkoutu jsou malé
CLI/test odkazy na externí konfigurace bez vlastních tajných hodnot. Webové
spuštění checkoutu odmítají; připouštějí CLI a izolované CLI-server fixtures.
Vývojový cache nepoužívá živý cache: testovací `TINYCAT_CACHE_DIRECTORY` má
přednost před výchozím externím vývojovým adresářem. Tyto lokální odkazy
nepatří do distribučního balíčku ani do Gitu.

Vývojové reporty a nástroje nadále patří do ignorovaného `storage` repozitáře.
Nejsou součástí živé instalace. Soukromý vývojový podpisový klíč se na web
nekopíroval.

## Synchronizace zdrojů na živou instalaci

Úprava souboru v checkoutu sama nezmění `tinycat.test`. Před živým ověřením
přenes odpovídající runtime změny do externí instalace a ověř, že web opravdu
načítá novou verzi. Pro související změnu modulu přenes společně PHP, manifest,
šablony, překlady a assety; zachovej konzistentní výběr modulů.

Synchronizace nesmí přepsat živý `config.php`, `feeds/config.php`, `storage`,
`uploads` ani `feeds/var`. Nekopíruj `.git`, `vendor`, testy nebo dev nástroje
do webu. Nepoužívej zrcadlení celého kořene s mazáním cíle. Mazání nahrazených
runtime souborů musí vycházet z konkrétní změny nebo updateru.
Provozní CLI spusť z externí instalace; vývojový checkout není provozní root.

Při nutné změně vhostu nejprve zálohuj konfiguraci, ověř ji pomocí Apache
`-t` (jen kontrola syntaxe) a použij existující správu Laragonu. Laragon
dokumentuje [CLI reload](https://laragon.org/docs/cli), ale návrat příkazu
nenahrazuje kontrolu, že služba skutečně běží a odpovídá doména. Při tomto
přesunu bylo znovuspuštění Apache dokončené uživatelkou v Laragonu poté,
co CLI reload službu zastavil. Další instanci Apache/MySQL nespouštěj.

## Běžné ověření

1. Ověř dostupnost existující instalace. Například v PowerShellu:

   ```powershell
   curl.exe --silent --show-error --connect-timeout 5 --max-time 20 --output NUL --write-out "%{http_code}\n" https://tinycat.test/
   Get-Command php
   php --ini
   ```

2. Browserové ověření otevři na `https://tinycat.test/` a požadované routě.
   Kontroluj skutečný výsledek UI, konzoli, HTTP odpovědi a načítání assetů.
   Při změně instalace nebo transportu zahrň chování přes Apache a `.htaccess`.
3. Při nedostupnosti nejprve zkontroluj stav Laragonu, obsazení portů,
   DocumentRoot, HTTPS a příslušné logy. Nenahrazuj chybující zapojení
   paralelním serverem, který by problém pouze obešel.
4. Používej ověřené HTTPS. Případnou chybu důvěry lokálního certifikátu
   diagnostikuj; nevypínej ověřování TLS jako výchozí nastavení nástrojů.

Ověření po přesunu 12. listopadu: `/` vrací 200 a HTML aplikace, všechny čtyři
vstupní JS a CSS vracejí 200 se správnými MIME typy. Vzorky z každého ze čtyř
adresářů médií odpovídají místním souborům v externí instalaci podle SHA-256.
Přes HTTP i HTTPS byl jednorázovým souborem prokázán nový DocumentRoot;
ověřovací soubor byl následně přesunut z webu do neveřejného protokolu.
`/install` vrací 404, tedy instalátor není
ve stávající instalaci otevřený. Neexistující kořen API `/api` vrací 404
s JSON odpovědí. Šlo o anonymní read-only smoke, nikoli přihlášené UI,
instalaci, migraci nebo úplný end-to-end průchod. [Výsledek smoke](../../storage/laragon-environment-check-20261112.json).

## Izolované automatizované testy

Živá instalace a deterministická fixture mají odlišný účel:

- MySQL scénáře se připojují ke stávající službě Laragonu a vytvářejí vlastní
  dočasnou databázi. Nestartují další MySQL daemon a nepoužívají živou DB jako
  náhradu fixture. Přihlašovací údaje se nevypisují ani necommitují.
- Doménové testy mohou použít vlastní SQLite. Runnery a data zůstávají
  oddělené od skutečné konfigurace, médií a účtů v `tinycat.test`.
- Existující API, instalátorové, produktové a recovery testy mohou přes své
  runnery spustit dočasný PHP HTTP worker nebo statický fixture server,
  pokud scénář vyžaduje vlastní router, proces, prázdnou DB či oddělené zdroje.
  Worker se po testu ukončí; neinstaluje se kvůli němu další Apache/MySQL.
- Rychlé ověření již běžící aplikace samo o sobě takovou izolaci nevyžaduje.
  Nový test musí mít konkrétní důvod pro vlastní server i novou kopii aplikace.

Spouštěj příslušné [existující runnery](../../tests/README.md) a zaznamenej,
která část proběhla na živé instalaci a která nad izolovanými fixtures.
Výsledek jednoho prostředí automaticky neprokazuje funkčnost druhého.

## Instalace a data

Aktuální nasazení z 12. 11. 2026 je **6.0.0-dev.3** s aktivním Blogem
i Socialem. [Report nasazení](../audits/platform-completion-2026-11-12.md)
uvádí podpisové mezikroky, zálohy, ověření vypnutého Socialu a shodná data,
konfiguraci i všech 9 141 existujících médií. Archiv Blogu je `/blog`.

Doména `.test` není souhlas k resetu. Stávající instalace má svou konfiguraci,
účty a média. Bez zadání reinstalace neměň její DB připojení, nemaž tabulky
a nespouštěj nad ní fresh-install, obnovu snapshotu ani rollback fixture.

Požadavek na novou instalaci nebo update musí určit, zda jde o tuto sdílenou
instalaci, nebo o prázdnou izolovanou sestavu. Zachovej odpovídající zálohu
a ověř výsledek na cílové doméně. Používej společné instalační a aktualizační
vstupy podle [module deployment](../operations/module-deployment.md) a
[aktualizačního postupu](../operations/updates.md).
