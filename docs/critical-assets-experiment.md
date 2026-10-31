# Kritické assety přímo v HTML: návrh a měření

Historick? z?znam m??en? p?ed vyd?n?m 5.14.0; popsan? stavy a hashe pat?? uveden? etap?.

31. 10. 2026, oddělený kandidát `release/ajax-safe-candidate`. Jde o experiment s doručováním assetů. Produkční šablony, CSP, manifest ani výchozí načítání se touto etapou nezměnily.

## Závěr

Smysluplný kandidát je **malé kritické CSS pro první viewport, zbytek CSS ponechat externí a načíst neblokujícím způsobem**. Pomáhá hlavně první návštěvě na pomalé síti. Pouhé přidání inline CSS při zachování blokujícího stylesheetu nepomáhá. Celé CSS a runtime JS bych plošně do HTML nepřesouval: teplá cache už funguje dobře a jejich inline kopie zvětšuje každý další HTML dokument.

Výsledek není důvod zapnout kritické CSS pro celý web bez další integrační práce. Měřeny jsou anonymní veřejné stránky. Pro implementaci je vhodné omezení na ověřené veřejné pohledy, deterministický build a samostatné ověření přihlášené navigace, formulářů a otevřených menu. Nelze z toho odvodit přínos pro administraci nebo AJAX navigaci, při které jsou CSS/JS už načtené.

Princip odstranění blokující CSS závislosti odpovídá [doporučení web.dev](https://web.dev/articles/extract-critical-css). Čísla níže jsou vlastní lokální měření TinyCat.

## Současný stav a varianty

Veřejný klient má jeden produkčně minifikovaný stylesheet: 71 016 B, přibližně 13 KB gzip, a čtyři skripty s `defer`. Soubory mají obsahové hashe a dlouhou immutable cache. `.htaccess` podporuje kompresi přes mod_deflate; skutečné nasazení závisí na aktivních Apache modulech. Téma je nastavené serverem atributem HTML, takže není potřeba přidávat inicializační JS jen kvůli barvám.

| Varianta | Co dělá |
|---|---|
| External | Současné externí CSS a čtyři odložené skripty |
| Full CSS | Celé CSS v `<style>`, skripty externí |
| Critical + blocking | Malé kritické CSS, plný stylesheet dál blokuje vykreslení |
| Critical + async | Kritické CSS + preload plného CSS; malý loader ho po načtení přepne na stylesheet; noscript fallback |
| Runtime inline | Základní runtime v HTML na konci body, ostatní tři skripty externí; pořadí závislostí zachované |

Kritická sada pro měření má **21 413 B raw / 4 902 B gzip**. Vznikla výběrem CSSOM pravidel skutečných pohledů home/profil/detail ve třech šířkách a dvou tématech, se zapnutým i vypnutým JS. Zůstává původní pořadí kaskády a media podmínky. Vedle viditelných prvků obsahuje také pravidla, která schovávají a umísťují obsah: bez nich se například objevil skip-link a změnila poloha hlavičky.

## Metoda a ověření

Skutečné anonymní SSR HTML exportované z izolované výkonové databáze a skutečné produkčně minifikované CSS/JS. Statický lokální replay odděluje doručování assetů od backendové latence. Avatary nahrazuje stejný lokální fallback SVG; nejde o měření reálných velkých obrázků, fontů či třetích stran.

- Desktop: 1280×900, síť 10 Mbit/s, latency 20 ms, CPU bez zpomalení.
- Mobilní simulace: 390×844, 1,6 Mbit/s, latency 150 ms, CPU 4× zpomalené přes CDP.
- Nativní headless Chrome, lokální HTTP/1.1, gzip level 9. To nereprodukuje HTTP/2/3, reálný telefon, produkční TTFB ani provozní distribuci cache hitů.
- Tři kola, střídání pořadí variant, home i detail. Cold začíná v novém izolovaném browser contextu; warm navazuje ve stejném contextu. HTML je no-store, statické assety immutable. Časy jsou mediány tří návštěv pro konkrétní stránku/profil/cache; tři vzorky nestačí na důvěryhodné p95 nebo statistický průkaz malých rozdílů.
- Před přijetím časů prošlo 18 porovnání geometrie a vybraných computed styles prvního viewportu pro kritické CSS bez plného stylesheetu (home/profil/detail × 390/760/1280 × light/dark). Zachované jsou kontrolní screenshoty; mobilní home a tmavý desktopový detail byly vizuálně zkontrolované.
- Na přesně měřeném CSS následně prošlo dalších 18 kontrol noscript fallbacku. Nejde o důkaz shody celého obsahu pod prvním viewportem bez plného CSS; jeho načtení zůstává nezbytné.
- Přijato je 96 CSS navigací a 48 navigací samostatného JS porovnání: bez JS/CSP chyb, s funkční inicializací aplikace a nenulovým FCP/LCP. Instrumentace čeká na nový dokument a dokončení jeho načtení, nikoli na starý complete dokument při opakované navigaci.

## CSS: výsledky hlavní stránky

### Zpomalený mobil

| Varianta | Cold FCP | Cold LCP | Warm FCP | Warm LCP | HTML gzip |
|---|---:|---:|---:|---:|---:|
| External | 992 ms | 992 ms | 304 ms | 328 ms | 4 337 B |
| Full CSS | 488 ms | 732 ms | 332 ms | 348 ms | 17 250 B |
| Critical + blocking | 1 140 ms | 1 140 ms | 316 ms | 352 ms | 9 089 B |
| Critical + async | **436 ms** | **704 ms** | 336 ms | 336 ms | 9 224 B |

Kritické CSS s neblokujícím zbytkem zkrátilo cold FCP o 56 % a LCP přibližně o 29 %. Nejde o stejné metriky: rychlý první paint není totéž jako dokončení největšího obsahu. Cold LCP jednotlivých kol bylo 980–1 068 ms původně a 612–712 ms s kritickým CSS. Warm LCP bylo 308–372 → 324–404 ms; rozsahy se překrývají, rozdíl mediánu 8 ms nelze považovat za přesný produkční dopad.

Cena je přibližně **+4,9 KB gzip v každém HTML** a opětovný přenos této kopie při další plné návštěvě. Externí plné CSS se stále stahuje do cache: cold celkový přenos hlavní stránky byl medián 101 015 → 105 955 B, warm 5 379 → 10 319 B. Kritické CSS tedy zlepšilo kritickou cestu, nikoli objem stažených dat.

Samotné inline kritické styly s blokujícím plným CSS výsledek zhoršily. Celé inline CSS mělo podobný cold přínos, ale přidává téměř 13 KB gzip do každého HTML, proto ho nedoporučuji jako výchozí řešení.

### Desktop

| Varianta | Cold FCP/LCP | Warm FCP/LCP |
|---|---:|---:|
| External | 244 / 244 ms | 116 / 116 ms |
| Full CSS | 156 / 156 ms | 116 / 116 ms |
| Critical + blocking | 252 / 252 ms | 120 / 120 ms |
| Critical + async | 160 / 160 ms | 124 / 124 ms |

Detail příspěvku měl na mobilu cold LCP 1 060 → 692 ms a warm 380 → 372 ms pro critical+async. Naměřené maximální CLS kritické varianty nepřekročilo baseline (zhruba 0,02); nejde o tvrzení nulových layout shiftů ve všech stavech webu. DOMContentLoaded home na mobilu zůstalo přibližně 1,10 → 1,09 s: tato změna převážně urychluje zobrazení, nenahrazuje optimalizaci JS práce ani měření INP.

## JS: samostatné opakování

Opravená runtime varianta byla znovu porovnána s externí baseline ve vlastních třech kolech. Její baseline se proto neslučuje s CSS tabulkou.

| Mobilní home | External | Runtime inline |
|---|---:|---:|
| Cold FCP/LCP | 924 / 924 ms | 916 / 916 ms |
| Warm FCP/LCP | 320 / 348 ms | 292 / 328 ms |
| HTML gzip | 4 337 B | 9 680 B |

Cold přínos je malý a warm rozdíly se pohybují v rozptylu krátkého testu. Přibývá přibližně 5,3 KB gzip do HTML při každé plné návštěvě. Runtime zároveň není nutný k samotnému SSR vykreslení. Data nepodporují plošné přesunutí JS do HTML.

První JS experiment byl chybný: řetězcové vkládání interpretovalo dolarové substituce v minifikovaném zdroji a změnilo jeho CSP hash. Těchto 24 navigací je explicitně vyřazených a zachovaných pouze diagnosticky. Callbackové vkládání chybu odstranilo; samostatné nové porovnání má nula CSP/JS chyb. Vadně neinitializovaná aplikace se nehodnotí jako rychlejší varianta.

## CSP a hranice implementace

TinyCat nyní pro běžné stránky povoluje skripty ze stejného původu a zakazuje inline event atributy. Prototyp používá přesný SHA256 hash loaderu/runtime a nepřidává `unsafe-inline` ani `onload` atribut. Inline klasický script nemá stejnou časovací semantiku jako externí `defer`; je nutné zachovat závislosti. Viz [HTML script model](https://html.spec.whatwg.org/multipage/scripting.html#the-script-element) a [CSP inline pravidla](https://www.w3.org/TR/CSP3/#does-element-match-source-list-for-type-and-source).

Konkrétní integrační problém: při zapnuté analytice se souhlasem dnes `Web::securityHeaders()` přidává `unsafe-inline` pro gtag inicializaci. Pouhé přidání nového hash-source by v moderním CSP přestalo obecně povolovat jiné inline bloky a mohlo zablokovat analytickou inicializaci. Produkční návrh musí všechny potřebné bloky řešit společně přes explicitní hash/nonce nebo ponechat loader v externím startovacím kódu. Toto chování vyplývá z [CSP algoritmu pro inline obsah](https://www.w3.org/TR/CSP3/#allow-all-inline). Analytika ani CAPTCHA v této fixture testované nebyly.

DOM-driven extrakce v různých diagnostických bězích vybrala 194–195 pravidel podle stavu hydratace sidebaru. Pro produkční build je potřeba deterministická fixture nebo explicitní výběr ze stávajících modulárních zdrojů; ne extrakce za běhu každého požadavku. Časy jsou svázané s konkrétní sadou 21 413 B, SHA256 `4055576e86aec25cb88fd0f806f8341bd8c8418b74f4c92e521345179618e9ca`.

Rozumné hranice další implementace: pouze ověřené veřejné SSR pohledy, zachovat plný hashovaný stylesheet pro cache a AJAX, buildově omezený rozpočet kritické sady kolem 5 KB gzip, funkční noscript/load-error fallback a integrační test CSP s analytikou/CAPTCHA. Až produkční cache-hit poměr a měření první návštěvy rozhodnou o výchozím zapnutí. Přihlášené menu, vyhledávací/formulářové stránky, administrace a otevřené ovladače před dokončením CSS potřebují vlastní pokrytí.

## Reprodukce a artefakty

```powershell
php tools/performance/critical-assets-fixture.php --config=storage/performance/large-config.php --output=storage/performance/critical-new
node tools/performance/critical-assets.cjs --directory=storage/performance/critical-new --rounds=3
python tools/performance/critical-assets-summary.py storage/performance/critical-new
```

Aktuální opravený runner umí všechny varianty v jednom běhu; vyřazení runtime se týká pouze historického prvního experimentu. Použité artefakty jsou lokálně ignorované:

- `storage/performance/critical-assets-20261031/`: fixture, výsledky CSS, screenshoty, původní zdroj experimentu, `critical-measured.css` a summary s explicitně vyřazenou vadnou JS variantou.
- `storage/performance/critical-runtime-20261031/`: samostatné platné runtime porovnání a jeho source hashe.
- `critical-assets-20261031/validation.json`: 18 kontrol kritického viewportu a 18 noscript kontrol nad přesně měřeným CSS. CSS source hashe i validní běhy ověřuje summary nástroj.

Assety se nijak nekopírují do produkčního DOM automaticky. Výstupem této etapy je měřený prototyp a návrh pro cílené nasazení, nikoli změna výchozího doručování celé aplikace.
