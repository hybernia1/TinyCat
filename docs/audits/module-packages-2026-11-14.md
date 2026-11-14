# Samostatné modulové balíčky: 14. 11. 2026

Etapa rozšiřuje správce modulů o ruční instalaci a aktualizaci ZIPu. Online
katalog a destruktivní odinstalace zůstávají mimo rozsah. Cílový runtime je
`6.0.0-dev.9`; nejde o veřejně vydaný release.

## Výchozí problém

Aktivační rozhraní umělo jen moduly předem přibalené do podepsané distribuce.
Samotné nahrání adresáře do `App/Modules` jej správně neaktivovalo, ale nebyl
podporovaný způsob jeho dodatečné registrace. Čisté core tak nemohlo dostat
první samostatný modul prostřednictvím administrace.

## Implementace

- Kořenový `package.json` zaznamenává identitu a verzi balíčku, kompatibilitu,
  vlastní manifest, inventář souborů a migrační historii s checksumy.
- `Core/Modules/ModulePackage` ověřuje omezený ZIP bez spuštění jeho PHP.
  `PackageRegistry` doplňuje explicitní místní registr k podepsané distribuci.
  Neprobíhá automatické hledání ani aktivace podle adresářů.
- Administrace používá společný lazy resource modal a multipart transport.
  Upload vyžaduje administrátora, CSRF a potvrzení přístupu PHP k aplikaci.
  První instalace nechává modul vypnutý; SQL patří až explicitní aktivaci.
- Novější balíček zachová aktivaci i data. Aktualizuje i schéma dříve zapnutého,
  nyní vypnutého modulu. Publikovanou historii migrací nesmí změnit.
- Modulové soubory i registr používají stejný updater, zámek, údržbu, journal,
  zálohy a rollback jako distribuce. Aktualizace core lokální moduly zachová
  a odmítne překrytí jejich souborů nebo vlastníků.
- Autor má `tools/release/build-module.php`, provoz `App/Console.php module-install`.
  Distribuční builder podporuje čisté `--assembly=core` a prázdné `--active=`.

Modul již přibalený distribucí se zatím aktualizuje distribučním updaterem.
ZIPy nejsou podepsaný katalog; instalátor kontroluje integritu a strukturu,
nikoli důvěryhodnost autora. Maximální archiv má 32 MiB, extrakce 100 MiB a
5 000 souborů. PHP/webserver mohou mít nižší upload limity.

## Ověření

Scénáře rozšiřují existující lifecycle a nativní Blog fixture, nepřidávají
další úplnou integrační matici. Izolovaný test ověřuje instalaci čistého core
s 18 tabulkami, dodatečný modul, odložené DDL, aktualizaci core se zachováním
modulu, migrace a obnovu. Negativní případy odmítají změněná data, cizí cesty,
kolize namespace, závislostí a tabulek. Browser ověřuje upload, potvrzení,
aktivaci, vypnutí a novější ZIP bez inicializace vypnutého modulu.

První browserový průchod odhalil chybějící autoload kontext při kontrole
tabulek distribučních modulů; kontrola nyní drží jejich katalog po celou dobu.

Úplný rozsah `composer preflight` je dokončený: 86 hlavních skupin mimo
frontendový wrapper prošlo, Memcached se přeskočil kvůli nedostupné místní
službě; prošlo všech 38 frontendových scénářů a sedm vstupních kontrol.
Původní preflight se zastavil na CSS referenci. Nový scope zahrnul dvě
existující pravidla prázdné tabulky, která reference neobsahovala; po kontrole
byla doplněna právě tato dvě pravidla. Následoval pouze cílený test `styles.cjs`
a zbývajících 77 hlavních skupin. Původní selhání zůstává v reportu, nejde
o jediný nepřerušený zelený příkaz.

Součet posledních dokončených běhů bez započtení nadřazených wrapperů je
**986,067 s (16 min 26 s)**. Součet všech dokončených pokusů etapy, včetně
diagnostiky uploadu a oprav, je **1 784,464 s (29 min 44 s)**. Nejde o dobu
na hodinách; přerušené nedokončené scénáře nejsou v součtu. Měření je
v `storage/module-packages-20261114/verification.json` a jednotlivých
`storage/test-runs/` reportech. Počet hlavních skupin zůstal 88.

Další praktické ověření použilo skutečně sestavený čistý core instalační ZIP
a samostatný Blog ZIP: 18 tabulek před i po nahrání, 22 po aktivaci, funkční
runtime assety i překlady bez tools, Composeru a Node v cílové instalaci.
Prošel také přípravný import bez rozšíření ZipArchive přes Phar. Podepsané
platformní instalační i aktualizační artefakty a čistý core instalační artefakt
jsou v ignorovaném adresáři etapy; nejde o zveřejněný release.

## Nasazení na tinycat.test

Standardní podepsaný updater převedl externí instalaci z `dev.8` na `dev.9`.
Záloha je `storage/updates/backups/6.0.0-dev.8-to-6.0.0-dev.9-20261114-124152-9730523d`
v živé instalaci. Nové migrace nebyly potřeba; ověřeno je 29 zálohovaných
změněných souborů a všech 1 187 souborů nového runtime podle manifestu.

Porovnání zachovalo přesné datové i schématové hashe všech 30 tabulek,
11 634 publikací, 38 účtů, inventář 9 142 médií, konfiguraci, migrační historii
a aktivaci Blogu i Socialu. Údržba je vypnutá. Prošlo 91 HTTPS smoke kontrol
a dalších pět assetů správce modulů včetně nového upload vstupu; zachována
je také ikona článků. Testovací modul se do živé databáze nenahrával.
Evidence je v `deployment-verification.json`, `live-smoke-combined.json`
a `live-module-assets.json` v adresáři etapy.

Návody: [autor modulu](../development/module-authoring.md#8-samostatný-instalační-zip),
[instalace a správa](../operations/module-deployment.md).
