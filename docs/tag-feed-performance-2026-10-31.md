# Zrychlení velkých tagových výpisů

Historick? z?znam m??en? p?ed vyd?n?m 5.14.0; popsan? stavy a hashe pat?? uveden? etap?.

31. 10. 2026, větev `release/ajax-safe-candidate`, základ v5.13.0 (`e73ffb9`). Úprava je v `App/Status/Feed.php`, odděleně od původního kompletního AJAX refaktoru. Není commitnutá ani publikovaná.

## Nález a změna

Původní MySQL plán načte kandidáty přes `content_tags_term_index`, dohledá obsah a autora, vyhodnotí případné podmínky médií a teprve pak seřadí výsledek. U tagu `community` šlo o 13 030 vazeb; plán textového filtru prováděl tisíce lookupů a anti-joinů, přestože vracel jen 50 příspěvků.

Pro MySQL běžný a čistě textový tagový výpis nyní nejprve zkusí omezené chronologické okno: `max(256, limit × 16)`, maximálně 1 600 příspěvků při podporovaném limitu 100. Používá existující `content_feed_index`, viditelnost publikace a stejný čas/ID kurzor. Materializované okno je první v pořadí joinů; celý feed se kvůli vzácnému tagu neprochází. Podmínky aktivního autora, tagu a textových médií zůstávají zachované.

Plná stránka nalezená uvnitř tohoto nejnovějšího okna je úplný správný výsledek. Pokud jich není dost, výsledek sondy se zahodí a provede se původní selektivní dotaz přes tag. Tím se zachová úplnost i pro vzácný/starý tag, hluboký kurzor nebo množství skrytých příspěvků. Kontrola `EXISTS … LIMIT 1 OFFSET limit−1` uvnitř stejného SQL navíc zabrání materializaci okna, pokud tag nemá ani dost vazeb na plnou stránku. Nekontroluje viditelnost, takže pouze bezpečně vylučuje nemožný rychlý výsledek; správnost stále zajišťuje fallback.

Filtry images/links/video/audio a ostatní databázové ovladače zůstávají u původní cesty. Plošné procházení od nejnovějších příspěvků bylo zamítnuté: u prázdného video/audio výsledku v původních datech procházelo příliš mnoho obsahu a dosahovalo 0,4–0,6 s. Nepřidává se produkční index, sloupec, výsledková cache ani její invalidace.

## Finální HTTP porovnání

PHP 8.4.12 s OPcache, MySQL 8.4.3, existující izolovaná databáze `tinycat_perf_*` s 99 827 příspěvky. Stejná data i konfigurace pro v5.13.0 a kandidáta, čtyři nezávislé loopback PHP workery, souborová cache oddělená pro každý běh. Pořadí baseline/candidate/candidate/baseline; 40 warm requestů na cestu a běh po cold/warmup vlnách. Neběžely souběžně regresní testy. Jde o instrumentované lokální serverové časy, nikoli produkční kapacitu nebo browser LCP/INP.

Dočasná syntetická média odpovídají původnímu říjnovému měření: obrázek u každého desátého příspěvku a existující odkazy rozdělené do link/video/audio. Ovlivňují SQL filtrování, ne skutečné stahování médií. Byla odstraněna ve `finally`; závěrečná kontrola potvrdila opět nula obrázků a nula video/audio klasifikací. Vývojová databáze se neměnila. Testovací databázi se před měřením doplnila existující migrace `20261029_001_avatar_appearance.php`, protože v5.13.0 jinak končila na chybě chybějícího sloupce. Tento první neúspěšný běh je z porovnání vyloučený.

Tabulka uvádí medián dvou round-level p50/p95, nikoli percentile všech requestů dohromady. Každá varianta má pro každý řádek 80 warm requestů, celkem 320. Source SHA256 měřeného `Feed.php`: baseline `8cd889ef8140150732e2af19332ee799cdda9d79441106353d36c3fe9b2773a3`, kandidát `ceb0792dcbe87cf71656520e42185c7776c34dad13a94bd9cc6b3ce3187afa61`.

| Výpis, limit 50 | HTTP p50 původní → nový | HTTP p95 původní → nový | SQL p50 původní → nový |
|---|---:|---:|---:|
| Běžný tag | 226,4 → **72,8 ms** | 371,6 → **157,7 ms** | 169,9 → **23,9 ms** |
| Pouze text | 328,7 → **90,7 ms** | 449,9 → **205,0 ms** | 275,1 → **28,7 ms** |

V tomto lokálním scénáři klesl p50 přibližně o 68 % a 72 %. Oba opakované běhy jsou rychlejší, ale rozptyl je značný: běžný tag měl původní p50 208–245 ms a nový 50–95 ms; text 303–355 → 90–91 ms. Nové p95 jednotlivých běhů bylo 71–245 ms a 122–288 ms. Z těchto dat neplyne stejné procento zrychlení celého webu.

Všech 320 warm odpovědí mělo HTTP 200, validní JSON a shodný normalizovaný SHA256 s původní verzí pro danou cestu. Počet SQL zůstal čtyři na request při úspěchu rychlé cesty. Fallback přidá jeden SELECT; v MySQL testu malého tagu s osmi starými vazbami a limitem 50 trvala dodatečná sonda 0,745–2,252 ms, medián 0,910 ms (12 vzorků). Před doplněním kontroly počtu vazeb byl medián přibližně 6 ms. Tato cena nezmizela a je součástí kompromisu; tag s dostatečným počtem starých/skrytých příspěvků může materializaci provést a následně stejně potřebovat fallback.

## Ostatní filtry a meze důkazů

Před přidáním závěrečné kontroly počtu vazeb proběhlo stejné čtyřkolové porovnání všech šesti filtrů (960 warm requestů), také se shodnými hashi. U čtyř neměněných větví se zaznamenal velký rozptyl a někde horší časy; nelze deklarovat výkonnostní paritu všech cest. Jejich SQL se touto změnou nemění a finální cílené porovnání se vztahuje pouze na běžný a textový filtr.

| Neměněný filtr | HTTP p50 původní → kandidát | HTTP p95 původní → kandidát |
|---|---:|---:|
| Obrázky | 125,8 → 140,7 ms | 156,7 → 227,7 ms |
| Odkazy | 182,2 → 244,1 ms | 219,7 → 397,0 ms |
| Video | 211,3 → 222,1 ms | 271,5 → 320,6 ms |
| Audio | 246,6 → 225,1 ms | 327,1 → 284,8 ms |

Pro tyto větve se nepřijímá změna join pořadí ani tvrzení o optimalizaci. Před produkčním nasazením je vhodný profil reprezentativních tagů různých hustot včetně vzácných tagů s mnoha starými vazbami a částečně zaplněných stránek. Mechanismus má omezenou práci navíc, nikoli záruku, že každý tag bude rychlejší.

## Správnost a reprodukce

- MySQL: 81 kontrol porovnává skutečnou novou cestu s původním výběrem ID. Pokrývá plné i neúplné stránky, kurzory za 50 000 příspěvky, stejné timestampy a ID, neplatné/negativní kurzory, limity mimo rozsah, vzácný starý tag, budoucí a neaktivní obsah. Transakční fixture je rollbacknutá a počet příspěvků ověřený.
- Archive/home filtry: 31 kontrol; query budgets; neměnný query baseline: 30 kontrol; veřejné exporty: 11 kontrol; API HTTP kontrakt: 4 125 kontrol.
- PHPStan level 8 prošel. PHP styl prošel. JS/CSS této etapy se nemění; předchozí kompletní frontendová sada kandidáta zůstává platným ověřením jeho klientských změn.

```powershell
php tests/mysql-tag-window.php --config=storage/performance/large-config.php
php tests/archive-filters.php
php tests/hot-read-query-budgets.php
php tests/http/api-contract.php
php tools/performance/tag-filter-benchmark.php --config=storage/performance/large-config.php --output=storage/performance/tag-new-run --baseline=../tinycat-release-5.13.0 --candidate=. --focused
python tools/performance/tag-filter-summary.py storage/performance/tag-new-run
```

Benchmark odmítá databázi bez prefixu `tinycat_perf_`, neočekávaný výchozí stav médií a existující výstupní adresář. Před fixture uloží obnovovací SQL a vždy provede cleanup ve `finally`; násilné ukončení procesu vyžaduje použít uložený recovery soubor. Nepracuje s produkční databází. `tag-query-probe.php` samostatně provádí pouze čtení a ukládá `EXPLAIN ANALYZE` alternativ včetně bounded varianty.

Lokální ignorované artefakty jsou pod `storage/performance/tag-compare-20261031-guarded/` (finální data, plány, source hash, summary a potvrzení obnovy), `tag-compare-20261031-final/` (předchozí porovnání všech šesti filtrů) a `ajax-final/tag-window-tests-final.json` (cena fallback sondy). Souhrnný skript odmítne neshodné response hashe nebo neúspěšné odpovědi.
