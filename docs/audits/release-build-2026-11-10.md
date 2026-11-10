# Audit sestavení release balíčku

Datum: 10. 11. 2026. HEAD: `a1fa193`, aktuální runtime 5.22.1.
Měřen byl pracovní strom po úklidu docs/tools/tests, nikoli čistý release tag.
Implementace builderu, kompilátoru, runtime ani testů se při auditu neměnila.
Nevznikl publikovaný release, tag ani commit.

## Závěr

Největší rezervu má opakovaná práce při kompilaci a validaci assetů. V měřených
bězích kompilace tvořila 68–83 % celkového času. Následuje normalizace ZIP
časových značek, 9–19 %. Hashování a podpis nejsou významným úzkým místem.

První změna by měla zachovat stejný obsah balíčku a odstranit opakované čtení,
parsování a výběr dat v jednom běhu. Druhá může sdílet hotovou kompilaci pro
update a install. Persistentní build cache a změna komprese mají následovat
až po ověření těchto jednodušších kroků.

Kompletní [naměřená data](release-build-measurements-2026-11-10.json) obsahují
časové události, paměť, počty souborů, hash balíčků, fingerprint inventáře,
rozložení velikostí a podrobná volání čtyř tříd.

## Jak se dnes sestavuje

1. `tools/release/build-update.php` ověří CLI, verzi, podporovaný update základ,
   pracovní strom a podpisový klíč.
2. Projde povolené kořeny App/Public/assets/docs/lang/migrations a vybere
   aplikaci, kořenové soubory a dva veřejné dokumenty. Generované soubory
   se vynechávají při průchodu; samotné jejich adresáře se předem neprořezávají.
3. Spočítá SHA-256 zdrojových souborů. Migrační PHP obsah normalizuje na LF.
4. `tools/assets/compile-assets.php` znovu spustí modal/UI guards, načte
   a zvaliduje překlady i klientské deklarace a sestaví serverové katalogy,
   runtime, aplikace, komponenty, CSS a katalogy všech scope/language kombinací.
5. Doplní generované soubory a hashe, migrace a mazací manifest.
6. Sestaví ZIP, nastavuje jeho časové značky a pak centrální i lokální ZIP
   hlavičky ještě opravuje vlastním normalizátorem.
7. Hashuje výsledný ZIP, zapíše manifest a podepíše jej Ed25519.

Fresh install volá stejný kompilátor znovu. Liší se vynecháním migrations,
mazacích položek a názvy výstupů. `--without-migrations` není totéž jako
fresh install: v kompatibilitním update režimu se neplánují migrace, ale
migrační soubory mohou dál být součástí aktualizačního inventáře.

## Metoda měření

Windows, PHP 8.4.12 CLI, `opcache.enable_cli=0`, ZipArchive 1.22.6 / libzip
1.11.2. Každý build byl samostatný PHP proces se stejným zdrojovým stromem
a `SOURCE_DATE_EPOCH=315532800`. Měřené peak PHP allocation bylo 60 MiB;
nejde o RSS celého procesu.

Čtyři základní běhy: dva instrumentované updaty, jeden instrumentovaný install
a jeden kontrolní build původním builderem. Časovače byly vloženy pouze do
kopií skriptů pod `storage/release-build-audit-20261110/`. Pátý samostatný běh
načetl auditní kopie AssetGraph, ClientAssets, Components a Minifier přes
dočasný autoloader, aby spočítal volání a jejich inkluzivní časy.

Každý proces si vytvořil jednorázový podpisový klíč v paměti. Produkční klíč
ani config se nečetly kvůli podepisování a neměnily se. Použito bylo
`--allow-dirty`, výstupy jsou oddělené v ignorovaném auditním adresáři.
**Tyto ZIPy a podpisy jsou jen měřicí artefakty, nikoli distribuce důvěryhodná
pro produkční updater.** Ověřena byla jejich podpisová matematika, ne důvěra
produkčního připnutého klíče.

## Celkové časy a fáze

Časy jsou sekundy, zaokrouhlené. Sloupce fází nezahrnují všechny drobné kroky.

| Běh | Celkem | Kompilace + guards | Vytvoření ZIP | Normalizace ZIP časů | Peak PHP |
| --- | ---: | ---: | ---: | ---: | ---: |
| Update A, instrumentovaný | 15,35 | 12,66 | 0,91 | 1,43 | 60 MiB |
| Install, instrumentovaný | 7,20 | 4,92 | 0,89 | 1,22 | 60 MiB |
| Update B, instrumentovaný | 11,22 | 7,68 | 1,24 | 2,11 | 60 MiB |
| Update, původní builder | 10,26 | neměřeno samostatně | neměřeno | neměřeno | 60 MiB |

Samotné hashování zdrojů trvalo přibližně 63–105 ms, hash hotového ZIPu
24–30 ms a zápis manifestu s podpisem 3–12 ms. Průchod a výběr zdrojů trval
46–90 ms. Optimalizovat nejprve podpis či obecně zrychlovat sken adresářů
by podle těchto dat mělo malý efekt.

Rozptyl je výrazný. První běh nebyl kontrolovaným cold-cache experimentem;
OS cache, filesystem, procesorová zátěž a ochranný software nebyly izolované.
Nelze tvrdit, že update je inherentně pomalejší než install, ani považovat
rozdíl kontrolního a instrumentovaného běhu za změřený overhead časovačů.
Jde o malý lokální vzorek, nikoli p95 nebo slib času v Linux CI.

## Co se opakuje při kompilaci

V hlubším běhu trvalo sestavení 7,32 s. Časy metod jsou **inkluzivní a vzájemně
se překrývají**; nesčítat je jako nezávislé fáze.

| Metoda | Volání | Inkluzivní čas | Zjištění |
| --- | ---: | ---: | --- |
| `AssetGraph::read` | 18 935 | 1,58 s | Jen 206 unikátních cest. |
| `AssetGraph::imports` | 753 | 0,30 s | 75 různých JS souborů. |
| `AssetGraph::order` | 28 | 0,31 s | Opakované výpočty grafu. |
| `Components::select` | 225 | 0,10 s | Stejné scope roots vybírají znovu komponenty. |
| `Components::labels` | 216 | 0,08 s | Jazykově nezávislý průchod definic. |
| `ClientAssets::catalog` | 216 | 0,20 s | 9 scopes × 12 jazyků × validace a emitování. |
| `Minifier::minifyJavaScript` | 12 | 2,28 s | Významná CPU práce. |
| `Minifier::minifyCss` | 10 | 0,14 s | Menší položka. |

`client-translations.php` uvnitř každého scope a jazyka opakovaně načítá
a skenuje stejné JS zdroje několika regexy. Ověření existence klíče pro jazyk
je oprávněné, ale opakované zjišťování těchto klíčů ze stejného JS zdroje ne.
V základních bězích tato validační fáze zabrala 1,56–3,95 s.

AssetGraph při imports čte soubor a při resolve znovu čte nalezené závislosti,
poté je znovu načte pro sestavení výstupu. Nemá cache obsahu/importů pro běh.
Například lifecycle.js byl přečten 759× a dom.js 698×. Není to jen počet
emitovaných souborů nebo počet HTTP požadavků.

Kompilátor deduplikuje výsledné cesty podle hashů, ale až po vytvoření obsahu.
Devět scopes má osm unikátních app bundlů a pět unikátních stylesheetů.
Stejný obsah se tedy může opakovaně sestavovat a minifikovat, přestože v ZIPu
nakonec skončí jednou. Samotné deduplikování ZIP položek už částečně funguje.

## ZIP a velikost

| Vlastnost | Update | Fresh install |
| --- | ---: | ---: |
| Soubory | 851 | 823 |
| Generované soubory | 165 | 165 |
| Migrační soubory / deklarované migrace | 28 / 28 | 0 / 0 |
| Mazací položky | 129 | 0 |
| ZIP | 2 537 875 B | 2 514 564 B |

Update má bez komprese 10 190 500 B. Generované browser assety zabírají
5 815 788 B a serverové katalogy 952 762 B, dohromady přibližně 66 %
nekomprimovaného obsahu. Obsahová shoda všech 165 generovaných výstupů může
být vhodným základem sdílení mezi variantami.

Raw i minifikované varianty se v balíčku používají záměrně: runtime vybírá
podle nastavení minifikace. Jejich prosté vyřazení by měnilo podporované
chování, nikoli pouze zrychlovalo build. Stejně tak zdrojové moduly a jazykové
katalogy nejsou automaticky odpad jen proto, že je přítomen compiled manifest.

Normalizátor načte celý ZIP a pro každý soubor dvakrát použije `substr_replace`
na společný řetězec kvůli čtyřem bajtům timestampu v hlavičkách. Naměřených
1,22–2,11 s je důvod samostatně porovnat přímou změnu bajtů bufferu nebo
bezpečně dávkované patchování. Neodstraňovat tuto fázi bez náhrady: zajišťuje
lokální i centrální hlavičky a determinismus také pro Phar fallback.

Změna ZIP compression level zatím nemá podklad v měření variant úrovní.
Mohla by měnit velikost a hash archivu; nejprve vyhodnotit čas/velikost
a ověřit oba backendy. Současný audit měřil pouze ZipArchive.

## Opakování v release workflow a testech

Builder dnes vždy kompiluje vše od začátku. Samostatný update a install to
udělají dvakrát. `asset-report.php` rovněž vyvolává celou kompilaci.
Artefaktové testy provádějí dva nezávislé buildy pro kontrolu determinismu,
a to pro obě varianty. Další kompilace mají testy katalogů a klientských
assetů. Úplný preflight proto není měřením rychlosti jednoho release builderu.

Nezávislý opakovaný build je užitečný test a nemá se odstranit jen pro kratší
číslo na stopkách. Případné sdílení v běžném release workflow musí být oddělené
od ověření reprodukovatelnosti z čistých vstupů.

## Robustnost, kterou musí optimalizace zachovat

- Počáteční čistý Git stav nenahrazuje neměnný snapshot. Zdroj se nyní typicky
  hashne a později znovu čte do archivu; souběžná změna může vytvořit nesoulad.
  Jeden konzistentní obsahový snapshot pomůže správnosti i opakovanému I/O.
- Výsledné jméno ZIPu se přepisuje před dokončením a manifest se podpisem se
  zapisují zvlášť přímo do finálních názvů. Selhání uprostřed může nechat
  nekompletní sadu. Vhodný je samostatný pracovní adresář a zpřístupnění až
  kompletně ověřeného výsledku.
- `ZipArchive::close()` a finální zápisy manifestu/podpisu nemají vyhodnocenou
  návratovou hodnotu. Posílit diagnostiku neúplného zápisu při úpravě této části.
- Zachovat byte hash všech položek, LF migrační obsah a checksumy, podpis,
  zakázané cesty, whitelist balíčku, update/install rozdíly a podporovaný základ.
- Cache nesmí obejít modal/popover/UI guards, překladovou paritu nebo neplatné
  deklarace. Tyto kontroly jsou součástí sestavení, nikoli volitelná testovací dekorace.

## Doporučené pořadí změn

1. **Cache a příprava jen v rámci sestavení:** jednou načíst validované assety,
   importy a manifesty; jednou vybrat komponenty a překladové klíče pro scope;
   JS regex analýzu provést mimo jazykovou smyčku. Zachovat jazykové kontroly
   nad jednou připravenou sadou klíčů. Cache omezit na životnost konkrétního
   sestavení, aby se nezměnilo chování opakovaného vývojového sestavování.
2. **Deduplikace CPU práce a ZIP patch:** minifikovat identický vstup jednou
   podle typu a obsahu, samostatně optimalizovat timestamp normalizaci. Každou
   změnu porovnat proti stejné baseline, aby bylo jasné, odkud přínos přišel.
3. **Společný build obou variant:** jednou připravit zdroje a 165 generovaných
   výstupů; odvodit samostatné ZIPy, manifesty a podpisy. Zachovat nynější
   samostatné CLI použití i výstupní názvy.
4. **Teprve poté persistentní cache:** jen pokud zůstane podstatný přínos.
   Klíč musí zahrnout zdroje, manifesty, katalogy, definice, kompilátor,
   minifier a validační pravidla i relevantní nastavení/verze. Nestačí mtime
   nebo samotné číslo releasu. Změna libovolného vstupu musí invalidovat výsledek.

První dvě etapy mají být pokud možno obsahově neutrální: stejný inventář,
stejné souborové hashe a při stejném prostředí a epoch stejný ZIP.
Změny formátu, odstranění raw variant, nový minifier ani paralelní workery
nejsou pro první optimalizaci nutné.

## Jak hodnotit implementaci

Použít stejný zdrojový snapshot a více střídajících se baseline/candidate běhů
v samostatných procesech. Oddělit jednorázový update, install, společný build,
prázdnou a teplou případnou cache. Vyhodnotit wall time, peak allocation,
počty čtení, minifikací a velikost archivu; neomezit výsledek na jeden nejlepší běh.

Zachovat a spustit testy podepsaného update/install balíčku, opakovaného
deterministického buildu, compiled translations a klientských assetů, budgetů
a grafu, UI/modal/popover guards a update/rollback/instalace. Doplnit cílené
scénáře invalidace jen tam, kde nová cache skutečně vznikne. Nový build nesmí
využít starší compiled výstup z pracovního stromu jako zdroj pravdy.

## Provedené ověření auditu a omezení

Všechny základní buildy dokončily build guards a byly ověřeny jejich podpisy,
hash archivu, inventář ZIPu a SHA-256 každé položky. Dva instrumentované update
ZIPy a kontrolní ZIP původního builderu byly bajtově shodné; shodoval se i ZIP
hlubšího profilu. To dokládá obsahovou neutralitu použitých měřicích kopií.
Plná funkční sada nebyla při auditu znovu spouštěna; implementace se neměnila.

Nebyl měřen Phar fallback, Linux CI, řízená cold filesystem cache, chybové
stavy disku ani produkční podpisová důvěra. Z výsledků nelze slíbit konkrétní
zrychlení dosud neimplementovaných změn. Měřicí podklady a testovací artefakty
zůstávají v odděleném ignorovaném storage adresáři pro následující porovnání.
