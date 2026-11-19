# Produktové stránky a hranice prezentace v 6.1.4

Navazující etapa převádí osm PHP šablon: správu modulů, diagnostiku, dvě části
updateru, hledání, core soukromí, sociální informační sekci a consent. Společně
s předchozí etapou odstraňuje release sedmnáct duplicitních prezentačních souborů.

Strukturu vlastní deset katalogů se 190 komponentami. Zůstává deset PHP šablon:
společný dokument a head, veřejné a administrační layouty, chybové dokumenty,
flash transport, lazy sidebar slot a samostatný embed dokument. To je zvolená
hranice této etapy; nové šablonovací jazyky ani soubory pro jednotlivé komponenty
nepřibývají. Serverové stránky se nepřibalují do klientských katalogů.

PHP připravuje prezentační modely: názvy stavů, formátované metriky, historii
a podmínky akcí. Kontrola dostupnosti aktualizace, kompatibility a oprávnění,
aktivace modulů i zápisové endpointy zůstávají u původních vlastníků. Katalogy
zachovávají CSRF, potvrzení a DELETE transport ukončení údržby.

Regrese ověřují instalační akci ve stavech dostupnosti, kompatibility a rozšíření,
historické názvy migrací, escapování chyb a prázdnou diagnostiku. Parita PHP/JS
pokrývá hledání, consent, aktivaci modulu a akce updateru. Při převodu byl opraven
neexistující překlad tlačítka stránkování hledání použitím stávajícího společného
popisku. Generovaný UI inventář byl obnoven.

Živá kontrola používá `https://tinycat.test/` po zálohované synchronizaci pouze
runtime souborů a kompilovaných assetů. Nekonfiguruje web, neaktivuje moduly
a nespouští instalaci ani zápisové aktualizační akce. Aktuální historické nálezy
diagnostiky, například lokální TLS chyba a starý běh plánovače, nejsou výsledkem
této prezentační změny.

Živé povrchy byly ověřeny na mobilní a desktopové šířce. Mobilní kontrola
odhalila roztažení stránky soukromí tabulkou limitů; lokální kontejner nyní drží
šířku stránky a tabulka se posouvá samostatně. Nativní regrese ověřuje skutečné
rozměry a posun tabulky, nikoli kopii CSS hodnot. Předchozí pořadí CSS pravidel
zůstalo stejné, do kontrolovaného cascade baseline přibylo jedno lokální pravidlo.

Závěrečné zdrojové a artefaktové ověření bude evidováno u vydání 6.1.4.
