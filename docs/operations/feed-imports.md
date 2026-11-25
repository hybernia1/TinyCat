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
