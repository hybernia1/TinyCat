# Audit navigace a vlastnictví URL modulů

## Implementovaný stav navazující etapy

Navazující implementace realizuje níže uvedené mapování. Zbytek auditu
zachycuje výchozí stav a návrh, nikoli další seznam nedokončených prací.

- Social vlastní `/admin/social/bots/accounts`, `/admin/social/bots/sources`,
  `/admin/social/bots/queue` a `/admin/social/moderation/reports`. Jediná lazy
  navigační registrace poskytuje Social se čtyřmi přímými odkazy. Vstup
  `/admin/social` vede na účty; detail bot účtu otevírá stejný workspace nad
  seznamem účtů. Core shell neobsahuje větvení podle modulu.
- Všechny doménové endpointy Socialu jsou pod `/api/social`: `bots`,
  `moderation`, `statuses`, `profiles`, `search/challenges`,
  `search/suggestions`, `pages` a `sidebar`. Metody, oprávnění po operaci,
  CSRF, readiness, veřejná dostupnost a JSON obálka zůstávají společné.
- Metadata-only `httpNamespace` je povinný pro route providery. Inventář
  hlídá kolize i neaktivních modulů a rezervované Core kořeny. Scoped `Api`
  zachovává vlastníka při odvození readiness/public web; stávající router
  atomicky ověří i přímé registrace. Admin metadata nesmějí odkazovat na
  cizí namespace. Aktualizace samostatného balíčku zachová již deklarovaný
  namespace; první doplnění dříve chybějícího údaje je možné.
- Manifesty, doručovací scopes, resources, formuláře, modaly, stránkování,
  návratové URL, Tasks odkazy, JS pathname kontroly a aktivní návody/fixture
  používají kanonické cesty. Staré dvě navigační registrace a původní helper
  botího menu jsou odstraněné. Nevznikla druhá sada API handlerů ani seznam
  modulů v Core. Aktivační/task IDs, tabulky a migrace se nemění.

### Přesně vymezená kompatibilita

| Zachovaný vstup | Důvod a chování | Plán odstranění |
| --- | --- | --- |
| `/admin/bots` | Uložený vstup rozlišující `bot`; owner redirect přímo vybere kanonický seznam účtů nebo zdrojů. | Po kompatibilitním období řady 8.x, při přechodu na 9.x. |
| `/admin/bots/accounts`, `/admin/bots/list`, `/admin/bots/queue` | Bookmarky a historické odkazy na seznamy; jeden owner redirect s povolenými query parametry, `list` se mění na `sources`. | Stejné období 8.x. |
| `/admin/bots/{id}` | Historické odkazy na bot účet včetně uložených výsledků; stejná autorizace a kanonický workspace. | Stejné období 8.x. |
| `/admin/moderation`, `/admin/moderation/reports` | Bookmarky a historické odkazy na nahlášení; owner redirect. | Stejné období 8.x. |
| GET `/api/statuses/{id}/links/{hash}` a `/api/statuses/{id}/comments/{commentId}/links/{hash}` | Tyto konkrétní resolver URL už byly publikované v HTML a feedech. Používají tentýž handler jako `/api/social/statuses/.../links/...`, stejnou viditelnost, readiness, public-web scope a JSON/native odpověď. Nejde o alias čtení nebo změny statusu. | Až po samostatném plánu převodu publikovaných odkazů; jejich odstranění není automatickou součástí konce admin kompatibility 9.x. |
| Staré resource fragmenty a modalová historie `bots/…`, `moderation/…`, `statuses/…`, `profiles/…` | Fragment server nevidí. Jediný Social [převod](../../App/Modules/Social/assets/legacy-modal-links.js) mění fragment a uložený stack na nové resource URL před společným obnovením dialogu; zachová query, tab i okolní historii. Nejde o síťový API alias. | Odstranit společně s výše uvedenými admin redirecty po 9.x. |
| Publikované `/status/{id}`, `/author/{id}`, `/tag/{tag}`, jejich feedy a embed/snippet vstupy | Veřejné permalinky a integrace; stále je registruje Social. Core discovery/oEmbed zůstává rozšiřovacím bodem. | V této etapě se nepřesouvají; případná změna vyžaduje samostatný permalink plán. |

Ostatní staré doménové API prefixy jsou vyřazené a vracejí společnou 404 obálku.
Pro externí klienty jde o přechod na uvedené kanonické endpointy; není doložen
důvod držet paralelní mutation aliasy. Web a jeho klientské assety se nasazují
společně a dříve otevřený klient vyžaduje reload. Core `/api/users`,
`notifications`, `settings`, `scheduler`, společné hledání a technické `site`
resources se nepřesunují. Historické reporty, release fixture očekávající
výslovně odstraněné endpointy a publikované migrační soubory zůstávají historií.

### Ověření implementace

- [Metadata, kolize a rezervované kořeny, atomická registrace, read-only výjimky, registry, hranice, UI a render-only kontrakty a komponentová parita](../../.work/tests/runs/20261127-133909-34239f2cd1/report.json) prošly.
- [HTTP kontrakt](../../.work/tests/runs/20261127-133607-9954721b53/report.json) prošel pro nové i vyřazené URL, admin/user/guest, CSRF, seznamy a editory, moderaci, profily, stav bez Socialu a oba publikované link resolvery včetně zákazu mutací a ztráty viditelnosti.
- [Nezávislý modulový pilot](../../.work/tests/runs/20261127-134023-5bf44afc34/report.json) ověřil vlastní namespace, MySQL data, HTTP oprávnění, aktivaci a assety. [Cílený lifecycle balíčků](../../.work/tests/runs/20261127-131843-be7bedce51/report.json) prošel včetně odmítnutí přejmenování již deklarovaného HTTP namespace, neaktivních updatů, načtených providerů a obnovy. Tento běh neobsahoval Social; navazující read-only výjimky Socialu ověřují uvedené HTTP a metadata kontroly.
- [Finální frontendový výběr](../../.work/tests/runs/20261127-134115-1bbe3b1d2f/report.json) prošel: zdroje a náhledy, feed/cursor lifecycle, filtrované mutace, sidebar, veřejná navigace, komentářové modaly, Back/Forward/reload a staré serializované modalové stavy včetně explicitních ID, validace formulářů, botí seznamy, embed, stylesheet inventář a source/compiled/minified assety.
- PHP lint, Composer style, PHPStan, parita všech 12 jazyků, formát katalogů a kontrola diffu prošly. Generovaný UI inventář je obnoven. Asset report prošel pro 24 scopes; [finální naměřené hodnoty](../../.work/audits/module-http/assets-final.json) zachovávají rozpočty, čtyři skripty a jeden stylesheet. Namespace etapa pouze přejmenovala klíče dvou admin scopes v budget fixture, nezvyšovala jejich limity.
- Na čistém panelu bez prototypové injekce byly na `tinycat.test` ověřené všechny čtyři stránky při šířkách 375 a 1280 px, otevřená skupina Social, čtyři přímé položky a správný aktivní odkaz bez vodorovného přetečení. Živý botí detail, editor zdroje, starý bookmark, uložený modalový stack a reload/zavření používají kanonické resources. Nasazené prepared minified assety jsou ověřené. Formuláře nebyly odesílány.

Jediný zaznamenaný skip cílených kontrol je symlink escape fixture, protože
Windows prostředí neumožnilo vytvoření testovacího symlinku. Kompletní preflight,
celá frontendová sada, celý release/updater průchod, POSIX a fyzické mobilní
zařízení nebyly spuštěné. Starší zastavené či neúspěšné dílčí reporty nejsou
finálním průchodem; opravené skupiny mají výše uvedené úspěšné běhy.

Runtime soubory a připravené assety byly průběžně synchronizované do externího
Laragonu. Historické migrace, checksumy, registr balíčků, userdata a zálohy
nebyly přepisované. Změny zůstávají necommitované nad checkpointem `3e17cf67`;
neproběhl push ani publikace releasu.

Datum: 27. listopadu 2026. Rozsah: trvalá podoba schváleného náhledu Socialu v administrativní navigaci, modulové URL a API, dopady převodu a obecný kontrakt pro budoucí moduly. Jde o audit a návrh; routy ani implementace navigace tímto dokumentem nejsou změněné.

## Cíl a závěr

**Z URL má být poznat modulový vlastník. Social nemá vystupovat jako sada zdánlivě core funkcí Boti a Moderace.** Stejné pravidlo má platit pro libovolný další modul, například obchod: `/admin/eshop/…` a `/api/eshop/…`.

Doporučené rozdělení:

- Administrační stránky vlastníka: `/admin/<modul>/…`.
- Doménové API vlastníka: `/api/<modul>/…`, bez ohledu na to, zda konkrétní operaci smí návštěvník, uživatel nebo administrátor.
- Veřejné vstupy modulu: `/<modul>` a případné nové stránky pod jeho prefixem; již publikované permalinky mají samostatný kompatibilitní plán.
- Povinné služby Core zůstávají vlastníkem účtů, nastavení, Tasks, notifikací a dalších společných mechanismů. Jejich endpointy se nepřesunují pod Social jen proto, že je Social používá.

Uživatel upřesnil, že v původním příkladu `/social/bots` pouze vynechal administrační prefix. Pro tuto etapu tedy používat `/admin/social/bots`, nikoli přesouvat administraci do veřejného prostoru `/social`.

Změna má dvě části: jednoduše sloučit menu v registraci Socialu a důsledně převést jeho doménové routy a jejich konzumenty. Samotné seskupení menu druhou část neřeší. V prvním kroku není nutná změna databáze ani migračních streamů.

## Potvrzený současný stav

### Navigace

[Social Module](../../App/Modules/Social/src/Module.php) přidává dvě samostatné `NavigationItem`: `tinycat.bots.admin` s pořadím 30 a `tinycat.moderation.admin` s pořadím 40. První vlastní odkazy Bot účty, Zdroje a Fronta příspěvků, druhá Nahlášení.

[Core administrativní navigace](../../App/Core/Ui/Admin/Navigation.php) pouze převezme registrované příspěvky. [Společná normalizace](../../App/Core/Ui/Navigation.php) dopočítá odkazy a aktivní stav. [Shell](../../App/Core/Ui/Admin/templates/shell.php) již umí jednu rozbalovací skupinu s přímými odkazy. Pro odsouhlasený výsledek proto není potřeba nový stromový renderer ani nový systém registrace.

Trvalá podoba má být jedna položka **Social**, pod ní přímo čtyři odkazy, bez mezititulků Boti a Moderace a bez dalšího vnoření.

### Administrativní stránky a API

Administrativní seznamy a editory mají metadata v [manifestu Socialu](../../App/Modules/Social/assets/module.json). Část cest je deklarovaná tam, část přímo v PHP nebo JavaScriptu. Například:

- [Bots Api](../../App/Modules/Social/src/Bots/Api.php) registruje účty botů, zdroje, frontu a jejich operace.
- [Moderation Api](../../App/Modules/Social/src/Moderation/Api.php) registruje hlášení, blokované URL a změny publikace.
- [Bots Pages](../../App/Modules/Social/src/Bots/Pages.php), [Queue](../../App/Modules/Social/src/Bots/Queue.php), [Resource](../../App/Modules/Social/src/Bots/Resource.php) a [Sorting](../../App/Modules/Social/src/Bots/Sorting.php) skládají další kanonické odkazy, redirecty a stránkování.
- [Controller botů](../../App/Modules/Social/assets/bots/controller.js) a [workspace](../../App/Modules/Social/assets/bots/workspace.js) skládají `/api/bots/…` také přímo v klientovi. [Dialogy statusů](../../App/Modules/Social/assets/status/dialog-controller.js) mají přímé vazby na `/api/moderation/…`.

Orientační statická kontrola našla pro prefixy `/admin/bots`, `/admin/moderation`, `/api/bots`, `/api/moderation` 69 doslovných výskytů ve 13 souborech Socialu a 255 výskytů ve 26 souborech testů. Jde o textové výskyty, nikoli počet rout. Kontrola nezahrnuje generovaný UI inventář, starší release balíčky ani dynamicky složené prefixy. V kontrolovaných zdrojích Core nebyl nalezen doslovný výskyt těchto čtyř prefixů.

### Social má více doménových API než Bots a Moderation

Zbývající části registrují dnes neprefixované sociální endpointy:

| Vlastník uvnitř Socialu | Současná veřejná cesta API | Důležitá vlastnost |
| --- | --- | --- |
| [Posts](../../App/Modules/Social/src/Posts/Api.php) | `/api/statuses/…` | Publikace, diskuse, reakce, historie komentářů, embed a výstupní odkazy. |
| [Profiles](../../App/Modules/Social/src/Profiles/Api.php) | `/api/profiles/…` | Sociální profil a sledování; není to správa účtu Core. |
| [Search](../../App/Modules/Social/src/Search/Api.php) | `/api/search/challenges`, `/api/search/suggestions` | Sociální ochrana hledání a návrhy editoru; ne celé společné hledání Core. |
| [Site](../../App/Modules/Social/src/Site/Api.php) | `/api/site/pages`, `/api/site/sidebar` | Sociální veřejné stránky a sidebar; nejsou obecný core page resource. |

Pokud je cílem poznat vlastníka všech doménových API, nestačí převést pouze `/api/bots` a `/api/moderation`. Ty jsou vhodnou první ucelenou etapou, ale zbývající sociální API musí být uvedená v navazujícím migračním plánu.

## Navržené kanonické cesty

### Administrace Socialu

| Dnes | Cílová cesta | Poznámka |
| --- | --- | --- |
| `/admin/bots/accounts` | `/admin/social/bots/accounts` | Bot účty. |
| `/admin/bots/list` | `/admin/social/bots/sources` | Sladit pojmenování zdrojů s API; `list` pouze popisuje způsob zobrazení. |
| `/admin/bots/queue` | `/admin/social/bots/queue` | Fronta příspěvků. |
| `/admin/bots/{id}` | `/admin/social/bots/{id}` | Zachovat vstup do detailu a následné otevření účtového workspace. |
| `/admin/moderation/reports` | `/admin/social/moderation/reports` | Nahlášení. |
| `/admin/bots`, `/admin/moderation` | Redirect na konkrétní nový seznam | Zachovat význam starých query parametrů, například `bot`. |
| Nový vstup `/admin/social` | Redirect na `/admin/social/bots/accounts` | Pouze vstupní rozcestník, bez dalšího Social dashboardu. |

V menu není potřeba mít současně obecný odkaz Social a jeho obsah. Rozbalovací Social obsahuje čtyři přímé odkazy. Na všech stránkách a detailech těchto oblastí má zůstat otevřený a označit odpovídající aktivní odkaz.

### API Socialu

| Současná rodina | Cílová rodina |
| --- | --- |
| `/api/bots/accounts/…` | `/api/social/bots/accounts/…` |
| `/api/bots/sources/…` | `/api/social/bots/sources/…` |
| `/api/bots/queue/…` | `/api/social/bots/queue/…` |
| `/api/moderation/…` | `/api/social/moderation/…` |
| `/api/statuses/…` | `/api/social/statuses/…` |
| `/api/profiles/…` | `/api/social/profiles/…` |
| `/api/search/challenges` | `/api/social/search/challenges` |
| `/api/search/suggestions` | `/api/social/search/suggestions` |
| `/api/site/pages` | `/api/social/pages` |
| `/api/site/sidebar` | `/api/social/sidebar` |

Poslední dvě cesty už nepotřebují vágní segment `site`: vlastníkem je Social a resources jsou jeho stránky a sidebar. Návrh nemění datovou strukturu odpovědí, metody, autorizaci ani limity operací.

## Hlavní zjištění a dopady

### 1. Administrační prefix má skutečný technický význam

[Surface](../../App/Core/Ui/Surface.php) rozpoznává administrační povrch podle `/admin` a jeho potomků. [Admin Definitions](../../App/Core/Ui/Admin/Definitions.php) vyžaduje pro seznamy `path` začínající `/admin/` a pro API `/api/`. Obyčejný přesun stránky pod `/social/bots` by tak vyžadoval obecnější úpravu klasifikace povrchů, validace definic a výběru assetů. Dohodnuté `/admin/social/…` současný mechanismus zachovává.

Přidání prefixu samo o sobě nezajišťuje autorizaci. Tu dál vlastní [společný API registrar](../../App/Core/Http/Api.php), middleware a konkrétní Pages/Actions vlastníka.

### 2. Prefix modulového API nesmí znamenat společné oprávnění

Bots API je administrační. Moderation ale obsahuje i uživatelské nahlášení příspěvku a načtení důvodů hlášení. Nelze tedy celý `/api/social/moderation` mechanicky zamknout pro administrátora. Zachovat současné role po jednotlivých operacích, viditelnost cílového objektu, CSRF, throttling a vazbu bot account/post při správě publikace.

Stejně zachovat `forPublicWeb()` a dostupnost modulových schémat. Konvence cesty, autentizace, autorizace a provozní dostupnost jsou samostatné vlastnosti.

### 3. Pouhá změna registrace rout není úplná migrace

Převést společně manifestové `path/api/endpoint/editor.creation`, PHP redirecty a resources, modalové URL, klientské formuláře a porovnávání pathname. Dále stránkování, sort/filter state, `next_url`, odkazy na bot účet, ruční importy a návratové URL.

`Definitions::modalId()` odvozuje některá ID z resource URL. [Detail bot účtu](../../App/Modules/Social/src/Bots/Pages.php) navíc přesměrovává na fragment `#modal=bots/accounts/{id}`. Fragment server nedostává, takže pouhý HTTP redirect nepřevede každou starou modalovou historii. Stabilní logické identifikátory dialogů pokud možno zachovat; případné nové identifikátory musejí mít promyšlené čtení starých fragmentů ve společném modalovém mechanismu.

### 4. Assetové scope a výběr komponent musejí odpovídat novým cestám

[Social manifest](../../App/Modules/Social/assets/module.json) vybírá botí a moderační assety podle `/admin/bots` a `/admin/moderation`. Převést je na nové prefixy zároveň s list definitions; jinak správná HTML routa nemusí dostat svůj controller, komponenty či překlady. Zachovat skutečné scope: převod není důvod posílat celý Social na každou administrační stránku.

Obnovit generovaný inventář, zkontrolovat sestavené i zdrojové doručení a odpovídající assetové rozpočty. Nevytvářet druhou registraci URL v JavaScriptu místo využití owner metadat a kanonických resources.

### 5. Nový obecný kontrakt musí mít jednoznačný namespace a hlídat kolize

Současná [Definition](../../App/Core/Modules/Definition.php) odděluje aktivační ID modulu a PHP namespace, ale nemá samostatný deklarovaný HTTP namespace. `tinycat.social`, namespace PHP `TinyCat\\Social\\` a URL segment `social` nejsou zaměnitelné údaje. Z poslední části vendor ID nelze bez ověření automaticky vyrábět unikátní HTTP prefix: dva různí vlastníci mohou zvolit stejný název.

Pro další moduly doporučuji přidat do metadata-only kontraktu stabilní HTTP namespace a ověřit jeho formát, jednoznačnost v inventáři a konflikt s rezervovanými core cestami. Přesný název nového pole a přechod jeho formátu patří až do implementačního návrhu. Návrh zde není tvrzením, že takové pole již existuje.

Použít stávající [registraci providerů](../../App/Core/Modules/Providers.php), [API](../../App/Core/Http/Api.php) a [Router](../../App/Core/Http/Router.php). Vlastníka a prefix lze nést v obecném registračním kontextu; případné rozšíření musí zachovat `whenReady`, roli, veřejný povrch i společnou JSON obálku. Nevytvářet lokální kopii routeru, další HTTP stack ani centrální seznam Social/Shop/Notes v Core. Pravidla API mají být vymahatelná při registraci a v quality kontrolách, nikoli jen doporučená v textu.

Core smí mít explicitně registrované rozšiřovací body a kompatibilní staré aliasy; takové výjimky musejí být pojmenované. Modul nemá libovolně zabírat jinému vlastníkovi neprefixované endpointy. Prefix nesmí aktivovat modul a nemění jeho aktivační ID, závislosti ani vlastnictví dat.

### 6. Zachovat hranici Core a veřejných sociálních odkazů

`/api/users`, `/api/notifications`, `/api/settings` a `/api/scheduler` zůstávají Core. Sociální rozšíření identity nemá převzít účtové API. Obdobně core hledání, Tasks, oEmbed host nebo doručení pošty nezískávají prefix Social jen proto, že obsah obsluhovaného výsledku patří Socialu.

[Social PublicPages](../../App/Modules/Social/src/Site/PublicPages.php) má již vstup `/social`, ale zveřejněné příspěvky `/status/{id}`, profily `/author/{id}` a tagy `/tag/{tag}`. [Embedy](../../App/Modules/Social/src/Posts/Embed.php) a `/assets/embed.js` jsou publikované integrační vstupy. Jejich přejmenování není nutnou podmínkou sjednocení administračních cest a modulového API. Úplný přesun veřejných permalinků pod `/social/…` by vyžadoval samostatné rozhodnutí včetně kanonických URL, RSS, sitemap, lokalizovaných cest, oEmbed discovery a trvalých redirectů.

Core API nyní nemá univerzální `/api/core/` prefix. Tento audit navrhuje rezervovat a zdokumentovat jeho existující cesty; plošný přesun Core API by byl další samostatná změna, nikoli vedlejší následek převodu Socialu.

## Kompatibilita

- **HTML GET odkazy:** staré botí a moderační stránky přesměrovat na nové kanonické cesty se zachováním povolených filtrů a významu parametrů. U modalových fragmentů řešit i klientskou část.
- **API:** pokud zůstane přechodné kompatibilitní období, staré cesty mají volat stejný handler se stejnou rolí, CSRF a kontrolou modulu. Především mutace nepřesměrovávat jako obyčejné HTML GET požadavky. Délku podpory starých cest stanovit a zdokumentovat před releasem.
- **Výstupy:** nové HTML, formuláře, resources a stránkování mají vždy generovat nové kanonické cesty. Staré aliasy se nemají znovu šířit do nových odkazů.
- **Vypnutý modul:** jeho nové routy i kompatibilní aliasy musejí být nedostupné společně; správa Core zůstává použitelná.
- **Trvalá data:** nepřejmenovávat tabulky, migrace, aktivace ani task IDs kvůli URL. Staré uložené odkazy mají buď funkční alias, nebo explicitní owner převod; nikoli nezdokumentovaný hromadný SQL replace.

## Doporučené etapy

1. Zakotvit obecné vlastnictví namespace v [kontraktu modulu](../contracts/module-contract.md), [HTTP API](../reference/api.md), [manuálu autora](../development/module-authoring.md) a validačních kontrolách. Stávající dokumentace obsahuje příklady `/api/profiles`, `/api/records` a `/admin/records`, takže nestačí pouze doplnit nový odstavec.
2. Sloučit dvě navigační registrace Socialu do jedné lazy `NavigationItem` s přímými čtyřmi odkazy a překladem názvu vlastněným Socialem. Žádné zvláštní větvení Socialu v core shellu.
3. Jako jednu konzistentní etapu převést HTML i API botů/moderace, všechny jejich konzumenty, assetové scope a kompatibilní vstupy. Současně sjednotit `list` na významové `sources`.
4. Dokončit převod zbývajících doménových API Socialu pod `/api/social`, s kontrolou veřejné publikace, profilů, hledání a stránkových resources. Každá etapa musí být nasaditelná; případné dočasné rozdělení popsat.
5. Regresní obecný kontrakt ověřit malým nezávislým fixture modulem s vlastním namespace a pokusem o kolizi, nikoli návratem odstraněné aplikace nebo kopírováním Socialu do Core.

## Ověření a stav auditu

Audit byl statický, s přečtením registrací, rout, manifestů, rendereru, autorizačního toku a reprezentativních konzumentů. Výskyty byly spočítané textovým průchodem zdrojů. Odkazy tohoto dokumentu jsou ověřené proti checkoutu. Nevznikla runtime změna a nebyl spuštěn preflight.

Při implementaci použít podle pokynu uživatele cílené kontroly, nikoli automatický celý preflight. Minimum podle konkrétní etapy: lint/style/analyse, module boundaries a dependencies, metadata/registries, UI a modal kontrakty, render-only a překlady při novém labelu, asset report a komponentová parita. Z existujících scénářů vybrat HTTP API autorizaci a příslušné botí/moderační případy, `client-assets.cjs`, `bot-admin-lists.cjs`, `source-preview.cjs`, `dialog-history.cjs` a odpovídající navigační/responzivní testy.

Regrese musí ověřit nové i staré vstupy, běžného uživatele versus administrátora, CSRF, filtry a stránkování po mutaci, přímý modalový odkaz a reload/Back/Forward, minifikované assety, vypnutý Social i nezávislý nový modul. Na `tinycat.test` po průběžné synchronizaci ověřit aktivní Social skupinu, všechny čtyři položky, detail bot účtu, zdrojový editor, frontu a nahlášení na desktopu i mobilu. Browserový prototyp v tomto vlákně je pouze náhled a neověřuje nové serverové routy.
