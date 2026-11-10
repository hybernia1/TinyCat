# Zpětné přiřazení RSS zdrojů

Samostatné SQL skripty pro MySQL/Percona 8.0+. Dotazy používají aktuálně vybranou
databázi; neobsahují přihlašovací údaje. Skripty 00–10 pouze čtou data.

## Co je považováno za shodu

- Příspěvek patří uživateli s rolí `bot` a kandidátní feed patří stejnému botovi.
- Používají se uložené odkazy `content_links` → `links.normalized_url`, nikoli
  volná zmínka domény v textu.
- Hostitel se převede na malá písmena a odstraní se úvodní `www.`, port a koncová
  tečka. Ostatní subdomény zůstávají odlišné. `example.com.evil.test` není shoda.
- Automatický návrh vyžaduje právě jednu doménu odkazů a právě jeden feed.
  Více feedů stejného bota na stejné doméně se nepřiřadí podle nejnižšího ID.
- Pro Steam existuje oddělené pravidlo: `/groups/<skupina>/rss/` musí odpovídat
  `/groups/<stejná-skupina>/announcements/…`. Parametry URL ani částečné názvy
  skupin se nepoužívají. Číselné adresy `/gid/…` zůstávají nevyřešené.

Jednoznačný kandidát podle těchto pravidel není důkaz historického původu.
Před zápisem je potřeba projít seznam feedů a vzorek návrhů, zejména pokud byly
feedům v minulosti měněny adresy nebo byly některé zdroje odstraněny.

## Postup

1. `00_inventory.sql`: ověřit správnou databázi, počty a existenci cílové tabulky.
2. `01_summary.sql`: počty podle jistoty shody.
3. `02_unique_candidates.sql`: konkrétní dvojice `content_id` / `source_id`
   s důkazní doménou, adresou feedu a ukázkou příspěvku. Před zápisem exportovat
   tento seznam z HeidiSQL jako kontrolní podklad.
4. `03_review_ambiguous.sql`, `04_unmatched_domains.sql`, `06_feed_collisions.sql`:
   nejasné případy, chybějící domény a kolize feedů.
5. `07_steam_group_candidates.sql`, `08_steam_group_summary.sql`: oddělené návrhy
   a počty podle přesné Steam skupiny; exportovat i tyto konkrétní dvojice.
6. `09_apokalypsa_aliases_to_review.sql`: návrhy několika konkrétních aliasů
   RSS/webové domény pro databázi `apokalypsa`. **Nejsou zahrnuté v žádném zápisu.**
   Ověřit jejich věcnou správnost jednotlivě. Skript navíc kontroluje ID feedu,
   jeho očekávanou doménu a vlastníka, takže se pravidlo nerozšíří na jiné feedy.
   `10_combined_summary.sql` pak změří všechny skupiny jediným SELECTem bez
   překryvů a se společným okamžikem čtení. Je vhodný jako závěrečný přehled.
7. Po instalaci TinyCat 5.6.0 spustit `05_verify_known.sql`. Předchozí SELECTy
   mohou fungovat i bez nové tabulky `bot_source_posts`.
8. Teprve po kontrole návrhů a záloze lze samostatně použít
   `90_apply_unique_dry_run.sql` a `91_apply_steam_groups_dry_run.sql`.
   Oba dočasně vkládají vazby a **výchozí poslední příkaz je `ROLLBACK`**.
   Do produkce se v této úloze nespouštěly. Trvalý zápis vyžaduje vědomou změnu
   posledního příkazu na `COMMIT`. Při chybě nejprve provést `ROLLBACK`.

Zápisové skripty zachovávají existující vazby a mění pouze `bot_source_posts`.
Nemění text, autora, čas publikace, GUID historii ani definice feedů. Opakované
spuštění již přiřazené příspěvky přeskočí; případný souběžný konflikt vyvolá chybu
místo přepsání cizí vazby. Neprovádějí žádné přiřazení doménových aliasů.

Po doplnění vazeb budou následné importy navazovat také na dříve naplánované
příspěvky nově přiřazené ke konkrétnímu feedu. Jejich uložené časy se nepřepočítávají.

## Zjištění na produkci 2026-10-29

Kontrola proběhla přes HeidiSQL v databázi `apokalypsa`, Percona 8.0.46-37.
Provedeny byly pouze SELECTy, žádná migrace ani doplnění vazeb.

| Výsledek závěrečného souhrnného auditu | Příspěvky |
| --- | ---: |
| Jedna doména, jeden feed stejného bota | 9 184 |
| Přesná cesta Steam skupiny | 153 |
| Návrhy doménových aliasů vyžadující ověření | 288 |
| Doména neodpovídá feedu ani navrženému aliasu | 130 |
| Více kandidátních feedů, cesta nerozlišila zdroj | 44 |
| Více domén v odkazech | 9 |
| Chybí použitelná doména odkazu | 1 |
| Celkem příspěvky botů v jednom okamžiku čtení | 9 809 |

Úvodní inventura ukázala 9 810 příspěvků celkem, 9 802 příspěvků botů a 50 feedů.
Data se během kontroly změnila; závěrečný souhrn výše používá jeden SELECT,
aby se nesčítaly počty z různých okamžiků. Cílová tabulka
`bot_source_posts` při kontrole ještě neexistovala, takže před zápisem je nutná
migrace z verze 5.6.0. Počty jsou okamžikový snímek; další importy je mohou změnit.

Ze 197 původních kolizí se přesnou cestou Steam skupiny rozlišilo 153 příspěvků:
feed 12: 51, feed 28: 34, feed 29: 68. Základní a Steam návrhy se nepřekrývají;
jejich závěrečný součet je **9 337 příspěvků (95,19 %)**.

Návrhy aliasů: feed 55 → `modrak.opinio.cz`: 100; feed 36 →
`darknetdiaries.com`: 99; feed 49 → `novinky.cz`: 67; feed 59 → subdomény
`mobilmania.zive.cz`, `vtm.zive.cz`, `doupe.zive.cz`, `avmania.zive.cz`: 22.
Pokud se všechny tyto konkrétní vazby potvrdí, pokrytí by bylo 9 625 / 9 809
(98,12 %). **Jsou to návrhy, nikoli ověřené historické přiřazení**, a nejsou
součástí zápisových skriptů. Dalších 184 případů zůstává k ručnímu posouzení.

## Provedené doplnění na produkci 2026-10-29

Po aktualizaci a ověření cílové tabulky bylo v databázi `apokalypsa`
transakčně doplněno **9 330 vazeb**: 9 177 podle jednoznačné domény a 153
podle přesné cesty Steam skupiny. Původních 12 vazeb zůstalo zachováno;
po COMMIT bylo ověřeno celkem 9 342 vazeb a 472 nepřiřazených příspěvků botů.
Doménové aliasy nebyly použity. Kontrola před COMMIT potvrdila nulový počet
chybějících plánovaných vazeb, změněných původních vazeb i změn textů,
autorů, zdrojových feedů nebo časů publikace sledovaných příspěvků.

V produkci byly ponechány tabulky `tinycat_feed_backup_20261029_01`
(12 původních vazeb) a `tinycat_feed_plan_20261029_01` (9 330 doplněných
vazeb včetně pravidla přiřazení a kontrolních hodnot). Jde o auditní podklad;
aplikace je nepoužívá. Názvy tabulek této dávky znovu nepoužívat.

## Lokální ověření skriptů

### Druhá produkční dávka po potvrzení vlastníkem

Dne 2026-10-29 bylo doplněno dalších 337 vazeb: darknetdiaries (36) 104,
modrak (55) 100, novinky krimi (49) 67, Živě (59) 22, indiegala steam (28)
40 a Steam free / GrabFreeGames (12) 4. Vlastník výslovně potvrdil doménové
aliasy; u Steamu byly načteny všechny tři RSS feedy a ověřeny názvy skupin.
Rozšířené pravidlo přijímá přesný prefix `/groups/<slug>/`, včetně `/events/`,
a vyžaduje jediný odpovídající zdroj stejného bota i při více odkazech.

Záloha `tinycat_feed_backup_20261029_02` obsahuje 9 343 původních vazeb,
plán `tinycat_feed_plan_20261029_02` obsahuje 337 nových. Před COMMIT byly
ověřeny všechny nové i původní vazby. U příspěvku 9287 se souběžně změnil čas
publikace z 2026-10-30 04:50:35 na 2026-10-29 20:43:54; autor, text i feed
zůstaly stejné. Vazba byla následně vložena s kontrolou nového času, bez
úpravy příspěvku. Po COMMIT: 9 680 vazeb celkem, 135 nepřiřazených příspěvků
botů. iDNES a odstraněné zdroje zůstaly bez zásahu.

`php tests/tools/bot-source-attribution.php` používá izolovanou testovací MySQL
databázi. Ověřuje normalizaci hostitelů, omezení na stejného bota, kolize feedů,
více domén, Steam cesty, nezměněné časy publikace a skutečné vrácení obou
zápisových skriptů přes `ROLLBACK`. Produkční připojení k testu nepoužívá.
