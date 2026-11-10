# Optimalizace sestavení 5.22.2

Navazuje na [audit](release-build-2026-11-10.md). Změna je součástí vydání
5.22.2 včetně předchozího rozřazení dokumentace, nástrojů a testů.

## Provedené změny

- AssetGraph drží obsah a importy jen uvnitř explicitního build snapshotu.
  Mimo sestavení zůstávají čtení živá. Vnoření a chyby obnovují předchozí stav.
- ClientAssets připravuje komponenty a překladové klíče jednou pro scope.
  Components při každém sestavení znovu načítá zdrojové definice; runtime
  cache se po dokončení obnoví. Další build vidí změny manifestů a komponent.
- Klientský překladový validator analyzuje JS soubor jednou; požadované klíče
  dál ověřuje pro každý jazyk. Modal, popover, UI a katalogové kontroly zůstaly.
- Minifikace stejného obsahu a typu proběhne uvnitř jedné kompilace jednou.
- ZIP timestamp helper mění přímo jednotlivé bajty bufferu místo dvou
  rekonstrukcí celého archivu pro každou položku. Formát a epoch se nemění.
- `--both` jednou kompiluje a vytváří oddělené update/install výstupy.
  Samostatné příkazy zůstávají podporované.
- Hashe zdrojů a ZIP používají stejné zachycené bajty. Zavření ZIPu a zápisy
  manifestu/podpisu se kontrolují. Dokončené soubory se přejmenují z privátního
  pracovního adresáře pod zámkem; při selhání publikace se obnovuje předchozí
  sada a případné neobnovitelné zálohy zůstávají pro obnovu. Nejde o tvrzení,
  že více souborů filesystem umí zpřístupnit jedinou atomickou operací.

Nevznikla persistentní cache ani nová produkční závislost. ZIP komprese,
raw/min varianty, formát manifestu, podpisový klíč, migrační checksumy a minimum
podporovaného updatu se nezměnily. Sdílené třídy jsou změnou aplikace, proto
je připravován normální podepsaný patch balíček, nejen commit tooling souborů.

## Srovnání

[Výsledky](release-build-optimization-results.json) obsahují první porovnání
a další střídající se baseline/candidate měření. Pro párové běhy byl původní
kompilátor, validator a čtyři třídy načteny z `a1fa193` v auditních kopiích.
Obě pipeline balily stejné aktuální zdrojové bajty při verzi 5.22.1 a pevné
ZIP epoch, aby šla porovnat i úplná binární shoda archivu.

| Párový běh | Čas |
| --- | ---: |
| Baseline A | 11,66 s |
| Candidate A | 4,06 s |
| Baseline B | 11,87 s |
| Candidate B | 3,29 s |
| Candidate update + install | 5,21 s |

Poměr mediánů pro jednotlivý update je přibližně 3,2×. Všech pět updatových
ZIPů bylo bajtově shodných. Samostatný install se také shodoval se společně
sestaveným installem; všech 165 generovaných souborů zůstalo shodných s auditem.
Podpisy měřicích artefaktů byly ověřeny jednorázovým auditním klíčem.

Jde o malý lokální Windows vzorek s neřízenou OS cache a zátěží. Nejde o
garantované zrychlení v CI ani o p95. Verze se zvýšila až po tomto porovnání;
vydané balíčky proto přirozeně mají jiné zdrojové hashe než měřicí ZIPy 5.22.1.

## Ověření

Nová skupina build-preparation pokrývá vnoření snapshotu, zotavení po chybě,
změny zdrojů mezi buildy, výměnu komponent a manifestových klíčů, kontrolu
chybějících překladů, normalizaci obou ZIP backendů a ochranu před nekompletní
publikací. Artefaktový test navíc ověřuje `--both`, podpisy, shodu se samostatným
updatem a přesné vyloučení migrací a mazání z instalačního inventáře.
Dosavadní kontroly opakovaného deterministického buildu zůstaly zachované.

Release musí projít celým preflightem, ověřením produkčního podpisu a
produktovou MySQL update rehearsal před publikací assetů.

Úplný `composer preflight` pro 5.22.2 prošel: 71 skupin, včetně nativního
frontendu, 5 349 API kontrol, nové build skupiny a obou balíčků, update/rollbacku
a MySQL instalace. Memcached integrace se přeskočila kvůli chybějícímu lokálnímu
serveru. Windows úklid některých dočasných adresářů vypsal varování, nikoli
selhání ověření. Produkční podpisový klíč odpovídá distribuovanému veřejnému klíči.
