# Import feedů

Současný Social modul umožňuje správcům připojit RSS, Atom a JSON Feed zdroje k botím
účtům. Náhled zdroje nepřidává publikace, nezaznamenává GUID ani nemění plán.
Plánovaný i ruční import používá společný runner Core/Tasks a úlohu `feeds`.
Zdroje mají vlastní interval kontroly a interval publikování; průběh a chyby
jsou dostupné v administraci a historii úloh.

Úspěšný zápis statusu a jeho GUID je atomický. Odmítnutá položka ani technická
chyba GUID nespotřebuje. Přílohy a odkazová metadata se připravují před zápisem;
síťové požadavky podléhají společným kontrolám. Podrobnosti plánování a příklady
spouštění jsou v [README](../../README.md#bots).

Blog a jeho zdroje článků nejsou součástí současných zdrojů. Historické releasy
a audity zachycují jejich tehdejší chování.

Core/Feeds obsahuje parser vstupních formátů. Parser vrací text
a původní HTML (`description_html`, `content_html`) bez redakčního čištění.
Pole HTML jsou nedůvěryhodná data, nikoli připravený obsah pro přímé vykreslení.
Social/Bots vlastní importní průchod, stav zdrojů, WordPress obohacování
a převod anotace do textu příspěvku. Kategorie parser vrací jako data;
na hashtagy je převádí až Social.

`Social/Bots/Importer` řídí průchod importem a používá konkrétní `FeedStore`
a `FeedPublication`. Příprava vrací přímo `Social/Posts/PostCreation`, stejný
objekt jako ruční publikování. Mezi importem a publikací není druhý obal
příspěvku ani jednorázová rozhraní úložiště a cíle.

HTML je pouze dočasný vstup parseru a importní extrakce v paměti. Publikace
předá finální prostý text do `PostCreation::fromText()`; SQL writer ukládá
výhradně připravené `body`. Odkazy a přílohy zapisuje odděleně. Náhled používá
stejnou přípravu, ale nevolá zápis ani claim GUID. Tato hranice nevyžaduje
automatické čištění existujících uživatelských dat.

XML enclosure a jeho `xml:base` zpracovává `Core/Feeds/FeedParser::audioEnclosure()`.
`Core/Links/Audio` nadále vlastní obecné ověření audio URL; jeho stará veřejná
metoda `enclosure()` zůstává kompatibilním delegátem. `FeedParser::text()` také
zůstává dostupný, ale obecné zpracování chyb používá přímo `Core/Text/Text`.
Při ručním nasazení přenášejte společně Text, FeedParser, Metadata a související
Social konzumenty: nové volání `htmlText()` a rozšíření `parseDocument()` vyžadují
odpovídající verzi core. Publikované migrace a staré snapshoty se nemění.
