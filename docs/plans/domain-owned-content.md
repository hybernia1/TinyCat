# Doménová data bez povinného Publishingu

Schválený směr: TinyCMS je obecný aplikační základ. Identita, autentizace,
oprávnění a technická infrastruktura zůstávají core. Publikace, komentáře,
reakce, taxonomie a evidence médií patří jejich funkčním modulům.
Tento dokument je implementační plán, nikoli popis dokončeného runtime.

## Výsledná hranice

- Blog vlastní `blog_articles` s vlastním ID, autorem, plným textem, perexem,
  stavem a daty. Dále vlastní své komentáře, reakce, obrázky, odkazy a zdroje.
- Social vlastní statusy a jejich vztahy, komentáře, reakce, tagy, odkazy,
  obrázky, profily a automatizaci. Nemá povinnou vazbu na Blog.
- Core neobsahuje univerzální tabulku `content` ani povinné publikační DDL.
  Sdílené UI, transakce, upload/konverze souboru, HTTP, parsování textu/feedu
  a plánovač jsou mechanismy bez závislosti na doménových tabulkách.
- Žádný `entity_type + entity_id` nepřekryje skutečné cizí klíče. Domény mají
  vlastní ID prostory; shodné číslo článku a statusu neznamená tentýž záznam.

## Jeden rebuild, interní pořadí

1. Zaznamenat zdrojový a datový výchozí stav. Zmapovat vlastníky a čtenáře
   všech dosavadních publikačních tabulek a jejich souborů.
2. Připravit kompatibilitu updateru se změnou povinného frameworku a explicitním
   instalačním schématem. Historické migrace zůstanou byteově neměnné; fresh
   instalace nesmí kvůli jejich přehrání vytvářet zrušené sdílené tabulky.
3. Oddělit technické služby od SQL a provozních politik. Feedový runner nesmí
   přímo ukládat odkazy či obrázky do cizích tabulek; připraví je jeho vlastník.
4. Převést Blog i Social na vlastní tabulky, repository a inicializaci. Převést
   hledání, sitemap, exporty, importy, notifikace, správu účtů, UI a assety.
5. Novými explicitními migracemi převést data a vazby. Zachovat ID, URL,
   rodiče komentářů, reakce, historii editací, zdroje a cesty souborů.
   Vypnutý nainstalovaný modul se také musí bezpečně převést.
6. Upravit architektonická pravidla, manuály, buildy a dosavadní testy.
   Nepřidávat další úplnou kopii integrační matice.
7. Na konci změřit celý rozsah preflightu, ověřit upgrade/rollback na kopii
   skutečného schématu a teprve potom nasazovat na tinycat.test se zálohou.

## Aktualizační kompatibilita

Runtime dev.9 ověřuje přesně vyjmenovaný povinný framework v descriptoru
a fresh schéma rekonstruuje přehráním celé historie. Nový datový model musí
mít předem připravenou kompatibilní aktualizační cestu; samotná změna registru
služeb by rozbila aktualizaci běžící ve starém PHP procesu.

Přípravná kompatibilita nesmí měnit živá doménová data. Oddělí validaci
nového formátu od současného běhu a poskytne explicitně podepsaný fresh
instalační soubor. Bez něj by historická migrace Blogu stále vyžadovala
starou `content` tabulku i v nové čisté instalaci.

## Kritéria dokončení

- Core bez modulů vytváří pouze účtové a provozní tabulky.
- Blog funguje fyzicky bez Socialu a jeho tabulek, Social bez Blogu.
- Souběh, deaktivace, opětovná aktivace a odstranění účtu zachovají izolaci.
- Článek ani jeho komentář se nemůže zpracovat jako sociální status.
- Upgrade je ověřený pro starší i současný modulový deployment; rollback
  obnovuje soubory, schéma, historii a aktivaci společně.
- Historické migrační checksumy a veřejná URL zůstávají zachované.
