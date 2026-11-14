# Co ještě nesprávně vlastní Social

Datum: 2026-11-12. Audit aktuálního pracovního stromu po rebuildu frameworku
a sjednocení administrace Blogu. Jde o návrh dalších změn, ne o dokončené přesuny.

## Závěr

Oddělení adresářů zatím nepředstavuje dokončené oddělení odpovědností.
Social pořád obsahuje obecné mechanismy, jejich UI a některé globální politiky.
Část zdánlivě sociálních souborů už ovšem pouze deleguje do existujícího core.
Současně jsou v core stále některá pevná sociální pravidla. Další etapa musí
řešit oba směry a odstranit zbytečné přeposílací vrstvy.

Rozlišujeme tři situace:

- **Framework:** dokument stránky, metadata, jazykové URL, chybové odpovědi,
  bezpečnostní mechanismy, obecné UI a hostitel dashboardu patří do core.
- **Sdílená publikační schopnost:** práce s textem, médii, diskusí, reakcí nebo
  importem může být v Core/Publishing; konkrétní použití, oprávnění, dostupnost
  a veřejný význam dodává vlastník obsahu. Přítomnost služby ji automaticky
  nezapíná nad každým druhem publikace.
- **Sociální funkce:** statusový feed, sledování, sociální profil, popularita,
  reputace, politika hlášení a publikování botího účtu zůstávají Socialu.

Pouhá možnost opětovného použití není důvod přenášet celou doménu do frameworku.
Produktové recenze nejsou automaticky lajky a objednávky e-shopu nejsou publikace.

## Rozsah a podklady

Inventář zahrnuje 123 PHP souborů v `Social/src` (11 222 řádků),
30 JavaScriptových souborů a 52 komponentových definic. Kontrolovány byly
registrace všech šesti vnitřních oblastí, hlavní implementace a jejich vazby
na Core/Publishing, Identity, Security, Discovery, Settings a Ui; také manifesty,
šablony, klientská prezentace a kompatibilní názvy. Statický inventář není důkaz,
že každý nalezený odkaz nastane za běhu.

Podklady v ignorovaném `storage/social-core-audit-20261112/`:
`php-inventory.json`, `scope.json`, `probe.php` a `probes.json`.
Otisk všech 249 souborů Socialu:
`76bf30be5e2a81f2078a48aa938d523bb4ec329cbd84b9afe9a9da1b25ec7cec`.

Sondy používají explicitní syntetickou konfiguraci a SQLite `:memory:`.
Nečetly živou konfiguraci ani provozní DB. Aplikační kód, schéma a živá instalace
se při auditu neměnily.

## Konkrétní nálezy

### 1. Veřejný dokument a obsluha HTTP stále závisejí na Socialu

[Metadata](../../App/Modules/Social/src/Presentation/Metadata.php) sestavují
canonical, Open Graph, obrázky, jazyk, robots a JSON-LD. Nejde o sociální
mechanismus. [LayoutData](../../App/Modules/Social/src/Presentation/LayoutData.php)
míchá načtení assetů, CSRF, flash zprávy, branding a souhlas s analytikou
se sociálním profilem a sidebarovou prezentací.

[Errors](../../App/Modules/Social/src/Presentation/Errors.php) řeší HTTP kódy,
hlavičky, JSON, chyby assetů i nouzovou stránku bez DB. Její callback se přes
[Registration](../../App/Modules/Social/src/Presentation/Registration.php)
zapisuje do globálního `Startup::$exception`. Bez Socialu má
[HttpKernel](../../App/Core/Runtime/HttpKernel.php) jednodušší chybový výstup.
`HttpEntry` navíc stále obsahuje instalační výjimky a reakci na nepřipravenou DB;
`Presentation/Accounts` konfiguruje i společný updater.

**Přesun:** jeden dokumentový a chybový mechanismus v Core/Ui a Core/Runtime,
společný branding a klientský souhlas. Téma Socialu dodá rozložení veřejné
sociální stránky, její obsah a specifické odkazy. Nesmí globálně přebírat
chování instalace, updateru nebo chyb cizího modulu. Použít stávající Config,
Localization, LocalUrls a HttpKernel; nevytvářet jejich druhé implementace.

### 2. Jazykové URL jsou obecné, ale mají pevný seznam sociálních cest

[LocaleRouting](../../App/Modules/Social/src/Presentation/LocaleRouting.php)
vlastní prefixy, canonical, jazykový přepínač, hlavičku Content-Language
i doporučení jazyka. `supports()` rozpoznává také core cesty `/login`,
`/notifications` a `/privacy`, ale ne `/blog`, `/articles/{id}` ani `/social`.
Neznámou cestu přepínač nahradí `/`.

Izolovaná sonda: `selector('/articles/123')` nabídne jako první URL `/cs/`,
nikoli jazykovou variantu stejného článku. To se projeví při použití sociálního
tématu pro článek Blogu. Neznamená to, že Blog musí lokalizovat samotný obsah.

**Přesun:** mechanismus jazykového routování a přepínač do core, podporované
cesty a povolené query parametry deklaruje vlastník routy. Stejně vlastník určí
canonical a skutečné jazykové alternativy dokumentu. Nedávat seznam Social/Blog
URL do core. Zachovat globální API, assety a feedy bez jazykových prefixů.

### 3. Blokování odkazů je fakticky závislé na zapnutí Socialu

[Blocking](../../App/Modules/Social/src/Moderation/Blocking.php) obsahuje obecnou
normalizaci a porovnání domén; nepotřebuje sociální tabulku.
[Moderation/Registration](../../App/Modules/Social/src/Moderation/Registration.php)
ale teprve při inicializaci Socialu připojí callback do
[Publishing/Integration](../../App/Modules/Social/src/Content/Integration.php).
Bez callbacku vrací `blockedHost()` prázdný řetězec. Blog tento společný vstup
volá při ukládání článku.

Sonda se stejným nastavením `moderation.blocked_urls = example.org`:
bez inicializace Socialu výsledek `''`, po ní `example.org`.

**Přesun:** obecný matcher do Core/Security, obsahová politika a její nastavení
do Core/Publishing. Samostatný Blog musí respektovat uloženou obsahovou blokaci.
Zachovat existující klíč při přechodu; nepřepisovat konfiguraci.
Nezaměňovat tuto obsahovou politiku s transportní SSRF ochranou `Http/Client`.
Hlášení statusů, sankce za sociální aktivitu a jejich důvody zůstanou Socialu.

### 4. Obecný Publishing má data, ale část použitelného výstupu zůstala Socialu

Konkrétní kandidáti k rozdělení:

| Dnešní místo | Společná část | Co zůstane vlastníkovi |
| --- | --- | --- |
| [Posts/Segments](../../App/Modules/Social/src/Posts/Segments.php) | Tokenizace textu a odkazů, bezpečné segmenty, prezentace tagů. | URL profilů a tagových archivů, sociální přesměrování, politika zkracování statusu. |
| [Posts/Mentions](../../App/Modules/Social/src/Posts/Mentions.php) | Stabilní reference na identitu, převod pro editaci; resolver identity může sdílet Core/Identity. | Rozpoznání `/author/{id}`, odkazy na sociální profil a sociální oznamování zmínky. |
| [Posts/Resource::link](../../App/Modules/Social/src/Posts/Resource.php) | Data linkové karty: poskytovatel, náhledy, audio/video a bezpečné embed parametry. | Oprávnění a navigační URL konkrétní publikace. |
| [Posts/Images](../../App/Modules/Social/src/Posts/Images.php) | Čtení `content_images`, limity médií, společné image resource. | Formulace alt textu odvozená ze statusu a jeho autora. |
| [Posts/ContentLinks::tagged](../../App/Modules/Social/src/Posts/ContentLinks.php) | UTM doplnění bez přepsání existujících parametrů. Nastavení už vlastní Core/Publishing. | Resolver viditelného statusu/komentáře a staré `/api/statuses/.../links/...` URL. |
| [Posts/Syndication](../../App/Modules/Social/src/Posts/Syndication.php) | Serializace RSS a bezpečná textová reprezentace. | Selekce statusů, autora/tagu, viditelnost a názvy konkrétních feedů. |
| [Posts/Embed](../../App/Modules/Social/src/Posts/Embed.php) | Hostitel oEmbed protokolu a společné bezpečnostní/renderovací části. | Lookup `/status/{id}`, obsah karty a sociální schema.org typ. |

Klientské protějšky jsou především `assets/status/media.js`, `media-preview.js`,
část `editor.js` a `translation.js`; komponenty `status/image`, `status/link`,
`status/segments` a související styly. Mechanismus přehrání média, linkový náhled,
textový editor s nabídkami a lokální strojový překlad nejsou specifické pro feed.
Mají dostat neutrální vstupy a selektory. Statusová karta, její tlačítka a limity
zůstávají sociální prezentací. Existující `assets/ui/image-uploads.js` už je společný.

Blog zatím vykresluje tělo jako prostý text. Připojení společné prezentace musí
být výslovné a musí respektovat jeho delší text; nesmí prostě převzít limity
a chování statusového editoru.

Další vazba je schovaná za callbacky: `Posts/Registration` připojuje normalizaci
zmínek do jediného globálního slotu `Publishing/Integration`; společný
`PostCreation::fromText()` ji následně používá i při přípravě náhledu článku.
`Presentation/Registration` obdobně nastavuje globální základ URL a odstraňování
jazykového prefixu. Samotné odstranění PHP importu tedy nestačí. Instalace musí
mít funkční neutrální výchozí chování a vlastníci doplňují jen svou explicitně
vybranou politiku, nikoli skrytou změnu všech publikací.

### 5. Komentáře a reakce jsou znovupoužitelné schopnosti, současný kód není obecný

[Comments](../../App/Modules/Social/src/Posts/Comments.php) řeší vlákno,
historii editací, lajky komentářů i mazání. Dotaz však filtruje `kind = post`,
oprávnění odkazují na `Posts::status_find()` a mazání volá sociální notification
hooks. Zápisy a limity jsou navíc rozptýlené v
[Posts/Actions](../../App/Modules/Social/src/Posts/Actions.php).
[Reactions](../../App/Modules/Social/src/Posts/Reactions.php) samo obsahuje
převážně čtení/cache, nikoli úplný engine zápisů.

**Doporučení:** společné úložiště a transakční služby diskuse/reakcí v
Core/Publishing. Vlastník publikace dodává dostupnost funkce, autorizaci cíle,
zobrazovací URL a události. Social zachová feedové počty, sociální reputaci,
adresáty a typy sociálních oznámení. Blog může použít stejnou schopnost bez
zapnutého Socialu; neznamená to automatické otevření komentářů všude.

Přesun musí zahrnout SQL zápisy, cache, API, JSON resources, komponenty, JS,
překlady, cleanup a vlastnictví tabulek. Pouhé odstranění `kind = post` by
rozšířilo sociální endpointy na cizí obsah a porušilo oprávnění.
Recenze s hodnocením produktu zůstávají případnou doménou Shopu, dokud se
neprokáže skutečně společný kontrakt.

### 6. Importní příprava už je v core, provozní pipeline je stále botí

FeedParser, WordPressFeed a FeedImport jsou už v Core/Publishing.
Přeposílací třídy v Socialu nejsou dalšími implementacemi parseru.

[Automation/Importer](../../App/Modules/Social/src/Automation/Importer.php)
ale stále spojuje plánování zdrojů, claim, deduplikaci, historii a selhání
s `bot_user_id`, rolí `bot`, statusy a botí frontou. `SourcePreview` používá
`Posts/Images` a `Moderation/Blocking`. `Queue` explicitně vybírá statusy botů.

**Rozdělit:** společnou publikační importní pipeline rozšířit nad existujícím
Core/Publishing a Core/Tasks; zdrojový store a publikační cíl předává vlastník.
Social vlastní účet bota a adaptér pro status. Blog dodá článek jako druhý
ověřený cíl. Nevytvářet další scheduler ani univerzální ETL framework pro
hypotetické objednávky. Botí administrační workspace a jeho konkrétní tabulky
se nestěhují do core jen kvůli obecnému názvu „automatizace“.

### 7. Obecné UI a technická diagnostika zůstávají v sociálním balíčku

- [Dashboard](../../App/Modules/Social/src/Presentation/Dashboard.php) vedle
  statusů a reakcí načítá účty, notifikace, Cache/Memcached/OPcache. Hostitel
  a technické widgety patří Core/Ui/Admin a Core/Health; Social dodá své widgety.
  Současný příspěvek `dashboards` vybírá celou stránku, ne společné widgety.
- `assets/search/global-search.js` už umí iterovat kolekce společného
  Discovery API, ale widget je v Socialu a má také větve tag/user a sociální
  texty. Core má hostit generické našeptávání, zdroj dodá popis výsledku;
  sociální vyhledávací feed a SQL zůstávají Socialu.
- `assets/site/analytics.js`, `language-suggestion.js` a obecná část
  `page-navigation.js` jsou použitelné mimo Social. Navigace nyní obsahuje
  vlastní sociální seznam cest, endpoint a obnovu sidebaru. Zobecnit pouze
  transport dokumentu/metadat nad existující `assets/core/navigation.js`;
  přijímané cesty, query a sidebarový callback poskytne vlastník.
- Z `bots/workspace.js` lze později vytáhnout workspace s lazy seznamy v tabech,
  izolovaným stavem a stránkováním ve footeru. Botí metadata a API zůstanou
  v Socialu. Stávající `ui.sectionedDialog()` a `ui.tabbedContent()` se nenahrazují.
- Privacy stránka a její consent UI dnes míchají platformní údaje s popisem
  feedu, veřejných statusů a reputačními limity. Core může skládat stránku
  z příspěvků vlastníků; sociální pravidla nesmí být univerzálním textem webu.
  Stejně se rozdělí `llms` dokument a jeho čistě statusový výběr.

### 8. Směr zpět: některé sociální politiky pronikly do core

[Core/Security/RateLimitPolicy](../../App/Core/Security/RateLimitPolicy.php)
obsahuje tabulky `new/normal/trusted`, kvóty `post/comment/like/report/search`,
cooldowny statusových akcí a `follow`. Obecný limiter a jeho úložiště jsou správně
v core; tyto konkrétní definice mají dodat příslušní vlastníci. Retence limiteru
musí vycházet z deklarovaných aktivních oken, nikoli z pevného seznamu domén.
Blog a Social dnes navíc volí rozdílný způsob ochrany hledání. Mechanismus
session budgetu/CAPTCHA může být společný; konkrétní kvóty nemusejí být stejné.

Při této kontrole byla reprodukována i chyba:
[Restrictions::moderation_user_post_count](../../App/Modules/Social/src/Moderation/Restrictions.php)
počítá všechny `content` autora bez filtru druhu. Jeden status a dva články
vrátily hodnotu 3. Články tak mohou ovlivnit sociální reputační úroveň.
Toto se má opravit jako doménový filtr; přesun počítadla do core by chybu zakryl.

## Co už je v core a nemá se tam stěhovat podruhé

Social má kompatibilní dědící/průchozí třídy, například
`Posts/Links`, `PostText`, `LinkParser`, `InlineText`, `ImageStorage`, `Audio`,
`Metadata`, `Providers`, `Redirects` a části `Profiles/Login`, `Authentication`,
`Accounts`, `Actions`, `Avatar`, `Recovery`, `Usernames` a `Resource`.
Jejich kanonické implementace jsou v Core/Publishing nebo Core/Identity.

Převést běžné volající na kanonické názvy a odstranit nepotřebné průchozí třídy.
Smíšené `Profiles/Profiles`, `Roles`, `Pages`, `Views` a `Posts/Tags` se musí
rozdělit po odpovědnostech: část je delegace, část skutečný sociální profil,
sledování, URL nebo ranking. Nepřesouvat je celé.
Historické názvy potřebné publikovanými migracemi zachová výhradně centrální
`Core/Runtime/LegacyNames`; nelze plošně přesměrovat všechny třídy Status na
Publishing, protože některé stále vlastní sociální chování.

## Co má po změně zůstat v Socialu

Statusy a jejich feed; sledování a sociální profily; sociální hodnocení popularity
autorů/tagů; statusové vyhledávací zdroje a jejich viditelnost; sociální reputace,
hlášení a sankční politika; botí identita a statusový publikační cíl; sociální
veřejné téma, sidebar a odpovídající URL. Vše pod jedním manifestem a jedním
vstupem `Social/src/Module.php`.

## Ověření auditu

Prošly současné kontroly hranic modulů a závislostí:
`storage/test-runs/20261112-201623-a3a77d1f67/report.json`.
Ty ověřují importy a vlastnictví deklarací, nikoli všechny významové hranice;
proto nevylučují nálezy tohoto auditu. Prošly uvedené izolované sondy.
Plný preflight ani browserové regresní sady se pro samotný audit nespouštěly.

Implementační pořadí a akceptační scénáře jsou v
[plánu čtyř ucelených etap](../plans/social-core-capabilities.md).
