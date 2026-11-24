# Jednotná pravidla textu příspěvků

Tělo nového i upravovaného sociálního příspěvku připravuje `Social/Posts/PostCreation::fromText()`.
Ruční zadání, API, náhled bota a import používají stejnou přípravu vlastníka.
`Core/Text/PostText::normalize()` poskytuje obecnou normalizaci bez odstraňování
redakčních dovětků. Social výslovně vybírá `cleanImportBoilerplate()` přes
`TextPolicy::prepare` před omezením délky a validací; následné rozpoznání zmínek
zůstává v jeho `TextPolicy::normalize`. Současné chování Socialu se tím nemění,
ale jiný druh textu či vlastník čištění nepřebírá automaticky. Pravidla níže se
nevztahují na komentáře, životopisy ani jiná textová pole.

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

RSS/Atom/JSON parser pro své anotace výslovně vybírá stejné volitelné čištění
a navíc odstraňuje HTML odkazy s přesným popiskem výzvy
(včetně krátkých `More` a `Více`), zachovává mezery mezi HTML bloky a používá
stejná pravidla pro výslednou anotaci. Dosavadní pravidlo pro odkazy v anotaci
zůstává: obecné URL nahradí samostatný odkaz článku, podporovaná videa zůstávají.
Toto pravidlo parseru se nepoužívá na ručně napsaná těla příspěvků.

## Limity ručního zadání a importu

Social deklaruje limit těla v `PostCreation::rules()`. Ruční vytvoření a editace
validují původní vstup společným validátorem před přetypováním a přípravou textu.
Neplatný typ nebo více než 2000 Unicode znaků vrátí HTTP 422 s chybou pole `body`;
editor zachová celý koncept. Prázdné tělo je přípustné při přiloženém obrázku.
Metadata editoru odvozuje `Validator::constraints()` ze stejných pravidel.

Feedový import používá `PostCreation::fromText()` a zachovává dosavadní zkrácení
anotace na limit vlastníka před odvozením vztahů. Komentáře mají vlastní pravidla
v `Interactions::rules()` a při překročení limitu chybu pole `comment`.

## Transaction and publication result

Edits lock the current post and image reference, then recheck existence, kind,
ownership/authorization and the text-or-image invariant. A previously loaded
image is not a write input. Deletion takes the same parent lock before reading
cleanup references. Link and image preparation happens before the transaction;
superseded files are deleted after the outer commit, new files on rollback.

Manual creation and editing persist inbox mentions atomically with the post.
Email runs after commit. Delivery, moderation-counter and result-projection
failures are diagnosed without reporting a committed write as failed. The
response retains its success code and post ID; unavailable projections can be
`null`. Imports keep their own policies and the shared transaction for the post,
GUID history and source mapping, without adopting manual notifications or quotas.

## Jednorázová oprava databáze

Nástroj je pouze pro CLI, není migrací a nespouští se automaticky:

```sh
php tools/maintenance/post-text-cleanup.php plan /private/path/post-text-plan.json
php tools/maintenance/post-text-cleanup.php apply /private/path/post-text-plan.json
php tools/maintenance/post-text-cleanup.php restore /private/path/post-text-plan.json
```

Plán obsahuje původní i navržené tělo, ID, autora a čas publikace. Musí být uložen
mimo veřejný web, obsahuje i neveřejné a naplánované příspěvky. Existující soubor
se nepřepíše. Před zápisem se kontrolují konkrétní změny. Zápis je transakční,
uzamyká řádky a odmítá souběžné úpravy; při konfliktu vrátí celou dávku zpět.
Obnova stejně kontroluje aktuální těla, aby nepřepsala pozdější editace.

Oprava zpracovává příspěvky všech autorů. Mění jen `social_posts.body`, bez přepočtu
časů, stavů moderace, médií, vztahů a historie importu. Případy, kde by výsledné
tělo bylo prázdné nebo se změnily rozpoznané odkazy, hashtagy či zmínky, plán
označí jako přeskočené. Nejsou automaticky opraveny. Opakovaný náhled po opravě
ověří, že nezůstaly další bezpečné změny.

Ověření: `php tests/domains/status/post-text.php`, `php tests/domains/status/write-path-integrity.php`,
`php tests/domains/bots/run.php` a společná sada `php tests/run.php`.
