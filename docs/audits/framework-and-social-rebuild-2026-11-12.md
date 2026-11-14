# Povinný framework a jeden Social — 12. 11. 2026

Etapa je implementovaná a nasazená na `https://tinycat.test/` jako
`6.0.0-dev.6`. Není to veřejný release. Aktuální strukturu popisuje
[core a moduly](../development/core-and-modules.md).

## Výsledek

- Povinné služby jsou v `App/Core/`: identita včetně účtového API a UI,
  Publishing, bezpečnost, nastavení, instalace, pošta, notifikace, plánovač,
  diagnostika a aktualizace. Updater má vlastní `Core/Updates`.
- Framework registruje čistý `Core/services.json` přes `Core/Runtime/Framework`.
  Nemá vypínatelné modulové manifesty ani položky ve správci modulů.
- `App/Modules/` obsahuje pouze `Social` a `Blog`. Social má jeden manifest,
  jednu aktivaci a jeden vstup `src/Module.php`; profily, statusy, moderace,
  hledání, automatizace a veřejná prezentace jsou jeho vnitřní části.
- Překlady a klientské definice Socialu mají společného vlastníka. Importy,
  šablony, nástroje, testovací fixture a dokumentační odkazy používají nové cesty.
- Administraci vlastní `Core/Ui/Admin`; uživatelské menu vlastní Core/Identity.
  Sdílená administrace nevyžaduje veřejné sociální téma. Správce nabízí dva
  skutečné moduly a jako modulové závislosti nezobrazuje povinné služby core.
- Normalizaci blokovaných URL přispívá Social do schématu vlastního nastavení.
  Core/Settings neimportuje sociální moderaci; serverový callback se neposílá do API.

## Aktualizace a data

Descriptor formátu 2 odděluje povinný `framework`, funkční `selection` a
`schemaOwners`. Jeden Social si ponechává původní migrační streamy Users,
Status, Moderation a Bots. `Core/Modules/Upgrade` převádí pouze aktivační ID;
nepřepisuje databázovou historii a neumožňuje zahodit neznámého nainstalovaného vlastníka.

Všech **47 publikovaných migračních souborů** má stejný normalizovaný checksum
jako před etapou. Rovněž všech 24 jazykových a e-mailových katalogů po sloučení
obsahuje stejné klíče a hodnoty. Mazací manifest zachovává starší položky a
přidává 656 přesných již nahrazených aplikačních cest; neobsahuje provozní data.

Živá modulová instalace přešla standardním podepsaným updaterem přes
`6.0.0-dev.5.1` na `6.0.0-dev.6`. Přechodový balíček zachoval předchozí doménový
kód a doplnil čtení metadat a převod aktivací. Oba kroky proběhly bez nových
migrací a s ověřením proti existujícímu důvěryhodnému klíči. Použily soukromý
rescue CLI, nikoli zrcadlení repozitáře do webu.

Zálohy těchto aktualizací:

```text
6.0.0-dev.5-to-6.0.0-dev.5.1-20261112-185733-74fe2bf1
6.0.0-dev.5.1-to-6.0.0-dev.6-20261112-190737-5a9b349d
```

Původních 11 631 publikací má shodný kontrolní otisk ID, autora, textu,
publikačního času a stavu. Konfigurační soubory i 9 141 souborů médií zůstaly
beze změny. Během souběžného ručního používání přibyl jeden článek; živá DB
má nyní 11 632 publikací, dva články a 38 účtů. Přibyly také provozní záznamy
limiteru a dvě nastavení; přístupový log potvrzuje úspěšný zápis nastavení
z prohlížeče. Žádný počet řádků tabulky neklesl.

## Ověření a měření

Měřený `composer preflight` byl po opravách navázán stávajícími runnery pouze
pro neúspěšné či dosud neprovedené skupiny. Závěrečné cílené běhy ověřily
poslední změny registrace, hranic, UI a Blogu. **Nejde o jeden nepřerušený zelený
preflight**; souhrn slučuje poslední výsledky jednotlivých kontrol.

- 87 hlavních skupin prošlo, jedna byla přeskočena kvůli chybějícímu Memcached.
- Všech 38 frontendových testů prošlo, včetně nativních browserových regresí.
- Prošlo všech sedm samostatných kontrol preflightu, PHPStan level 8 a lint PHP 8.4.
- Prošly podpisy, inventáře balíčků, modulový lifecycle, obnova přerušené operace,
  přesný rollback, aktualizace z 5.16 a instalace v izolované MySQL.
- Blog prošel samostatně i společně se Socialem; browser ověřil editor, přepínání
  modulů a dvanáct rozložení v šířkách 1440 a 390 px.
- `asset-report --check` prošel pro devět scopes výchozí sestavy. Veřejný JS
  má 77 870 B gzip; rozpočet se zvýšil o 128 B kvůli změně pořadí zdrojů.
  Počet skriptů a stylových požadavků se nezměnil. UI inventář nemá nevyřešené reference.
- Symlinkový scénář katalogu byl přeskočen kvůli nedostupnému vytváření symlinků.

Součet posledních zaznamenaných běhů hlavních skupin je **1 023,572 s
(17 min 4 s)**; všechny pokusy včetně opravných běhů spotřebovaly 1 175,419 s.
Čas úprav, nasazení a čekání na Laragon v těchto číslech není.

| Skupina | Poslední běh |
| --- | ---: |
| Frontend včetně nativních browserů | 336,696 s |
| Podepsaný modulový lifecycle | 153,977 s |
| HTTP API kontrakt | 96,265 s |
| Aktualizace a rollback | 91,803 s |
| Podepsaný distribuční balíček | 45,284 s |
| Blog a souběh se Socialem | 28,344 s |
| Modulový pilot | 26,812 s |
| Výběr zdrojů a cache | 21,167 s |

První čtyři skupiny tvoří přibližně 66 % času. Samotné dotykové popovery uvnitř
frontendové sady trvaly 147,337 s. Počet hlavních skupin zůstal 88 a frontendových
testů 38; nepřibyla další úplná integrační kopie. Existující Blog fixture nyní
čeká na skutečnou navigaci po přepnutí, místo závodu se starou načtenou stránkou.

Živé HTTPS ověření prošlo ve společném režimu, s vypnutým Socialem i po obnově:
46 + 9, 23 + 10 a 46 + 10 kontrol. Ověřilo assety, ochranu administrace a API,
zmizení sociálních rout a zachování Blogu a core služeb. Jeden pokus se střetl
se souběžným přepnutím z prohlížeče; následný souvislý běh prošel. Konečný stav
má oba moduly aktivní a vypnutou údržbu. Výpadek služeb Laragonu mezi oběma
aktualizacemi byl vyřešen jejich opětovným zapnutím uživatelkou; nevznikl další stack.

Podrobné časy, výsledky, snímky UI, parity a živé kontroly jsou v ignorovaném
`storage/core-social-rebuild-20261112/`; měřicí reporty v `storage/test-runs/`.
Podepsané vývojové balíčky jsou mimo repo v
`%LOCALAPPDATA%/TinyCat/builds/framework-20261112/`.
