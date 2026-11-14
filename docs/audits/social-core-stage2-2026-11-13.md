# Etapa 2: sdílený Publishing a obsahové politiky

Datum: 13. 11. 2026. Navazuje na [plán](../plans/social-core-capabilities.md)
a [první etapu](social-core-stage1-2026-11-13.md).

## Implementace

- Core/Publishing vlastní `Segments`, `Resource`, `Images`, editor, lokální překlad,
  linkové karty a přehrávače. Social dodává inline význam zmínek a tagů a navigaci
  statusů/komentářů. `TextPolicy` je vázaná na vlastníka a konkrétní `content.kind`;
  Blog připravuje `article` bez vedlejšího účinku sociální inicializace.
- `Core/Security/UrlRules` pouze normalizuje a porovnává pravidla. Publishing vlastní
  jejich konfiguraci, vyhodnocení a API, včetně zachovaného klíče
  `moderation.blocked_urls` a URL `/api/moderation/blocked-urls`. Vypnutí Socialu
  už neodstraní nastavení ani ochranu článků.
- `Publishing/Outbound` sdílí UTM a kontrolu vazby odkazu na autorizovaný text
  a přílohy. Social ověřuje status, komentář a redirect; Blog ověřuje článek.
  Resolver core neobsahuje sociální SQL ani seznam doménových URL.
- Reputační kvóty a cooldowny vlastní Social/Moderation/ActionPolicy. Sociální
  počet příspěvků filtruje `kind = post`; články neudělují sociální reputaci.
  Follow budget deklaruje Social, obecný limiter odvozuje retenci z deklarovaných
  oken a povinných frameworkových pravidel.
- `Publishing/Rss` serializuje data vlastníků. Blog přispívá `/blog/feed`, Social
  nadále vybírá své autorové, tagové a vyhledávací feedy. Core hostuje `/oembed`,
  vlastníci přispívají lazy `EmbedSource`. Cizí origin se odmítne bez stahování;
  callback vlastníka znovu kontroluje veřejnou viditelnost. Blog nabízí souhrn článku,
  Social zachovává své rámce a pravidla embedu.

Blog používá stejné textové a mediální komponenty i společný editor. Přijímá
multipart obrázek a odebrání obrázku. Metadata nejvýše tří náhledových odkazů
se připravují před transakcí. Specializace článku a společná publikace se zapisují
atomicky; při aktualizaci se zamyká záznam vlastníka. Nový soubor se při selhání
uklidí, předchozí až po commitu. U mediálních změn služba odmítá cizí vnější
transakci, protože musí vlastnit okamžik commitu. Schéma ani publikované migrace
se v této etapě nemění.

## Klientské závislosti a velikost

Asset manifest může deklarovat pasivní pojmenovanou schopnost (`id`, `routes: []`)
a spotřebitel ji vybere přes `uses`. Loader kontroluje kolize, chybějící závislosti
a cykly uvnitř právě aktivního katalogu. Výběr zahrnuje skripty, komponenty,
styly i překlady. Core nabízí `publishing.editor`, `publishing.media`
a `publishing.presentation`. Blog tím nepřibírá sociální feed ani sociální controller.

Limity společných scopes zůstaly stejné. Pouze `/admin/articles` dostává limit
249 856 B raw JS a 57 344 B gzip místo 223 232 / 51 200 B: nově načítá společný
editor a práci s obrázky, které původní textarea neobsahovala. Naměřený stav při
zavádění byl 246 055 / 55 941 B; CSS zůstalo pod původním limitem. Pozdější
drobné změny a výsledné limity ověřuje přímo test distribučního superset buildu.
Serverový body renderer článku se neposílá do klientského katalogu, dokud ho
klientský tok skutečně nepotřebuje.

Při rozdělení stylů se zachovalo všech 759 původních pravidel, včetně jejich počtů,
a přibylo jedno pro text článku. Pořadí skupin core/Social je nyní zachycené novou
referencí kaskády. Názvy stávajících CSS tříd a datových atributů zůstaly kvůli
kontraktům zachované; nejde o další sociální implementaci uvnitř core.

## Ověření

Stávající Blog fixture nově pokrývá blokování bez Socialu, izolaci normalizace,
RSS/oEmbed a jejich viditelnost/origin, autorizované odchozí odkazy, společné
HTML komponenty, multipart obrázek, rollback náhrady a odstranění po commitu.
Souběh kontroluje oba oEmbed poskytovatele a nezapočítávání článků do reputace.
Registrační test ověřuje lazy embed callbacky; resource test pojmenované klientské
závislosti, chybějícího vlastníka, cyklus a konflikt. Počet hlavních skupin zůstává
88 a frontendových scénářů 38.

První preflight zastavil test, který ještě předpokládal sociální návrhy v každém
editoru. Test nyní předává explicitní politiku. Další korekce zahrnuly referenci
kaskády a starý payload blokovaných URL, jehož vlastníkem je nyní Publishing.
Navazované běhy neopakují všechny již úspěšné skupiny. Závěrečný souhrn pokrývá
všech 88 hlavních skupin, 38 frontendových scénářů a 7 vstupních kontrol preflightu.
Jediný přeskok je integrace Memcached, protože místní server není dostupný.
Jde o preflight a navazující běhy po opravách, nikoli o jeden nepřerušený zelený běh.

Součet posledních úspěšných měření je **14 min 14,382 s**; součet všech měřených
pokusů včetně oprav je **22 min 35,865 s**. Jde o součty dílčích časů, nikoli
celkovou dobu práce; samostatně spuštěné kontroly mimo runner se do nich nepočítají.
Prošly také závěrečný PHP lint, style, PHPStan, hranice modulů, závislosti,
překlady, UI a modal kontrakty. Poslední Blog scénář ověřil i obrazová metadata
OG/JSON-LD, izolované databázové scénáře a nativní UI. Nový univerzální runner
ani další úplná kopie integrační sady nevznikly.

Podrobné výsledky a časy jsou v ignorovaném
`storage/social-core-stage2-20261113/verification.json`; jednotlivé reporty zůstávají
v `storage/test-runs/`.

## Nasazení na tinycat.test

Nasazeno přes společný deployment lock: 221 ověřených změn runtime souborů,
z toho 8 odstranění. Záloha je mimo repozitář:
`E:/laragon/backup/tinycat-core-stage2-20261113-203935.zip`.
Verze zůstává `6.0.0-dev.6`; nový distribuční release se nevydával.

Prošlo 58 HTTPS kontrol včetně článků, RSS, JSON/XML oEmbed, odmítnutí cizího
originu a neplatného odchozího odkazu. Blog i Social zůstaly aktivní a správce
modulů dostupný. Živý smoke byl anonymní; autorizované zápisy a práci editoru
ověřovaly izolované testovací instalace.

Kontrola před a po nasazení potvrdila stejných 11 633 publikací, 38 účtů,
9 141 mediálních souborů a 27 tabulek, shodný hash obsahu publikací, inventář
médií, konfiguraci i metadata aktivace. Režim údržby je vypnutý. Schéma a data
se nemigrovaly. Detaily jsou v `deployment-verification.json` vedle souhrnu testů.

Stávající konfigurace inzeruje kanonické URL článku s `http://tinycat.test`.
oEmbed smoke proto předává tuto inzerovanou URL; samotné požadavky běží přes
HTTPS. Konfigurace ani striktní kontrola originu se kvůli testu neměnily.

## Zbývající etapy

Diskuse, reakce, jejich oprávnění a předání vlastnictví tabulek jsou samostatná
etapa 3. Závěrečné ověření a posouzení testovacích nákladů patří do etapy 4.
