# Instalace a správa modulové sestavy

Runtime `6.0.0` odděluje povinný framework od funkčních
modulů. Social je jeden modul; Blog je druhý
a mohou běžet společně. Core není součástí aktivačního seznamu.

Formát distribuce 2 vyžaduje u dřívějších modulových instalací přechodový
runtime `6.0.0-dev.5.1`, který umí převést jejich uložená ID. Platformní
a samostatné Blog balíčky tento minimum deklarují. Klasická sociální 5.16.0
bez modulového deploymentu může použít sociální aktualizaci se zachovanou
globální historií. Aktualizace nesmí měnit identitu produktu.

## Správa v administraci

Přihlášený administrátor otevře **Moduly** v navigaci nebo `/admin/modules`.
Stránka ukazuje dostupné moduly, jejich stav, povinné závislosti a aktivní
moduly, které je potřebují. Zapnutí doplní potřebné závislosti; vypnutí
zachová data a odmítne rozbití zbývající sestavy. Social má jediný přepínač pro celou svoji funkci. Core se v seznamu nenabízí. Změna vyžaduje potvrzení, CSRF a aktuální revizi výběru. Po úspěchu
se načte celá stránka s novými routami, navigací, překlady a assety.

Správce je součástí core UI, takže zůstává dostupný i bez Socialu a Blogu; Settings a Updates jsou povinnou součástí frameworku. Používá stejný lifecycle, zámek, zálohu a kontroly jako
CLI. Zpřístupňuje moduly distribuce i samostatné ZIPy. Tlačítko **Nahrát modul**
přijímá důvěryhodný PHP balíček do 32 MiB; vyžaduje potvrzení, oprávnění
administrátora a CSRF. První nahrání zachová modul vypnutý a neprovádí jeho SQL
ani PHP inicializaci. Zapnutí je samostatná operace. Podrobnosti sestavení
ZIPu jsou v [manuálu autora](../development/module-authoring.md#8-samostatný-instalační-zip). V surovém checkoutu bez descriptoru
je přehled pouze pro čtení. CLI níže zůstává dostupné i pro obnovu.

## Sestavení

Z kořene zdrojů s připraveným podpisovým klíčem:

```powershell
php tools/release/build-update.php --assembly=platform --version=6.0.0 --both --key=C:/private/signing.key --output=storage/releases/platform
```

Výchozí `--assembly=source` používá explicitní `App/modules.php`.
`--assembly=core` vytvoří framework bez volitelných modulů.
`--assembly=platform` používá `App/available-modules.php`: stejný sociální
výběr doplněný Blogem. `--assembly=blog` je preset Blogu nad stejným povinným frameworkem. Presety jsou seznamy manifestů, nikoli další runtime.
`--product=vendor.product` určuje identitu vlastní sestavy a `--active=id1,id2`
její výchozí aktivní moduly; bez této volby jsou aktivní všechny dostupné.
Funkční moduly jsou volitelné; jejich funkční závislosti musí být aktivní. Core je vždy přítomný. Manifestový modul musí ležet
pod `App/Modules/` s vlastními `src/`, zdroji a neměnnou historií migrací.
Builder validuje poskytovatele a tabulky, přibalí dostupné vlastníky a sestaví
assety a překlady pouze aktivního výběru. Produkční balíček nepotřebuje tools,
Composer ani Node. Blog artefakty mají prefix `tinycat-blog`, sociální `tinycat`.

Podepsaný manifest obsahuje druh artefaktu (`install`/`update`), identitu
produktu, dostupné a aktivní moduly, povinný framework, vlastnictví schémat, závislosti a migrační
plány s checksumy. Stejný popis je uvnitř balíčku v `App/distribution.json`.
Lokální aktivaci vlastní chráněný `storage/deployment.json`; nepřepisuj ji ručně.

## Fresh instalace

Rozbal instalační artefakt do samostatné instalace a připrav její `config.php`
s připojením k **prázdné** databázi. Blog čte například `i18n.locale`, `blog.title`,
`cache` a `datetime` přímo z konfigurace. Příkazy spouštěj z kořene instalace:

```powershell
php App/Console.php status
php App/Console.php install
```

`install` čte jeden JSON objekt ze standardního vstupu: `username`, `password`,
`password_confirm`, `locale`. Předej jej správcem tajných údajů nebo chráněným
vstupem; heslo nedávej do argumentů příkazu, shellové historie ani repozitáře.
V interaktivním terminálu příkaz vyžádá přesměrovaný vstup. Validace i zápis
hesla používají Accounts. Opakovaná instalace nebo neprázdná DB se odmítá.
Sestava bez Accounts administrátora nevytváří.

Čisté core vytváří 8 tabulek. Blog přidává 13 vlastních tabulek, celkem 21;
Social přidává 16, s oběma moduly je jich 37. Core nemá povinný obsah, diskuse
ani média. [Inventář a datové vlastnictví](../development/domain-storage.md).
Bootstrap nikdy neprovádí DDL.
Webserver musí používat kořenový `.htaccess` nebo ekvivalentní pravidla:
`App/`, konfigurace a `storage/` nejsou veřejné soubory; cache assetů se
zpřístupňuje jen přes úzké pravidlo `/cache/assets/`.

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

### Blog a Social společně nebo odděleně

Z kořene **nasazené instalace**, pro tinycat.test tedy `E:/laragon/www/tinycat`:

```powershell
php App/Console.php status
php App/Console.php activate tinycat.blog
php App/Console.php deactivate tinycat.social
php App/Console.php activate tinycat.social
php App/Console.php deactivate tinycat.blog
```

`tinycat.social` je jeden modul s vlastní inicializací a vnitřními částmi
pro profily, statusy, moderaci, hledání, automatizaci a veřejné stránky.
Vypnutí zachová celý framework včetně identity, nastavení,
scheduleru, diagnostiky, obecné schránky a doručování pošty.
Nesmaže statusy, vztahy ani články. Blog lze vypnout nezávisle.

Blog má archiv `/blog`, detail `/articles/{id}` a administraci
`/admin/articles`. Social má `/social` a dosavadní `/status/{id}`.
Kořen `/` vybírá core z příspěvků `homepages`: nastavení `site.homepage`
volí například `tinycat.blog.home` nebo `tinycat.status.home`. Prázdná hodnota
vybírá první aktivní nabídku; po vypnutí zvolené nabídky se použije aktivní
fallback. `site.theme` obdobně vybírá vzhled; bez aktivního tématu je společný
core rám. Obě volby jsou v editoru nastavení webu. Nastavení, jeho klientské
popisky a e-mailové šablony přispívají pouze aktivní vlastníci.

Starší vývojová instalace `6.0.0-dev.1` odmítá nové druhy manifestových zdrojů
`emails` a `componentExtensions`. Před platformním balíčkem potřebuje podepsaný
kompatibilní mezikrok `6.0.0-dev.1.1`, který aktualizuje pouze podporu metadat
a číslo verze nad jejími stávajícími zdroji. Neobcházej validátor ručním
přepisem živého PHP. Oba kroky používají normální updater a vlastní zálohy.

Předkompilované výstupy obsahují otisk výběru. Při změně aktivace runtime
odmítne výstupy jiné sestavy a použije společný assetový a překladový compiler
nad dostupnými zdroji. Cache musí být zapisovatelná. Úlohy drží sdílený zámek,
nasazení výhradní; souběžný konflikt se odmítá a může se později zopakovat.

## Aktualizace a obnova

Datový model dev.10 používá descriptor formátu 3 bez povinného Publishingu.
Starý runtime dev.9 jej neumí ověřit: nejprve použij kompatibilní sestavu
dev.9.1, teprve potom datovou aktualizaci dev.10. Každý krok má vlastní snapshot.
Neměň framework ani tabulky ručně. Blog i nainstalovaný vypnutý Social převádí
standardní updater; ID, URL, média a historie zůstávají zachované.
Podrobnosti `fresh`, `legacy` a `retired` jsou v
[datovém kontraktu](../development/domain-storage.md).


```powershell
php App/Console.php rescue
php storage/updates/console.php update /private/tinycat-blog-update.json /private/tinycat-blog-update.sig /private/tinycat-blog-VERSION.zip
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
migracích konzistentní snapshot DB. SQLite používá `VACUUM INTO`, MySQL
transakční snapshot a index hranic SQL příkazů. Migrace se berou z ověřeného
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

SQLite virtuální tabulky vyžadují offline obnovu souboru; automatická obnova
je odmítá před změnou živého schématu. Staré zálohy bez formátu 2 obnovuj
podle [ručního postupu](updates.md), ne novým CLI. Uchovávej celé záložní
adresáře včetně metadata a indexu příkazů, nikoli jen SQL soubor.

Ověření: `tests/release/module-lifecycle.php` staví skutečné podepsané sestavy
a provozuje je v dočasné instalaci; `tests/release/database-snapshots.php`
ověřuje SQLite a dostupné MySQL v samostatné náhodné databázi.

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
