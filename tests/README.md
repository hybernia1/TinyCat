# Testy TinyCat

Příkazy spouštěj z kořene repozitáře. `composer test` spouští explicitních
71 skupin přes [hlavní runner](run.php), včetně 38 frontendových scénářů.
`composer preflight` navíc přidává metadata, dependency audit, styl, lint,
PHPStan, překlady a repository security.

## Prostředí

- PHP 8.4 a Composer dev závislosti podle kořenového README.
- SQLite pro řadu izolovaných doménových a HTTP fixtures.
- MySQL konfigurace a právo vytvořit izolovanou testovací DB pro MySQL a
  instalační regrese. CI používá [config.ci.php](fixtures/config.ci.php).
- Node.js pro frontend; nativní scénáře navíc potřebují Chromium executable
  (`TINYCAT_TEST_CHROME`) nebo Playwright/WebKit (`TINYCAT_TEST_PLAYWRIGHT_MODULE`).
  Příklad instalace WebKitu je v [CI workflow](../.github/workflows/quality.yml).
- Další integrační požadavky jako GD/WebP, ZIP/Sodium či Memcached určují
  konkrétní skupiny. SKIP znamená neprovedenou kontrolu, nikoli ověřenou integraci.

## Hlavní oblasti

| Oblast | Vstup nebo umístění |
| --- | --- |
| Architektura a UI kontrakty | `tests/quality/` |
| API a veřejné routy | `php tests/http/api-contract.php`, `php tests/http/public-route-smoke.php` |
| Frontend | `php tests/frontend/run.php` |
| Vykreslování a překlady | `tests/presentation/` |
| Domény | `tests/domains/`: users, status, bots, search, moderation, security, health a scheduler. |
| Cache a síťová infrastruktura | `tests/infrastructure/`, včetně `cache/` a `network/`. |
| Assety | `tests/assets/` |
| Release, instalace a rollback | `tests/release/`, `tests/release/updater/` |
| Vývojové a údržbové nástroje | `tests/tools/` |

V kořeni zůstává pouze hlavní runner a tento rozcestník. Provedené
rozřazení popisuje [audit](../docs/audits/tests-2026-11-10.md) a
[inventář](../docs/audits/tests-inventory-2026-11-10.json).

PHP generátory a workers pro klientské testy jsou v `tests/presentation/fixtures/`,
JavaScript fixture v `tests/frontend/fixtures/`, HTTP router a data v
`tests/http/fixtures/` a zahrnované scénáře v `tests/http/cases/`.
Společná podpora patří do `tests/support/`; sdílená data a konfigurace do
`tests/fixtures/`. Hlavní seznam obsahuje 71 skupin a frontendový 38
scénářů. Nová skupina `tests/release/build-preparation.php` ověřuje životnost
cache, invalidaci, oba ZIP backendy a publikaci připraveného výstupu.
Historický performance baseline byl pouze přesunut do `tests/performance/`;
jeho čtyři verzované reporty zůstávají beze změny ve storage.

## Samostatné kontroly a pomocné soubory

`php tests/performance/mysql-tag-window.php --config=/private/performance/config.php`
ověřuje tagové dotazy na připravené izolované `tinycat_perf_*` databázi.
Není součástí hlavního runneru; předpoklady stanoví test a
[standard dotazů](../docs/contracts/query-selection-standard.md).

`tests/security/` je nyní ignorovaná lokální sada, kterou čistý klon neobsahuje.
Má vlastní návod a není součástí hlavního runneru; její starší API scénáře
vyžadují revizi před zapojením do CI.

Nespouštěj každý PHP/JS soubor jako samostatný test. Část souborů tvoří routery,
workers, generátory fixture a zahrnované scénáře; jejich konzument určuje vstupy
i životní cyklus. Zachovej izolaci cache z `support/cache-isolation.php` a oddělené
testovací databáze. Pro změny UI platí také testy uvedené v závazných
[modal](../docs/contracts/modal-contract.md) a
[popover](../docs/contracts/popover-contract.md) kontraktech.
