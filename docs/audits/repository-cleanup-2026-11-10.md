# Audit úklidu repozitáře

Datum: 10. 11. 2026. Výchozí HEAD: `a1fa193` (5.22.1).
Rozsah: verzované soubory, lokální ignorované soubory, dokumentace a vstupní
body nástrojů. Původní audit byl pouze čtecí; navazující provedené změny
zachycují stavové oddíly níže. Runtime ani databáze se při úklidu nemění.
Velikosti jsou logické velikosti souborů, nikoli obsazení disku; nezahrnují `.git`.

## Zjištění

### Úklid dokumentace — aktuální stav

Na žádost uživatele byla dokumentace zredukována a roztříděna. Obsahuje
23 souborů včetně nového `docs/README.md`. V kořeni docs zůstává pouze tento
rozcestník; zbytek patří do contracts, reference, operations, development,
performance, plans a audits.

Odstraněno bylo 23 překonaných souborů: baseline 2.0.25, plán a reporty etap
2.0.26 včetně výsledných JSON, datované performance/AJAX reporty a manifest,
prototyp kritických assetů a uzavřené audity modalů, UI, moderace a releasu 5.18.
Jejich historie zůstává v Gitu. Aktivní kontrakty a návody zůstávají zachované;
z překladového dokumentu byly odstraněny hotové migrační úkoly a historická
měření, pravidla katalogů a prezentační hranice API zůstala.

Návrh modularity 6.0 a jeho původní inventář zůstávají v plans jako otevřený
návrh, výslovně odlišený od aktuální implementace. Neplatný odkaz na původní
Bots/views byl přesměrován na současný controller. Generátor UI inventáře
zapisuje do reference a byl znovu spuštěn; jeho metodické meze jsou přímo
v generovaném dokumentu místo odkazu na odstraněný audit.

Aktualizované jsou odkazy v README, CHANGELOG a AGENTS, relativní odkazy
v dokumentech, whitelist dokumentace v release builderu a kontroly balíčků.
Balíček nadále obsahuje jen API a aktualizační návod, nyní v nových cestách;
staré distribuované cesty jsou v seznamu souborů odstraňovaných updaterem.
Acceptance agregátor a příklady benchmarků zapisují výsledky do storage.
README již neuvádí dvě jazyky vedle dvanácti ani instalační schéma 2.x.
Historický inventář API byl nahrazen současným návodem `development/modules.md`;
z návodu k partialům zmizely dokončené etapy a poznámky o staré lokální DB.

Ověření změny: všechny lokální Markdown odkazy, regenerace UI inventáře,
PHP syntaxe změněných nástrojů, deterministic style, UI kontrakty (183 kontrol),
updater (13 testů) a podepsané aktualizační i instalační balíčky (851 a 823
runtime souborů). Oba testy balíčků prošly; při úklidu dočasných adresářů
vypsaly na Windows varování `Directory not empty`. Zůstaly pouze prázdné
adresáře dist-b pod dvěma náhodnými package-artifact adresáři, nikoli další
release balíčky. Kompletní preflight ani browser testy nebyly při této čistě
dokumentační a distribuční změně spouštěny.

Následující původní nálezy a inventura zachycují stav před těmito opravami.
Ignorované bezpečnostní testy a sjednocení browser bootstrapu zůstávají otevřené.

### Navazující úklid — 10. 11. 2026

Uživatel po ověření původu výslovně schválil odstranění i záloh, jejichž
lokální původ nebyl doložen. Pro první úklid byl vybrán tento konkrétní rozsah:

- `storage/db-backups/` — SQL dump a záloha nastavení úloh, přibližně 331,60 MiB.
- `storage/backups/` — zálohy úprav příspěvků, YouTube obrázků a souvisejícího
  kódu, přibližně 10,91 MiB.
- `storage/updates/backups/` — historické rollback zálohy, přibližně 863,21 MiB.
- `storage/phpstan/` — obnovitelná cache analýzy, přibližně 154,84 MiB.

**Dokončeno a ověřeno po ručním odstranění uživatelem.** Všechny čtyři
adresáře již neexistují. Automatické pokusy byly předtím odmítnuty kontrolou
s důvodem `blocked by policy`; uživatel následně provedl úklid sám.
Nová inventura zjistila 37 171 ignorovaných souborů o velikosti 1 012,11 MiB.
Oproti výchozí inventuře ubylo 2 005 souborů a přibližně 1 360,57 MiB
(1,33 GiB). Původní tabulka níže zachycuje stav před úklidem.
Konfigurace, klíče, média, runtime cache, dev závislosti, distribuce i ostatní
pracovní instalace nebyly zahrnuty do tohoto pokusu o odstranění.

Při ověření se zjistilo: současná konfigurace používá lokální hosty
a `http://tinycat.test`; zálohy příspěvků uvádějí databázi `apokalypsa`, která se
liší od současné demo DB. Mezi rollback zálohami je explicitní test aktualizace
na `9.9.9-test`, ale původ všech dalších záloh nelze z dostupných metadat
potvrdit. Souhlas s jejich odstraněním proto vychází z rozhodnutí uživatele,
nikoli z tvrzení, že všechny prokazatelně vznikly na localhostu.

- Repozitář má 993 verzovaných souborů, z toho 44 v `docs/`, 80 v `tools/`
  a 154 v `tests/`. Před auditem byl pracovní strom čistý.
- Git ignoruje 39 176 lokálních souborů o celkové velikosti 2 372,68 MiB
  (2,32 GiB). Ignorování samo o sobě neznamená, že je soubor nepotřebný.
- `storage/` směšuje runtime, zálohy, tajné klíče, vývojové závislosti,
  historické release artefakty, testovací instalace a jednorázové experimenty.
- Platné kontrakty a provozní návody jsou v `docs/` vedle historických auditů,
  výsledků měření a plánů bez společného rozcestníku a jasného stavu.

## Prioritní opravy

1. **Bezpečnostní testy nejsou reprodukovatelné z klonu.** README odkazuje na
   `tests/security/README.md`, ale `.gitignore` ignoruje celé `tests/security/`.
   Lokálně existují `README.md` a `run.php`; hlavní `tests/run.php` tuto sadu
   nespouští. `tools/security-check.php`, `tools/style-check.php` a
   `tools/monolith-inventory.php` ji také výslovně vynechávají. Projít obsah,
   verzovat přenositelné testy a určit jejich místo v kontrolách; lokální
   konfiguraci a výsledky držet odděleně. Nestačí opravit odkaz v README.
2. **Historické acceptance nástroje mají staré výchozí cesty.**
   `prepare-acceptance.php` používá `storage/performance-2.0.25/app`
   a `storage/apache-release-stage/app`; `run-acceptance.php`,
   `aggregate-acceptance.php` a `cleanup-acceptance.php` navazují na stage 8.
   Před odstraněním těchto adresářů archivovat celý postup nebo jej převést na
   explicitní parametry. Nezaměňovat jej za současný release preflight.
3. **Závislosti prohlížečových testů mají dva způsoby instalace.** CI instaluje
   Playwright 1.52.0 a axe-core 4.10.3 do dočasného adresáře a nastavuje
   `TINYCAT_TEST_PLAYWRIGHT_MODULE`; více lokálních testů používá fallback
   `storage/ui-webkit-tools/node_modules/playwright`. Sjednotit instalaci,
   rozlišení modulu a dokumentaci. Tento adresář nyní nelze prostě smazat.
4. **Dokumentace obsahuje konkrétní drift.** README uvádí vedle dvanácti jazyků
   také pouze angličtinu a češtinu, instalaci popisuje jako schéma 2.x a obsahuje
   rozbité oddělovače `Admin ? Settings`. Začátek `feeds/README.md` má poškozenou
   diakritiku i při čtení jako UTF-8. Lokální TODO botů z 4. 8. popisuje starý
   `cron.php` a tok, který je nutné znovu porovnat se současným schedulerem.
5. **Historický audit má neplatný odkaz.** Kontrola relativních Markdown odkazů
   ve verzovaných dokumentech našla chybějící
   `assets/modules/bots/views.js` v `modularity-6.0.0-audit.md`.
   Existence cíle neověřuje platnost kotev ani věcnou aktuálnost dokumentu.

## Ignorované soubory: návrh zacházení

| Oblast | Soubory | MiB | Doporučení |
| --- | ---: | ---: | --- |
| `storage/updates/` | 109 | 863,21 | Zachovat; rozlišit rollback zálohy a pracovní data podle updateru a jeho retence. |
| `storage/db-backups/` | 2 | 331,60 | Zachovat; vyhodnotit zálohovací politiku a dostupnost jiné kopie. |
| `dist/` | 395 | 256,18 | Kandidát na promazání starých distribucí po porovnání s publikovanými releasy. |
| `storage/phpstan/` | 1 810 | 154,84 | Obnovitelná cache; kandidát na vyčištění mimo běžící analýzu. |
| `storage/cache/` | 1 493 | 116,92 | Provozní cache; použít pravidla aplikace, nikoli plošné mazání storage. |
| `storage/release-4.6.0-downloaded/` | 1 818 | 104,38 | Historický rozbalený release; kandidát po ověření návazností. |
| `storage/performance/` | 3 521 | 100,47 | Rozdělit zachovávané baseline/reporty a obnovitelné pracovní instalace. |
| `vendor/` | 78 | 83,80 | Aktivní dev závislosti z composer.lock; zachovat. |
| `uploads/links/` | 8 913 | 35,72 | Média; bez kontroly referencí v databázi nelze označit za odpad. |
| `storage/ui-webkit-tools/` | 210 | 20,49 | Aktivní fallback testů; nejdřív sjednotit bootstrap. |
| `storage/update-rehearsal-75da73a72eba/` | 2 317 | 18,48 | Kandidát jako zbytek testu; prověřit dokončení a navázané DB/procesy. |
| `storage/ui-webkit-legacy-tools/` | 485 | 13,29 | Kandidát po ověření uživatelů a sjednocení browser tooling. |
| `storage/backups/` | 85 | 10,91 | Nezařazovat do automatického úklidu bez klasifikace obsahu. |

Další kandidáti: adresáře `storage/patch-mysql-*` (jednotlivě přibližně
7,6–18,9 MiB), `fresh-artifact-*-extracted`, staré `release-*-downloaded`,
QA browser profily, screenshoty, HTML náhledy, logy a jednorázové PHP/Python
skripty v kořeni storage. Jde o kandidáty podle struktury a vazeb, nikoli o
schválený seznam k odstranění. Datum či název sám nepotvrzuje nepotřebnost.

Chránit `config.php`, `feeds/config.php`, média, zálohy a klíče.
Ve storage jsou mimo jiné `update-signing.key`, `integrations.key`,
`extension-signing.key` a testovací podpisové klíče. Audit nečetl jejich hodnoty.
Ani historicky pojmenovaný klíč nelze bezpečně označit za nepotřebný podle názvu.
`feeds/var/` patří samostatné feed aplikaci; není to testovací artefakt TinyCat.
`.agents/plans/` obsahuje lokální plány `custom-pages.md` a `modular-json-api.md`;
nejprve určit jejich stav. `TODO.bot-logic.local.md` ignoruje `.git/info/exclude`;
audit zohledňuje i tento lokální mechanismus, nejen verzovaný `.gitignore`.

## Dokumentace

### Druhá schválená vlna úklidu

Uživatel schválil odstranění starých distribucí, releasů, testovacích instalací
a QA profilů při zachování konfigurace, klíčů, médií a aktivních nástrojů.
Kontrola kandidátů našla junction `vendor` uvnitř
`storage/release-4.6.0-downloaded/` a `signing.key` uvnitř
`storage/update-rehearsal-75da73a72eba/`. Obě celé složky byly zatím vyřazeny.
Nebyla odstraněna žádná další data: automatická kontrola zamítla i samostatné
odstranění `dist/` s důvodem `blocked by policy`.

Pro ruční provedení zbývajícího vybraného rozsahu je připraven
`tools/cleanup-local-artifacts.ps1` s kontrolou cest, odkazů a klíčů a podporou
`-WhatIf`. Pokrývá dist, pojmenované release artefakty, patch-mysql pracovní
adresáře, fresh-artifact rozbalení a čtyři konkrétní QA/preview profily.
Neodstraňuje performance baseline, historické acceptance instalace ani legacy
browser závislosti, které ještě vyžadují rozpletení návazností nástrojů.
**Dokončeno a ověřeno po ručním spuštění skriptu uživatelem.** `dist/` ani
žádný další cílový adresář skriptu již neexistuje. Zůstávají obě záměrně
vyřazené instalace včetně jejich klíče, hlavní i feed konfigurace, podpisový
a integrační klíč, uploads, vendor, aktivní ui-webkit-tools a runtime cache.
Kontrola potvrzuje existenci těchto chráněných cest, nikoli porovnání obsahu
každého jejich souboru s předchozím stavem.

Po druhé vlně zbývá 21 172 ignorovaných souborů o velikosti 547,68 MiB.
Proti stavu po první vlně ubylo 15 999 souborů a přibližně 464,43 MiB.
Celkem od původního auditu ubylo 18 004 souborů a přibližně 1 825,00 MiB
(1,78 GiB). Git nehlásí změnu ani odstranění verzovaných souborů; jedinými
novými soubory jsou tento audit a úklidový skript. Testy aplikace nebyly
spouštěny, protože tato vlna odstranila pouze ignorované lokální artefakty.

Navržené členění:

- Aktivní kontrakty: modal, popover, post text, UI, query selection a partials.
  Zachovat jako normativní dokumenty včetně kontrol a testů.
- Provoz a vývoj: API, updates, storage, profiling, benchmark, link optimization,
  thumbnails a bot cleanup. Doplnit rozcestník, předpoklady a příkazy.
- Historie: baseline 2.0.25, plán 2.0.26, stage 4–9, datovaná měření,
  release audity a uzavřené UI/translation audity. Přesunout do archivu teprve
  po kontrole stavu; spolu s JSON výsledky a opravou všech odkazů.
- Otevřené návrhy: audit modularity 6.0.0 zatím výslovně označuje stav návrhu.
  Nepovažovat jej automaticky za dokončený jen podle stáří.
- Generované inventáře: `ui-inventory.md/json` mají generator
  `tools/ui-inventory.py`. Zachovat dvojici a označit generování; nemanipulovat
  s nimi jako s ručně udržovanou dokumentací.

Release builder výslovně balí jen `docs/reference/api.md` a `docs/operations/updates.md`.
Přesun dokumentů proto musí respektovat i `tools/build-update.php`, nejen odkazy.
CHANGELOG, SECURITY, licence a podporované migrace nejsou kandidáty na mazání
jen kvůli historickému obsahu.

## Sjednocení nástrojů

Zachovat `composer preflight` jako hlavní vstup: již spojuje metadata,
dependency audit, style, překlady, lint, PHPStan, security check a hlavní testy.
CI jej skutečně používá; není důvod vytvářet paralelní univerzální runner.

Oddělit běžné quality nástroje, release/podpisové nástroje, obnovitelné
performance experimenty a údržbu dat. Přesuny v `tools/` dělat až s kontrolou
`__DIR__`, require cest, testů a dokumentace. `seed.php` je záměrný kompatibilní
wrapper na `demo-import.php`, nikoli druhý seeder k okamžitému odstranění.
`prepare-performance.php` vytváří izolovanou DB; historická acceptance sada
řeší porovnání dvou instalací. Podobné názvy nejsou důkaz duplicity.

Pro budoucí pracovní výstupy zavést společný adresář běhu s metadaty: nástroj,
čas, výchozí commit, výsledek, případná testovací databáze a retence. Bootstrap
vývojových závislostí držet odděleně od těchto výstupů. Úklid má nejdřív vypsat
konkrétní kandidáty a chráněné cesty; plošný `git clean -fdX` by odstranil také
konfiguraci, klíče, zálohy a média.

## Doporučené pořadí realizace

1. Zreprodukovat dev/test prostředí z čistého klonu, vyřešit ignorované
   bezpečnostní testy a společný browser bootstrap.
2. Doplnit rozcestník a stavy dokumentů, opravit konkrétní drift; archivovat
   uzavřené reporty společně s výsledky a opravit odkazy/generátory.
3. Vytvořit přesný manifest lokálních kandidátů s velikostí a návaznostmi;
   odděleně rozhodnout retenci release artefaktů a záloh. Ověřit aktivní procesy
   a testovací DB před odstraněním pracovních instalací.
4. Odstranit ověřené obnovitelné výstupy, následně sjednotit nástroje a jejich
   výstupní cesty. Zachovat kompatibilní vstupy tam, kde mají uživatele.
5. Po implementaci spustit odpovídající kontroly a `composer preflight`.
   Zachovat build kontroly modalů/popoverů i nativní regresní testy uvedené
   v jejich závazných kontraktech.

## Meze ověření

Audit provedl inventuru Git tracked/ignored souborů včetně fyzických velikostí,
četl vstupní body a CI, kontroloval relativní Markdown odkazy a vybrané
reference. Neprováděl kompletní analýzu dosažitelnosti každého souboru ani
kontrolu DB referencí médií, procesů, publikovaných release kopií a obsahu záloh.
Nespouštěl testy, build, import, scheduler ani databázové příkazy. Odhad bezpečně
uvolnitelného místa proto zatím nelze zaměnit za celkovou velikost ignorovaných dat.
