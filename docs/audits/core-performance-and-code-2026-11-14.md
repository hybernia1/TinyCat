# Audit čistého jádra TinyCat

Datum: 14. 11. 2026. Revize c78ea3b355f38c0cf2a9ed0eb25f37a1314ace51
a osm rozpracovaných souborů předchozí etapy dashboardu a administrace.
Runtime 6.0.2, produkt tinycat.core, prostředí https://tinycat.test/.

**Jde o audit a podklady k domluvě, nikoli schválený implementační plán.**
Aplikační kód, konfigurace serveru, schéma a uživatelská data se v této etapě
neměnily. Předchozí rozpracované změny zůstaly zachované.

## Závěr

První kandidát je start aplikace a opakovaná práce s metadaty. I bez funkčních
modulů proběhne dvojí inicializace UI platformy, 215 kontrol poskytovatelů
a 67 validací definic. Databáze na malé měřené instalaci není doložená hlavní
brzda: zahřáté přihlášení má v doplňkové SQL stopě nula dotazů, dashboard tři.

Samostatná levná možnost je doručování assetů. Přihlášení načítá 230 769 B
JavaScriptu a místní Apache ho v ověřených odpovědích nekomprimuje.
Další práce je údržbová: přesnější typy, dlouhá poziční volání a velké třídy.
Zkrácení souboru ani snížení počtu tříd samo o sobě nezrychlí request.

## Rozsah a metoda

- Čisté core má osm tabulek a jednoho administrátora. Nemá dostupné, aktivní
  ani nainstalované funkční moduly; Social a Blog v měřeném runtime nejsou.
- Shoda všech 508 zdrojových souborů [App/Core](../../App/Core) mezi checkoutem
  a instalací ověřena po normalizaci konců řádků. Generované soubory jsou mimo počet.
- Podrobně prošel runtime, registry, transport, konfigurace, cache, UI,
  překlady, účtový čtecí model a vybrané části updateru. Ostatní oblasti jsou
  v inventáři. Nejde o bezpečnostní audit každé metody.
- HTTP: stávající Laragon Apache, HTTPS a zapnutá OPcache. Závěrečná série má
  pět sekvenčních GET pro každou ze sedmi tras. Bez souběhu, restartu, mazání
  cache, velkého datasetu nebo změny serverové konfigurace.
- SQL: čerstvé CLI procesy nad stejnou instalací a databází, s existujícím
  ProfileDatabase. Jen čtení, žádné DDL. CLI časy nejsou časy Apache.
- Počty volání: profiler v soukromé kopii runtime. Jeho chyba byla obejita
  pouze v této kopii; viz A10. Absolutní instrumentované časy nejsou použité
  jako příslib výkonu.
- Neběžel preflight, zátěžový test, nový serverový stack ani velké seedování.
  Neměřil se layout a CPU mobilního prohlížeče. Do apokalypsa.net se nezasahovalo.

## Mapa jádra

Inventář: **314 PHP souborů, 26 762 řádků PHP**. Zahrnuje šablony a migrace;
nejde o počty tříd ani metriku složitosti.

| Oblast | PHP soubory | Řádky PHP |
| --- | ---: | ---: |
| [Database](../../App/Core/Database) | 11 | 1 415 |
| [Discovery](../../App/Core/Discovery) | 4 | 208 |
| [Feeds](../../App/Core/Feeds) | 8 | 722 |
| [Files](../../App/Core/Files) | 3 | 408 |
| [Health](../../App/Core/Health) | 8 | 359 |
| [Http](../../App/Core/Http) | 13 | 1 741 |
| [Identity](../../App/Core/Identity) | 47 | 3 672 |
| [Installation](../../App/Core/Installation) | 6 | 803 |
| [Links](../../App/Core/Links) | 7 | 1 421 |
| [Mail](../../App/Core/Mail) | 4 | 314 |
| [Modules](../../App/Core/Modules) | 47 | 2 105 |
| [Notifications](../../App/Core/Notifications) | 14 | 576 |
| [Runtime](../../App/Core/Runtime) | 14 | 722 |
| [Security](../../App/Core/Security) | 13 | 1 217 |
| [Settings](../../App/Core/Settings) | 11 | 943 |
| [Support](../../App/Core/Support) | 32 | 4 307 |
| [Tasks](../../App/Core/Tasks) | 24 | 1 170 |
| [Text](../../App/Core/Text) | 8 | 643 |
| [Ui](../../App/Core/Ui) | 30 | 1 845 |
| [Updates](../../App/Core/Updates) | 10 | 2 171 |

Běžný tok: index → Application.start → výběr katalogu → inicializace služeb
→ registry příspěvků → registrace rout → HttpKernel → autorizace → resource/UI.

Registruje se 80 rout. Samotný počet není chyba: core vlastní účty, nastavení,
aktualizace a další povinné funkce. Datový základ tvoří users,
password_reset_tokens, settings, notifications, ip_action_limits,
scheduler_runs, module_installations a module_migrations.

## Naměřený základ

### HTTP přes skutečný Apache

TTFB zahrnuje nové TLS spojení. Druhý čas je rozdíl time_starttransfer minus
time_pretransfer: orientační čekání po přípravě přenosu, nikoli čistý čas CPU.
Pět vzorků stačí pro orientaci, nikoli pro p95, kapacitu nebo SLA.

| Trasa | Identita | HTTP | Medián TTFB | Medián čekání po přípravě přenosu |
| --- | --- | ---: | ---: | ---: |
| /login | anonymní | 200 | 76,4 ms | 51,6 ms |
| /privacy | anonymní | 200 | 62,6 ms | 36,6 ms |
| /admin | admin | 200 | 85,7 ms | 63,3 ms |
| /admin/users | admin | 200 | 119,3 ms | 81,2 ms |
| /admin/modules | admin | 200 | 109,8 ms | 87,7 ms |
| /api/users | admin | 200 | 70,1 ms | 47,7 ms |
| /api/settings/editor | admin | 200 | 76,2 ms | 43,9 ms |

/admin/settings není stránka: nastavení používá modal /api/settings/editor.
/robots.txt bez přispěvatelů sitemap není registrován. Jejich 404 v orientační
sérii nejsou doložené regrese ani výkonnostní problémy.

### Doplňková SQL stopa

Cache nastavení byla zahřátá. Počty souborů zahrnují diagnostický obal.
Špička paměti 6–8 MiB patří celému CLI procesu, nikoli workeru Apache/PHP.

| Trasa | SQL dotazy | Zaznamenaný čas SQL | Načtené soubory |
| --- | ---: | ---: | ---: |
| /login | 0 | 0 ms | 158 |
| /privacy | 0 | 0 ms | 153 |
| /admin | 3 | 1,195 ms | 152 |
| /admin/users | 4 | 2,439 ms | 160 |
| /api/users | 5 | 2,585 ms | 144 |
| /admin/modules | 1 | 0,333 ms | 150 |

Čas helperu zahrnuje vykonání příkazu a buffering PDO, nikoli následnou PHP
transformaci. Jde o jednotlivé doplňkové průchody. Nelze z nich vyvodit rychlost
při desítkách tisíc účtů ani totožný počet SQL ve všech webových stavech.

### Assety

| Stránka | JavaScript | CSS | Komprese při požadavku --compressed |
| --- | ---: | ---: | --- |
| /login | 230 769 B | 45 372 B | žádná, identity |
| /admin | 216 768 B | 45 302 B | žádná, identity |

Jde o první načtení čtyř skriptů a jednoho stylesheetu; bez HTML, SVG a dalších
API. Opakovaná návštěva s browser cache má jinou bilanci. Měřily se skutečně
odkazované varianty. Výpis httpd -M potvrdil filter_module, ale bez kompresních
modulů deflate_module a brotli_module.

## Nálezy

Priority jsou doporučení k diskuzi: P1 řešit nejdřív, P2 navazující práce,
P3 až po důkazu přínosu.

### A1 — Dvojí inicializace runtime, P1

[Application](../../App/Core/Runtime/Application.php), ř. 26–56, nastavuje
Resources a inicializuje Platform ve start() a znovu v boot().
Profil potvrzuje obě operace dvakrát při /login i /admin.

Kandidát: jasné vlastnictví přípravy a jediný dokončovací krok. Zachovat
samostatný boot(Context) pro izolované scénáře, lazy callbacky a pořadí služeb.
Nezavádět produktovou vrstvu nebo nový DI kontejner. Kontrola výsledku: jedna
inicializace, stejné routy, příspěvky, oprávnění a funkční core bez modulů.

### A2 — Opakované kontroly poskytovatelů a descriptorů, P1

[Providers](../../App/Core/Modules/Providers.php) přes validate, provider,
checked, schemas a schemaOwners opakují ověření vlastnictví tříd reflexí
a cestami. [Loader](../../App/Core/Modules/Loader.php) a
[Deployment](../../App/Core/Modules/Deployment.php) znovu čtou nebo validují
distribuční metadata.

Login i dashboard vykázaly **215 volání provider, 67 volání validate** nad
12 frameworkovými/technickými definicemi. Distribution::validate běží sedmkrát.

Kandidát: výsledek ověření a odvozená metadata sdílet v rámci requestu nad
konkrétním neměnným katalogem. Kontroly vlastnictví neodstraňovat a nedůvěřovat
pouze adresáři. Scoped katalog, změna modulu a instalace musí mít odpovídající
invalidaci. Přínos ověřit znovu HTTP měřením, ne jen profilerem.

### A3 — Přenos a výběr klientského kódu, P1/P2

Stávající [.htaccess](../../.htaccess) kompresi podporuje, ale současný Apache
nemá potřebný modul. Nejprve vyhodnotit existující minifikované varianty
a kompresi statických textových assetů. Jde o samostatnou konfigurační změnu;
audit ji neprovedl a nestanovuje stejný stav všech produkčních serverů.

[Manifest Identity](../../App/Core/Identity/assets/module.json) vybírá společný
entry pro public i admin. Jeho [index](../../App/Core/Identity/assets/index.js)
importuje avatar editor i registraci administračního seznamu uživatelů.
Přihlášení tak dostává širší účtovou výbavu, než přímo používá.

Další kandidát: jemnější klientské featury v existujících manifestech, pokud
to jejich výběr umožní. Zachovat deklarované závislosti i účtové modaly na
veřejných stránkách. Neoddělovat Identity do volitelných modulů, nepsat druhý
loader a neupravovat generované soubory ručně. Měřit také browser CPU.

### A4 — Opakované fingerprinty, P2

[Resources::fingerprint](../../App/Core/Modules/Resources.php), ř. 34,
opakovaně vybírá ID, řadí, serializuje a hashuje. Login jej volá 31krát,
dashboard 30krát. [CompiledAssets](../../App/Core/Support/CompiledAssets.php)
a [CompiledTranslations](../../App/Core/Support/CompiledTranslations.php)
manifesty již cachují, jejich platnost však opakovaně kontrolují.

Kandidát: fingerprint katalogu a ověřený manifest cachovat po dobu stejné
revize výběru. Zachovat fallback při jiné aktivaci. Jde o menší optimalizaci
než A1/A2; počet volání není počet čtení souboru.

### A5 — Čtecí model účtů a škálování SQL, P2

[ReadModel](../../App/Core/Identity/ReadModel.php), ř. 27, používá SELECT *.
[Query::count](../../App/Core/Database/Query.php), ř. 123, obaluje základní výběr
do odvozené tabulky. SQL stopa oba tvary potvrzuje.

Kandidát: explicitní projekce podle čtecího účelu a ověření plánů filtrování,
hledání a počítání. MySQL může vnořený count optimalizovat; bez EXPLAIN
a většího izolovaného datasetu nelze tvrdit opak ani přidávat indexy.
Autorizace nesmí přijít o potřebné údaje a resource nesmí vracet interní
sloupce. N+1 dotazy v měřených trasách doložené nejsou.

### A6 — Komponenty a příprava dokumentu, P2

[Components::templates](../../App/Core/Support/Components.php), ř. 29, při
prvním použití čte JSON katalogy a prochází jejich strom pro extensions.
Další volání již používají memoizaci. Kompilované assety a překlady tedy
neznamenají kompilovaný serverový strom komponent.

Pozdější kandidát k měření: validačně svázaný předpřipravený katalog v buildu,
se správnou invalidací podle výběru. Jediná komponentová struktura musí dál
sloužit PHP i AJAX prezentaci.

Veřejný [DocumentData](../../App/Core/Ui/DocumentData.php) a administrační
[Layout](../../App/Core/Ui/Admin/Layout.php) samostatně připravují část identity,
assetů, flash zpráv a dokumentu. Část lze sjednotit bez sloučení odlišných
shellů. Zachovat noindex, autorizaci a administrační akce. Zatím jde hlavně
o údržbový nález, ne prokázaný významný výkonový problém.

### A7 — Přesnost typů a čitelný zápis, P2

[Contributions](../../App/Core/Modules/Contributions.php), ř. 27, má 21
konstruktorových parametrů na jednom řádku. [Registries](../../App/Core/Modules/Registries.php),
ř. 54, je předává pozičně. Kontrola vazeb a bezpečné přidání pole jsou obtížné.

Kandidát: pojmenované argumenty, víceřádkový zápis a přesné array-shapes pro
pevné datové struktury. Uzavřené interní stavy mohou dostat pojmenované typy;
otevřené kontrakty modulů zbytečně neuzavírat enumem. Nevytvářet DTO ke každému
lokálnímu poli jen kvůli formě.

Další konkrétní úklid: historické názvy tc_admin_* v Identity, mix
snake_case/camelCase, redundantní importy ve vlastní namespace a neurčité
`array<mixed>` na hranicích. Nejprve zjistit volající. Kompatibilní jména,
publikované migrace a jejich identifikátory nepřejmenovávat plošně.

### A8 — Velké víceúčelové třídy, P2/P3

Největší jsou [Updates/Manager](../../App/Core/Updates/Manager.php) (1 152 řádků),
[Minifier](../../App/Core/Support/Minifier.php) (820),
[Cache](../../App/Core/Support/Cache.php) (772) a
[Links/Providers](../../App/Core/Links/Providers.php) (732).

Kandidát: vymezit odpovědnosti a potom případně oddělit privátní části pod
stávající fasádou. U Cache backendy, generované soubory a diagnostiku; u updateru
transport, bezpečnost cest, journal, zálohu a dokončení. Nevytvářet druhé
implementace společných služeb a nepřesouvat kód jen podle délky souboru.

### A9 — Provozní výkon obnovy DB, P2, zatím nezměřeno

[Snapshots::restore](../../App/Core/Database/Snapshots.php), ř. 96, vykonává
příkazy z indexu samostatně. V této větvi není explicitní dávkování INSERTů
ani řízení transakcí pro datové bloky. Na větším datasetu může být režie
významná; dnešní audit restore nespouštěl.

Kandidát: měření na dočasné DB, oddělení DDL s implicitními commity od dat,
omezené dávky a kontrola skutečné obnovitelnosti. Nevypínat záruky zálohy.
Nejde o hot path běžné stránky, proto to nepatří do prvního úklidu startupu.

### A10 — Chyba profilovacího nástroje, P1 před hlubším profilováním

[Instrumenter](../../tools/performance/instrument-performance.php), ř. 66–67,
ukládá vložení začátku a konce funkce do mapy podle offsetu. U těla {} jsou
oba offsety shodné a druhé vložení přepíše první. Vznikne syntaktická chyba
unexpected token finally, například v konstruktoru Loaderu.

Na čistém core se problém projevil u 13 prázdných těl. Neúspěšné profily se
nepoužívají jako výsledky. Pro doplňkové počty volání byla jen v soukromé kopii
tato těla ponechána bez instrumentace. Zdroj nástroje zůstal beze změny.

Kandidát: sloučit vložení na stejném offsetu a přidat malou fixture prázdné
funkce/konstruktoru. Fatální chyba nebo neúplný bootstrap musí znamenat chybu
měření, nikoli zdánlivé HTTP 200 a nulovou práci.

## Co zachovat

- Vlastnictví dat a zdrojů: core bez univerzálního obsahu, Social/Blog samostatně.
- Jedinou implementaci HTTP, DB, session, mailu, úloh, cache a updateru.
- Lazy callbacky a registraci bez SQL, DDL a provozních vedlejších efektů.
- Kontrolu vlastnictví providerů, bezpečné cesty a ověření balíčků.
- Autorizaci, CSRF, JSON kontrakt, stabilní stránkování a viditelnost.
- Jednotné komponenty, modaly a popovery včetně build kontrol.
- Neměnné publikované migrace/checksumy a ověřenou obnovu/rollback.
- Existující memoizaci identity, nastavení a katalogů; nová cache nebo Redis
  nejsou na základě těchto měření zdůvodněné.

Readiness obsahuje nákladné kontrolní cesty. Běžné hot requesty dokončené
instalace je však podle [Platform](../../App/Core/Ui/Platform.php), ř. 26,
a SQL stopy neprovádějí. Tvrzení, že každá stránka ověřuje všechny tabulky
přes INFORMATION_SCHEMA, by zde nebylo správné.

## Podklady k dohodě

Doporučené pořadí: **A10 → A1/A2 → stejné měření znovu → A3/A4 → A7**.
A3 lze probrat samostatně jako lokální konfigurační práci. Pro A5/A6/A8/A9
nejprve doplnit důkaz přínosu před rozsáhlým refaktorem.

První malou implementační etapu bych omezil na jedinou inicializaci a sdílení
již ověřených metadat v rámci requestu. Hotová je až při zachovaných
kontraktech/routách a opakovatelném porovnání na stejné sestavě. Procentní
úsporu ani cílové latence nyní neslibujeme.

Průběžně stačí krátké cílené kontroly: syntaxe/styl, registry, offline startup
a HTTP smoke. U klientských featur navíc příslušné UI a modal/popover regrese.
Úplný preflight patří před vydáním na konec ucelené etapy. Release smoke musí
kontrolovat skutečné HTML a resource po aktualizaci, nejen verzi a readiness DB.

## Data měření

Rutinní podklady jsou v ignorovaném pracovním adresáři:

- [HTTP](../../storage/performance/core-audit-20261114/http-refined.json)
- [SQL a footprint](../../storage/performance/core-audit-20261114/cli-sql-probes.json)
- [Volání přihlášení](../../storage/performance/core-audit-20261114/calls-login.json)
- [Volání dashboardu](../../storage/performance/core-audit-20261114/calls-admin.json)
- [Assety](../../storage/performance/core-audit-20261114/asset-footprint.json)
- [Inventář](../../storage/performance/core-audit-20261114/inventory.json)
- [Shoda zdrojů](../../storage/performance/core-audit-20261114/source-parity.json)

Výsledky platí pro malou lokální instalaci s jedním účtem. Nejsou důkazem
propustnosti při souběhu, škálování MySQL nebo rychlosti produkčního serveru.

## Navazující etapa malých zásahů

Po dohodě následovala samostatná implementační etapa. Výše uvedené údaje
zůstávají původním auditem; níže je porovnání po úpravách:

- A1: platforma a výběr zdrojů se inicializují pouze jednou. Opakovaný start
  je odmítnut před změnou společného stavu.
- A4: fingerprint se uchovává pro aktuální revizi katalogu. Změna scoped
  výběru i jeho opuštění cache zneplatní.
- A7: konstruktor Contributions je víceřádkový, Registries předávají všechny
  položky pojmenovanými argumenty. Veřejný kontrakt se nemění.
- A10: vložení profileru na stejný offset se spojí. Regrese ověřuje prázdnou
  funkci, metodu i konstruktor; soukromá úprava profilu už není potřeba.

| Kontrola | Předtím | Potom |
| --- | ---: | ---: |
| Platform::initialize na /login i /admin | 2 | 1 |
| Resources::configure na /login i /admin | 2 | 1 |
| Catalog::definitions na /login | 43 | 13 |
| Catalog::definitions na /admin | 40 | 11 |
| Ověření poskytovatelů na /login i /admin | 215 | 215 |
| Registrované routy | 80 | 80 |

Kontroly vlastnictví poskytovatelů zůstávají beze změny. Jejich případná
memoizace patří do další samostatné etapy. Porovnání nedokazuje procentní
zrychlení HTTP; snižuje doloženou opakovanou práci.

Prošly cílené testy profileru, registrů a minimálního startu, PHP lint, styl
a PHPStan. Přihlášení, dashboard, správa účtů a účtové API prošly HTTP smoke.
Čtyři runtime soubory byly synchronizovány do čisté instalace tinycat.test
se zálohou původních souborů. Databáze a konfigurace Apache se neměnily.
Plný preflight v této etapě neběžel.

[Data porovnání](../../storage/performance/core-easywins-20261114/comparison.json)
