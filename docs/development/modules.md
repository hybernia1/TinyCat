# Moduly a HTTP transport

Praktický začátek nabízí [manuál tvorby modulu](module-authoring.md).

Aktuální architekturu a registrační fáze popisuje
[Core a samostatné moduly](core-and-modules.md). Funkční vlastníci sídlí
v `App/Modules/`, společné mechanismy v `App/Core/`.

## Data a prezentace

Modul vlastní své entity, dotazy, FK, oprávnění a transakce. Core nemá univerzální
`content` ani společné komentáře; přesný inventář a převod popisují
[doménová data](domain-storage.md). Blog používá `Articles` a `Discussion`, Social
vlastní `Content` a sociální politiku. Nevolej repository jiného vlastníka jen
kvůli sdílenému editoru nebo stejnému číselnému ID.

`Core/Ui/InteractionTarget`, `RichTextResource`, `DiscussionResource` a
`ThreadTree` zpracují předaná data bez SQL. Vlastník předem ověří cíl a načte
autorizované přílohy, komentáře a stav reakcí. Zápis zamkne vlastní agregát,
ověří rodiče komentáře i jeho vazbu a atomicky zapíše změnu a limiter.
`Core/Text/Revisions` pomůže s formátem historie; tabulku vlastní modul.

`Database/Transactions::afterCommit` odkládá vedlejší efekty po vnějším
spravovaném commitu. Při rollbacku se zahodí. Vnější transakce s takovými efekty
musí použít `Transactions::run`; samotné PDO `beginTransaction` neslibuje jejich
doručení. Soubor neodstraňuj před commitem a neukládej nepotvrzené čtení do cache.

Společná prezentace je v `assets/ui/content/`; kompatibilní feature ID
`publishing.discussion-base` a `publishing.discussion` zůstávají zachována.
URL, `submit_controller` a schopnost `reply_mentions` dodává vlastník.
Sociální nabídka je volitelné `componentExtensions` rozšíření. Blog nepřebírá
zmínky, reputaci ani sociální události jen kvůli společnému UI.

HTTP i počáteční HTML používají stejné komponentové definice. Doménové endpointy
vracejí kanonická data a oprávnění, nikoli HTML či definice komponent. Autorizační
kontrola, CSRF a JSON obálka jsou povinné také pro AJAX a multipart `_method`.
Společné rozhraní nevytváří druhý ruční renderer modalů či seznamů.

## Veřejný dokument a příspěvky UI

Stránka volá `Core/Ui/Page::render()` s obsahem a metadaty. Core připraví dokument,
head, jazykové menu, uživatelské menu a consent UI. Vlastní téma dodává rozložení
obsahu přes `PageTheme`; nekopíruje head a vnější dokument. Blog je příkladem
použití výchozího rámu, Social příkladem vlastního tématu nad týmž dokumentem.

`ProvidesContributions::contributions()` může vrátit tyto lazy příspěvky:

| Položka `Contributions` | Kontrakt | Význam |
| --- | --- | --- |
| `localizedRoutes` | `LocalizedRoute` | Přenos jazykového prefixu pro vlastní veřejné cesty; explicitní allowlist query parametrů. |
| `information` | `InformationSection` | Obsah vlastníka pro `privacy`, `llms` nebo doplňkový `llms-full`. |
| `widgets` | `DashboardWidget` | HTML widgetu pro společný administrátorský dashboard. |
| `embeds` | `EmbedSource` | Lazy výběr veřejného obsahu pro společný oEmbed podle lokální kanonické cesty. |

Příklad deklarace cesty: `new LocalizedRoute('blog.article', '^/articles/[1-9][0-9]*$')`.
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
renderovací hooky. Social používá `post`, Blog `article`. Politiky nemají SQL;
normování a validaci uloženého textu určuje vlastník.

Blokování URL a UTM má každý modul samostatné. Social zachovává historické klíče
`moderation.blocked_urls` a `content_links.*`, Blog používá `blog.blocked_urls`
a `blog.links.*`; normalizaci poskytuje `Core/Security/UrlRules` a
`Core/Links/Tracking`. Blog-only instalace neposkytuje sociální moderation API.

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
