# YouTube náhledy

YouTube video karty používají přímé URL na `i.ytimg.com`. Pro viewport do
759 CSS pixelů se přes `<picture><source media>` načítá `default.jpg`, pro
větší viewport `mqdefault.jpg`. Malá varianta má běžně 120 × 90 px a střední
320 × 180 px; viz [velikosti náhledů YouTube](https://developers.google.com/youtube/data/reference/rest/v3/thumbnails).
Výběr je podle viewportu, nikoli hustoty pixelů. Mobil s vyšším DPI proto
automaticky nestahuje střední nebo velkou variantu.

URL odvozuje `Status/Providers::youtubeThumbnails()` z ověřené adresy videa,
včetně krátkých odkazů, Shorts a live videí. Nepřebírá libovolný hostitel nebo
ID z metadat. Stejný resource slouží PHP vykreslení, JSON/AJAX i exportovanému
přehrávači. oEmbed používá střední náhled.

`links.image_url` u těchto videí zůstává prázdné. Příprava i ukládání metadat
odmítají uchování lokálního obrázku; YouTube nevstupuje do fronty ke stahování
obrázků. Import navíc nestahuje thumbnail jako obrázkovou přílohu, ani když má
zdroj v šabloně `{{image}}`. Názvy, popisy a přehrávání se nemění. Ostatní
poskytovatelé a vlastní obrázkové přílohy nadále používají své dosavadní cesty.

## Oprava existujících záznamů

Pouze CLI:

```sh
php tools/youtube-thumbnail-cleanup.php
php tools/youtube-thumbnail-cleanup.php --apply --backup=/private/path/new-backup.json
```

Výchozí režim pouze čte. Zápis zálohuje původní hodnoty, zamyká a kontroluje
řádky proti souběžné úpravě a transakčně vyprázdní jen `image_url` rozpoznaných
YouTube videí. Nemaže odkazy, příspěvky ani jejich vazby. Opakování už neplánuje
žádné změny. Záloha obsahuje původní ID, URL a cestu obrázku.

Vrácené `paths` jsou kandidáti pro samostatnou kontrolu souborů. Před jejich
mazáním je nutné zkontrolovat zbývající reference a přesnou cestu
`/uploads/links/YYYY/MM/<40 hex>.webp`. Sdílené obrázky se nesmějí smazat.
Při vzdáleném nasazení nejdřív nasadit runtime, sdílené komponenty a sestavené
assety s manifestem, potom opravit databázi a nakonec odstranit ověřené soubory.

Na apokalypsa.net dne 2026-11-01 vyčištěno 73 hodnot `image_url` a po ověření
referencí odstraněno 66 souborů (980 266 bajtů). Zálohy databázových hodnot,
souborů a původního kódu jsou lokálně v chráněném `storage/backups/`, spolu
s výsledkem kontroly. Veřejný detail i vložený přehrávač byly ověřeny přes HTTP.

Regrese: `php tests/link-parser.php`, `php tests/tools/youtube-thumbnails.php`,
`php tests/bots/run.php`, `php tests/presentation/view-templates.php`,
`php tests/http/api-contract.php` a `node tests/frontend/youtube-thumbnails.cjs`.
