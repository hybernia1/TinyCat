# Moduly a HTTP transport

Praktický začátek nabízí [manuál tvorby modulu](module-authoring.md).

Aktuální architekturu a registrační fáze popisuje
[Core a samostatné moduly](core-and-modules.md). Funkční vlastníci sídlí
v `App/Modules/`, společné mechanismy v `App/Core/`.

## Data a prezentace

Modul vlastní své entity, dotazy, FK, oprávnění a transakce. Core nemá univerzální
`content` ani společné komentáře; přesný inventář a převod popisují
[doménová data](domain-storage.md). Social vlastní statusy a sociální politiku.
Nevolej repository jiného vlastníka jen
kvůli sdílenému editoru nebo stejnému číselnému ID.

`Social/Discussion/InteractionTarget`, `Resource`, `ThreadTree` a
`Social/Posts/TextResource` vlastní sociální resource a prezentaci. Social ověří cíl a načte
autorizované přílohy, komentáře a stav reakcí. Zápis zamkne vlastní agregát,
ověří rodiče komentáře i jeho vazbu a atomicky zapíše změnu a limiter.
`Social/Discussion/Comments` vlastní formát historie komentáře včetně aktéra
a změn těla. Čtení a zápis historie jsou přímo součástí tohoto vlastníka;
tabulku vlastní Social.

`Database/Transactions::afterCommit` odkládá vedlejší efekty po vnějším
spravovaném commitu. Při rollbacku se zahodí. Vnější transakce s takovými efekty
musí použít `Transactions::run`; samotné PDO `beginTransaction` neslibuje jejich
doručení. Soubor neodstraňuj před commitem a neukládej nepotvrzené čtení do cache.

Po úspěšném commitu se vyhodnotí celá fronta efektů, i když některé selžou.
`Transactions::run` pak vyhodí `TinyCat\Database\AfterCommitFailed` se seznamem
`failures` a první příčinou v `getPrevious()`. Tato výjimka znamená, že databáze
už změny potvrdila; neopakuj kvůli ní celý zápis ani nemaž jeho uložené přílohy
jako při rollbacku. Výjimka je definována společně s runnerem v
`App/Core/Database/Transactions.php`. Mazání účtu používá stejnou frontu pro
úklid všech vlastníků. Efekty mimo transakci se provádějí ihned a jejich
výjimky se předávají přímo. Fronta není persistentní outbox: pád procesu
po commitu nezaručuje doručení. Vnořené `run` sdílí vnější transakci bez savepointů.

Volitelný druhý parametr `afterCommit($callback, $key)` sloučí opakovaný efekt:
první callback se stejným namespacovaným klíčem v dané vnější transakci vyhraje.
Klíče se zahodí při commitu i rollbacku a neovlivňují další transakce. Mimo
transakci se callback nadále provede ihned. Používej jej pro společnou invalidaci,
nikoli pro různé e-maily či soubory, které se musí zpracovat jednotlivě.

Sociální prezentace je v `App/Modules/Social/assets/content/`; její feature ID
`publishing.discussion-base` a `publishing.discussion` patří Socialu.
Core nabízí obecné formuláře a dialogy, nikoli univerzální komentářovou doménu.
Sociální nabídka používá `componentExtensions` uvnitř vlastního katalogu.
Jiný modul si definuje své entity, resource a komponenty bez závislosti na Socialu.

HTTP i počáteční HTML používají stejné komponentové definice. Doménové endpointy
vracejí kanonická data a oprávnění, nikoli HTML či definice komponent. Autorizační
kontrola, CSRF a JSON obálka jsou povinné také pro AJAX a multipart `_method`.
Společné rozhraní nevytváří druhý ruční renderer modalů či seznamů.

## Veřejný dokument a příspěvky UI

### Hranice cest a společná prezentace

`Request::path()` dekóduje cestu z HTTP URL právě jednou. Totéž platí pro explicitní
zakódovanou cestu přijatou resource endpointem. Interní již dekódovaná cesta
prochází pouze idempotentním `Request::normalizePath()`. `Router::dispatch($path)`,
`pageResource()` a `isApiPath()` přijímají tuto interní podobu; router parametry
po shodě znovu nedekóduje. Doslovné `%2F` vzniklé z URL `%252F` tak nezmění
význam na oddělovač segmentů. Deklarace tras jsou struktura, nikoli zakódovaná
URL; zachovávají regexové escape sekvence. GET registrace obsahuje také implicitní
HEAD, včetně metadat a hlavičky `Allow` při HTTP 405.

Server i klient používají stejná skalární pravidla komponent: číselná nula včetně
`0.0` je nepravdivá, řetězec `"0"` zůstává pravdivý. Textový převod zachovává
dosavadní PHP chování (`true` → `"1"`, `false` a `null` → prázdný text);
lookup nezahodí číselný klíč `0`. Pole a objekty se při explicitním textovém
převodu serializují jako JSON. Číselné `eq` sjednocuje int/float podle JSON,
ale nepřevádí číselné řetězce na čísla. Regrese parity používají společnou
fixture v `tests/fixtures/component-contexts.json`.

Volitelný třetí argument `Page::render($page, $content, $document)` předá již
připravený výstup `Metadata::document()` pro stejnou stránku. Core i místní téma
ho použijí místo druhého výpočtu. Nejde o místo pro libovolný JSON od klienta.
Administrace si zachovává vlastní metadata a robots politiku. Veřejné stránky
Socialu skládají query parametry společným mechanismem pro URL a zachovávají
`compact` při změně nebo vyčištění mediálního filtru, v SSR i při AJAX navigaci.

Chybové HTML připravuje `Core/Runtime/Errors` s názvem, stavem a dostupnými
akcemi. Běžná šablona `Core/Ui/templates/error.php` používá společný veřejný
rám; hledání nabízí pouze při registrovaném zdroji. Samostatný
`errorFallback.php` zůstává použitelný bez databáze, tématu a externích assetů.
Obě šablony dostávají připravené URL a popisky, nikoli pevnou cestu či značku.
Nouzový bootstrap může vrátit prostý text. Chybové dokumenty sdílejí
`Http/Document::error`, který zachovává status, neposílá ETag, zakazuje cache
a neposílá tělo při HEAD. Aplikační API dál používá vlastní společnou JSON obálku.

XML/TXT/SVG serializace je oddělená od doručení přes `Http/Document`.
`Support/Xml` poskytuje escapování, CDATA a ploché skalární elementy s ověřenými
názvy. RSS renderer přijímá metadata enclosure (`url`, `length`, `type`) od
vlastníka; nepředpokládá typ sociální přílohy. SVG avatar má vlastní verzovanou
geometrii a CSP, ale sdílí HTTP validátory a HEAD. TXT příspěvky vlastníka
vracejí řetězec bez výstupního bufferu a neopakují společné discovery odkazy.
HTTP responder přijímá také gzip variantu svého ETag, protože Apache
[`mod_deflate`](https://httpd.apache.org/docs/2.4/mod/mod_deflate.html#deflatealteretag)
přidává příponu až za PHP. Změna těla zneplatní obě varianty. Aplikace kvůli
tomu nevyžaduje změnu serverového vhostu ani vlastní implementaci komprese.

Stránka volá `Core/Ui/Page::render()` s obsahem a metadaty. Core připraví dokument,
head, jazykové menu, uživatelské menu a consent UI. Volitelné
[samostatné téma](themes.md) dodává veřejné rozložení nad stejným dokumentem.
Funkční moduly včetně Socialu poskytují obsah; bez tématu používají core rám.

`ProvidesContributions::contributions()` může vrátit tyto lazy příspěvky:

| Položka `Contributions` | Kontrakt | Význam |
| --- | --- | --- |
| `localizedRoutes` | `LocalizedRoute` | Přenos jazykového prefixu pro vlastní veřejné cesty; explicitní allowlist query parametrů. |
| `information` | `InformationSection` | Obsah vlastníka pro `privacy`, `llms` nebo doplňkový `llms-full`. |
| `widgets` | `DashboardWidget` | HTML widgetu pro společný administrátorský dashboard. |
| `embeds` | `EmbedSource` | Lazy výběr veřejného obsahu pro společný oEmbed podle lokální kanonické cesty. |

Příklad deklarace cesty: `new LocalizedRoute('notes.detail', '^/notes/[1-9][0-9]*$')`.
Vzor neobsahuje regex oddělovače; musí být použitelný v PHP i JavaScriptu.
Obsah článku se přepnutím jazyka nepřekládá a canonical zůstává bez prefixu.
`translatedDocument: true` používej pro překládaný dokument, například privacy;
core mu vytvoří jazykový canonical a alternativy. Query povoluj jen pro skutečný
veřejný stav stránky, nikdy pro koncepty nebo tajné hodnoty.

Příspěvky mají unikátní ID a pořadí, registry hlídají vlastníka a konflikty.
Render callbacky se při registraci nevolají. Widget používá společné komponenty
administrace; serverová autorizace jeho dat stále patří vlastníkovi. Vypnutý modul
nepřispívá cestami, informačním obsahem ani widgety.

`Social/Posts/PostCreation` připravuje sociální text přímo bez registru politik.
Core nemá kontrakt pro přípravu nebo vykreslování příspěvků; normalizaci,
validaci a prezentaci doménového textu určuje jeho vlastník.

Blokování URL a UTM má každý modul samostatné. Social zachovává historické klíče
`moderation.blocked_urls` a `content_links.*`; další modul deklaruje své klíče.
Normalizaci poskytuje `Core/Security/UrlRules` a `Core/Links/Tracking`.

`Core/Text/LinkParser` zachovává query parametry a neklasifikuje poskytovatele.
`Core/Links/Metadata` čte obecné HTML, Open Graph, audio a obrázky přes společný
HTTP klient. Konkrétní služby a jejich metadata vlastní `Social/Posts/Providers`
a `LinkMetadata`; obecný parser ani síťovou vrstvu nekopírují.
`Core/Text/InlineText` umožňuje transformovat text mimo doslovné URL.
Social vlastní celý renderer `InlinePresentation`, hashtagy, emotikony,
rozložení tagů a přehrávače. `Social/Posts/ContentLinks` přijímá autorizovaný
text a lazy přístup k přílohám.
Social přispívá povolené originy přehrávačů lazy přes typované `PlayerOrigins` v `Contributions/Registries` (sloučení, kontrola kolizí ID a validace HTTPS originů před CSP);
core bez modulu žádné externí přehrávače nepovoluje.
Výstupní RSS prezentuje `Core/Ui/Rss::respond`, které používá společnou HTTP
odpověď včetně ETag, podmíněného GET a HEAD. Modul vlastní routu, výběr a viditelnost
řádků; předává finální titulek, popis, absolutní URL, jazyk a položky.
Renderer nečte nastavení instalace ani nezná druh entity. Položky mají `title`,
`url` a volitelně `html`, `published_date` (RSS datum), `creator`, `creator_url`,
`categories`, `image_url`, `image_type` a `enclosure`. `html` připravuje vlastník;
pro prostý text může použít bezpečný helper `Rss::html`.
oEmbed hostuje `Core/Discovery/Oembed` pro aktivní `EmbedSource`;
cizí origin odmítne bez stahování a vlastník ověřuje veřejnou viditelnost.
Discovery URL vytváří `Core/Discovery/Oembed::discoveryUrl` z kanonické URL
vlastníka. `Core/Ui/Oembed` serializuje a odesílá standardní JSON/XML přes
společný HTTP dokument, bez aplikační JSON obálky. Modul dodává typ (`link`,
`photo`, `video` nebo `rich`), metadata a případné HTML vložení; endpoint zachová
jeho typ a doplní výchozí údaje instalace. Tato podpora nevyžaduje Social ani
jeho modal pro sdílení.

## Ověření změn

- `php tests/quality/module-boundaries.php` a `php tests/quality/dependencies.php`.
- `php tests/quality/module-catalog.php` pro offline katalog, manifesty a legacy registrace.
- `php tests/quality/module-resources.php` pro jednotný výběr zdrojů, cache a shodu buildu.
- `php tests/quality/runtime-registries.php` pro čisté registry, minimální start a výběr poskytovatelů.
- `php tests/http/api-contract.php` pro transport, oprávnění a datové kontrakty.
- `php tests/quality/render-only-partials.php` pro šablony bez doménové logiky.
- `composer preflight` pro úplnou vývojovou kontrolu v připraveném prostředí.

Historická mapa již odstraněných endpointů a hotové etapy migrace byly
z dokumentace odstraněny. Jejich obsah zůstává v historii Gitu; nepředstavují
podporované aliasy aktuálního API.

`Core/Ui/Platform` registers `HttpPolicy` as `tinycat.core`. Social registers identity extensions as `tinycat.social`; historical contribution IDs and migration streams keep their original IDs. Only Social publication quotas exempt bot authors for the `post` operation; core request, CAPTCHA and cooldown protections still apply.
