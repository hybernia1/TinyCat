# Sloučení parsování a textu — 26. 11. 2026

Etapa mění 9 existujících runtime souborů, 5 existujících testovacích souborů
a 2 existující dokumenty; tento audit je jediný nový soubor. Počet dotčených
runtime souborů před/po je **9 → 9**. Nevznikla nová runtime třída ani fasáda.
Text již existoval jako necommitnutý výsledek předchozího přesunu ze Support;
tento přesun není odstraněním souboru ani výsledkem této etapy.

## Potvrzené nálezy a provedené změny

- [Text](../../App/Core/Text/Text.php) sdílí převod HTML pro parser feedů,
  metadata, excerpt a botí anotace. Bloky odděluje mezerou, zachovává `<3`,
  sjednocuje whitespace a zkracuje podle Unicode znaků. Délku a příponu
  excerptu nadále vlastní jeho dosavadní kontrakt.
- Rozdíl mezi zakódovanými značkami a escapovaným doslovným textem je skutečný
  kontrakt vstupu. `htmlText()` standardně dekóduje před odstraněním značek;
  metadata výslovně používají `encodedMarkup: false`, tedy dekódování po něm.
  Regrese X provideru prokázala, že mechanické sjednocení pořadí by odstranilo
  legitimní doslovné `<b>bez HTML</b>`. Dekódování není rekurzivní.
- [Metadata](../../App/Core/Links/Metadata.php) vytvoří dokument jednou a předá
  jej protected `parseDocument()`; [Social rozšíření](../../App/Modules/Social/src/Posts/LinkMetadata.php)
  použije tentýž DOM pro provider discovery. Priorita metadat, bezpečnost HTTP,
  redirect/SSRF kontroly a oEmbed fallback zůstávají zachované. Jediný DOM je
  ověřen kontrolou toku kódu, nikoli testem počítajícím interní volání.
- [PostCreation](../../App/Modules/Social/src/Posts/PostCreation.php) a
  [InlinePresentation](../../App/Modules/Social/src/Posts/InlinePresentation.php)
  používají existující `InlineText::map()` místo vlastních offsetových smyček.
  Regrese chrání URL včetně neviditelného znaku, query, fragmentu a interpunkce.
- [FeedParser](../../App/Core/Feeds/FeedParser.php) sdílí validaci kandidátů
  mezi XML obrázky a HTML obrázky. Opakované neprázdné HTML vstupy deduplikuje
  před DOM parsováním; pořadí a limit příloh zachovává. JSON URL jsou datové
  řetězce a nepodléhají dekódování HTML entit, což má samostatnou regresi.
- XML audio enclosure nyní vlastní FeedParser a respektuje také `xml:base`
  konkrétního enclosure. [Audio](../../App/Core/Links/Audio.php) ponechává
  `direct/isDirect/remote`; veřejné `enclosure()` zůstává kompatibilním delegátem.
  Jde o přesun extrakce, nikoli odstranění veřejného API.
- [Importer](../../App/Modules/Social/src/Bots/Importer.php) formátuje chyby
  přes Text, bez závislosti této operace na feedovém parseru. Veřejné
  `FeedParser::text()` zůstává dostupné.

## Vlastnictví a zápis těla

Ukládání raw HTML jako těla příspěvku **nebylo prokázáno**. Prověřený tok je:

1. FeedParser vrací strukturovaná pole a dočasné `description_html/content_html`.
2. [FeedPublication](../../App/Modules/Social/src/Bots/FeedPublication.php)
   vybírá anotaci a provádí importní redakční pravidla, odstranění běžných URL
   a zachování podporovaných videí. Tyto politiky zůstaly výhradně v Bots.
3. `prepare()` vytvoří finální `PostCreation` přes stejný vstup jako ruční text;
   objekt má privátní konstruktor. Náhled používá tuto čistou přípravu.
4. `publish()` volá `PostCreation::create()`. SQL do `social_posts.body` dostane
   pouze `$draft->body`; odkazy a média mají oddělené zápisy.
5. [FeedStore](../../App/Modules/Social/src/Bots/FeedStore.php) obaluje claim GUID,
   publikaci, mapování zdroje a jeho stav společnou transakcí. Preview tento
   zápisový tok nevolá.

[Zápisová regrese](../../tests/domains/status/write-path-integrity.php) nově
připravuje skutečný úspěšný botí zápis z HTML odstavců a ověřuje přesné uložené
prosté tělo s kanonickou zmínkou/tagem. Existující scénáře současně ověřují
rollback při chybě odkazů/historie, opakování a duplicitní claim. Testy ručního
textu chrání entity jako doslovný text, porovnávací znaky a `<3`. Nebyl zaveden
zákaz všech znaků `<` ani automatické čištění historických uživatelských dat.

Zápisový test a jeho zahrnutá image fixture měly nedokončený převod ze starého
reflexního čtení konfigurace. Byly opraveny na veřejné `snapshot()/configure()`;
runtime konfigurace, data a schéma se nemění.

## Výslovně neslučované oblasti

- RSS HTML renderer zůstává v Ui: escapuje prózu, vytváří odkazy a používá
  `splitTail()`. Samotná podobnost krátké smyčky neodůvodňuje nový token stream.
- [Embed karta](../../App/Modules/Social/src/Posts/Embed.php) skutečně renderuje
  `status/card` a poté jej přes DOM/XPath upravuje. Je kandidátem na samostatný
  funkční krok s explicitní prezentační variantou a testy exportu; v této etapě
  se nemění. Oprava root-relative `frame_url` ani dialog-controller nebyla dotčena.
- Sanitizer bezpečného HTML, síťová URL bezpečnost, lexikální URL parser,
  Social tracking/provider pravidla a samostatná aplikace `feeds/` zůstávají
  oddělené. Core nepřevzal botí publikační politiku ani doménové SQL.
- Migrace, checksumy, updater snapshoty, překlady, assety a nastavení živého
  webu nebyly touto etapou změněny.

## Ověření

Jde o cílené ověření, nikoli plný preflight. Celá sada a preflight byly podle
výslovného zadání odloženy k rozhodnutí o releasu. Neproběhl benchmark,
produkční import, commit ani release. Časové zrychlení se netvrdí.

Prošly PHP lint, `composer style`, `composer analyse` a diff whitespace kontrola.
Úspěšné skupiny byly opakovány jen po relevantní opravě; výsledky jsou rozloženy
do následujících reportů, nikoli vydávány za jediný úplný běh:

| Report v `.work/tests/runs/` | Platné úspěšné výsledky |
| --- | --- |
| `20261126-204526-3d6eb782c9/report.json` | Module boundaries, dependencies, RSS; následující metadata krok selhal na doslovném textu X a byl opraven. |
| `20261126-204556-57fb05b776/report.json` | Metadata/DNS, audio, content-links; link-parser měl varování z nového regexu, později opraveno; zápis zastavila stará config fixture. |
| `20261126-204630-5902316965/report.json` | Link-parser bez varování; zápis zastavila další zahrnutá config fixture, později opraveno. |
| `20261126-204717-4310d93360/report.json` | Inline tokens, render-only partials, sanitizer, public exports, feed queue; post-text a Bots mají novější doplněné regrese níže. |
| `20261126-204729-6aa68e1ef9/report.json` | Finální write-path integrity včetně skutečného SQL zápisu z HTML. |
| `20261126-204857-6d84e819ba/report.json` | Finální post-text a Bots včetně JSON URL a ručních entity literálů. |

Mezilehlý report `20261126-204701-8ae8362a23` zaznamenává neúspěšný návrh
zápisové regrese, který změnil sdílený očekávaný obsah; finální regrese ponechává
stejný obsah a mění pouze HTML vstup přípravy. Tento běh není dokladem úspěchu.
Ve vybraných finálně úspěšných skupinách nebyly hlášeny SKIP.

Frontendová/nativní sada, plný HTTP API kontrakt a build/asset report nebyly
spuštěny: neměnil se JS, CSS, šablony, dialogový mechanismus ani doručování
assetů. Doménové zápisové testy používají skutečné Actions/importní služby
a izolované MySQL fixture. Browser smoke na `https://tinycat.test/` ověřil
načtení feedu a `/status/34`, včetně doslovného `< š`, bez zápisu dat. Nebyla
provedena vizuální screenshotová ani mobilní kontrola; nejde o změnu layoutu.

## Nasazení a kompatibilita

Dev PHP změny byly po uceleném kroku synchronizovány do externí Laragon
instalace a závěrem porovnány hashe všech 9 runtime souborů. Bez přenosu
config, storage, uploads, testů či dev závislostí; bez buildu a mazacího mirroru.

Nové Social konzumenty je nutné nasadit spolu s odpovídajícím core Text/Metadata:
staré core nemá `htmlText()` ani hook `parseDocument()`. Zachované veřejné
`FeedParser::text()` a `Audio::enclosure()` omezují rozbití starých volajících,
ale libovolné kombinace starých/nových souborů nejsou podporovaným nasazením.
Publikované snapshoty ani migrační soubory nebyly upraveny.
