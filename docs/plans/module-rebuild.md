# Jedna rebuild etapa: core a samostatné moduly

Zadání z 11. 11. 2026. Tento plán nahrazuje cílovou architekturu s vrstvou
`Composition`. Etapy A–D v předchozím plánu jsou historie provedených změn
a jejich ověření; nejsou důkazem dokončené cílové modularity.

Jde o jednu společně dodanou etapu. Níže uvedené pracovní kroky nejsou další
samostatné etapy s plným testováním mezi nimi. Na konci proběhne měřená úplná
sada a vyhodnocení nákladů. Testy se před získáním výsledků nemažou.

## Cílový stav

```text
App/
  Core/                   # Nezávislé mechanismy, kontrakty a runtime
  Modules/
    Accounts/
      module.json
      src/Module.php
      migrations/
      templates/
      lang/
      assets/
    Publishing/
    Blog/
    …
  modules.php             # Explicitní výběr; žádné PHP propojování domén
  bootstrap.php
  autoload.php
  Console.php
```

`Composition/` ani `Products/` v cíli neexistují. Core nesmí obsahovat
podmínky rozlišující Accounts, Blog, Status nebo konkrétní sociální sestavu.
Instalace může mít identitu a profil potřebné pro kompatibilitu podepsaných
aktualizací; tyto údaje neopravňují runtime větvit podle názvu produktu.

Modul má jeden adresář, stabilní ID, manifest se závislostmi a vlastní
registrační vstup. Core načte pouze explicitně dostupné a aktivní vlastníky,
ověří závislosti a provede registrační/startovací fáze v určeném pořadí.
Registrace popisuje poskytovatele; neprovádí DDL, SQL, session ani další
provozní efekty. Instalace a migrace mají samostatný explicitní lifecycle.

Accounts funguje bez sociálních zdrojů a tabulek. Sociální rozšíření se
registruje u kontraktu Accounts ze svého modulu. Accounts nevolá
Composition, nehledá Status a nevyžaduje sociální layout, Settings ani Mail.
Stejný princip platí pro Publishing a další sdílené domény. Povinné
závislosti jsou deklarované; volitelná rozšíření mají určeného vlastníka,
pořadí a řešení konfliktů, nikoli neurčitou mapu callbacků v centrálním lepidle.

## Rozsah společné realizace

1. **Vlastnictví a kontrakty.** Zmapovat přímé i obrácené závislosti,
   konfiguraci, vykreslování, identitu, locale, doručování a lifecycle.
   Rozdělit skutečné technické služby v Support, Security a Mail od domén.
   Neprovádět slepý přesun celého Support do Core.
2. **Core loader a runtime.** Sjednotit manifestový a vestavěný způsob
   načítání. Registrace rout, datového schématu, překladů, assetů, navigace,
   úloh a rozšíření používají jednu sadu kontraktů. Bootstrap a HTTP vstup
   spouštějí core; nevybírají BlogApplication/BuiltInApplication.
3. **Samostatná inicializace modulů.** Převést současné BuiltInAccounts,
   BuiltInPublishing a BlogApplication na chování příslušných vlastníků.
   Odstranit centrální rozhodování o dvojicích modulů. Před každým hookem
   určit, komu patří kontrakt a komu implementace. Nezakládat jeden náhradní
   „integrační modul“ obsahující veškeré staré propojení.
4. **Jednotné adresáře.** Přesunout funkční moduly včetně jejich assets,
   překladů, templates a schema histories do App/Modules. Aktualizovat
   namespaces, autoload, manifesty, source roots, komponentové a assetové
   cesty, fixtures, vývojové nástroje, dokumentaci a architektonické kontroly.
   Vnější API a URL se tím nemění. Nevytvářet další trvalou legacy hierarchii.
5. **Distribuce a kompatibilita.** Převést výběr, aktivaci, migrace, CLI,
   signed inventory a rollback na nový model. Stabilní ID úloh a modulů,
   historie a publikované checksumy zůstávají. Historické globální migrace
   se nepřepisují; nutné kompatibilní vstupy mají přesný důvod a vlastníka.
   Mazací manifest musí pokrýt staré cesty bez zásahu do konfigurace a dat.
6. **Měření existujících runnerů.** Před závěrečným během doplnit časování
   preflight kroků, PHP skupin a jednotlivých JS scénářů. Použít současné
   tools/preflight.php, tests/run.php a tests/frontend/run.php; nevytvářet
   další univerzální runner. Podporovat výpis a explicitní výběr skupin pro
   opravy, úplný výchozí rozsah neměnit potichu.
7. **Jeden závěrečný měřený průchod.** Po dokončení celé implementace spustit
   composer preflight. Při chybě opravit stejnou etapu a cíleně opakovat
   ovlivněné skupiny. Report nesmí vydávat postupné dokončení za jediný
   nepřerušený zelený běh ani přenášet starý výsledek přes relevantní změnu.
8. **Vyhodnocení testů.** Zpracovat nejdražší skupiny, opakované buildy,
   fixture přípravu a starty browserů. Pro každý návrh ponechat/sloučit/
   nahradit/odstranit uvést chráněné chování a zbývající pokrytí. V tomto
   prvním běhu nic nevyřazovat jen kvůli délce nebo počtu assertions.

## Rozdělení dnešního Composition

| Dnešní část | Cílový vlastník a podmínka |
| --- | --- |
| Application, GenericApplication | Core runtime a společný HTTP vstup bez produktových větví. |
| Resources, Registrations, ModuleAutoload | Core katalog/registry a zdrojový loader bez znalosti konkrétních modulů. |
| LegacyModules, ManifestModules | Jeden core loader; odstranit rozdílné zacházení s vestavěnými doménami. |
| Distribution, Deployment, Lifecycle | Obecná distribuce a lifecycle v Core; výběry, schémata a kompatibilní baseline dodávají deklarace vlastníků. |
| Console | Core CLI; App/Console.php zůstává tenký vstup. |
| AccountParticipants | Kontrakt vlastní Accounts, obecný registry mechanismus core; cleanup dodávají nainstalovaní vlastníci svých dat. |
| BuiltInAccounts, BuiltInPublishing | Rozdělit konkrétní politiky a rozšíření mezi vlastníky, původní třídy odstranit. |
| BuiltInApplication, BlogApplication | Zrušit specializované aplikační starty; Blog registruje svoje routy a výchozí chování uvnitř modulu. |

Tato tabulka není mechanický seznam přejmenování. Přesun stejného doménového
větvení pod Core by nesplnil zadání.

## Měřený výstup

Výsledky patří do ignorovaného `storage/`, každý průchod má vlastní ID.
Strukturovaný report obsahuje:

- Revizi a otisk testovaného pracovního stromu, verze PHP/Node, OS,
  dostupné DB a browser enginy bez tajných konfiguračních hodnot.
- Plánované i skutečně spuštěné položky, stabilní ID, příkaz, rodičovskou
  skupinu, začátek/konec, reálnou dobu z monotónního času a exit code.
- Stav passed/failed/skipped/not-run. Exit 0 s výpisem SKIP není provedená
  integrace; smíšené skupiny mohou obsahovat passed i skipped scénáře.
- Log položky a návaznost opakovaného pokusu. Neprovedené položky po pádu
  zůstávají v reportu, úspěšné předchozí výsledky se nemažou.
- Samostatný celkový čas a časy potomků; frontend se při součtu nepočítá
  dvakrát. Timeout a přerušení nesmějí být zaznamenané jako úspěch.

Report porovná skutečné náklady a pokrytí. Bez srovnatelného dřívějšího
měření nebudeme tvrdit procentuální zrychlení rebuildu. Tento běh poskytne
baseline pro následující optimalizaci testů.

## Akceptace etapy

- Žádný adresář, namespace, import ani runtime závislost na Composition;
  historické dokumenty mohou původní stav popisovat.
- Všechny funkční moduly mají jednotné umístění a vlastní registraci;
  přidání modulu nevyžaduje editaci core ani centrální propojující třídy.
- Accounts a Publishing projdou izolací bez sociálních zdrojů; Blog i
  sociální instalace startují stejným core bootstrapem. Core nemá obrácené
  doménové importy ani speciální seznam známých doménových politik.
- Zachované HTTP/UI kontrakty, oprávnění, CSRF, překlady, query budgets,
  aktivace a cleanup neaktivních dat, podpisy, podporovaný update a rollback.
- Všechna dosavadní relevantní ověření mají dohledatelný měřený výsledek
  nebo výslovný důvod neprovedení. Nové testy ověřují nové hranice; nevrství
  další kompletní kopie již existujících scénářů.
- Dokumentace i AGENTS odpovídají výsledku, zveřejněný release ani commit
  nevznikají automaticky. Provozní konfigurace, klíče a data se nemění.

Stav: implementace a ověření etapy dokončeny. `App` obsahuje Core a Modules,
Composition je odstraněné a výběr i inicializaci vlastní manifesty modulů.
Úplný plán 88 skupin a 38 JS scénářů byl ověřen v měřených navazujících bězích
po opravách; nejde o jeden nepřerušený zelený preflight. Memcached integrace
a symlink fixture mají výslovné důvody přeskočení. Závěrečné balíčky a statické
kontroly mají stejný otisk zdrojů bez změn během běhu.

Výsledky, časy každé položky, omezení a konkrétní kandidát k odstranění
opakovaného buildu jsou v [protokolu etapy](../audits/module-rebuild-verification-2026-11-11.md).
Žádná testovací skupina se v této etapě neodstranila. Další práce se týká
optimalizace měřených buildů a fixtures, nikoli pokračování vrstvy Composition.
