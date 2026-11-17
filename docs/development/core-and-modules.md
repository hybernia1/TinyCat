# Core a funkční moduly

Core je povinný framework. Funkční modul přidává konkrétní použití aplikace
a lze jej vypnout. Social a Blog mohou běžet samostatně, společně, nebo být
oba neaktivní. Administrace a správa modulů zůstávají součástí core.

Společné veřejné vstupy a jejich vynucení určuje
[kontrakt core pro API, UI a validace](../contracts/core-application-contract.md).

Začínáš nový modul? Použij [vstupní manuál s příklady](module-authoring.md).

Výsledek přestavby, kompatibilitu a měřené ověření zachycuje
[zápis etapy z 12. 11. 2026](../audits/framework-and-social-rebuild-2026-11-12.md).

```text
App/
  Console.php
  modules.php                    # Výchozí výběr Socialu
  Core/
    services.json                # Povinné registrace frameworku, ne modulové manifesty
    Runtime/                     # HTTP/CLI start a načtení frameworku
    Modules/                     # Kontrakty, katalog, zdroje, lifecycle a deployment
    Http/
    Database/
    Identity/                    # Účty, oprávnění, API i přihlášení
    Security/
    Settings/
    Text/                        # Čistá příprava a prezentace textu
    Links/                       # Parsery, metadata a prezentace odkazů
    Files/                       # Souborové mechanismy a reference vlastníků
    Feeds/                       # Parser a technický průchod importem
    Tasks/                       # Scheduler včetně API, administrace a historie
    Mail/
    Notifications/
    Discovery/
    Health/
    Updates/
    Installation/
    Ui/
      Admin/                     # Společný rám administrace a navigace
      templates/
    Support/
  Modules/
    Social/
      module.json                # Jediná aktivace celého Socialu
      src/
        Module.php               # Jediný registrační a inicializační bod
        Content/                 # Vlastní statusy, diskuse, tagy, odkazy a média
        Profiles/
        Posts/
        Moderation/
        Search/
        Automation/
        Presentation/
      migrations/
      templates/
      lang/
      emails/
      assets/
    Blog/
      module.json
      src/Module.php
      migrations/
      templates/
      lang/
      assets/
```

Vrstva `Composition` ani produktové bootstrapy neexistují. Vnitřní složky
Socialu nemají manifesty, aktivační stav ani samostatné položky ve správci.
Jejich pomocné registrace volá výhradně `Social/Module.php`.

Uvnitř Socialu profily volají přímo jeho autorské stránky, statistiky a
notifikační události. Příspěvky používají přímo vlastní notifikační služby;
tyto pevné vazby nepotřebují další callbackový registr. Lazy příspěvky do core
a veřejné kontrakty mezi modulem a frameworkem zůstávají zachované.

`Social/Posts/PostCreation` je jediná příprava a vytvoření sociálního příspěvku,
společná pro ruční zápis i feed import. `Social/Posts/Tags` vlastní normalizaci,
vazby tagů a jejich veřejné výpisy bez dědičné mezivrstvy. `Posts/Resource`
předává vlastní odkazy přímo společnému `Core/Ui/RichTextResource`.
Transakční aktualizaci a mazání uložených dat nadále poskytuje `Content/Posts`.
Diskuse a reakce volají přímo `Posts/InteractionPolicy`; vlastní interní registr
vlastníků nemají. Nastavení a lokalizaci URL čtou z core a blokování odkazů
z vlastní `Content/Policy`. Textové politiky registrují přímo do `Core/Text/Policies`.

## Načtení a vlastnictví

HTTP vstup načítá přímo `Core/Runtime/autoload.php`, explicitně volá
`Application::start()` a předává požadavek `HttpKernel::respond()`. Samostatná
bootstrapová obálka není potřeba. Stejný loader používají produkční CLI vstupy;
kontrola minimální verze PHP probíhá před autoloadováním tříd.

Externí skripty a nástroje načítají `App/Core/Runtime/autoload.php` pomocí
`require_once`; loader nezahajuje aplikaci, SQL ani session. Nástroj, který
potřebuje celý runtime, pak explicitně volá `Application::start()`.
Obálka `App/autoload.php` je odstraněná. Před aktualizací z dokončené 6.0.10
obnov privátní rescue launcher příkazem `php App/Console.php rescue` podle
[aktualizačního návodu](../operations/updates.md#retiring-the-autoload-facade).

`Core/Runtime/Framework` nejprve načte čistá metadata povinných služeb
z `Core/services.json`. `Core/Modules/Loader` k nim přidá explicitně vybrané
aktivované funkční manifesty, ověří namespace a závislosti a vytvoří společný katalog.
Dostupnost určuje metadata-only inventář `App/Modules/*/module.json`; neaktivní
modul se při inventarizaci nenačítá jako PHP. Výchozí aktivace zdrojů například:

```php
return [
    'tinycat.social' => 'App/Modules/Social/module.json',
    'tinycat.blog' => 'App/Modules/Blog/module.json',
];
```

Prázdný výběr znamená celý framework bez funkčních modulů. Služby core
se neuvádějí do tohoto seznamu ani do `storage/deployment.json`. Stabilní
interní capability ID označují povinné služby frameworku. Historické migrační
streamy jako `tinycat.publishing` nejsou capability ani aktivovatelné moduly.

`Core/Modules/Resources` poskytuje stejný katalog bootstrapu, překladům,
komponentám, assetům i buildu. Soukromé zdroje patří svému vlastníkovi.
Core má vlastní překlady a UI; Social má společný katalog svých částí.
Pouhá přítomnost adresáře modul neaktivuje. Neaktivní zdroje nesmějí proniknout
do klientského grafu ani přes nepřímý import.

Manifest deklaruje poskytovatele `services`, `routes`, `schema` a
`contributions`. `src/Module.php` může implementovat všechny odpovídající
kontrakty. Metadata a příspěvky neprovádějí SQL, session ani provozní operace;
callbacky se vyhodnocují až při použití. Instalace a migrace jsou explicitní
lifecycle operace. API vrací data, Pages autorizuje a připravuje vstupy,
šablony pouze vykreslují.

## Hranice domén

Core/Identity vlastní účty a jejich administraci. Social/Profiles vlastní
veřejné autory a vztahy. Životní cyklus účtu používá příspěvky vlastníků;
při smazání zapojuje i nainstalované neaktivní vlastníky zachovaných dat.

Blog vlastní celý článek v `blog_articles`, Social celý status v `social_posts`.
Každý má vlastní diskuse, reakce, tagy, odkazy, média a zdroje. Core neobsahuje
univerzální publikační tabulky. Podrobný [inventář a mechanismy](domain-storage.md)
rozlišují technickou podporu od doménového SQL. Souběh ani stejné ID nevytváří
vazbu mezi vlastníky.

Core poskytuje společný dokument, editor, seznamy a dialogy. Moduly přispívají
stránkami, tématy, homepage a dashboardy. `/` a `/admin` vybírá core z aktivních
definic; vypnutí jednoho modulu neodstraní účet ani data druhého.

## Migrace a kompatibilita

Podepsaný descriptor odděluje `selection` od povinného `framework`
bez Publishingu. `schemaOwners` přiřazuje historické streamy aktuálním vlastníkům.
Social vlastní Users, Moderation, Bots, Publishing verze 4 a Status verze 3;
Blog vlastní verzi 4. Historické ID není aktivační jednotka. Publikované migrační
bajty a checksumy se nemění. Vestavěná historie je od 6.0.9 archivní prefix
formátu 4 s původními ID, pořadím, checksumy a explicitním `file: null`.
Každý vlastník má samostatnou podepsanou `fresh` baseline bez řetězení starých
datových převodů. Původní journalové záznamy se nepřepisují.
Aktualizační cesta a retirement historie jsou v [datovém kontraktu](domain-storage.md).

Současné zdroje podporují descriptor formátu 3 i 4 a vyžadují dokončenou 6.0.8
nebo novější instalaci. Archivovaný prefix vyžaduje také odpovídající dokončené
schéma vlastníka. Starší aktivační ID převádí nezměněný podepsaný release
6.0.6, archivní čtení přidává neměnná 6.0.8; současný core nemá seznam částí
Socialu ani převodní adaptér. Povinné
technické schopnosti deklaruje `Core/Runtime/Framework::CAPABILITIES`.
Nainstalovaný vypnutý vlastník zůstane vypnutý a zachová vlastnictví dat.
Neznámý nainstalovaný modul nelze přechodem zahodit ani přejmenovat.

Popisky tabulek pro instalátor a klientskou prezentaci vlastních nastavení
dodává vlastník ve svých překladech a assetech. Core vybírá tabulky z registrací;
instalační text nepředpokládá přítomnost konkrétního funkčního modulu.

Textové renderery `Core/Text/Segments` a `Core/Ui/RichTextResource` vyžadují
explicitní druh textu. Registr `Core/Text/Policies` použije příspěvek vlastníka
pro tento druh, nebo neutrální chování, pokud příspěvek neexistuje. Aktivní
Social nemění renderování textu jiného vlastníka.

Postup instalace, aktivace a aktualizace popisuje
[provozní návod](../operations/module-deployment.md). Závazné transportní
a UI kontrakty zůstávají v [modulech a transportu](modules.md).
