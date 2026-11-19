# Audit aplikačního JavaScriptu — 2026-11-19

Audit zdrojů po vydání 6.1.3, commit `f73c56c1ef3422a748255bcf66a19c4a81cf59f3`.
Zahrnuje inventář 92 zdrojových JS souborů (8 285 řádků), importní graf,
deklarace assetů a kontrolu společného lifecycle, požadavků, seznamů, formulářů,
notifikací a jejich hlavních modulových konzumentů. Generované bundly nejsou
dalšími implementacemi. Nejde o úplný bezpečnostní audit ani benchmark.

## Výsledek

Společný základ je použitelný: transport, rušení požadavků, historie dialogů,
formuláře, komponenty a seznamy už mají centrální mechanismy. Úklid však není
dokončený. Následující nálezy vyžadují opravu nebo zúžení existujících rozhraní;
nový framework ani další registrační vrstva nejsou potřeba.

### 1. P1: stránkování core notifikací závisí na Socialu

[Notifikační stránka](../../App/Core/Notifications/templates/parts/page.php) vytváří
`data-status-feed-more` a `data-status-feed-load`. Jediný handler těchto atributů
je v [sociálním feed loaderu](../../App/Modules/Social/assets/status/feed-loader.js),
který přímo rozlišuje `notifications-list` a volá core renderer notifikací.
[Manifest notifikací](../../App/Core/Notifications/assets/module.json) tento loader
nedeklaruje a core sestava jej správně neobsahuje.

Dopad: bez Socialu se první stránka notifikací vykreslí, ale tlačítko pro další
stránku nemá obsluhu. Platí také pro samostatný Blog. Izolovaný probe ověřil
současně vykreslené tlačítko a nepřítomnost jeho handleru v core grafu.

Oprava: stránkování notifikací musí vlastnit core notifikace, případně úzký
společný kurzorový loader. Sociální virtualizaci, pruning a statusové formuláře
ponechat Socialu. Neřešit importem celého Socialu do core.

### 2. P2: avatarový editor neukončuje svou asynchronní práci

[Avatarový controller](../../App/Core/Identity/assets/avatar-editor.js) po
`await prepareClientImageInput` ověřuje pouze pořadí výběrů. Neověřuje životnost
editoru, nemá dispose a callbacky FileReaderu také nejsou vázané na životnost.
Zavření dialogu proto nezneplatní právě rozpracovaný výběr.

Izolovaný probe spustil skutečný change handler, odpojil kořen a následně odmítl
přípravu obrázku: controller i poté zobrazil jeden globální chybový toast.
Úspěšná větev může obdobně pokračovat v aktualizaci odpojeného náhledu.
Není tím prokázaný trvalý únik paměti; prokázaná je chybějící hranice životnosti.

Oprava: dispose zneplatní generaci výběru a čtení souboru; úspěch i chyba musí
ověřit aktuální životnost. Stejný princip prověřit u
[obrázku composeru](../../assets/ui/content/media.js),
[obecného uploadu](../../assets/ui/image-uploads.js) a
[načítání sledovaných účtů](../../App/Modules/Social/assets/users/following.js).
Tyto další cesty nebyly tímto probem funkčně reprodukované.

### 3. P2: společný obsah se hydratuje a disposuje dvěma cestami

[Prezentace obsahu](../../assets/ui/content/presentation.js) a
[editor](../../assets/ui/content/editor-entry.js) jsou samostatně registrované
v `TinyCat.modules`. [Sociální vstup](../../App/Modules/Social/assets/status/index.js)
je současně znovu volá přes [publishing facade](../../assets/ui/content/index.js).

Probe nad skutečnými registračními soubory naměřil dvě volání hydratace editoru
na jeden průchod `modules.hydrate`. Ready příznaky většinou zabrání dvojím
listenerům, nikoli opakovanému průchodu DOM. Stejně jsou zdvojené dispose cesty.
[Diskuse](../../assets/ui/content/discussion.js) také přímo hydratuje editor,
ačkoli jeho registrace už patří společnému lifecycle.

Oprava: jeden vlastník registrace každé schopnosti. Social hydratuje jen své
formuláře a feed; společné prezentaci a editoru nechá jejich vlastní registraci.
Zachovat explicitní hydrataci nově vložených kořenů a testovat pořadí i dispose.

### 4. P2: neukončený přechod výsledků na konkrétní změněné kořeny

[Registry modulů](../../assets/core/modules.js) podporuje současně `false`,
legacy `true` a pole kořenů. [AJAX adaptér](../../assets/ui/ajax-bindings.js)
při jiném výsledku než poli hydratuje celý target nebo dokument.
[Účty](../../App/Core/Identity/assets/controller.js) a
[notifikace](../../App/Core/Notifications/assets/controller.js) stále vracejí
`true`, zatímco novější seznamy a nastavení používají pole.

Dopad: i operace měnící pouze text, stav nebo existující avatar může znovu
spustit všechny hydratační služby. V kombinaci s nálezem 3 se práce násobí.
Audit nevyčísluje časovou ani paměťovou úsporu.

Oprava: sjednotit obsloužený výsledek na `Node[]`, včetně prázdného pole.
Při vložení nového DOM vrátit jeho konkrétní kořeny. Před odstraněním legacy
větve ověřit všechny callbacky a podporované externí modulové kontrakty.

### 5. P3: controllery publikují příliš mnoho vnitřních helperů

[Feed loader](../../App/Modules/Social/assets/status/feed-loader.js) vystavuje
26 metod a [editor](../../assets/ui/content/editor.js) 23 metod přes globální
controller objekty. Část helperů využívá pouze vlastní soubor nebo testy/nástroje.
Například viewport/gap/spacer helpery feedu nepotřebují veřejné aplikační API.
[Administrační vstup](../../assets/modules/admin/index.js) navíc po importech
obsahuje prázdné IIFE s nepoužitým aliasem; obdobný alias je v sociálním vstupu.

Oprava: oddělit nezbytné mezisouborové operace od implementačních detailů,
zachovat skutečné testovací a experimentální konzumenty. Samotný chybějící
interní volající není důkazem bezpečného odstranění veřejného rozhraní.
Nezavádět kvůli tomu další facade soubory.

## Ověření a omezení

- Syntaxe: všech 92 zdrojových JS souborů přes `node --check`, bez chyby.
- UI kontrakty: úspěch, 130 aktivních komponent; asset report: úspěch, 10 scopes.
- Cílený frontendový běh: `feed-lifecycle.cjs`, `notifications-state.cjs`,
  `ajax-races.cjs`, `list-state.cjs`, `form-validation.cjs`; všech pět prošlo,
  bez přeskoku vybraných scénářů. Jde o zúžený běh, ne celý preflight.
- [Měřený report](../../.work/tests/runs/20261119-074100-e9fa7f8a51/report.json).
- Lokální reprodukce jsou v ignorovaném
  [pracovním adresáři](../../.work/audits/javascript-2026-11-19/).
  Používají skutečné zdrojové funkce a izolované VM/render vstupy; nenahrazují
  nativní end-to-end regresi nově nalezených chyb.
- Na živé instalaci nebyla měněna data ani runtime. Nové nálezy nebyly ručně
  ověřené v živém prohlížeči. Celý preflight nebyl opakován pro čtecí audit.
- Podezření na chybějící AJAX error helper a na neodpovídající data notifikační
  šablony byla vyvrácena: existuje pozdější alias, respektive mezilehlá šablona.

## Pořadí následné práce

Nejprve odstranit závislost notifikací na Socialu a doplnit regresi core/Blog
s více než jednou stránkou notifikací. Potom uzavřít asynchronní životnost
editorů. Následně sjednotit hydrataci a výsledkové kořeny v jedné etapě, včetně
měření počtu volání. Nakonec zúžit helperová rozhraní a odstranit prázdné obálky.
Změny sdíleného lifecycle ověřit úplným preflightem až na konci celé etapy.

## Následná oprava — 2026-11-19

Všech pět bodů je implementovaných v jedné navazující etapě:

- Notifikace používají vlastní kurzorové ovládání nad společným AJAX transportem,
  včetně automatického načítání při přiblížení ovladače do viewportu. Social už
  neimportuje notifikační controller ani nerozlišuje notifikační seznam.
- Avatar, composer a obecný upload při dispose zneplatňují probíhající výběr;
  náhledy ruší FileReader. Sledované účty používají společné request ownership.
- Prezentace, editor a obrázky mají jednoho vlastníka lifecycle; importní vstup
  nepřidává další facade volání. Diskuse nehydratuje celý editor podruhé.
- Vestavěné výsledkové handlery vracejí konkrétní kořeny nebo `[]`.
  `false` nevyvolává náhradní hydrataci dokumentu. Starší `true` zůstává jen
  kompatibilním adaptérem pro externí moduly.
- Feed zveřejňuje 13 skutečně používaných operací místo 26, editor 11 místo 23.
  Testovací a experimentální konzumenti zůstávají zachovaní. Prázdné IIFE
  a nepoužité aliasy ze vstupů jsou odstraněné.

Regrese zahrnují jeden průchod hydrate/dispose, pozdní úspěch i chybu uploadů,
rušení FileReaderu a nativní stránkování více než 40 notifikací v samostatných
core a Blog grafech bez Socialu. Historické výsledky výše patří původnímu auditu;
výsledek následné implementační etapy zaznamenává samostatný závěrečný běh.

### Výsledek implementační etapy

Závěrečný `composer preflight` prošel za 951,25 s: 137 úspěšných položek,
jedna přeskočená skupina a jedna záměrně nevybraná historická kontrola.
Všech 39 frontendových scénářů prošlo. `source_changes_during_run` je prázdné.
[Úplný report](../../.work/tests/runs/20261119-075731-67f290f1bb/report.json)
obsahuje jednotlivé výsledky, časy a prostředí.

Symlink fixture nebyla dostupná, Memcached extension chybí; archivní benchmark
je výslovně mimo výchozí sadu. Testovací runtime byl synchronizován se zálohou.
Živý seznam osmi notifikací se načetl bez overflow a avatarový resource dialog
se otevřel a odstranil přes společný close lifecycle bez zápisu. Vícestránkové
notifikace bez Socialu ověřil izolovaný nativní test, nikoli živá databáze.
Po dokončení preflightu byly doplněny pouze výsledky auditu a opraven počet
testovacích skupin v dokumentaci; aplikační ani testovací vstupy se nezměnily.
