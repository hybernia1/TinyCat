# Kontrakt funkčního modulu

Tato pravidla platí pro Social i nové aplikace nad TinyCat. Praktický postup a
manifest ukazuje [manuál autora](../development/module-authoring.md).

## Vlastnictví a vstup

- Jeden modul představuje jednu vypínatelnou funkci. Má jeden manifest a veřejný
  registrační vstup `src/Module.php`. Vnitřní složky jsou implementace vlastníka,
  nemají vlastní aktivační ID, katalogy ani start aplikace.
- Manifest deklaruje pouze použité providery `services`, `routes`, `schema` a
  `contributions`. Nastavení, úlohy, navigace a další rozšíření jsou typované
  příspěvky `Contributions`; nevytvářej jejich druhý registrační mechanismus.
- Core nesmí importovat volitelný modul. Import jiného modulu vyžaduje jeho ID
  v `requires`. Kontrola závislostí čte namespace a závislosti z manifestů,
  nepoužívá seznam vestavěných produktů.
- Vstup skládá registrace a deleguje provozní práci. SQL, zápis dat, soubory a
  doménová pravidla patří konkrétnímu vlastníkovi uvnitř modulu.

## Registrace a provoz

- Načtení metadat ani sestavení příspěvků nevyžaduje databázi, session, síť nebo
  zápis na disk. Callback předávej jako callable; nevyhodnocuj jej při registraci.
- `initialize()` nastavuje pouze procesové politiky a cache. Nevytváří tabulky,
  nečte provozní data ani neprovádí skryté migrace.
- Modul vlastní celý agregát, SQL, autorizaci a viditelnost. Využívá společné DB,
  HTTP, Tasks, Settings, Mail a UI mechanismy. Nepřidává jejich lokální kopie.
- `Api` registruje transport, aplikační služba provádí operaci, `Pages` připravuje
  stránku a komponenty vykreslují. HTTP resource nevrací HTML ani definice UI.
- U modulu členěného na oblasti skládá `Registration` jejich API a HTML routy;
  vlastní JSON endpointy deklaruje příslušná `Api`. Nemíchej další endpointy do
  registračních pomocníků. Sociální profily používají `/api/profiles`, správu
  identity poskytuje core pod `/api/users`. Sdílené hledání hostuje core;
  vlastník přispívá zdroji a kurzorovými daty, ne paralelním výsledkovým API.
- Nastavení vlastní jednu PHP definici sekcí, polí, validace a popisků. Úloha
  vždy běží přes core runner se zámkem a historií; CLI není alternativní cesta.

## Zdroje a životní cyklus

- PHP, migrace, překlady, komponenty, e-maily a privátní assety zůstávají pod
  kořenem modulu. Jejich zdrojem je manifest, nikoli seznam domén v core.
- Serverové HTML a AJAX sdílejí komponentové definice. Modaly, popovery, formuláře,
  navigace, tokeny a dva responzivní režimy dodržují společné UI kontrakty.
- Stránku předávej `Core/Ui/Page::render()`, které vybírá administraci a téma.
  Modul poskytuje obsah a metadata, nikoli registraci tématu nebo vlastní
  výběr globálního rendereru. Veřejný shell vybírá core.
  Volitelný [lokální veřejný layout](../development/themes.md) nevyžaduje
  funkční modul a nepřepisuje komponenty vlastníka ani přihlašovací stránky.
- Administrační seznamy skládej přes `Core/Ui/Admin/Definitions::page()`.
  Vlastní důvěryhodné HTML akcí může dodat vlastník jako třetí argument;
  titulek, seznam a rám kvůli vlastnímu tlačítku nekopíruj do stránky modulu.
- Nový modul používá jeden schema stream. Více historických streamů Socialu je
  kompatibilita publikovaných instalací, nikoli vzor pro novou aplikaci.
- Publikované ID a checksumy migrací jsou neměnné. Změna má novou migraci a
  odpovídající fresh schéma. Vypnutí zachovává data; jejich nainstalovaný vlastník
  dál poskytuje potřebný úklid účtu a reference souborů.
- `MaintenanceTask::whenInactive` má výchozí hodnotu `false`. Vlastník může
  výslovně povolit svůj omezený orphan úklid souborů i po deaktivaci. Core přibírá
  pouze tyto příspěvky nainstalovaných neaktivních vlastníků, každé ID nejvýše
  jednou; nespouští jejich inicializaci, routy ani ostatní callbacky. Odinstalovaný
  vlastník se nezapojuje jen proto, že jeho zdroje zůstaly na disku. Úklid běží
  přes chráněný core runner, kontroluje reference všech nainstalovaných vlastníků
  a stáří příprav a zachovává rozpočty i checkpointy. Chybějící manifest nebo PHP
  provider zastaví mazací část před prvním unlink; neúplná mapa není prázdná mapa.

## Povinné ověření

Ověř prázdné core, samotný modul, vypnutí a opětovnou aktivaci. Registrace musí
fungovat bez provozní DB; vypnutý modul nesmí dodávat routy, běžné úlohy ani
klientské zdroje. Obecné kontrakty testuj malou fixture v `tests/fixtures/modules`,
ne dalším distribuovaným produktem. Doménové scénáře patří vlastníkovi.

Kontroly `module-catalog`, `module-resources`, `module-boundaries`, `dependencies`
a podepsaný `module-lifecycle` jsou společné vstupy. API, překlady, UI a release
ověř podle dopadu v [pravidlech repozitáře](../../AGENTS.md#ověření).

## Aktuální Social

`Module.php` skládá `Registration` tříd vnitřních částí. `Posts` vlastní příspěvek,
jeho zápis, čtení, feed, tagy, odkazy a přílohy; `Discussion` komentáře a reakce;
`Profiles` veřejné profily a sledování, `Bots` importy a boty, `Moderation` moderaci,
`Search` hledání a `Site` veřejné téma a stránky. Toto členění nevytváří
další framework ani samostatné moduly. Přesuny musí aktualizovat všechny volající
a nesmějí měnit historická ID, endpointy nebo data jen kvůli názvosloví.

Novou pomocnou třídu odděluj podle samostatné odpovědnosti. Jedno přeposlání do
komponenty, accessor cache nebo jediný dotaz automaticky nevyžaduje vlastní soubor.
Malý adaptér má smysl, pokud drží konkrétní kontrakt nebo politiku, například
`Posts/ImageStorage`. Neslučuj HTTP orchestrace a doménové zápisy kvůli počtu souborů.

Blog byl z aktuálních zdrojů odstraněn. Historické releasy a audity popisují
tehdejší stav; nejsou šablonou nového modulu. Instalovaný historický vlastník se
nesmí obejít přepsáním deploymentu ani smazáním dat.
