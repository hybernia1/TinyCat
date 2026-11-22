# Import feedů

Současný Social modul umožňuje správcům připojit RSS a Atom zdroje k botím
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
