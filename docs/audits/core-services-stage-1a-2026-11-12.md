# Platformní služby: etapa 1A

Datum: 12. 11. 2026. Navazuje na [audit hranic](core-module-boundaries-2026-11-12.md)
a [otevřený plán](../plans/core-services-and-feature-modules.md).

## Rozsah implementace

- `Core/Tasks`: Runner, History, Execution, Tasks, HealthCheck, Cleanup a
  FileCleanup. Mechanismus používá katalog příspěvků, nikoli konkrétní domény.
- `Core/Settings/Store`: stejný store, cache, citlivé hodnoty a typování;
  původní název je alias se stejným statickým stavem.
- `Core/Mail/Smtp`: SMTP transport používá core store a validaci adresy
  bez potřeby Accounts. Doménové šablony zůstávají ve vlastním adaptéru.
- `ScheduledTask::detail`: botí SQL a doplnění souhrnu vlastní Bots/RunDetails.
  Historický běh neaktivního vlastníka vrací obecný detail bez jeho tabulek.
- `TemporaryDirectory`: vlastníci poskytují lazy cesty dočasných adresářů;
  společná úloha zachovává ID, vzory disposable souborů, stáří a ochranu záloh.
- `Contributions::configOverrides`: publikační UTM a moderátorské klíče
  deklarují vlastníci, nikoli obecný store. Registry kontrolují jedinečnost.
- `Sorting::LISTS`: SQL allowlist a navigace patří vlastníkovi seznamu.
  Obecný AdminSorting dostává definici; neobsahuje katalog doménových endpointů.

Interní konzumenti používají kanonické core názvy. Staré Scheduler názvy,
Settings/Store, Mail/Smtp a Support/TaskContext zůstávají kompatibilními aliasy,
bez druhé implementace. Nezměnily se tabulky, ID úloh ani publikované migrace.
Nebyl proveden commit, release ani nasazení do externí instalace `tinycat.test`.

## Ověření

Závěrečný `composer preflight` má
[měřený report](../../storage/test-runs/20261112-054155-70c61c7322/report.json)
a [úplný výpis](../../storage/core-module-audit-20261112/platform-preflight.log).
Prošel s exit code 0. Celý preflight trval **771,698 s (12 min 52 s)**,
z toho testovací sada **758,221 s (12 min 38 s)**. Vybráno bylo všech 88 PHP
skupin včetně vnořených frontendových a infrastrukturních kontrol.
Report eviduje 133 úspěšných příkazů a jeden přeskočený; tento počet obsahuje
i nadřazené runnery, neznamená 133 nezávislých integračních scénářů.
Memcached integrace byla přeskočena kvůli nedostupnému serveru; symlink escape
fixture nemohla vytvořit symlink, ostatní kontroly dané skupiny prošly.

| Nejdražší dokončené scénáře bez nadřazených runnerů | Čas |
| --- | ---: |
| Update a rollback rehearsal | 114,836 s |
| Podepsaná distribuce a životní cyklus modulů | 95,921 s |
| Dotyková matice popoverů | 95,760 s |
| HTTP API kontrakty | 56,006 s |
| Výběr a sjednocení modulových zdrojů | 26,756 s |

Tyto časy nejsou kontrolovaným benchmarkem proti předchozím etapám.
Rebuild se schématem, balíčky a aktivací má zachovat release/lifecycle scénáře.
Dotykovou matici není třeba násobit pro každý přesun backendové třídy;
ponechat jednu společnou závěrečnou kontrolu a scénáře požadované UI kontrakty.
V této etapě se žádná z těchto kontrol neodstranila ani nekopírovala.

Rozšířený minimální runtime ověřuje dostupnost core služeb bez načtení
Accounts, Scheduler, Settings, Mail, Bots či Status. Konstrukce registrací
nevyhodnocuje cesty a odmítá duplicitní vlastníky. Původní test řazení dál
provádí skutečné SQL nad allowlisty a navíc ověřuje detail historického importu
bez botích tabulek. Nezavádí se další plná integrační kopie stejné instalace.

Doplněná regrese historie byla ověřena také samostatně:
[report](../../storage/test-runs/20261112-054336-433b97674f/report.json),
70 kontrol, 0,336 s. Následný styl i PHP lint prošly. Tento doplněk nemění
aplikační implementaci ověřovanou souběžným preflightem.

Report správně zaznamenává změnu dvou testovacích souborů během preflightu:
`tests/presentation/admin-sorting.php` a jeho viewport fixture. První dostal
doplňkovou regresi a byl ověřen samostatně; viewport fixture byla převedena
na definice řazení vlastníka před spuštěním příslušného frontendového scénáře,
který prošel. Aplikační PHP se během závěrečného preflightu neměnilo.

## Zbývající hranice

1A není dokončením celé původní etapy 1. Scheduler, Settings a Mail stále
obsahují registrační/prezentační adaptéry a některé instalační předpoklady.
Produkční CLI ještě rozlišuje obecný fallback a schedulerový runtime callback;
sjednocení vyžaduje společné vlastnictví instalačního schématu historie.
Identita zámků runneru se v této části neměnila.

V 1B zbývá převést identitu, obecnou schránku oznámení a health, jejich datové
kontrakty, zdroje nastavení/šablon, technickou administraci a HTTP pipeline.
Do té doby Notifications stále obsahují sociální typy a SQL. Etapa 2 následně
řeší souběh Blogu a Socialu a vlastnictví druhů publikací. Tyto cíle se nesmějí
označit za dokončené pouze proto, že existuje nový core adresář.
