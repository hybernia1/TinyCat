# Feedy pro boty

SamostatnĂ˝ projekt vedle tinycat. PHP 8.4 + cURL, bez Composeru a databĂˇze.

## SpuĹˇtÄ›nĂ­

1. ZkopĂ­ruj `config.example.php` do `config.php`.
2. Do `tmdb_token` vloĹľ **API Read Access Token** z nastavenĂ­ TMDB. AlternativnÄ› vyplĹ `tmdb_api_key` (API klĂ­ÄŤ v3). NenĂ­ to uĹľivatelskĂ© jmĂ©no a heslo. PodporovanĂ© jsou takĂ© promÄ›nnĂ© prostĹ™edĂ­ `TMDB_TOKEN` a `TMDB_API_KEY`.
3. Pro lokĂˇlnĂ­ zkouĹˇku ve sloĹľce feeds spusĹĄ `php -S 127.0.0.1:8085 router.php`.
4. OtevĹ™i `http://127.0.0.1:8085/tmdb/upcoming?language=cs-CZ&genre=27`.

Na Apache nahraj sloĹľku jako `/feeds`, povol mod_rewrite a `.htaccess`. URL pak bude napĹ™Ă­klad:

`https://example.cz/feeds/tmdb/upcoming?language=cs-CZ&region=CZ&genre=27`

Tuto veĹ™ejnou URL vloĹľ do RSS zdroje bota v TinyCat. VzdĂˇlenĂ˝ bot potĹ™ebuje adresu dostupnou ze svĂ©ho serveru.

## Parametry

| Parametr | VĂ˝chozĂ­ | VĂ˝znam |
| --- | --- | --- |
| language | cs-CZ | Jazyk nĂˇzvu, popisku a traileru |
| region | CZ | ZemÄ› uvedenĂ­ do kin |
| genre nebo id | vĹˇechny | TMDB ID ĹľĂˇnru, napĹ™. 27 horor, 878 sci-fi, 35 komedie, 28 akÄŤnĂ­ |
| days | 90 | VĂ˝hled ode dneĹˇka, 1â€“365 dnĂ­ |
| page | 1 | StrĂˇnka TMDB, 1â€“500; nejvĂ˝Ĺˇe 20 filmĹŻ na strĂˇnce |

Parametry se spojujĂ­ `&`: `?language=cs-CZ&id=27`. VĂ­ce ĹľĂˇnrĹŻ: `genre=27,878` vyĹľaduje oba; `genre=27%7C878` znamenĂˇ kterĂ˝koliv z nich.

`/tmdb/genres?language=cs-CZ` vracĂ­ JSON s aktuĂˇlnĂ­mi ID a nĂˇzvy ĹľĂˇnrĹŻ.

RSS obsahuje nĂˇzev, dostupnĂ˝ popisek, datum premiĂ©ry, ĹľĂˇnry, plakĂˇt a YouTube trailer (pokud ho TMDB uvĂˇdĂ­). Trailer se hledĂˇ nejprve v poĹľadovanĂ©m jazyce, pak anglicky. Odkaz poloĹľky vede na trailer, pĹ™Ă­padnÄ› na TMDB. GUID je stabilnĂ­ podle ID filmu, aby bot pĹ™i kaĹľdĂ©m naÄŤtenĂ­ nevytvĂˇĹ™el stejnĂ© pĹ™Ă­spÄ›vky. LokalizovanĂ© popisky mohou na TMDB chybÄ›t.

Feedy pouĹľĂ­vajĂ­ Discover s ÄŤasovĂ˝m oknem a filtrem kin, coĹľ umoĹľĹuje filtrovat ĹľĂˇnry pĹ™Ă­mo v API. Jde o vlastnĂ­ vĂ˝hled premiĂ©r, nikoli pĹ™esnou kopii seznamu `/movie/upcoming`. ĹazenĂ­ podle popularity; dalĹˇĂ­ strĂˇnky lze pĹ™idat jako dalĹˇĂ­ RSS zdroje. RegionĂˇlnĂ­ data zĂˇvisejĂ­ na Ăşplnosti TMDB.

OdpovÄ›di API se uklĂˇdajĂ­ na 30 minut do `var/`; PHP potĹ™ebuje prĂˇvo tuto sloĹľku vytvoĹ™it a zapisovat do nĂ­. Chyba API vracĂ­ HTTP 502, chybnĂ˝ parametr 400, ne prĂˇzdnĂ© ĂşspÄ›ĹˇnĂ© RSS.

Apache pĹ™Ă­stup ke konfiguraci a cache blokuje. Pro jinĂ˝ webserver nastav obdobnĂˇ pravidla; nikdy nevystavuj soubory `config.php` a `var/` jako statickĂ© soubory. `config.php` a cache nepatĹ™Ă­ do Gitu.

Dokumentace: [Upcoming](https://developer.themoviedb.org/reference/movie-upcoming-list), [Discover](https://developer.themoviedb.org/reference/discover-movie), [Videos](https://developer.themoviedb.org/reference/movie-videos), [Authentication](https://developer.themoviedb.org/docs/authentication-application).

This product uses the TMDB API but is not endorsed or certified by TMDB.


## Jednotný standard pro další feedy

Používej RSS 2.0 v UTF-8 a sdílený `src/Feed.php` (`Feed::item`). Kořen RSS musí obsahovat `xmlns:media="http://search.yahoo.com/mrss/"`.

| Vstup Feed::item | RSS | Pole parseru / šablona bota |
| --- | --- | --- |
| id | guid, isPermaLink=false | guid; stabilní ID zdroje a položky |
| title | title | title / {{title}} |
| description | description, čistý text | description / {{description}} |
| url | link, absolutní HTTPS URL | url / {{url}} |
| image_url | media:content medium=image | image_url + image_urls / {{image}} |
| categories | opakované category | categories / {{categories}} nebo #{categories} |

Text se očistí od HTML, skriptů, stylů, řídicích znaků a HTML entit. XML se escapuje až při serializaci; například `&amp;` v samotném XML je správně, parser z něj získá znak `&`. Obrázky nejsou vložené do popisku. U TMDB hlavní odkaz vede na YouTube trailer, pokud existuje, jinak na detail TMDB. Premiéra zůstává v popisku; není vydávána za čas publikace RSS položky.

Pro přiložení plakátu přidej v nastavení zdroje do šablony samostatný řádek `{{image}}` (a měj povolené obrázky příspěvků):

```text
{{title}}

{{description}}

{{url}}

{{image}}

#{categories}
```

TinyCat popisek normalizuje do jednoho řádku a omezuje na 1200 znaků. Ostatní informace proto musí být v samostatných standardních polích, ne schované na konci dlouhého popisku. Vlastní libovolná pole se automaticky nestávají proměnnými šablony ani poli API.

## Moduly – přidání dalšího zdroje

```text
feeds/
  index.php                 společný router, konfigurace a HTTP odpovědi
  src/Feed.php              společné čištění textu a RSS serializace
  modules/
    tmdb/
      module.php            endpointy upcoming a genres
      Tmdb.php              TMDB klient s vlastní cache var/tmdb
  examples/module.php       neaktivní kopírovatelný vzor
  config.php                lokální přístupové údaje všech modulů
```

1. Vytvoř `modules/nazev-zdroje/` a zkopíruj do ní `examples/module.php`.
2. Uprav endpoint `latest`, načítání dat a mapování položek. Adresa automaticky vznikne jako `/feeds/nazev-zdroje/latest`.
3. Přístupové údaje přidej do `config.php`, například do `$config['nazev_zdroje']['token']`. Existující TMDB konfigurace se nemění.

Není potřeba měnit router ani `.htaccess`. Registrují se pouze lokální soubory `modules/*/module.php`. Modul vrací pole endpointů s funkcemi přijímajícími konfiguraci. Funkce vrací `['type' => 'rss', 'data' => [...]]` podle vzoru; pomocný JSON endpoint může vrátit `['type' => 'json', 'data' => [...]]`. Modul nepíše `echo`, HTTP hlavičky ani `exit`; obsluhu odpovědi řeší router. Parametry lze číst přes `parameter('language', 'cs-CZ')`, jejich význam a rozsah validuje modul. Neplatné hodnoty oznam `InvalidArgumentException`, chybu zdroje `RuntimeException`.

Seznam dostupných adres najdeš na `/feeds/`. Soubory modulů, příkladů, cache a společného kódu jsou přes HTTP blokované. Celá složka feeds zůstává ignorovaná Gitem.

## Volitelný přístupový token

V `config.php` nastav `'access_token' => 'tvuj-dlouhy-nahodny-token'`. Prázdný řetězec ponechá veřejný přístup. Pokud je token vyplněný, všechny endpointy včetně seznamu zdrojů a JSON vyžadují URL parametr `token`; chybný nebo chybějící token vrací HTTP 403 ještě před načtením modulů a voláním API.

Příklad URL pro bota:

`https://example.cz/feeds/tmdb/upcoming?language=cs-CZ&genre=27&token=tvuj-dlouhy-nahodny-token`

Token s případnými speciálními znaky URL-enkóduj. Používej HTTPS; token v URL může být uložen v historii nebo přístupových logách. Chráněné odpovědi používají `Cache-Control: private, no-store`.

## IGDB – chystané hry

Do `config.php` doplň `igdb_client_id` a `igdb_client_secret` z aplikace typu Confidential na https://dev.twitch.tv/console/apps . Podporované jsou i proměnné prostředí `IGDB_CLIENT_ID` a `IGDB_CLIENT_SECRET`. OAuth app token získává klient automaticky, ukládá s expirací do neveřejné `var/igdb/` a obnovuje po expiraci nebo HTTP 401. Přístupy se neposílají v URL feedu; parametr `token` je pouze společná ochrana feedů.

- RSS: `/feeds/igdb/upcoming?language=en-US&genre=5&platform=6&days=90&page=1&token=TVUJ_TOKEN`
- Žánry: `/feeds/igdb/genres?token=TVUJ_TOKEN`
- Platformy: `/feeds/igdb/platforms?token=TVUJ_TOKEN`

`genre` (nebo `id`) a `platform` jsou volitelné číselné IGDB identifikátory, které se liší od TMDB. Více ID oddělených čárkou znamená kterýkoliv z nich. `days` má rozsah 1–365, výchozí 90; `page` 1–500, po 20 hrách. Pomocný seznam platforem vrací nejvýše 500 záznamů.

Výběr používá **první vydání hry** (`first_release_date`) v UTC a řadí od nejbližšího data. Nezahrnuje nové porty už vydaných her podle pozdějšího data vydání na jiné platformě. Neznámé termíny bez data se nezobrazí; datum může být předběžné. IGDB popisky a žánry se nepřekládají: modul přijímá anglické `language=en-US`, `en-GB` nebo `en` (výchozí en-US). České zadání odmítne, aby feed neoznačoval anglický obsah jako český.

Položka obsahuje název, dostupný souhrn, první vydání, žánry, platformy a obal. Hlavní odkaz vede na dostupné YouTube video (přednost má název obsahující Trailer), jinak na IGDB. Stejný RSS standard i šablony jako u TMDB; pro obal použij `{{image}}`. Cache dat používá společné `cache_seconds` (výchozí 1800 sekund).

Dokumentace: https://api-docs.igdb.com/ a https://dev.twitch.tv/docs/authentication/getting-tokens-oauth/#client-credentials-grant-flow .

IGDB navíc podporuje `theme` (číselná ID oddělená čárkou, kterýkoliv z nich). Horror = 19, Science fiction = 18, Survival = 21. Seznam: `/feeds/igdb/themes?token=TVUJ_TOKEN`. Například `upcoming?theme=19,18,21&platform=6,48,167,49,169` vybírá kterékoliv z těchto témat pro PC, PS4, PS5, Xbox One nebo Xbox Series X|S. Parametr `genre` může zůstat vynechaný; při současném zadání musí hra splňovat filtr tématu i žánru.

## ČSFD – filmové novinky

RSS: `/feeds/csfd/news?token=TVUJ_TOKEN`.

Kino premiéry: `/feeds/csfd/premieres?token=TVUJ_TOKEN`.
Vrací filmy z aktuálního měsíce na https://www.csfd.cz/kino/premiery/ v pořadí zdroje. Každá položka obsahuje název, odkaz na film, dostupný plakát a v popisu datum premiéry, žánry, tvůrce a distributora podle dostupnosti. Kategorie je Kino premiéry; stabilní ID `csfd:premieres:123:2026-11-05` rozlišuje film i datum uvedení. Datum premiéry není vydáváno za čas publikace RSS. Používá společný token a `cache_seconds`, oddělenou cache `var/csfd/premieres-YYYY-MM.json` podle měsíce v Europe/Prague. Prázdná nebo nerozpoznaná stránka vrací 502 stejně jako blokování zdroje. Regresní kontrola: `php tests/bots/csfd-premieres.php`.

Modul `modules/csfd/` načítá veřejný seznam https://www.csfd.cz/novinky/ a vrací nejvýše 20 krátkých anotací, názvy, původní odkazy na články, dostupné obrázky a autory. Plné články nestahuje. Kategorie je Filmové novinky; stabilní ID má tvar `csfd:news:123`. Relativní datum typu „včera“ nepřevádí na vymyšlený čas publikace. Pro obrázek přidej `{{image}}`, pro dostupného autora `{{author}}` do šablony bota.

Platí společný přístupový token a `cache_seconds` (výchozí 30 minut). Cache: `var/csfd/news.json`. Parser používá DOM, odstraňuje odkaz „více“ a text prochází společným čištěním. HTTP chyba, blokovací stránka nebo změna HTML bez rozpoznaných položek vrátí chybu 502 místo úspěšného prázdného feedu. Modul ochrany webu neobchází; dostupnost může záviset na IP serveru. Při změně struktury ČSFD bude potřeba aktualizovat parser.
