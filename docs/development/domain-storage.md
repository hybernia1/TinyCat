# Doménová data a čisté core

Core poskytuje identity, bezpečnost a technické mechanismy. Nezakládá univerzální
publikace, komentáře, tagy ani média. CRM nemusí instalovat žádnou z těchto funkcí.
Funkční moduly vlastní celé agregáty včetně tabulek, pravidel a HTTP rozhraní.

## Čerstvé schéma

Samotné core má osm tabulek:

```text
users, password_reset_tokens, ip_action_limits, settings,
notifications, scheduler_runs, module_installations, migrations
```

Social přidává šestnáct tabulek:

```text
social_posts, social_post_redirects, social_post_images,
social_terms, social_post_tags, social_links, social_post_links,
social_comments, social_post_likes, social_comment_likes,
user_followers, content_reports,
bot_sources, bot_feed_history, bot_source_runs, bot_source_posts
```

Core se Socialem má 24 tabulek. Blog byl z aktuálních zdrojů odstraněn.
Společný journal `migrations` rozlišuje `kind = global` a `kind = schema`;
`stream` zachovává původní ID migračního streamu. Globální verze vydání je
v `release_version`, číselné pořadí schématu v `sequence`. Při ověřeném převodu
z v6.0.4 nahrazuje `schema_migrations` a `module_migrations` bez opětovného
spuštění jejich migrací. Staré modulové záznamy mají neznámý `applied_at`;
nové zápisy obsahují skutečný čas. Stav schémat zůstává v `module_installations`.
Jiné historické tabulky nejsou automaticky určené k odstranění.

Social zachovává místní sloupec
`content_id` a historické `kind = post`, ale jeho FK odkazují na `social_posts`.
Entity různých vlastníků mohou mít totožná číselná ID.
Identitu určuje vlastník a konkrétní endpoint, nikoli globální číselný prostor.

## Sdílené mechanismy

| Core | Co vlastní modul |
| --- | --- |
| `Core/Text`: parser, hashtagové tokeny, revize, segmenty a pojmenované `TextPolicy` | Limity textu, případné zmínky, SQL tagů a komentářů |
| `Core/Links`: metadata, prezentace, UTM a autorizované odchozí odkazy | Připojené odkazy, jejich SQL cache a pravidla URL |
| `Core/Files`: validace a konverze rastru, cache náhledů | Přílohy, oprávnění, kvóty a databázové reference |
| `Core/Feeds`: parser a průchod importem | Zdroj, claim, GUID historie, příprava příloh a vlastní zápis |
| `Core/Ui`: editor, diskuse, resource formátování a AJAX | Autorizovaný cíl, načtená data, akce a transportní URL |
| `Core/Database`: transakce a stabilní kurzor | Dotazy, řádkové zámky a cizí klíče vlastní funkce |
| `Core/Discovery`: hledání, sitemap a oEmbed | Viditelnost a kanonická URL svého výsledku |

Sdílené soubory v `assets/ui/content/` a historická ID komponent
`publishing.*` zajišťují kompatibilní UI. Neznamenají službu ani modul Publishing.
Nový modul nedeklaruje závislost `tinycat.publishing`.

Zápis agregátu a jeho vztahů je atomický. Přílohy se připraví před transakcí,
starý soubor se maže až po commitu. `ProvidesFileReferences` chrání soubor
používaný jiným nainstalovaným vlastníkem, včetně vypnutého modulu. Zachované
cesty `/uploads/status-images/...` jsou kompatibilita existujících médií;
nevytvářej kvůli přejmenování URL jejich zbytečné kopie.

Core Notifications nečte žádnou publikační tabulku. Historická pole
`content_id` a `comment_id` jsou neprůhledná data producenta bez doménových FK.
Social vlastní jejich význam, hydrataci i úklid. Smazání účtu zapojuje
i nainstalované vypnuté vlastníky.

## Archivace migrační historie

Současný zdrojový baseline vyžaduje dokončenou 6.0.10. Neměnný release 6.0.8
zavedl čtení archivní historie a ponechal publikované soubory pro updater 6.0.7.
Navazující zdroje archivují všech 24 dosavadních vestavěných migrací ve formátu 4;
každý vlastník má samostatný aktuální fresh snapshot. Původní migrační PHP,
vedlejší historické DDL a datové převodníky již nejsou v runtime ani balíčku.
Další změna schématu přidá novou spustitelnou migraci za archivní prefix.

`SchemaMigration` rozlišuje spustitelný soubor a archivní záznam s původním
checksumem. Archiv tvoří souvislý prefix od verze 1; za ním zůstávají pouze
spustitelné změny. Prefix určuje minimální podporované **schéma vlastníka**,
nezávisle na verzi core nebo zapnutí modulu. Schéma s archivním prefixem musí
mít samostatnou spustitelnou aktuální `fresh` baseline a nesmí používat staré
přebírání nepřiřazených tabulek. Nová instalace nadále zaznamená pokrytí
původní historie fresh schématem; nepředstírá spuštění chybějících souborů.

Podepsaný descriptor formátu 4 používá pro archivní položku explicitní
`file: null` spolu s původním `version`, `id` a `checksum`. Archivní soubor
není ve file inventory a nikdy se nespouští. Instalovaná historie se porovná
s těmito metadaty; původní záznamy a časy se nepřepisují. Formát 3 archivní
položky odmítá. Zbývající PHP a fresh baseline stále podléhají kontrolám
checksumů a vlastnictví. ZIP nezávislého modulu používá pro stejný kontrakt
formát 2 a vyžaduje minimálně checkpoint-aware runtime 6.0.8.

V prvním vydání se publikuje nový čtenář s původními soubory ve formátu 3.
Následující vydání smí odstranit PHP pouze pro ověřený prefix, zvýší minimum
na checkpoint-aware runtime a použije formát 4. Původní podepsané releasy
zůstávají neměnné. Podporované mezikroky jsou výslovné; chybějící převod se
nepřeskočí ani nepřevezme pouze podle existence tabulek. Stejné pravidlo
platí pro nainstalované vypnuté vlastníky a nezávislé modulové ZIPy.

Rollback nadále obnovuje standardní snapshot databáze, zdrojů a aktivace,
včetně PHP souborů odstraněných při archivaci. Nevyžaduje jejich opětovné
spuštění ani změnu původních checksumů.

## Převod historické instalace

Následující popis zachycuje historický převod v neměnných vydáních. Současné
zdroje tyto datové adaptéry ani Blog neobsahují; nepřidávej je zpět kvůli novému
modulu. Pro podporovaný upgrade platí dokončená 6.0.10 a aktuální provozní návod.

Descriptor formátu 3 zachovává seznam povinného frameworku bez Publishingu.
Schéma vlastníka deklaruje neměnnou historii, případně podepsaný `fresh` soubor
aktuálního DDL a explicitní `legacy` konverzi. Registrace tyto callbacky nevolá.
Fresh instalace nepřehrává staré DDL vyžadující zrušené společné tabulky;
zaznamená však jejich původní ID a checksumy. Nové změny nadále přidávají migrace.

Převod nejprve připraví core, včetně odstranění starých FK notifikací. Blog
zkopíruje články a všechny jejich vazby a porovná hodnoty před odstraněním
původních řádků. Zachová ID, URL, rodiče komentářů, revize, média i zdroje.
Social potom přejmenuje zbývající vlastní tabulky na `social_*`. Původní ID
streamů `tinycat.publishing` a `tinycat.status` zůstávají historií Socialu,
nepředstavují aktivovatelné moduly. Publikované migrační soubory zůstávají
bajtově stejné, i když jejich cestu nyní vlastní Social.

Blog-only převod může odstranit pouze prázdné staré společné úložiště. Jeho
historie označí jako `retired`, nemaže ji; později přidaný Social vytvoří vlastní
prázdné tabulky. Neznámé vazby, jiný druh obsahu či nedoložená původní instalace
konverzi zastaví. Historické globální převzetí vyžaduje ověřený checkpoint.

Historický převod používal bridge dev.9.1 a checkpoint dev.9.2. Tehdejší balíčky
podporovaly dokončenou instalaci TinyCat 6.0.4 a novější; současnou podporu
určuje výše uvedený baseline a provozní návod. Publikované migrace a jejich checksumy
zůstávají neměnné.

Rollback obnovuje schéma, data, soubory i aktivaci společně. Nepřepisuj descriptor
nebo schéma ručně na živé instalaci. Stav konkrétního nasazení a výsledky kontrol
patří do auditu etapy, nikoli do předpokladu, že změna zdrojů aktualizovala web.
