# Blogová sestava

Blog je samostatný funkční modul nad core. Vlastní úplné články, diskuse, reakce,
tagy, odkazy, média a importní zdroje. Nepotřebuje soubory ani tabulky Socialu.
Oba moduly mohou běžet zároveň, sdílet účty a používat stejné UI mechanismy;
jejich záznamy mají nezávislá ID a databázové vazby.

## Instalace a přidání modulu

Release builder vytváří podepsanou instalační i aktualizační sestavu pomocí
`--assembly=blog`. Rozbal ji mimo zdrojový repozitář, nastav vlastní `config.php`
a prázdnou databázi. `php App/Console.php install` čte chráněný JSON vstup
administrátora ze stdin; heslo nepatří do argumentů ani historie příkazů.
Postup je v [provozním návodu](../operations/module-deployment.md).

Do existujícího core lze Blog nahrát také jako samostatný ZIP z
`tools/release/build-module.php --module=App/Modules/Blog --version=1.0.0`.
Nahrání kód nespustí ani modul nezapne. Zapnutí explicitně připraví jeho schéma.
Blog přidává třináct tabulek; společně s osmi tabulkami core má instalace 21.
Přesný [inventář a převod starších dat](domain-storage.md) popisuje datový kontrakt.

Pro izolovaný vývoj vyber v pracovní kopii `App/modules.php`:

```php
<?php
declare(strict_types=1);
return require __DIR__ . '/Modules/Blog/selection.php';
```

`Blog/Installation::install($pdo)` je programový testovací adaptér nad stejným
schématem; odmítá neprázdnou DB a nevytváří účet. Produkční instalace používá
CLI lifecycle. Změna zdrojů neaktualizuje automaticky externí `tinycat.test`.
Nikdy nepoužívej jeho konfiguraci pro fresh-install test.

## Vlastnictví a chování

`blog_articles` obsahuje vlastní ID, autora, název, úplný text, náhled, stav,
datum publikace a čas vytvoření/úpravy. Článek není specializace společného
řádku `content`; taková povinná tabulka už neexistuje. Titulek má nejvýše 200
znaků, text 60 000 bajtů, náhled nejvýše 2 000 znaků. HTML se odstraní.
Zápis je vyhrazen aktivnímu administrátorovi. Veřejné dotazy vracejí pouze
publikované články aktivních autorů s dosaženým časem publikace.

`Articles` zapisuje článek, tagy, odkazy a obrazový vztah v jedné transakci.
Přílohy se připraví před transakcí; starý soubor se odstraní až po commitu.
`Discussion` zamyká vlastní článek, ověřuje komentáře a rodiče a zapisuje
reakce i limiter atomicky. Používá `blog_comments`, `blog_article_likes`
a `blog_comment_likes`, nikoli sociální repository. UI a formát historie
poskytuje core. Blog nespouští sociální notifikace nebo reputaci.

Sdílené kurzory v `Core/Database/Keyset` řadí podle času a ID. `SearchSource`,
`SitemapSection` a `EmbedSource` používají vlastní viditelnost a kanonické URL.
`/blog/feed` serializuje nejvýše dvacet veřejných článků přes `Core/Feeds/Rss`.
Odchozí odkaz lze otevřít jen z aktuálně autorizovaného článku a jeho příloh.

## Nastavení, soubory a import

Blog vlastní klíče `blog.images.enabled`, `blog.images.max_upload_kb`,
`blog.blocked_urls` a `blog.links.utm.*`. Nečte sociální nastavení za běhu.
Historická konverze zkopíruje původní hodnoty do vlastních klíčů, existující
nové hodnoty nepřepíše. Společný editor Settings ověřuje serverová oprávnění.

Rastr validuje a konvertuje `Core/Files/RasterStorage`, odkazy připravují
technické služby `Core/Links`. Blog vlastní SQL příloh a jejich oprávnění.
Staré fyzické URL pod `uploads/status-images` se zachovávají;
`ProvidesFileReferences` chrání média nainstalovaných aktivních i vypnutých
vlastníků před odstraněním nebo orphan cleanupem.

`blog_sources`, `blog_feed_history` a `blog_source_articles` vlastní Blog.
Společný `Core/Feeds/FeedPipeline` řídí průchod, Blog dodává `FeedSourceStore`
a `FeedTarget`, včetně přípravy obrázků, odkazů a plného textu. Náhled neclaimuje
GUID ani nemění plánování. Claim zdroje, publikace, GUID a mapování zdroje se
ověřují při zápisu; opakovaný či souběžný import nevytvoří druhý článek.
Ruční i plánovaný běh používá `Core/Tasks`, jeho zámek a historii.

## UI jako vzor dalšího modulu

Blog registruje stránky `/blog`, `/articles/{id}`, `/admin/articles`
a `/admin/article-sources`. Menu, oprávnění a příspěvky jsou v `src/Module.php`
a registračních definicích. `/` a `/admin` vybírá core z aktivních poskytovatelů.
Článek má vlastní veřejná metadata, oEmbed a překlady; Social se nemusí načíst.

Administrace používá stejné komponentové definice pro HTML i AJAX, společné
seznamy, hledání, filtry, stránkování a modaly načítané na vyžádání. Sdílený
editor, diskuse, obrázky a link cards jsou v `assets/ui/content/` s kompatibilními
feature ID `publishing.*`. Tyto názvy neznamenají doménovou službu Publishing.
Formulářový refresh nepřepisuje rozpracovaný koncept.

## Ověření a kompatibilita

`tests/domains/blog/module.php` fyzicky vynechává Social, ověřuje CRUD, HTTP,
oprávnění, assety, obrázky a diskuse. Potom v téže fixture přidá Social a ověří
stejná číselná ID, oddělené importy, browserové UI a nezávislé vypínání modulů.
`tests/domains/blog/mysql.php` ověřuje MySQL, závody, rollback a historický převod.

Schéma Blogu má verzi 4. Publikované migrace 1–3 zůstávají stejné. Nová migrace
přenese celý agregát, zachová ID a URL a porovná přenesené hodnoty před smazáním
starých řádků. Podepsaný `fresh` baseline vytvoří aktuální schéma přímo.
Formát descriptoru 3 vyžaduje kompatibilní runtime dev.9.1; datový přechod
na dev.10 patří standardnímu updateru se snapshotem a společným rollbackem.
