# Jednotná pravidla textu příspěvků

Tělo nového i upravovaného příspěvku připravuje `Status/PostCreation::fromText()`.
Ruční zadání, API, náhled bota a import používají stejný `Status/PostText` před
omezením délky a rozpoznáním odkazů, zmínek a hashtagů. Pravidla se nevztahují
na komentáře, životopisy ani jiná textová pole.

Stejné čištění se používá pro popis karty odkazu při ukládání metadat i při
zobrazení již uloženého náhledu. Starší cache tak nevrací odstraněné dovětky.

- Konce řádků jsou LF. Na řádku je mezi slovy jedna mezera; tabulátory a pevné
  mezery se sjednotí, mezery na krajích řádků se odstraní.
- Zachovají se jednotlivé řádky a odstavce. Mezi odstavci je nejvýše jeden
  prázdný řádek. Mimo URL se odstraní neviditelné U+200B a U+FEFF; emoji včetně ZWJ zůstávají.
- Značky zkrácené anotace `[…]` a `[...]` se sjednotí na `…`. Chybějící text
  se nedoplňuje a poslední neúplná věta se nemaže.
- Samostatné výzvy `Read more`, `Continue reading`, `Keep reading`, `Číst dál`,
  `Číst dále`, `Číst více`, `Přečíst více`, `Přečíst celý článek`, `Celý článek`
  a `Pokračovat ve čtení` se odstraní. Platí i pro koncovou výzvu po tečce,
  otazníku, vykřičníku či výpustce a pro výzvu před URL. Běžná věta jako
  „I want to read more about fiction.“ zůstává.
- Koncový automatický dovětek `The post … appeared first on …` se odstraní.
- URL, jejich parametry, fragmenty, hashtagy a zmínky se zachovávají. Text se
  nepřekládá, neopravuje stylisticky a nevynucuje se nová šablona odstavců.

RSS/Atom/JSON parser navíc odstraňuje HTML odkazy s přesným popiskem výzvy
(včetně krátkých `More` a `Více`), zachovává mezery mezi HTML bloky a používá
stejná pravidla pro výslednou anotaci. Dosavadní pravidlo pro odkazy v anotaci
zůstává: obecné URL nahradí samostatný odkaz článku, podporovaná videa zůstávají.
Toto pravidlo parseru se nepoužívá na ručně napsaná těla příspěvků.

## Jednorázová oprava databáze

Nástroj je pouze pro CLI, není migrací a nespouští se automaticky:

```sh
php tools/post-text-cleanup.php plan /private/path/post-text-plan.json
php tools/post-text-cleanup.php apply /private/path/post-text-plan.json
php tools/post-text-cleanup.php restore /private/path/post-text-plan.json
```

Plán obsahuje původní i navržené tělo, ID, autora a čas publikace. Musí být uložen
mimo veřejný web, obsahuje i neveřejné a naplánované příspěvky. Existující soubor
se nepřepíše. Před zápisem se kontrolují konkrétní změny. Zápis je transakční,
uzamyká řádky a odmítá souběžné úpravy; při konfliktu vrátí celou dávku zpět.
Obnova stejně kontroluje aktuální těla, aby nepřepsala pozdější editace.

Oprava zpracovává příspěvky všech autorů. Mění jen `content.body`, bez přepočtu
časů, stavů moderace, médií, vztahů a historie importu. Případy, kde by výsledné
tělo bylo prázdné nebo se změnily rozpoznané odkazy, hashtagy či zmínky, plán
označí jako přeskočené. Nejsou automaticky opraveny. Opakovaný náhled po opravě
ověří, že nezůstaly další bezpečné změny.

Ověření: `php tests/post-text.php`, `php tests/write-path-integrity.php`,
`php tests/bots/run.php` a společná sada `php tests/run.php`.
