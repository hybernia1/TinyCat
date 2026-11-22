# Veřejný web a oddělení prezentace core

Datum: 22. 11. 2026. Audit zdrojů a návrh další etapy; navržené chování zatím není implementované.

TinyCat musí fungovat bez funkčních modulů a umožnit kdykoliv zapnout nebo vypnout veřejný web v nastavení. Doporučené řešení zachovává společný HTML dokument, komponentové katalogy, lokální témata a graf závislostí assetů. Doplní explicitní povrch stránky a deklaraci veřejné dostupnosti endpointů. Šablona řídí prezentaci; dostupnost veřejného webu řídí core.

## Současný stav

| Oblast | Zjištění a zdroj |
| --- | --- |
| Homepage | [EntryPages](../../App/Core/Runtime/EntryPages.php) vybírá uložené ID, jinak první registrovaný příspěvek. Neexistuje samostatný přepínač veřejného webu. Změna dostupných příspěvků proto může změnit automatický výběr. |
| Instalace bez modulů | [Identity ServiceProvider](../../App/Core/Identity/ServiceProvider.php) přispívá přihlašovací homepage s nejnižší prioritou. [Pages](../../App/Core/Identity/Pages.php) hostovi vykreslí login, správce pošle na jeho landing a běžnému účtu umí zobrazit účetní rozcestník. Základ bez Socialu už existuje. |
| Nastavení | [Settings](../../App/Core/Settings/Settings.php) obsahuje `site.homepage` a `site.theme`. Obě hodnoty jsou runtime nastavení; nový režim nemá být instalační profil ani aktivace modulu. [Store](../../App/Core/Settings/Store.php) má explicitní seznam klíčů přepisujících konfiguraci. |
| Výběr prezentace | [Page](../../App/Core/Ui/Page.php) nejprve vybere administraci, potom větev `layout=auth`, jinak lokální téma nebo core rám. Kombinuje se cesta, `public`, `adminShell` a `layout`. |
| Administrace | [Admin Layout](../../App/Core/Ui/Admin/Layout.php) už má vlastní přípravu a volá přímo společný `Document::frame()`. Neprochází přes `DocumentData::prepare()`. Předchozí představa o přípravě veřejné hlavičky pro běžnou administraci byla nepřesná. |
| Přihlášení | Větev `auth` v Page pouze obejde lokální téma. Stále volá `Document::render()`, tedy přípravu veřejných dat a [public shell](../../App/Core/Ui/templates/public-shell.php). Samostatný minimální přístupový rám dosud není. |
| Veřejné podklady | [DocumentData](../../App/Core/Ui/DocumentData.php) spojuje metadata, assety, účet, navigaci, jazyk, hledání, hlavičku a consent. Přihlášenému mimo admin připravuje stav a náhled inboxu. Přítomnost zdrojů hledání ovlivňuje hlavičku. |
| Dokument | [Document](../../App/Core/Ui/Document.php) má vhodný čistý vstup `frame()`. [Document template](../../App/Core/Ui/templates/document.php) je společný dokument, ale obsahuje i podmíněnou veřejnou patičku. Umístění odkazu na soukromí se odvozuje také z hledání sidebarových atributů v řetězci obsahu. |
| Témata | [Theme](../../App/Core/Ui/Theme.php) načítá metadata lokálního tématu a vykresluje jeho layout uvnitř core dokumentu. Nejsou to moduly. Chybějící téma vrací fallback, neplatný existující manifest může vyhodit výjimku. V checkoutu není produkční adresář témat, existuje [fixture](../../tests/fixtures/themes/App/Themes/example/theme.json). |
| Komponenty | [Core katalog](../../App/Core/Ui/assets/components.json) a katalogy vlastníků jsou společným zdrojem PHP i klientského vykreslení. Není důvod tento mechanismus měnit nebo umožňovat tématům přepisovat doménové komponenty. |
| Assety | [ClientAssets](../../App/Core/Support/ClientAssets.php) odvozuje `public` negativně: vše mimo `/admin` a `/install`. Login a recovery tím spadají do veřejného scope. [UI manifest](../../App/Core/Ui/assets/module.json) má rozsáhlé styly a několik interakcí ve skupině `*`; [public entry](../../App/Core/Ui/assets/public.js) importuje analytics, jazykové doporučení a hledání. |
| Release assety | [Compiler](../../tools/assets/compile-assets.php) předem skládá scope balíčky. [CompiledAssets](../../App/Core/Support/CompiledAssets.php) je vybírá podle prefixu cesty. Změna pouze vývojového výběru by nestačila. |
| HTTP a API | [Router](../../App/Core/Http/Router.php) eviduje metody, cesty a content type; stránky mají skupinu, resource a query parametry. Chybí jednotná deklarace příslušnosti k veřejnému webu. API `access=public` v [Api](../../App/Core/Http/Api.php) znamená anonymní přístup, nikoli veřejný web. |

## Hlavní hranice změny

Povrch prezentace a přístupová politika jsou dvě různé věci. Přihlášení musí být dostupné anonymně i při vypnutém veřejném webu. Stejně tak obnova přístupu, potřebné branding assety a informace o soukromí. Naopak pouhé nevykreslení veřejné šablony nezastaví veřejné JSON resources, RSS ani embed.

Aktivní modul může současně poskytovat administrační a veřejné funkce. Vypnutí webu proto nesmí deaktivovat modul, schéma, úlohy ani správu jeho dat. Core nesmí obsahovat seznam cest nebo schopností Socialu. Jejich zařazení deklaruje vlastník.

Přihlášený účet nemusí být správce. Pravidlo „každého přihlášeného pošli do `/admin`“ by porušilo existující chování. Zachová se samostatný účetní povrch core a bezpečná volba cíle po přihlášení.

## Doporučený model

### Nastavení veřejného webu

Doplnit jedno core nastavení, pracovně `site.public_enabled`. Vlastní jej stávající SettingsSection; respektuje autoload a invalidaci Store. Žádný instalační přepínač ani druhý seznam polí v JavaScriptu.

Pro nové instalace doporučujeme veřejný web vypnutý, nezávisle na počtu modulů. Pro existující instalace zachovat dosavadní dostupnost explicitním kompatibilním přechodem. Nestačí změnit fallback chybějící hodnoty na false: tím by se stávající weby při aktualizaci zavřely. Přechod musí rozlišit existující instalaci a nový instalační default; případná migrace musí být nová.

`site.theme` zůstává výběrem prezentace a `site.homepage` výběrem obsahu. Jejich hodnoty se při vypnutí webu nemažou. Samotné přidání modulu nebo tématu veřejný web nezapne.

Uložené, ale nedostupné ID homepage nemá automaticky vybrat jiný modul. Doporučený fallback je jednoduchá core stránka bez modulového obsahu; správce dostane v nastavení informaci o nedostupném výběru. U automatického výběru zachovat jasně popsané pořadí stávajícího registru. Existující ID Identity homepage zachovat jako kompatibilní volbu, nikoli dál používat jako náhradu vypínače webu.

### Explicitní povrchy prezentace

| Povrch | Rám a příprava |
| --- | --- |
| Přístup a účet | Minimální core rám pro login, recovery, logout a účetní rozcestník. Bez veřejného hledání a inboxového náhledu. |
| Administrace | Stávající admin rám. Zachovat autorizaci a jeho vlastní přípravu. |
| Veřejný web | Vybrané lokální téma nebo výchozí veřejná šablona core. Připravovat jen podklady potřebné konkrétní stránkou a rámem. |
| Instalace a technické chyby | Stávající samostatná příprava a společný dokument; fungování i při chybě DB nebo nastavení. |

Zavést jediný validovaný údaj o povrchu pro výběr rámu a assetů. Konkrétní podobu objektu nebo enumu volit podle existujících konvencí; není potřeba registry adaptérů nebo nová Composition vrstva. Příznaky `public`, `adminShell` a `layout` postupně odstranit z rozhodování po převodu jejich volajících. Povrch nenahrazuje serverovou autorizaci.

`Document::frame()` zachovat jako společný renderer připravených dat. Veřejnou patičku a její rozmístění přesunout do veřejného rámu. Consent zůstává core komponentou, kterou povrch explicitně zahrne podle použitých služeb. Chybové stránky mají používat bezpečný rám pro svůj povrch bez rekurzivního selhání při načítání rozbitého tématu.

Veřejná šablona vlastní geometrii a okolí obsahu. Dostává hotový obsah a explicitní podklady či fragmenty; neprovádí SQL, nerozhoduje o oprávněních a nepřebírá formuláře, dialogy nebo lifecycle. U výchozího rámu odstranit implicitní detekci sidebaru v HTML. Odpovědnost za patičku a sidebar musí být zřejmá ze smlouvy stránky a rámu.

### Dostupnost endpointů

Doporučená politika vypnutého webu:

| Požadavek | Výsledek |
| --- | --- |
| `/` a lokalizovaný kořen | Host přejde na login; účet na povolený interní cíl nebo účetní rozcestník. Použít dočasné přesměrování. |
| Obsahová veřejná stránka | 404 bez spuštění doménového resource callbacku. Platí i pro přihlášeného správce; správa obsahu používá admin endpointy. |
| JSON pro veřejnou navigaci, hledání, veřejné seznamy | 404 ve společné JSON obálce před přípravou obsahu. |
| RSS, veřejný embed a oEmbed, veřejné sitemap sekce, LLM exporty | Nevydávat veřejný obsah. Každý transport musí být pokryt deklarací vlastníka. |
| Přihlášení, recovery, potřebné Identity API a soukromí | Zachovat přístup; registraci nadále řídí její vlastní existující nastavení. |
| Admin, účetní API, plánovač, interní integrace | Zachovat jejich vlastní oprávnění. Veřejný přepínač není plošné vypnutí API. |
| Robots | Dostupná technická odpověď, při vypnutém webu bez propagace veřejných map. Robots není zabezpečení. |

Rozšířit stávající registraci rout o explicitní politiku dostupnosti, například příslušnost k veřejnému webu. Vyhodnocuje ji společná HTTP vrstva před handlerem. Pro HTML stránky a jejich JSON resources použít stejnou deklaraci; nevytvářet dva oddělené seznamy. API potřebuje tuto vlastnost nezávisle na `access`, protože anonymní login resource není veřejný obsah.

Využít existující [Router::page](../../App/Core/Http/Router.php) pro vazbu stránky na její resource; samostatné feedy, embedy a exporty deklarují vlastníci svých transportů. [Discovery routes](../../App/Core/Discovery/Routes.php), [Search](../../App/Core/Discovery/Search.php) a [Information](../../App/Core/Ui/Information.php) musí stejnou politiku respektovat při odpovědích i při vytváření odkazů a seznamů. Nezakazovat vše kromě centrálního seznamu povolených URL.

Zákaz webu není záruka ochrany již veřejně dostupných souborů v uploads, cizích cache nebo dříve exportovaných dat. Ochrana soukromých médií je samostatný storage a autorizační kontrakt; tento přepínač jej nemá předstírat.

### Výběr assetů

Výběr má pracovat se stejným explicitním povrchem jako renderer a se stávajícími deklarovanými závislostmi stránky. Cesta zůstává vstupem pro její konkrétní funkce, ale nemá sama určovat, že login je veřejný web.

Zúžit skupinu `*` na skutečný společný základ po auditu konzumentů. Dialogy, popovery nebo validace mohou být potřebné i na loginu; jejich odstranění se řídí použitými komponentami a importy, ne názvem souboru. Hledání, jazykové doporučení a analytics rozdělit na volitelné závislosti namísto povinného importu celého public entry.

Zachovat AssetGraph, komponentové kořeny a překladové katalogy. Změnu scope provést současně v ClientAssets, seznamu build variant, CompiledAssets a jejich cache klíčích. Předkompilované varianty musí pokrýt login, účet, admin i veřejné stránky; provozní změna nastavení nesmí vyžadovat build nebo procházení zdrojů. Změna dostupnosti webu vybírá hotovou variantu, nevytváří kombinaci balíčků pro každého uživatele. Obnovitelné navigace a lazy dialogy musí mít deklarované závislosti už ve variantě stránky.

## Postup implementace

1. Zapsat kontrakt povrchů a dostupnosti endpointů, doplnit inventář konzumentů včetně Identity, Notifications, Discovery, Social transportů a chybových stránek. Určit kompatibilní default nastavení a chování nedostupné homepage.
2. Oddělit přístupový a účetní rám od veřejného DocumentData. Převést volající na explicitní povrch při zachování dosavadní dostupnosti webu. Zachovat jeden dokument a katalogy.
3. Převést asset scope společně s release compilerem a runtime výběrem. Ověřit shodu vývojových a podepsaných sestav ještě před zavedením přepínače dostupnosti.
4. Doplnit nastavení a jednotné vyhodnocení deklarované dostupnosti rout. Převést veřejné endpointy vlastníků včetně JSON, feedů, embedů a discovery. Vyřešit kořen, lokalizaci a přihlašovací cíle.
5. Dokončit smlouvu veřejné šablony, výchozí core stránku, explicitní rozmístění patičky a fallback při odebraném tématu či modulu. Aktualizovat návody a generované inventáře příslušnými nástroji.
6. Nad stabilními zdroji dokončit společné ověření. Neodkládat veřejné API a transporty do neurčité další etapy po zpřístupnění přepínače.

## Ověření navazující změny

Jde o společný runtime a doručování assetů, proto implementace vyžaduje závěrečný úplný `composer preflight`. Průběžně používat cílené kontroly podle AGENTS; nevytvářet univerzální nový runner.

Rozšířit stávající [core Identity scénář](../../tests/domains/accounts/module.php), [view templates](../../tests/presentation/view-templates.php), [asset graph](../../tests/assets/graph.php), [asset budgets](../../tests/assets/budgets.php), HTTP kontrakty a frontendové scénáře. Zachovat dosavadní testy ukončení přihlašovacích redirectů; pouhá čerstvá instalace správce nestačí.

Povinná matice zahrnuje instalaci bez modulů i s aktivním modulem; vypnutý a zapnutý web; hosta, běžný účet a správce; kořen i lokalizované cesty; přímé HTML a JSON odkazy; změnu nastavení tam i zpět bez buildu; odebranou homepage a téma; vývojové i release assety. U vypnutého webu ověřit, že veřejné callbacky skutečně neběžely a že login nepřipravuje inbox ani hledání. Rozpočty opřít o změřený výchozí stav, ne o předpokládanou úsporu nebo počet assertions.

Browserové ověření na existujícím `https://tinycat.test/` má po synchronizaci pouze runtime souborů pokrýt desktop a mobil: login, recovery, účetní rozcestník, admin, veřejnou homepage, privacy a chyby. Použít izolované fixture pro přepínání kombinací, které by zasahovaly do živých účtů či dat. Živou instalaci nereinstalovat.

Tento audit mění pouze dokumentaci. Proběhla kontrola zdrojů; browserové chování, velikosti přenosů a počty SQL dotazů zde nejsou měřené. Aplikační testy ani preflight nejsou pro samotný audit vyžadované.
