# Dokončení sdílených schopností core

Stav: návrh po [auditu Socialu z 12. 11. 2026](../audits/social-core-capabilities-2026-11-12.md).
Etapa 1 je dokončená a [ověřená i nasazená](../audits/social-core-stage1-2026-11-13.md);
etapa 2 je také [ověřená a nasazená](../audits/social-core-stage2-2026-11-13.md).
Etapa 3 je [ověřená a nasazená](../audits/social-core-stage3-2026-11-13.md);
etapa 4 je také [ověřená a nasazená](../audits/social-core-stage4-2026-11-14.md).
Všechny čtyři etapy tohoto plánu jsou dokončené. Plán vychází z aktuálního Core + Social + Blog,
nikoli z dřívější produktové composition vrstvy.

## Cílové pravidlo

Core poskytuje funkční framework a společné publikační služby.
Modul registruje vlastní data, obsahové druhy, dostupnost, oprávnění, routy,
UI příspěvky a události. Sdílená schopnost nevyžaduje zapnutí Socialu.
Social zůstává jedním modulem s jedním manifestem. Nové core služby nemají
aktivační přepínače; vlastník rozhoduje, zda je na svém obsahu použije.

Přesun je dokončený teprve s odpovídajícím API, UI, překlady, assety, instalací,
cleanupem a kontrolami. Nedoplňovat průchozí doménové adaptéry jako náhradu za
správný kontrakt. Zachovat lazy registraci bez SQL a provozních vedlejších efektů.

## Etapa 1: samostatný veřejný framework

**Výsledek:** Blog ani jiný modul nepotřebuje sociální téma kvůli metadatům,
chybovým stránkám, jazykům, souhlasu s analytikou či použitelnému hledání.

- Společný dokumentový model a HTML head v Core/Ui; přenést obecnou část
  Metadata/LayoutData a společné obsluhy Errors/HttpEntry do existujícího
  HttpKernel. Sociální téma ponechá obsah, rozložení a specifické odkazy.
- Mechanismus locale URL připojit k existujícímu Router/LocalUrls/Localization.
  Vlastníci deklarují cesty, povolené query a canonical/alternativy;
  core neobsahuje seznam sociálních ani blogových URL.
- Přenést obecné jazykové a consent UI, globální vyhledávací widget a jejich
  assets/překlady. Rozdělit společné a sociální části privacy/LLM prezentace.
- Core/Admin získá hostitele dashboardových widgetů a technické widgety;
  Social přispěje sociálními statistikami. Zachovat pojmenované vstupní stránky.
- Odstranit čisté průchozí fasády Identity/Publishing ze Socialu a převést
  běžná volání na kanonické třídy. Zachovat prokazatelně potřebná historická
  jména přes LegacyNames, bez změn publikovaných migrací.

**Akceptace:** core bez modulů, Blog, Social a jejich souběh mají shodný
technický dokumentový/chybový kontrakt; jazykový přepínač zachová článek;
žádná společná cesta nezíská funkčnost teprve aktivací Socialu. Ověřit HEAD,
JSON chyby, chybějící asset, nedostupnou DB a fallback bez DB; data a drafty
nesmějí pronikat do URL. Sociální sidebar a feed se do samotného Blogu nedostanou.

## Etapa 2: kompletní sdílená prezentace Publishing a bezpečnostní politiky

**Výsledek:** Blog umí používat stejnou textovou a mediální infrastrukturu,
kterou dosud z větší části obaloval Social.

- Přesunout obecné textové segmenty, media/link resources, linkové karty,
  klientské přehrávače/náhledy a volitelný lokální překlad. Rozdělit editorový
  mechanismus a statusové nastavení. Zapojit Blog do společné prezentace.
- Odstranit závislost společných výchozích URL a přípravy zmínek na globálních
  callbackách nastavovaných Socialem. Rozšíření uplatňovat pro explicitní kontext vlastníka;
  aktivace Socialu nesmí mimoděk měnit zpracování článků.
- UTM funkci připojit ke Core/Publishing, které už vlastní její nastavení.
  Bezpečný resolver cíle dostane autorizovaný kontext vlastníka; sociální URL
  a přístup ke komentáři nepřesouvat jako pevné větve do core.
- Vytáhnout RSS serializaci a oEmbed hostitele s příspěvky vlastníků.
  Blog dodá skutečný druhý zdroj; Social dál vybírá své statusy a veřejné URL.
- Obecné URL rules do Core/Security, obsahovou politiku a nastavení do
  Core/Publishing. Zachovat uložené `moderation.blocked_urls` a jejich účinek
  bez Socialu. Síťová SSRF ochrana zůstává samostatnou transportní kontrolou.
- Společný limiter/CAPTCHA zachovat; přenést sociální reputační kvóty,
  cooldowny a follow definice ke skutečným vlastníkům. Retenci odvodit
  z deklarací. Opravit započítávání článků do sociální reputace.

**Akceptace:** stejné bezpečné renderování textu, odkazů a médií v Socialu
i Blogu; rozdílné obsahové limity a oprávnění zůstanou zachované. Vypnutí Socialu
nezruší blokované domény ani společnou UTM politiku. Zachovat query budgets,
viditelnost, kanonické URL, SSR/JS paritu a lokální překlad původního textu.
Články nezmění sociální reputaci. Společné assety nesmějí načítat statusový feed.

## Etapa 3: diskuse a reakce použitelné bez Socialu

**Výsledek:** Blog může výslovně povolit komentáře a reakce přes společné služby;
Social používá stejnou implementaci s vlastní politikou.

- Nejprve zavést úzký kontrakt cíle: vlastník/druh a ID publikace, oprávnění
  ke čtení/interakci/správě, kanonická URL a dostupné schopnosti.
  Neaktivní vlastník neposkytuje čtení ani možnost interakce.
- Přenést celé transakční chování diskuse a reakcí: čtení, zápisy z Actions,
  historii, parent vazby, cache, cleanup, resources a klientskou prezentaci.
- Obecné služby vyvolají událost; sociální notifikační typy, příjemce,
  reputaci a feedové aktualizace zajišťuje Social. Schránka a transport
  nadále patří Core/Notifications a Core/Mail.
- Zachovat stávající statusové endpointy jako autorizované vstupy nad stejnou
  službou. Blog registruje vlastní použití. Nikdy pouze nerozšířit sociální
  endpoint odstraněním filtru `kind = post`.
- Připravit explicitní předání vlastnictví `content_comments`, `content_likes`
  a `comment_likes`. Zachovat IDs, vazby, historii, přečtení a notifikační
  deduplikaci. Neměnit publikované migrace ani checksumy.

**Migrační podmínka:** historický stream `tinycat.status` nelze prostě celý
přejmenovat na core stream. Přechod současného vlastnictví tabulek, fresh schéma
a nový případný core migrační stream musejí respektovat původní historii,
metadata schemaOwners, vypnuté instalované vlastníky a přesný rollback.
Žádná tabulka nesmí být současně deklarovaná dvěma vlastníky.

**Akceptace:** skutečný komentář/reakce článku v izolované Blog-only instalaci,
stejný průchod pro status, souběh obou, odmítnutí cizího druhu a neaktivního
vlastníka. Ověřit hranice oprávnění, přímá ID, skrytý/budoucí obsah, parenty,
souběžné zápisy, smazání a účetní cleanup i s neaktivním modulem. Přechod
z dev.6 zachová existující data; fresh a upgrade mají stejné aktuální schéma.

## Etapa 4: importní mechanismus s více publikačními cíli

**Výsledek:** společná feedová pipeline použije Social i Blog; nepotřebuje
pro každý cíl další kopii botího importéru.

- Navázat na existující FeedParser, WordPressFeed a FeedImport v Core/Publishing
  a runner Core/Tasks. Oddělit fetch/claim/přípravu/deduplikaci/diagnostiku
  od úložiště zdroje a konkrétního publikačního cíle.
- Social dodá botí identitu, současný source store a statusový writer.
  Blog dodá článek, jeho plné tělo a příslušnou publikační politiku.
- Náhled používá stejnou přípravu jako import, ale neclaimuje položku,
  nezapisuje historii úspěchu a nemění plánování. Selhání publikace nesmí
  spotřebovat externí položku ani zanechat soubory bez vlastníka.
- Zachovat úlohu `feeds`, její režimy a zámky, historii, GUID deduplikaci,
  intervaly a aktuální botí UI. Obecný tabový workspace vytáhnout pouze při
  skutečném druhém použití, nad stávajícími form/modal/list službami.
- Botí tabulky nepřejmenovávat a nestěhovat plošně. Případná společná
  persistence vyžaduje samostatně zdůvodněný migrační návrh, ne vedlejší efekt
  extrakce importní funkce.

**Akceptace:** stejný vstupní feed vytvoří status i článek přes dva explicitní
cíle; import článku nevyžaduje botí účet nebo Social. Retry, souběh, přerušení,
duplicitní položky a odmítnutí nemění dřívější záruky. Ruční i plánované spuštění
používají Core/Tasks, žádný paralelní runner ani nový serverový stack.

## Ověřování po etapách

Během implementace cílené kontroly; na konci každé celé etapy jeden měřený
preflight a případné cílené doplnění po konkrétní opravě. Znovu použít současné
fixtures a runnery. Matici vlastníků ověřit v příslušné doménové fixture,
ne čtyřmi novými úplnými update/rollback sadami.

Přidávat pouze scénáře nového kontraktu nebo reprodukované chyby. Zachovat
relevantní nativní modal/popover/UI regrese, SQL rozpočty a vydané migrační
checksumy. V reportu uvést čas, rozsah, přeskoky a případné změny zdrojů během
běhu. Nasazení na tinycat.test nemění aktivace, účty ani provozní obsah.
