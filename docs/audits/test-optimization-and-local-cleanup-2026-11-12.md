# Optimalizace testů a úklid lokálních kopií

Stav k 12. 11. 2026. Navazuje na [měření rebuildu](module-rebuild-verification-2026-11-11.md).
Jde o první provedené optimalizace; cílových 15–20 minut celé sady zatím
nepotvrzuje nový úplný průchod.

## Provedené změny

- PHP lint zpracovává stejné soubory po dávkách 16. Pro 588 souborů spouští
  37 PHP procesů místo 588. Velikost dávky drží příkaz pod limitem Windows.
  Ověřena byla i chyba uprostřed dávky: PHP zkontrolovalo následný soubor
  a vrátilo nenulový exit code. Diagnostika chyb se zachovává.
- Update větev `package-artifact.php` vynechává druhý samostatný build.
  Bytovou shodu dál ověřuje mezi samostatným a nezávislým `--both` buildem.
  Fresh větev zachovává dva nezávislé buildy. Podpisy, hranice artefaktu,
  obě varianty a ochrana publikovaných souborů při chybě se dál kontrolují.
- `client-assets.cjs` kompiluje společné release vstupy jednou za celý běh.
  Výstup předává jednotlivým PHP fixtures přes stdin; nevzniká trvalá cache
  ani zápis do provozních assetů. Shodné požadavky sdílejí pouze JSON vstup,
  každý scénář znovu parsuje data a startuje vlastní VM. Počet 177 kontrol
  i všechny routy, jazyky a režimy source/compiled/minified zůstávají.
- `.gitignore` používá nové umístění generovaných serverových překladů
  `App/Core/Support/compiled`, které nahradilo původní `App/Support/compiled`.

## Ověření

Testy používají Laragon PHP 8.4.12 NTS se stejným ini, limitem 512 MB,
neomezeným CLI časem, vypnutým CLI OPcache a bez Xdebug. Limity se neměnily.
Časy jsou reálné doby příkazů; podmínky staršího měření nejsou kontrolovaný
benchmark, proto rozdíl nepřipisujeme celý změně kódu.

| Kontrola | Poslední čas při rebuildu | Tento běh | Výsledek |
| --- | ---: | ---: | --- |
| PHP lint, stejných 588 souborů | 171,885 s | 6,250 s | passed |
| Styl PHP | 0,572 s | 0,326 s | passed |
| Klientské assety, 177 kontrol | 112,148 s | 21,100 s | passed |
| Update artefakt | 152,726 s | 33,501 s | passed |
| Fresh artefakt | 142,874 s | 15,242 s | passed |

Fresh kontrola má stejné dva buildy jako dříve. Její velký časový rozptyl
ukazuje, proč nelze ze srovnání těchto jednotlivých běhů odvodit procento
zrychlení celé sady. Doloženou strukturální úsporou je počet procesů lintu,
jedna vynechaná kompilace update artefaktu a jedna společná příprava
klientského buildu místo kompilace pro každý požadavek.

Reporty mají UTC identifikátory, přestože lokálně již bylo 12. listopadu:

- [Styl a lint](../../storage/test-runs/20261111-230228-86d8262a70/report.json).
- [Klientské assety](../../storage/test-runs/20261111-230236-2ae9c90e64/report.json).
- [Oba balíčkové testy](../../storage/test-runs/20261111-230259-8bef0ca368/report.json).

Všechny tři reporty mají prázdný seznam změn zdrojů během běhu. Celá sada,
ostatní browserové scénáře ani nový preflight se neopakovaly; aplikace
v `App`, veřejné vstupy a publikované migrace se v tomto úklidu neměnily.

## Lokální kopie a retence

Retence pracovních release artefaktů používá poslední tři publikované verze:
5.22.2, 5.22.1 a 5.22.0. Neomezuje Git historii, tagy, podporované migrace
ani provozní rollback zálohy.

- Publikované balíčky 5.22.2 zůstávají v `dist/release-5.22.2`.
  Jejich duplicitní stažení bylo před smazáním porovnáno pomocí SHA-256.
- Z auditních buildů 5.22.1 zůstává jediná dvojice install/update v
  `storage/release-archive/5.22.1-audit`. Jde o auditní build, nikoli nově
  publikovaný oficiální release. Původní měřicí JSON a logy se zachovaly.
- Lokální balíček 5.22.0 přítomen nebyl; verze zůstává dostupná přes Git tag.
  Nový balíček nebyl kvůli archivaci vytvářen ani stahován.
- Odstraněny staré worktrees 4.2.0, 5.8.0 a 5.11.0, ZIPy 4.0.1/4.6.0,
  staré source snapshots, dvě staré update fixtures, 33 API databází
  a 12 nadbytečných výstupů auditních buildů.
- Konfigurace, klíče, média a obnova z historických lokálních instalací
  jsou oddělené v `storage/local-preserved-installations`. Konfigurace
  4.2.0/4.6.0, pět starých benchmark instalací a dvě update fixtures mají
  zachovaná data; hlavní `config.php`, `uploads` a `storage/updates` se neměnily.
- Starý samostatný klon Bots obsahoval historii mimo hlavní repozitář.
  Před odstraněním zdrojů byla uložena do ověřeného
  `storage/local-preserved-installations/bots-core-source.bundle`.

Inventář eviduje přibližně **124,56 MiB odstraněných souborových kopií** po
odečtení přesunutých zachovaných souborů. Není to měření volného místa celého
disku: obě strany inventáře vynechávají runtime/cache/backup adresáře,
symlinky a instalace nástrojů. [Strojový záznam úklidu](../../storage/cleanup-result-20261112.json)
uvádí konkrétní cesty a výjimky.

Automatická kontrola odmítla odstranění tří zbývajících read-only Git pack
souborů v `storage/bots-core-source/.git/objects/pack` s důvodem
`blocked by policy`. Zbývá přibližně 68 kB metadat; zdroje klonu jsou pryč
a jeho úplná historie je zachovaná v bundle. Ostatní aktivní worktrees mimo
tento repozitář ani dev nástroje se neodstraňovaly.

Registrace již neexistujících worktrees byly uklizené přes Git.

Další etapa má měřit fáze buildu a přípravu browserových fixtures a omezit
opakované vytváření stránek. Nemá znovu přidávat celé integrační kopie testů.

Následné oddělení `tinycat.test` přesunulo zachovaný archiv lokálních instalací
z `storage/local-preserved-installations` do
`C:/Users/Nikola/AppData/Local/TinyCat/relocation-backup-20261112/historical-local-installations`.
Aktuální umístění živé instalace, médií, konfigurace a záloh uvádí
[návod k lokálnímu prostředí](../development/local-environment.md). Výše
uvedené cesty zachycují původní stav při dokončení samotného úklidu.
