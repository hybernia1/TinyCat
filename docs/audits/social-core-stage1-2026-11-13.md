# Etapa 1: samostatný veřejný framework

Datum: 13. 11. 2026. Navazuje na [audit Socialu](social-core-capabilities-2026-11-12.md)
a [čtyřetapový plán](../plans/social-core-capabilities.md).

## Změna vlastnictví

Core/Ui připravuje společný dokument, head a metadata, veřejnou hlavičku,
jazykové menu, souhlas s analytikou a globální hledání. Social vlastní své téma,
sidebar a obsah. Blog používá společný rám a dodává metadata článku včetně Article JSON-LD.
Core/Runtime řídí společnou HTTP politiku, chyby a fallback bez databáze.

Locale routing je v Core/Http. Aktivní vlastníci přispívají `LocalizedRoute`:
cesta, povolené query parametry a informace, zda se překládá samotný dokument.
Tentýž seznam cest dostává klient. Přepnutí jazyka zachová článek i kurzor archivu;
nekopíruje libovolné query parametry. Kanonický článek zůstává bez jazykového prefixu.

Privacy a LLM dokumenty hostuje Core/Ui/Information. `InformationSection` připojuje
lazy příspěvky vlastníků. Sociální podmínky, reputační přehled a vzorek statusů
se zobrazují jen při aktivním Socialu. Technický dashboard a diagnostika cache
patří Core/Ui/Admin; `DashboardWidget` připojuje sociální statistiky.
Endpoint `/api/site/cache-resets` zůstává stejný, jeho implementace je v core.

Čisté delegující třídy Identity/Publishing byly odstraněny ze Socialu a volající
převedeni na kanonické implementace. Historická jména používaná publikovanými
migracemi mapuje LegacyNames; migrační soubory a checksumy se nemění.
Odstraněné runtime cesty jsou zahrnuté do mazacího manifestu aktualizací.

## Rozsah ověření

Stávající Blog fixture pokrývá Blog bez souborů Socialu, souběh, samostatné
vypínání obou modulů i framework bez funkčních modulů. Byly doplněny jazykové
odkazy článku a archivu, metadata, privacy/LLM dokument, technický dashboard,
chyby HTML a assetů, autorizace/CSRF cache resetu, HEAD a nedostupná DB.
Fallback se ověřuje i před připojením databáze. Registrační test kontroluje lazy
callbacky, vlastnictví a konflikty nových příspěvků. Počet hlavních skupin zůstává 88.

## Výsledky a měření

Ověření je dokončené napříč všemi 88 hlavními skupinami, včetně všech 38
frontendových scénářů. Jediná přeskočená hlavní skupina je Memcached, který
v prostředí není dostupný. Kontroly Composeru, bezpečnosti, překladů, lint,
stylu a PHPStan prošly; rovněž kontrakty UI/modalů, hranice modulů a asset budgets
bez zvýšení limitů. Publikované migrace jsou byte-identické s počátečním snapshotem.

První `composer preflight` se zastavil na zastaralém očekávání vlastníka překladů.
Následující běhy navazovaly od neúspěšného místa, již úspěšné části se plošně
neopakovaly. Opravy zahrnuly testovací odkazy na odstraněné fasády, očekávání
rozděleného dashboardu, zachování instalační readiness politiky, privacy JSON-LD
a pevný endpoint CAPTCHA ve sdíleném hledání. Jeden běh zastavil vypnutý Laragon;
po jeho spuštění uživatelkou se použily stejné služby. Nejde o jeden čistý zelený
preflight; úplný rozsah dokládá agregace jednotlivých měřených běhů.

V sebraných reportech je součet posledních úspěšných kontrol **1 264,233 s
(21 min 4 s)**, součet všech měřených pokusů těchto kontrol **1 605,233 s
(26 min 45 s)**. Jde o součty časů kontrol, nikoli čas od zahájení práce;
část běhů probíhala souběžně. Přímé cílené kontroly mimo měřené runnery tyto
součty nezahrnují. Původní logy, neúspěšné pokusy, rozsah a odkazy na reporty
zachovává [souhrn ověření](../../storage/social-core-stage1-20261112/verification.json).

| Nejdražší dokončené kontroly | Sekundy |
| --- | ---: |
| Podepsaný lifecycle modulů | 237,283 |
| Update a rollback | 188,925 |
| Dotyková matice popoverů | 135,129 |
| Blog, souběh a nezávislé vypínání modulů | 102,859 |
| HTTP API kontrakty | 73,356 |
| Výběr resources a shoda kompilace | 36,241 |

Výsledky slouží dalšímu posouzení testovacích nákladů; v této etapě se skupiny
nerušily a nepřibyla další úplná integrační kopie.

## Nasazení na tinycat.test

Na externí instalaci `E:/laragon/www/tinycat` bylo přes společný deployment zámek
přeneseno 170 změněných runtime/kompilovaných souborů, včetně explicitního odstranění
38 nahrazených cest. Záloha je
`E:/laragon/backup/tinycat-core-stage1-20261113-005721.zip`.
Verze zůstává `6.0.0-dev.6`; nevydával se nový balíček.

Před nasazením už byly aktivní Blog i Social. Aktivace ani její metadata se neměnily.
Přes ověřené HTTPS prošlo 52 anonymních kontrol včetně článku v jiném jazyce,
privacy, LLM sekcí Socialu, chyb, přístupových omezení a skutečných assetů.
Přihlášené interakce se ověřovaly v izolovaných browser fixtures, nikoli účtem
živé instalace. Blog-only i core-only režim pokrývají izolované integrační testy.

Po nasazení i HTTPS smoke zůstaly shodné počty všech 27 tabulek, obsah 11 633 publikací,
38 účtů, konfigurace a všech 9 141 médií. Kontrolní součty změněného runtime souhlasí,
maintenance není aktivní. [Protokol nasazení](../../storage/social-core-stage1-20261112/deployment-verification.json).

## Zbývající práce

Etapy 2–4 zůstávají otevřené: sdílená prezentace Publishing a bezpečnostní politiky,
diskuse/reakce a závěrečné ověření lifecycle. Tato etapa neřeší započítávání článků
do sociální reputace ani sociální registraci blokovaných URL; to patří do etapy 2.
