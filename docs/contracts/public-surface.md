# Veřejný web a povrchy prezentace

Veřejný web řídí runtime nastavení `site.public_enabled`. Je nezávislé na aktivaci
modulů, vybrané homepage a veřejném tématu. Nová instalace ukládá hodnotu `false`;
existující instalace bez uložené hodnoty zachovává dosavadní veřejnou dostupnost.
Přepnutí nevyžaduje instalaci, migraci schématu ani rebuild assetů.

## Dostupnost HTTP

Veřejné obsahové stránky registruj přes `Router::page()`. Stejná registrace
zajišťuje dostupnost HTML, `pageResource()` i klientských definic navigace.
Samostatný veřejný transport deklaruje `Router::route(..., publicWeb: true)`.
Veřejné JSON a interakční endpointy registruj přes `$api->forPublicWeb()`.
Tato vlastnost je nezávislá na oprávnění `public`, `user` nebo `admin`.
Anonymní přihlášení není veřejný obsah a tuto vlastnost nemá.

Při vypnutém webu router veřejné routy nezpřístupní, a to před vyhodnocením
handleru i nabídky povolených metod. JSON používá společnou obálku HTTP 404.
Žádné veřejné resource callbacky se nevyhodnotí. Deklarace se týká i RSS,
embedů, oEmbed, hledání, sitemap a exportů pro crawlery. Core nevede seznam
doménových cest. Aktivní modul si ponechává administraci, úlohy a data.

Kořen používá dočasné přesměrování na přihlášení nebo bezpečný cíl přihlášeného
účtu. Správce může do administrace, běžnému účtu zůstává `/account`.
Přihlášení nesmí vrátit účet na zavřenou veřejnou stránku ani vytvořit redirect
smyčku. Přihlašovací, recovery a účetní API, branding a privacy zůstávají
dostupné podle vlastních oprávnění. Registraci dál řídí její vlastní nastavení.
Inbox je core služba a jeho autentizované endpointy zůstávají dostupné.
Robots při zavřeném webu nepropaguje sitemap ani příspěvky crawler hints.

Přepínač nechrání dříve veřejné soubory v uploads a neodvolává již stažené
kopie. Soukromá média vyžadují samostatnou autorizaci doručování.

## Prezentace a témata

Stránka předává `Page::render()` údaj `surface`: `access`, `admin` nebo `public`.
Bez explicitního údaje se použije stejná klasifikace cesty jako pro assety.
Staré přepínače `layout=auth`, `adminShell` a `public` nevybírají plochu.
Povrch není oprávnění.

`access` používá minimální core rám bez veřejné navigace, hledání, inboxového
náhledu a analytics. `admin` zachovává samostatný admin rám. `public` vybírá
lokální téma nebo výchozí core veřejný rám. Všechny používají společný
`Document::frame()` a stejné komponentové katalogy.

Veřejné téma vlastní okolí obsahu, ne dostupnost endpointů nebo doménová data.
Dostává připravené fragmenty `publicHeader`, `content`, `consent` a
`publicFooter`. Musí zachovat jeden `main-content` a potřebné consent UI.
Patička patří do veřejného layoutu; společný HTML dokument ji nepřidává.
Stránka s vlastní patičkou nastaví `privacyFooter=false`. Přítomnost sidebaru
se nezjišťuje z hotového HTML. Chybějící téma použije core rám.

Nedostupná explicitně vybraná homepage nepřepíná na jiný modul. Kořen použije
core sdělení a uložené ID zachová. Prázdný výběr nadále používá pořadí
registrovaných homepage včetně kompatibilní Identity homepage.

## Assety

Plocha stránky určuje také rodinu assetů přes `Surface::assetPath()`. Nestandardní
URL s explicitní administrativní nebo přístupovou plochou použije její výchozí
profil. Vlastník může předat `asset_path` pro konkrétní profil stejné plochy;
profil jiné plochy se odmítá. Klasifikaci cest sdílí layout a výběr manifestů.

Administrativní operace nesmějí záviset na veřejných API. Správa botích příspěvků
používá autorizované endpointy pod `/api/bots/accounts/{id}/posts/{postId}`,
včetně návrhů editoru; sdílí přípravu zápisu a editor s veřejnou prezentací.
Odkazy notifikací do vypnuté veřejné části vedou na schránku, přičemž původní
událost zůstává zachována. Chybové stránky respektují plochu a oprávnění návštěvníka.

Manifestový scope `access` vybírá společné přístupové a účetní závislosti.
`public` nezahrnuje login, registraci, recovery, logout a účetní cesty.
Přihlašovací stránky vybírají vlastní předkompilované profily, například `/login`,
bez editorů účtu a avataru. Účet používá `/account`; specializované core stránky
mohou použít profil pod `/access/` bez editorů účtu. Tyto profily nejsou další
HTTP aplikace. Konkrétní klientské závislosti deklarují vlastníci manifestů.

ClientAssets a release compiler používají stejný seznam scope, který obsahuje
přístupové profily. CompiledAssets vybírá hotovou variantu podle stejného
prefixového pravidla. Závislosti lazy dialogů musí být deklarované v profilu
jejich hostitelské stránky. Nevytvářej balíčky podle identity návštěvníka
ani druhý frontendový build mechanismus.

## Ověření

Regrese patří do existujících Identity, presentation, HTTP a client-assets
scénářů. Pokrývají přepnutí tam i zpět, instalaci bez modulů, všechny role,
lokalizované přímé odkazy, HTML i JSON transporty a zdrojové i release assety.
Změna společného runtime nebo asset selection vyžaduje závěrečný úplný
preflight podle pravidel repozitáře.
