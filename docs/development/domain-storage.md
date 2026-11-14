# Doménová data a čisté core

Core poskytuje identity, bezpečnost a technické mechanismy. Nezakládá univerzální
publikace, komentáře, tagy ani média. CRM nemusí instalovat žádnou z těchto funkcí.
Blog a Social vlastní celé agregáty včetně tabulek, pravidel a HTTP rozhraní.

## Čerstvé schéma

Samotné core má osm tabulek:

```text
users, password_reset_tokens, ip_action_limits, settings,
notifications, scheduler_runs, module_installations, module_migrations
```

Social přidává šestnáct tabulek:

```text
social_posts, social_post_redirects, social_post_images,
social_terms, social_post_tags, social_links, social_post_links,
social_comments, social_post_likes, social_comment_likes,
user_followers, content_reports,
bot_sources, bot_feed_history, bot_source_runs, bot_source_posts
```

Blog přidává třináct tabulek:

```text
blog_articles, blog_article_redirects, blog_article_images,
blog_terms, blog_article_tags, blog_links, blog_article_links,
blog_comments, blog_article_likes, blog_comment_likes,
blog_sources, blog_feed_history, blog_source_articles
```

Core s Blogem má 21 tabulek, se Socialem 24 a s oběma moduly 37. Historická
instalace může navíc zachovat globální `schema_migrations` a nepoužívané staré
tabulky; nejde o požadavky čerstvého core ani souhlas k odstranění jejich dat.

`blog_articles` obsahuje celého autora, název, text, náhled, stav a časové údaje.
Neobsahuje `content_id` ani `kind`. `blog_source_articles.article_id` i ostatní
článkové vztahy odkazují přímo na článek. Social zachovává místní sloupec
`content_id` a historické `kind = post`, ale jeho FK odkazují na `social_posts`.
Článek a status, stejně jako jejich komentáře, mohou mít totožná číselná ID.
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
Social vlastní jejich význam, hydrataci i úklid. Blog diskuse jeho události
nevyvolává. Smazání účtu zapojuje i nainstalované vypnuté vlastníky.

## Převod historické instalace

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

Runtime dev.9 nový descriptor nepřijímá. Přechod vyžaduje nejprve kompatibilní
runtime dev.9.1 a potom dev.10; před datovou konverzí updater pořizuje snapshot.
Rollback obnovuje schéma, data, soubory i aktivaci společně. Nepřepisuj descriptor
nebo schéma ručně na živé instalaci. Stav konkrétního nasazení a výsledky kontrol
patří do auditu etapy, nikoli do předpokladu, že změna zdrojů aktualizovala web.
