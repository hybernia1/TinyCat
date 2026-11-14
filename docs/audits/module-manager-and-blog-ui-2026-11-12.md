# Správce modulů a sjednocení Blog UI

Dokončeno a nasazeno na `https://tinycat.test/` jako **6.0.0-dev.5**.
Navazuje na [předchozí dokončení platformy](platform-discovery-and-tasks-2026-11-12.md),
které ještě nabízelo přepínání modulů pouze přes CLI.

## Výsledek

- **Administrace → Moduly**, `/admin/modules`, patří do Core/Ui. Přehled
  ukazuje stav, povinné závislosti, aktivní závislé moduly a skupinu Social.
  Zůstává dostupný i při vypnutí Site/Socialu.
- `GET /api/modules` a `POST /api/modules/activation` vyžadují administrátora;
  zápis také CSRF a aktuální revizi plánu/deploymentu. Povinné moduly a závislosti
  kontroluje server. Zapnutí doplní potřebné závislosti, vypnutí zachová data.
  Změny používají existující lifecycle, společný zámek, zálohu a plné načtení
  stránky. Nejde o upload nebo spuštění libovolného modulu z filesystemu.
- Blog má společnou administrační tabulku, datum publikace, stav, oddělené
  akce a viditelné vytvoření článku. Odkazy na veřejné zobrazení se nenabízejí
  pro skryté a budoucí články. Budoucí publikace mají stav „Naplánováno“.
  Editor používá společná pole a ochranu konceptu; platné nativní odeslání
  nevyvolává varování o neuložených změnách. Veřejný seznam a detail mají
  čtecí šířku, nadpis, metadata a čitelnou sazbu. Odstranila se duplicitní
  navigační akce vytvoření; články jsou za hlavním dashboardem.
- Generický core shell používá společné styly a responzivní navigaci;
  Blog nevyžaduje sociální layout. Nevznikla další sada formulářových ani
  dialogových controllerů. Potvrzení používá společnou factory.

Provozní postup je v [návodu](../operations/module-deployment.md), JSON kontrakt
v [API referenci](../reference/api.md). Databázové schéma se v této etapě nemění.

## Ověření

`composer preflight` prošel: **87 testovacích skupin PASS, Memcached SKIP**,
včetně **38 frontendových scénářů**. Celý preflight trval **1 050,1 s
(17 min 30 s)**; součet testovacích skupin byl 1 031,6 s. Symlinkový podscénář
zůstal přeskočen kvůli omezení Windows. Prošly Composer metadata/advisories,
styl, lint, PHPStan level 8, překlady, security, hranice, query budgets,
balíčky, instalace i aktualizace z podporované řady s rollbackem.

Závěrečné změny v navigaci, popisku a balení nastaly během širokého běhu;
report je eviduje jako změny zdrojů. Poté se zopakovala příslušná Blog fixture,
kontrola šablon, styl, lint a PHPStan. Nešlo o opakování celého preflightu.
Finální Blog fixture trvala **58,1 s**: 418 kontrol samostatné sestavy a 64
kontrol kombinace, API autorizace, CSRF, zastaralé revize, závislostí a přepínání.

Prohlížeč sdílí její existující server a databázi; nevznikla další integrační
kopie ani nová hlavní testovací skupina. Ověřuje dvanáct rozložení na desktopu
a mobilu, skutečná potvrzení a vypnutí/obnovení Blogu i Socialu, uložení editoru
a dostupnost core správce bez Site. Ve fixture workeru se doplnilo obsluhování
cache assetů a veřejných SVG podle běžného Apache; ověření tak kontroluje
skutečně nastylované stránky. Při chybě by selhala kontrola dodání stylů.

| Nejdražší skupina | Sekundy |
| --- | ---: |
| Frontend, 38 scénářů | 248,6 |
| Update/rollback rehearsal | 162,1 |
| Podepsaný module lifecycle | 127,3 |
| HTTP API kontrakty | 101,9 |
| Blog včetně nativního UI, finální opakování | 58,1 |

Script count se nezměnil. Kvůli společnému guardu nativního uložení se po
kontrole velikostí upravily jen dvě malé rezervy asset budgetů: `/ raw_js`
364 544 → 365 056 B a `/admin/moderation gzip_js` 60 672 → 60 800 B.
Platformní kontrola prošla všech devět scopes. Rejstřík UI byl regenerován
standardním nástrojem.

## Úklid přerušené fixture a balení

Našly se dva adresáře `selection-probe-3f5b42e9dff7*` po dříve zastavené
resource fixture. Byly přesunuty do ignorovaného pracovního adresáře, nikoli
zaměněny za uživatelská média. Dev.4 obsahovala 19 jejich neaktivních souborů.
Dev.5 je odstraňuje explicitním podepsaným mazacím manifestem; v novém balíčku
nejsou. Builder nově zahrnuje `assets/modules/` jen podle deklarovaných vlastníků
ze stejného katalogu. Existující build-preparation test prokazuje vyloučení
nevybraného probe i zachování technických admin assetů. Nové runtime moduly
se tím nezačínají hledat globem.

## Živé prostředí

Podepsaný update/install balíček vznikl za **7,3 s** v privátním
`C:/Users/Nikola/AppData/Local/TinyCat/builds/platform-20261112/release5/`.
Dosavadní runtime ověřil podpis a inventář proti svému důvěryhodnému klíči.
Nasazení proběhlo běžným rescue updaterem a neaplikovalo žádnou novou migraci.

Prošlo **145 HTTPS kontrol s běžným ověřováním TLS**: 56 v kombinované sestavě,
33 bez Socialu a 56 po jeho obnovení. Zahrnují assety, nový správce a API jako
chráněné endpointy, veřejný článek, Blog, root, hledání a sitemap.
Přihlášené změny přes formulář se ověřovaly v izolované fixture, nikoli
obcházením přihlášení do živého webu. Na živém webu přepnul Social společný CLI
lifecycle; po kontrole bylo obnoveno všech **17 aktivních modulů**.

Zachováno: **11 631 publikací, 38 uživatelů, 9 141 mediálních souborů a 27 tabulek**.
Hash kanonických polí publikací, hashe lokální konfigurace a metadata médií
(cesty, velikosti a mtime) jsou stejné. Všech **47 publikovaných migračních
souborů** má stejný normalizovaný hash. Změnil se pouze počet rate-limit
bucketů po ověřovacích dotazech. Maintenance je vypnutá a 19 testovacích
assetových souborů již v živé instalaci není.

Zachované zálohy:

- Update: `6.0.0-dev.4-to-6.0.0-dev.5-20261112-170333-aad0b081`.
- Vypnutí Socialu: `6.0.0-dev.5-to-6.0.0-dev.5-20261112-170637-0a0376bd`.
- Obnovení Socialu: `6.0.0-dev.5-to-6.0.0-dev.5-20261112-170645-065c94bc`.

Měřené reporty: `20261112-163914-80ced833bb`,
`20261112-165815-f2af59408d`, `20261112-165915-cc4419a34a`.
Srovnání, živé kontroly, build log a vizuální podklady jsou v ignorovaném
`storage/module-ui-20261112/`. Živá instalace zůstává v Laragonu mimo repo;
nebyl spouštěn další Apache ani MySQL.
