# Dokončení instalačních adaptérů a souběhu Blogu se Socialem

Datum: 12. 11. 2026. Navazuje na etapy
[1A](core-services-stage-1a-2026-11-12.md),
[1B](core-services-stage-1b-2026-11-12.md) a
[realizační plán](../plans/core-services-and-feature-modules.md).

Zadaný celek je implementovaný a nasazený na **https://tinycat.test/** jako
**6.0.0-dev.3**. Blog i Social jsou aktivní. Jde o vývojový balíček, nikoli
nový veřejný release; poslední zveřejněná verze zůstává 5.22.2.

## Implementace

- Core vybírá `/` a `/admin` z pojmenovaných příspěvků. Homepage, dashboard
  a téma nejsou globální přepisy prováděné Blogem nebo Site. Volby
  `site.homepage` a `site.theme` jsou v nastavení; při vypnutí vlastníka funguje
  aktivní fallback. Navigace zpřístupňuje `/blog` i `/social`.
- Publishing ukládá druh publikace: `post` nebo `article`. Sociální feed,
  hledání, sitemap, statistiky, exporty, embed, editor, odkazy a botí fronty
  nesmějí článek vydávat za status. Blog má vlastní kanonickou URL
  `/articles/{id}` a administraci `/admin/articles`.
- Definice nastavení, jejich klientské popisky a překlady přispívají
  Settings, Publishing, Accounts, Security, Moderation a Mail. Společný
  editor používá jen aktivní vlastníky; fresh výchozí hodnoty vycházejí
  ze stejných definic.
- Accounts vlastní welcome/recovery šablony, Status sociální e-maily a Mail
  společný podpis. `resources.emails` odděluje tyto texty od překladů aplikace.
  Obecný renderer zůstává v Core/Mail, branding v Core/Ui.
- Samostatné instalační baseline mají také Users, Status, Settings, Scheduler,
  Bots a Moderation. Kompatibilní instalátor získává DDL z vybraných vlastníků.
  Nemá druhou kopii jejich tabulek; specializace s modulovou historií vytváří
  přímo migrační mechanismus. Instalační partial už nevlastní Site.
- Bots a Health připojují vlastní detail běhu přes `componentExtensions`.
  Scheduler nemá povinnou klientskou závislost na jejich komponentách.
- `--assembly=platform` zpřístupňuje 17 modulů. Skupina `tinycat.social`
  zahrnuje Site, Status, Users, Bots, Moderation a Search. Aktivace doplní
  závislosti; deaktivace zachová data a odmítne porušení zbývajícího grafu.
  Podepsaná identita distribuce `tinycat.social` zůstává kvůli kompatibilitě
  updateru, neurčuje aktivaci sociálních funkcí.

Konkrétní příkazy a volby jsou v [provozním návodu](../operations/module-deployment.md).
Nevznikl Composition, další produktový bootstrap ani nový univerzální runner.

## Schéma a kompatibilita

Nové globální migrace jsou `20261112_001_notification_payload` a
`20261112_002_publication_kinds`. První doplní neutrální payload schránky,
druhá druh publikace a index. Blog má navazující migraci klasifikace svých
dřívějších publikací. Původní řádky zůstávají statusy; migrace nemažou data.
Všech 28 dřívějších migračních souborů živé instalace zůstalo beze změny.

Starý vývojový runtime 6.0.0-dev.1 neznal nové druhy manifestových zdrojů.
Nasazení proto použilo podepsaný mezikrok 6.0.0-dev.1.1 nad jeho stávajícími
zdroji: změnil načítání metadat a číslo verze, nikoli doménové chování.
Potom proběhl update na 6.0.0-dev.2 a menší balíček 6.0.0-dev.3 po úpravě
klientských assetů Blogu. Všechny kroky provedl společný updater z privátního
rescue CLI, se standardními podpisy, maintenance, journalem a zálohami.

## Ověření

Napříč měřenými běhy: **87 úspěšných skupin, 1 přeskočená**, včetně
**38 úspěšných frontendových scénářů**. Memcached byl přeskočen kvůli
nedostupnému lokálnímu serveru. Katalog navíc přeskočil jeden symlinkový
podscénář, protože Windows nepovolil vytvoření symlinku.

Prošly metadata Composeru, dependency audit, styl, PHP lint, PHPStan level 8,
12 překladových katalogů a repository security. PHP pochází z existujícího
Laragonu: 8.4.12 NTS; MySQL testy používaly jeho běžící službu a oddělené
dočasné databáze. Kvůli živému ověření nebyl spouštěn další Apache ani MySQL.

Rozšířená stávající Blog fixture má 393 kontrol izolovaného Blogu a dalších
39 kontrol kombinace, samostatných schémat, HTTP, CLI aktivace/deaktivace,
nastavení, šablon a zachování dat. Ověřuje i zákaz sociální editace/embedu
článku a nepřepsání Blog homepage sociální AJAX navigací.

Nejde o jeden čistý zelený průchod `composer preflight`. Sada po nalezených
chybách pokračovala jen neprovedenými a dotčenými skupinami; původní neúspěšné
výsledky zůstaly zachované. Stejně byly dokončeny zbývající frontendové
scénáře bez opakování už úspěšných dlouhých browser testů. Jednotlivé časy
a otisky zdrojů jsou v původních reportech; součet níže není wall time jednoho
preflightu a nepřičítá frontendové potomky podruhé.

Poslední úspěšné výsledky skupin dávají přibližně **27 min 25 s**, z toho
frontend **5 min 11 s**. Největší náklady byly update/rollback rehearsal
(477,7 s), podepsaný modulový lifecycle (144,9 s) a HTTP API (121,1 s).
Část těžkých filesystemových a MySQL kontrol běžela souběžně; tato měření
nejsou čisté srovnání rychlosti s předchozí etapou. Pro další optimalizaci
mají přednost tyto existující drahé scénáře, nikoli přidávání dalších kopií.

Rozbalený balíček 6.0.0-dev.2 prošel 2 387 kontrolami, včetně fresh MySQL
instalace. Jedna kontrola dříve odhalila předčasné vytváření tabulky Blogu;
adaptér byl opraven a opakováním ověřen. Další pokus narazil na dočasné
zamčení checkpointu demo importu ve Windows. Checkpoint nyní zachová původní
soubor do úspěšného přejmenování a má omezené opakování; importní test i celé
ověření artefaktu následně prošly.

U 6.0.0-dev.3 se měnila verze a klientský výběr Blogu, nikoli schéma.
Blog nepotřebuje posílat serverově vykreslované komponenty klientovi.
Po odstranění těchto kořenů prošla kombinovaná fixture znovu a všech
**9 scopes skutečného podepsaného ZIPu** splnilo původní rozpočty. Kontrola
`tests/assets/budgets.php` nyní stejným jedním během ověřuje platformní
nadmnožinu; limity nebyly navýšeny. Kompresi posuzuje stejný PHP `gzencode`
jako dosavadní kontrola, nikoli odlišná implementace zlib v Pythonu.

Měřené reporty a konsolidovaný přehled:

- `storage/platform-completion-20261112/verification-union.json`
- `storage/test-runs/20261112-085039-a364112cbb/`
- `storage/test-runs/20261112-085734-9e7294cd9a/`
- `storage/test-runs/20261112-090316-dfdda0aea9/`
- `storage/test-runs/20261112-090419-2ed7724276/`
- `storage/test-runs/20261112-090847-90ec9a6473/`
- `storage/test-runs/20261112-091023-90f87b844b/`
- `storage/test-runs/20261112-085709-b50bb8f36b/`
- `storage/test-runs/20261112-090316-d6b7890a35/`
- `storage/test-runs/20261112-092039-53569390e2/`
- `storage/test-runs/20261112-093040-484bd16ea3/`
- `storage/test-runs/20261112-094414-dcdd895bfc/`

## Živá instalace

Root zůstává `E:/laragon/www/tinycat`, mimo repozitář. Na konci je aktivních
17 ze 17 nainstalovaných modulů, Blog i Social, maintenance vypnutá.
Proběhlo 46 HTTPS kontrol společné sestavy, 23 bez Socialu a dalších 46 po
jeho obnovení, s normálním ověřením TLS. Kontrolovaly veřejné stránky,
kontrakty API, assety, manifest, sitemap a ochranu administrace i privátních
souborů. Social byl po zkoušce vždy obnoven.

| Kontrola dat před / po | Výsledek |
| --- | --- |
| Publikace | 11 630 / 11 630 |
| Účty | 38 / 38 |
| Existující média | 9 141 / 9 141, shodné cesty, velikosti a časy |
| Otisk ID, autorů, textů, dat a stavů publikací | Shodný |
| Otisky privátní konfigurace a sledovaného klíče | Shodné |
| Počty řádků původních doménových tabulek | Shodné |
| Nové tabulky | `blog_articles`, `module_installations`, `module_migrations` |

Živé ověření bylo anonymní HTTP/API a provozní CLI. Přihlášené UI a zápis
článku proběhly v izolovaných fixtures, nikoli pod napodobenou živou session.
Na živém webu nebyl vytvářen demonstrační článek ani měněna hesla.

Zálohy pod `E:/laragon/www/tinycat/storage/updates/backups/`:

| Operace | ID zálohy |
| --- | --- |
| Kompatibilní mezikrok | `6.0.0-dev.1-to-6.0.0-dev.1.1-20261112-092651-2cc25ecb` |
| Platformní update a datové migrace | `6.0.0-dev.1.1-to-6.0.0-dev.2-20261112-092813-53574308` |
| Menší klientský balíček | `6.0.0-dev.2-to-6.0.0-dev.3-20261112-093444-797e3252` |
| Instalace schématu Blogu | `6.0.0-dev.3-to-6.0.0-dev.3-20261112-093542-f326484a` |
| Vypnutí Socialu | `6.0.0-dev.3-to-6.0.0-dev.3-20261112-093728-4b65f279` |
| Obnovení Socialu | `6.0.0-dev.3-to-6.0.0-dev.3-20261112-093734-f943681e` |

Zálohy se nemažou. Přímé CLI obnovení je omezené na poslední operaci;
starší záloha není automaticky návrat celého pozdějšího řetězce změn.
Výsledné update/install artefakty jsou v privátním adresáři
`C:/Users/Nikola/AppData/Local/TinyCat/builds/platform-20261112/release3/`.
Podrobné výsledky a srovnání jsou v ignorovaném
`storage/platform-completion-20261112/`; neobsahují vypsané přihlašovací údaje.

## Hranice tohoto dokončení (historický stav dev.3)

Následující mezery řeší [navazující etapa dev.4](platform-discovery-and-tasks-2026-11-12.md).

Sociální Search a sitemap nadále patří sociální funkci. Neagregují automaticky
dlouhé články Blogu. Obecný agregátor hledání/sitemap a rozdělení obecného
importu od sociálního adaptéru Bots zůstávají širší částí původního návrhu.
Technické adaptéry a kompatibilní fasády nejsou vydávány za kompletně
odstraněné; minimální CLI bez Scheduler adaptéru má dosavadní provedení
jednorázového callbacku bez jeho trvalé historie. Nasazená platformní sestava
používá společný runner, historii a zámky přes Scheduler adaptér.

## Poslední úspěšné měřené skupiny

<!-- measured-groups -->

| Skupina | Stav | Sekundy |
| --- | --- | ---: |
| `tests/quality/module-catalog.php` | PASS | 0,4 |
| `tests/quality/module-resources.php` | PASS | 36,5 |
| `tests/quality/runtime-registries.php` | PASS | 0,6 |
| `tests/quality/module-pilot.php` | PASS | 27,5 |
| `tests/quality/module-boundaries.php` | PASS | 0,3 |
| `tests/quality/dependencies.php` | PASS | 0,3 |
| `tests/quality/ui-contracts.php` | PASS | 0,7 |
| `tests/infrastructure/storage.php` | PASS | 0,2 |
| `tests/infrastructure/run.php` | PASS | 0,3 |
| `tests/presentation/admin-sorting.php` | PASS | 0,3 |
| `tests/frontend/run.php` | PASS | 310,5 |
| `tests/quality/monolith-inventory.php` | PASS | 1,9 |
| `tests/infrastructure/network/metadata-dns.php` | PASS | 3,6 |
| `tests/infrastructure/network/http-client-reuse.php` | PASS | 1,4 |
| `tests/domains/status/link-parser.php` | PASS | 0,7 |
| `tests/domains/status/audio.php` | PASS | 1,2 |
| `tests/domains/status/content-links.php` | PASS | 0,3 |
| `tests/domains/security/rate-limiter.php` | PASS | 0,4 |
| `tests/domains/security/rate-limiter-mysql.php` | PASS | 0,6 |
| `tests/domains/status/write-path-integrity.php` | PASS | 1,1 |
| `tests/domains/status/post-text.php` | PASS | 0,3 |
| `tests/domains/moderation/moderation-migration.php` | PASS | 2,6 |
| `tests/domains/security/runtime-hardening.php` | PASS | 1,7 |
| `tests/domains/users/language-detection.php` | PASS | 0,6 |
| `tests/presentation/localization.php` | PASS | 0,8 |
| `tests/presentation/translation-catalogs.php` | PASS | 4,4 |
| `tests/presentation/compiled-translations.php` | PASS | 17,2 |
| `tests/domains/status/inline-tokens.php` | PASS | 0,5 |
| `tests/domains/status/numeric-tags-mysql.php` | PASS | 5,9 |
| `tests/domains/users/usernames.php` | PASS | 0,7 |
| `tests/domains/users/account-lifecycle.php` | PASS | 5,0 |
| `tests/domains/accounts/identity.php` | PASS | 8,3 |
| `tests/domains/accounts/onboarding.php` | PASS | 10,4 |
| `tests/domains/accounts/profile.php` | PASS | 9,8 |
| `tests/domains/accounts/administration.php` | PASS | 4,0 |
| `tests/domains/accounts/avatars.php` | PASS | 4,7 |
| `tests/domains/accounts/module.php` | PASS | 13,1 |
| `tests/domains/publishing/module.php` | PASS | 4,3 |
| `tests/domains/blog/module.php` | PASS | 21,5 |
| `tests/domains/blog/mysql.php` | PASS | 2,9 |
| `tests/domains/publishing/social-boundaries.php` | PASS | 0,3 |
| `tests/quality/render-only-partials.php` | PASS | 0,3 |
| `tests/domains/security/html-sanitizer.php` | PASS | 0,3 |
| `tests/presentation/view-templates.php` | PASS | 0,8 |
| `tests/presentation/components.php` | PASS | 0,5 |
| `tests/presentation/dashboard.php` | PASS | 0,5 |
| `tests/domains/status/public-exports.php` | PASS | 0,3 |
| `tests/domains/status/archive-filters.php` | PASS | 0,5 |
| `tests/domains/status/archive-filters-mysql.php` | PASS | 1,1 |
| `tests/http/public-route-smoke.php` | PASS | 10,4 |
| `tests/http/api-contract.php` | PASS | 121,1 |
| `tests/performance/performance-baseline.php` | PASS | 0,1 |
| `tests/performance/hot-read-query-budgets.php` | PASS | 19,2 |
| `tests/tools/performance-profiler.php` | PASS | 0,6 |
| `tests/infrastructure/cache/run.php` | PASS | 1,3 |
| `tests/infrastructure/cache/memcached.php` | SKIP | 2,2 |
| `tests/infrastructure/cache/concurrency.php` | PASS | 1,7 |
| `tests/assets/run.php` | PASS | 2,0 |
| `tests/assets/budgets.php` | PASS | 8,4 |
| `tests/assets/graph.php` | PASS | 0,3 |
| `tests/domains/bots/run.php` | PASS | 0,4 |
| `tests/domains/bots/csfd-premieres.php` | PASS | 0,2 |
| `tests/domains/bots/queue.php` | PASS | 0,4 |
| `tests/domains/bots/feed-queue.php` | PASS | 0,7 |
| `tests/domains/bots/duplicate-cleanup.php` | PASS | 1,5 |
| `tests/domains/bots/publication-migration.php` | PASS | 0,4 |
| `tests/release/updater/run.php` | PASS | 0,5 |
| `tests/release/database-snapshots.php` | PASS | 0,8 |
| `tests/release/module-lifecycle.php` | PASS | 144,9 |
| `tests/release/updater/mysql-registry.php` | PASS | 2,0 |
| `tests/domains/scheduler/files.php` | PASS | 0,8 |
| `tests/domains/scheduler/retention.php` | PASS | 10,4 |
| `tests/domains/health/health.php` | PASS | 5,0 |
| `tests/domains/health/health-http.php` | PASS | 2,7 |
| `tests/domains/scheduler/run.php` | PASS | 2,4 |
| `tests/domains/scheduler/cli.php` | PASS | 2,3 |
| `tests/tools/demo-import.php` | PASS | 0,4 |
| `tests/tools/bot-source-attribution.php` | PASS | 1,6 |
| `tests/tools/link-optimizer.php` | PASS | 1,6 |
| `tests/tools/youtube-thumbnails.php` | PASS | 0,5 |
| `tests/domains/search/search.php` | PASS | 0,7 |
| `tests/domains/search/search-mysql.php` | PASS | 7,5 |
| `tests/release/package-artifact.php` | PASS | 64,2 |
| `tests/release/build-preparation.php` | PASS | 1,9 |
| `tests/release/fresh-package-artifact.php` | PASS | 65,6 |
| `tests/release/update-rehearsal.php` | PASS | 477,7 |
| `tests/release/installation-api.php` | PASS | 76,6 |
| `tests/release/mysql-installer-rehearsal.php` | PASS | 91,6 |

### Frontendové scénáře

| Scénář | Sekundy |
| --- | ---: |
| `source-preview.cjs` | 0,2 |
| `embed.cjs` | 0,2 |
| `feed-cursor.cjs` | 0,2 |
| `feed-lifecycle.cjs` | 0,1 |
| `filtered-home-actions.cjs` | 0,1 |
| `email-availability.cjs` | 0,2 |
| `images.cjs` | 0,1 |
| `youtube-thumbnails.cjs` | 9,1 |
| `infrastructure.cjs` | 2,2 |
| `modules.cjs` | 5,3 |
| `user-menu.cjs` | 0,2 |
| `toasts.cjs` | 0,2 |
| `translation.cjs` | 0,2 |
| `tag-layout.cjs` | 11,4 |
| `locale-urls.cjs` | 0,5 |
| `language-menu.cjs` | 0,1 |
| `sidebar-viewport.cjs` | 0,2 |
| `sidebar-refresh.cjs` | 0,2 |
| `scheduler-live.cjs` | 0,1 |
| `search-navigation.cjs` | 1,0 |
| `notifications-state.cjs` | 0,1 |
| `admin-sorting.cjs` | 0,1 |
| `ajax-races.cjs` | 0,2 |
| `search-races.cjs` | 0,2 |
| `list-state.cjs` | 0,2 |
| `popovers.cjs` | 4,5 |
| `popover-sizing.cjs` | 19,9 |
| `popover-touch.cjs` | 127,5 |
| `comment-modals.cjs` | 13,5 |
| `ui-consistency.cjs` | 7,3 |
| `link-labels.cjs` | 10,9 |
| `dialog-history.cjs` | 19,8 |
| `form-validation.cjs` | 24,7 |
| `admin-viewport.cjs` | 15,3 |
| `dashboard.cjs` | 3,2 |
| `bot-admin-lists.cjs` | 0,3 |
| `client-assets.cjs` | 29,7 |
| `styles.cjs` | 1,1 |
