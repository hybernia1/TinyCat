# Instalace a správa modulové sestavy

TinyCat odděluje povinný framework od funkčních modulů. Social je jeden
volitelný modul. Core není součástí aktivačního seznamu.

Aktualizace na 9.0.0 vyžaduje dokončenou instalaci TinyCat 8.0.14 nebo novější.
Instalace 6.0.10–8.0.13 nejprve aktualizuj podepsaným reader bridgem 8.0.14;
starší instalace nejprve převedou neměnná historická vydání na 6.0.10.
Samostatný Social aktualizuj na 9.0.0 mezi Core 8.0.14 a Core 9.0.0 podle
[pořadí přechodu](updates.md#upgrading-to-900).
Kompatibilní transport pro starší verze a builder přechodových balíčků byly
odstraněny. Historie schémat a checksumy zůstávají zachované; aktualizace musí
zachovat identitu produktu. Viz [podporovaný výchozí stav](updates.md#supported-update-baseline).

## Správa v administraci

Dostupnost určuje přítomnost platného `App/Modules/<Name>/module.json`, nikoli
seznam v distribučním descriptoru. Složku lze přidat ručně: správce ji nabídne
vypnutou bez spuštění PHP. Výslovná aktivace ověří závislosti a zdroje, uloží
snapshot vlastnictví a migrační metadata do existujícího registru balíčků a
provede instalaci přes společný lifecycle, zámek a zálohu. Ručně přidané moduly
bez verze balíčku dostávají interní verzi snapshotu `0.0.0`.

Odstranění nikdy neaktivovaného modulu jej odebere z nabídky. Instalovaný modul
s chybějícími zdroji zůstane v evidenci a správce zobrazí problém. Runtime jej
ani jeho závislé moduly nespustí; jejich uložená aktivace ani data se automaticky
nemění. Chybějící modul lze výslovně vypnout. Vrácení souborů není nová aktivace:
respektuje uložený stav. Neplatný neaktivní manifest neblokuje správce.

Blog a jeho build preset jsou odstraněny. Historické instalace s evidovaným
Blogem nejsou cílem nové sestavy; updater nadále odmítá odstranit instalovaného
vlastníka dat. Neobcházej tuto kontrolu přepisem deploymentu nebo mazáním dat.

Přihlášený administrátor otevře **Moduly** v navigaci nebo `/admin/modules`.
Stránka ukazuje dostupné moduly, jejich stav, povinné závislosti a aktivní
moduly, které je potřebují. Zapnutí doplní potřebné závislosti; vypnutí
zachová data a odmítne rozbití zbývající sestavy. Social má jediný přepínač pro celou svoji funkci. Core se v seznamu nenabízí. Změna vyžaduje potvrzení, CSRF a aktuální revizi výběru. Po úspěchu
se načte celá stránka s novými routami, navigací, překlady a assety.

Správce je součástí core UI, takže zůstává dostupný i bez Socialu; Settings a Updates jsou povinnou součástí frameworku. Používá stejný lifecycle, zámek, zálohu a kontroly jako
CLI. Zpřístupňuje moduly distribuce i samostatné ZIPy. Tlačítko **Nahrát modul**
přijímá důvěryhodný PHP balíček do 32 MiB; vyžaduje potvrzení, oprávnění
administrátora a CSRF. První nahrání zachová modul vypnutý a neprovádí jeho SQL
ani PHP inicializaci. Zapnutí je samostatná operace. Podrobnosti sestavení
ZIPu jsou v [manuálu autora](../development/module-authoring.md#8-samostatný-instalační-zip). V surovém checkoutu bez descriptoru
je přehled pouze pro čtení. CLI níže zůstává dostupné i pro obnovu.

## Sestavení

Z kořene zdrojů s připraveným podpisovým klíčem:

```powershell
php tools/release/build-update.php --assembly=platform --version=X.Y.Z --both --key=C:/private/signing.key --output=dist/releases/platform
```

Výchozí `--assembly=source` používá explicitní `App/modules.php`.
`--assembly=core` vytvoří framework bez volitelných modulů.
`--assembly=platform` načte produkční manifesty `App/Modules/*/module.json`
přes `SourceModules::production()`, bez modulů s `development: true`.
`--assembly=development` zahrne i vývojové moduly. Nový modul nevyžaduje zápis do centrálního seznamu.
`--product=vendor.product` určuje identitu vlastní sestavy a `--active=id1,id2`
její výchozí aktivní moduly; bez této volby jsou aktivní všechny dostupné.
Funkční moduly jsou volitelné; jejich funkční závislosti musí být aktivní. Core je vždy přítomný. Manifestový modul musí ležet
pod `App/Modules/` s vlastními `src/`, zdroji a neměnnou historií migrací.
Builder validuje poskytovatele a tabulky, přibalí dostupné vlastníky a sestaví
assety a překlady pouze aktivního výběru. Produkční balíček nepotřebuje tools,
Composer ani Node. Core artefakty mají prefix `tinycat.core`, sociální `tinycat`.

Podepsaný manifest obsahuje druh artefaktu (`install`/`update`), identitu
produktu, dostupné a aktivní moduly, povinný framework, vlastnictví schémat, závislosti a migrační
plány s checksumy. Stejný popis je uvnitř balíčku v `App/distribution.json`.
Lokální aktivaci vlastní chráněný `storage/deployment.json`; nepřepisuj ji ručně.

Historický mazací seznam se při sestavení omezí na modulové adresáře vlastněné
danou distribucí. Core balíček proto nemaže soubory samostatně instalovaných
modulů; jejich úklid patří aktualizaci vlastníka. Toto pravidlo platí i pro
vypnuté moduly a neexistující cesty. Staré cesty mimo `App/Modules/` zůstávají
v plánu kvůli podporovaným přechodům. Runtime nadále odmítá balíček zasahující
do nezávisle instalovaného modulu před změnou souborů nebo dat.

Veřejné discovery výstupy používají stejný registr aktivních vlastníků.
Core přispívá homepage a stránkou soukromí do `SitemapSection`; moduly přidávají
své vlastní sekce, `InformationSection`, `CrawlerHint` a feedové routy.
Po vypnutí vlastníka jeho sekce zmizí a jeho feedové i sitemapové adresy vracejí
404. Dynamické sitemap, robots, LLM a RSS dokumenty používají společný
`Http/Document` s ETag a povinnou revalidací; trvalé cache bez revalidace jsou
vyhrazené výslovně verzovaným výstupům.

## Fresh instalace

Rozbal instalační artefakt do samostatné instalace a připrav její `config.php`
s připojením k **prázdné** databázi. Příkazy spouštěj z kořene instalace:

```powershell
php App/Console.php status
php App/Console.php install
```

`install` čte jeden JSON objekt ze standardního vstupu: `username`, `password`,
`password_confirm`, `locale`. Předej jej správcem tajných údajů nebo chráněným
vstupem; heslo nedávej do argumentů příkazu, shellové historie ani repozitáře.
V interaktivním terminálu příkaz vyžádá přesměrovaný vstup. Validace i zápis
hesla používají Core/Identity. Opakovaná instalace nebo neprázdná DB se odmítá.

Čisté core vytváří 8 tabulek. Social přidává 16, celkem 24. Core nemá povinný obsah, diskuse
ani média. [Inventář a datové vlastnictví](../development/domain-storage.md).
Bootstrap nikdy neprovádí DDL.
Webserver musí používat kořenový `.htaccess` nebo ekvivalentní pravidla:
`App/`, konfigurace a `storage/` nejsou veřejné soubory; cache assetů se
zpřístupňuje jen přes úzké pravidlo `/cache/assets/`.

Statické WebP soubory musí vracet `Content-Type: image/webp`. Apache má explicitní
mapování v kořenovém `.htaccess`. Pokud soubory obsluhuje přímo nginx, načti jeho
tabulku `mime.types` a ověř mapování `image/webp webp;` v účinném kontextu.
Nginx `.htaccess` nečte. Chybný MIME typ neopravuj vypnutím `nosniff` ani změnou
cache parametru v URL; ověř GET i HEAD odpověď konkrétního souboru.

## Aktivace a provoz

```powershell
php App/Console.php activate vendor.notes vendor.reports
php App/Console.php deactivate vendor.reports vendor.notes
php App/Console.php upgrade
php App/Console.php task vendor.notes.cleanup batch=500
```

Aktivovat lze moduly dostupné v distribuci nebo registrované instalací ZIPu. Aktivace doplní jejich
závislosti; při deaktivaci se celý zbývající graf znovu validuje. Povinný
framework nemá aktivační přepínače. Operace validuje celý výběr a zdroje
před změnami. Aktivace instaluje nebo doplní modulové migrace pod společným
zámkem a se zálohou DB. Stav se zveřejní až po úspěchu.

Deaktivace odstraní routy, navigaci, úlohy, assety a překlady z aktivního
výběru od dalšího requestu. Zachová tabulky, data a migrační historii.
Nainstalovaní vlastníci zůstávají zapojení do úklidu při odstranění účtu;
jejich běžné úlohy a statusové reakce se tím neaktivují. Destruktivní
odinstalace není součástí tohoto rozhraní.

### Volitelný Social

Z kořene **nasazené instalace**, pro tinycat.test tedy `E:/laragon/www/tinycat`:

```powershell
php App/Console.php status
php App/Console.php deactivate tinycat.social
php App/Console.php activate tinycat.social
```

`tinycat.social` je jeden modul s vlastní inicializací a vnitřními částmi
pro profily, statusy, moderaci, hledání, automatizaci a veřejné stránky.
Vypnutí zachová celý framework včetně identity, nastavení,
scheduleru, diagnostiky, obecné schránky a doručování pošty.
Nesmaže statusy ani vztahy.

Social má `/social` a dosavadní `/status/{id}`.
Kořen `/` vybírá core z příspěvků `homepages`: nastavení `site.homepage`
volí například `tinycat.status.home`. Prázdná hodnota
vybírá první aktivní nabídku; po vypnutí zvolené nabídky se použije aktivní
fallback. `site.theme` vybírá [samostatné téma](../development/themes.md);
moduly témata neregistrují. Bez vybraného tématu je společný core rám.
Obě volby jsou v editoru nastavení webu. Nastavení, jeho klientské
popisky a e-mailové šablony přispívají pouze aktivní vlastníci.

Předkompilované výstupy obsahují otisk výběru. Při změně aktivace runtime
odmítne výstupy jiné sestavy a použije společný assetový a překladový compiler
nad dostupnými zdroji. Cache musí být zapisovatelná. Úlohy drží sdílený zámek,
nasazení výhradní; souběžný konflikt se odmítá a může se později zopakovat.

### Asset, component and translation generations

The signed `assets/build/manifest.json` and
`App/Core/Support/compiled/translations.php` are one pair with `version` and a
shared deterministic content `generation`. The compiler hashes emitted content,
normalized internal references, server components and all translation channels
before either index is written. Neither index participates in its own hash.
Publication URL differences do not change content identity. Identical prepared
data retain the release PHP path; changed data can overlay the same-version
release only as a complete prepared generation.

Metadata retains a provisional candidate once per request, without declaring a
payload delivered. Before delivery an unavailable candidate is reconsidered for
a complete overlay or source fallback; after delivery failure remains 503.
Runtime checks the used locale and client scope, including raw/min variants,
deferred script/style, catalog variants and compiler-recorded CSS font/image
dependencies. It never recursively hashes sources or stats every scope/locale.
Deferred `resources` are API triggers, not files. Domain media/srcset are outside
the inventory; individual owned build assets use the same URL check. Canonical
managed paths are `/assets/build/<name>.<24hex>.<ext>` and
`/cache/assets/<name>.<20hex>[.min].<ext>` for supported asset extensions. Inventory
URLs reject queries, fragments, traversal, foreign paths and origins. CSS can
append its original fragment to a checked base dependency; external/data CSS
references are outside the local inventory. Storage access rules do not change.

Before payload delivery, rejection selects the existing source fallback for all
channels. After delivery, a request retains its pinned generation; a late missing
locale/chunk raises recoverable `503 asset_inventory_unavailable`. Failed readers
fail fast on reentry. The HTTP kernel discards partial HTML and uses an emergency
document without external assets. Fresh requests can use complete source fallback,
including admin/updater. Missing build is normal in source checkouts; malformed
present release indexes are diagnosed and rejected as a pair.

Preparation publishes immutable public files/private JSON parts before its
format-3 index. The index anchors the exact release pair bytes, including absence
in source checkouts. A later release rejects the old overlay even at the same
SemVer; rollback restores its original anchor. Identical prepared data can repair
an incomplete release before delivery. Concurrent publication cannot alter a
pinned reader. Retention protects old format-2 references and already open pages.
No manual build/cache deletion is required.

Supported module lifecycle updates the content checksums in
`App/module-packages.json`. ModuleAutoload captures this identity before the first
module include, including withCatalog/scoped builds. Loaded providers from an old
registry, old core version or old reader API defer optional preparation without
publishing a new index. Fresh workers then use the release or a temporary complete
source fallback; no automatic preparation is scheduled. Explicit preparation
reports failure when a fresh process is required. After a coherent update, run
this existing command in the target installation in a fresh process:

```sh
php App/Console.php assets
```

Manual source edits, including copied same-version modules outside the package
lifecycle, require this command: HTTP does not discover arbitrary source changes
on each GET. Concurrent manual editing during preparation is unsupported. Changed
release anchor/selection aborts publication. Editing hashed outputs without the
index is corruption, not a new generation; HTTP does not checksum all output bytes.

Completed 8.0.14 updates require no published baseline changes. New readers reject
legacy indexes without generation and prepared formats 1/2, while retention can
keep their files. Fresh installations receive the new signed pair. Old classes
already loaded by an updater can defer optional preparation as above. PHP catalogs
remain release-managed; OPcache is independent of Memcached.

## Aktualizace a obnova

```powershell
php App/Console.php rescue
php storage/updates/console.php update /private/tinycat.core-update.json /private/tinycat.core-update.sig /private/tinycat.core-VERSION.zip
php storage/updates/console.php rollback BACKUP_ID
```

`rescue` připraví soukromý CLI launcher mimo aktualizované soubory. Použij jej
pro aktualizace a obnovu, zejména na Windows, kde běžící `App/Console.php`
nelze nahradit. Launcher není další updater: volá tentýž společný engine.
Před zveřejněním nové verze se ověří podpis, soubory, produkt, minimální
runtime a historie všech nainstalovaných modulů včetně neaktivních.
Jiný produkt, instalační artefakt nebo odstranění zdrojů nainstalovaného
modulu se odmítá. Aktivace uživatele se zachová; nový povinný modul se přidá.

Updater zálohuje měněné spravované soubory, stav aktivace a při čekajících
migracích konzistentní transakční snapshot MySQL/MariaDB
a index hranic SQL příkazů. Migrace se berou z ověřeného
podepsaného plánu, nikoli z již načtené staré PHP třídy poskytovatele.
MySQL snapshot předpokládá transakční tabulky a žádné souběžné ruční DDL;
externí zapisovače musí během údržby také stát.

Po přerušení nebo chybě zůstane maintenance a journal
`storage/updates/operation.json`. Další operace se odmítají, dokud se neobnoví
zaznamenaná záloha. `rollback` obnovuje pouze poslední operaci: před zápisem
kontroluje checksumy a identitu cílové DB, obnoví schéma/data, soubory i přesné
bajty stavu aktivace a odstraní nově zavedené soubory a tabulky. Lokální
konfiguraci, klíče, uploads ani média nepřepisuje. Po úspěchu vyčistí cache
a maintenance. Po úspěšném nasazení rollback vrací také DB na čas snapshotu;
pozdější zápisy proto vyžadují samostatné provozní rozhodnutí a zálohu.

Staré zálohy bez formátu 2 obnovuj
podle [ručního postupu](updates.md), ne novým CLI. Uchovávej celé záložní
adresáře včetně metadata a indexu příkazů, nikoli jen SQL soubor.

Ověření: `tests/release/module-lifecycle.php` staví skutečné podepsané sestavy
a provozuje je v dočasné instalaci; `tests/release/database-snapshots.php`
ověřuje MySQL/MariaDB v samostatné náhodné databázi.

## Samostatné moduly a aktualizace

```powershell
php App/Console.php module-install C:/packages/vendor-notes-1.0.0.zip
php App/Console.php activate vendor.notes
```

Stejným příkazem nebo tlačítkem nahraj novější verzi. Aktualizace zachová
aktivaci a data, pro dříve aktivovaný modul provede nové migrace i při jeho
současném vypnutí. ID, adresář, namespace a publikovaná migrační historie
zůstávají stejné. Neúspěch využívá společnou obnovu updateru.

`App/module-packages.json` vlastní lokální registr. Podepsaný descriptor
distribuce zůstává nedotčený. Aktualizace core zachová lokální moduly a odmítne
kolizi jejich souborů. Modul dodávaný distribucí se zatím aktualizuje spolu
s distribucí, nikoli samostatným ZIPem. Online katalog a odstranění dat při
odinstalaci nejsou součástí této etapy.

ZIP není podpis autora: instaluj pouze důvěryhodný PHP kód. Kontrola ověřuje
checksumy, verze, cesty, vlastnictví a závislosti; nenahrazuje kontrolu zdroje.
Nižší limity `upload_max_filesize`, `post_max_size` nebo webserveru platí i zde.

### Completing a fresh installation

The CLI installer and the final web installation step share
`Lifecycle::completeInstallation()`. It verifies the declared schema readiness
and writes the initial deployment with the product's explicit active/installed
module lists. The web wizard keeps staged table creation, account validation,
configuration writing and login; source checkouts without a distribution do not
invent a product descriptor. An existing deployment cannot be finalized again
as a fresh installation. Subsequent module operations use the normal lifecycle.

### Application release channels

The installed signed distribution retains both the product ID and artifact stem.
The updater uses that stored stem even when the module declaring the original
profile is inactive. A custom application therefore keeps its asset identity
without a hardcoded core product list. Configure its repository and public signing
key through `updates.repository` and `updates.public_key` when using a separate
channel. A shared release must contain a complete signed asset trio for every
supported product. See [product update channels](updates.md#product-update-channels).
