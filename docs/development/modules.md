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
`Core/Text/Revisions` pomůže s formátem historie; tabulku vlastní modul.

`Database/Transactions::afterCommit` odkládá vedlejší efekty po vnějším
spravovaném commitu. Při rollbacku se zahodí. Vnější transakce s takovými efekty
musí použít `Transactions::run`; samotné PDO `beginTransaction` neslibuje jejich
doručení. Soubor neodstraňuj před commitem a neukládej nepotvrzené čtení do cache.

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

`Core/Text/Policies::provide(owner, name, policy)` registruje pojmenované čisté
renderovací hooky. Social používá `post`. Politiky nemají SQL;
normování a validaci uloženého textu určuje vlastník.

Blokování URL a UTM má každý modul samostatné. Social zachovává historické klíče
`moderation.blocked_urls` a `content_links.*`; další modul deklaruje své klíče.
Normalizaci poskytuje `Core/Security/UrlRules` a `Core/Links/Tracking`.

`Core/Text/Segments`, `Core/Links/Presentation` a UI komponenty sdílejí text,
odkazy, obrázky a přehrávače. `Core/Links/Outbound` přijímá autorizovaný text
a lazy přístup k přílohám. RSS serializuje `Core/Feeds/Rss`, výběr řádků dodává
vlastník. oEmbed hostuje `Core/Discovery/Oembed` pro aktivní `EmbedSource`;
cizí origin odmítne bez stahování a vlastník ověřuje veřejnou viditelnost.

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
