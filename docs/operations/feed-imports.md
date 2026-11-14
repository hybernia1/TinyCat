# Import článků a statusů

Blog nabízí **Zdroje článků** na `/admin/article-sources`. Jeho zdroje jsou
nezávislé na Socialu a nepotřebují botí účet. Správce při vytvoření vybere
aktivního administrátora jako autora, veřejnou HTTP(S) URL feedu, interval
kontroly 5 až 43 200 minut, výslednou viditelnost a případný import obrázků.
Výchozí viditelnost je **skryté**; zveřejnění musí správce výslovně zvolit.

Náhled ve formuláři připraví až tři nejnovější články stejnými pravidly jako
import. Nezapisuje publikace, GUID historii ani plánování zdroje. RSS a Atom
upřednostňují plný `content:encoded` nebo `content`; JSON Feed používá
`content_text` nebo `content_html`. Krátký popis zůstává zvlášť pro statusy.
Článek je prostý text, nejvýše 60 000 UTF-8 bajtů, s titulkem do 200 znaků.
Odkaz na původní položku se doplní, pokud již v těle není.

Ikona spuštění v řádku provede ruční import přes společný scheduler. Úloha
`article_feeds` je také ve správě plánovače a ve standardním běhu všech úloh.
Při plánovaném spuštění vybírá jen zapnuté zdroje, které dosáhly svého intervalu,
s aktivním autorem se správcovským oprávněním. Výsledky a jednotlivé odmítnuté
položky jsou v persistentní historii Core/Tasks; poslední chyba je také v seznamu.

```powershell
php App/Console.php task article_feeds
php App/Console.php task article_feeds source_id=1
```

CLI spusť v instalaci, jejíž data chceš zpracovat. Na místním testovacím webu
je to `E:/laragon/www/tinycat`, nikoli zdrojový repozitář. Podporované volby
ověř přes nápovědu CLI. Specifický zdroj používá stejný zámek a historii jako
běžný běh; neexistuje samostatný importní runner.

Změna uloženého zdroje zvýší jeho revizi. Již rozpracovaný import po změně,
vypnutí či odstranění zdroje nesmí zapsat článek pod starými pravidly.
URL uloženého zdroje nelze měnit: jiný feed vytvoř jako nový zdroj.
Odstranění zdroje odstraní jeho historii a vazby, zachová importované články.
Smazání autora uklidí jeho zdroje i publikace databázovými vazbami, také když
je Blog neaktivní. Vypnutí modulu data nemaže.

Každý úspěšný GUID se zapisuje ve stejné transakci jako článek a vazba na zdroj.
Odmítnutí obsahovou politikou ani technická chyba GUID nespotřebuje; po opravě
je možné položku opakovat. Blog uchovává úspěšné GUID do odstranění zdroje.
Doplňková URL deduplikace hledá jen články téhož autora, včetně skrytých.
Social zachovává svou bounded historii, botí identity a interval publikování
v rámci úlohy `feeds`. Stejný feed tedy může sloužit současně oběma modulům.

Příprava obrázků a odkazových metadat proběhne před transakcí. Neúspěšný zápis
vrátí publikaci i GUID a odstraní nově stažený obrázek. Na všechna stažení
platí společné síťové kontroly proti SSRF a obsahová URL politika.
