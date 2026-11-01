# Audit modalů — 30. 10. 2026

## Stav po sjednocení

Následná oprava settings odstranila předem vložený formulář z administračního
shellu. Nastavení nyní načítají `/api/settings/editor` přes společný resource
loader a po zavření se odstraňují. Dřívější popis persistentního lokálního
settings editoru níže je historický stav.

Níže je původní nálezový stav. Navazující implementace zavedla společné
typy `content` / `editor` / `confirm`, pojmenované velikosti a tokeny rámu.
JS a PHP editory sdílejí `ui/editor-actions`. Všechny editory používají
stejný mobilní režim; nahlášení, autentizace a nově dohledaná CAPTCHA
vyhledávání mají akce ve společné patičce. CAPTCHA již neskládá vlastní
HTML modalu a její požadavek se při zavření ruší.

Dílčí uložení vlastního účtu nezavírá ostatní koncepty, změna hesla resetuje
pouze svůj formulář a navigace po změně jazyka respektuje ochranu změn.
Vytvoření bota i nahlášení chrání rozepsané hodnoty. Osiřelé auth/following
styly a lokální přepisy rámu byly odstraněné.

Regresní testy pokrývají matici editorů, patičky, ochranu ostatních záložek,
odmítnutou navigaci po změně jazyka, skutečnou větev CAPTCHA vyhledávání a
simulovaný visualViewport s vnořeným modalem a obnovou scrollu.
Vizuální ověření na zařízení a ověření čtečkou zůstává samostatnou kontrolou;
automatický test simulace není náhradou těchto kontrol.

Audit aktuálního pracovního stromu zahrnuje frontend i administraci. Jde o
kontrolu zdrojového kódu a existujících testů, nikoli o vizuální ověření v
prohlížeči nebo na zařízení s mobilní klávesnicí. Níže rozlišuji doložené
rozdíly v implementaci od dopadů, které je ještě potřeba ověřit interaktivně.

## Závěr

Máme jednu HTML komponentu `ui/modal` a jeden správce otevření, zavírání,
focusu, zásobníku a vzdáleného načítání v `assets/ui/dialogs.js`.
Nemáme však jeden kontrakt pro sestavení a chování editoru. PHP wrapper,
JS factory a jednotlivé moduly stále rozhodují o příliš mnoha stejných věcech.
Poslední sjednocení osobního účtu přes `sectionedDialog()` pomohlo, ale
nevyřešilo ukládání více formulářů, mobilní režim ani ostatní typy modalů.

## Nálezy podle priority

### 1. Vlastní účet může při uložení zahodit změny v jiné záložce

**P1, doložený tok zavření; ověřit scénář v prohlížeči.**

`assets/modules/users/views.js:203` zpracovává úspěšnou odpověď s `data.user`
a na řádku 215 volá přímo `T.dialog.close(modal)`. Osobní účet přitom
obsahuje samostatné formuláře Účet, E-mail a Zabezpečení. Přímé `close()`
neprovádí kontrolu neuložených změn; tu obsahuje `requestClose()`.
Vzdáleně načtený modal se při zavření odstraní.

Scénář: rozepsat bio, přejít do E-mailu a uložit jej. Pokud operace vrátí
`data.user`, celý editor se zavře i s rozepsaným bio. Obdobně zavření po
změně hesla může zasáhnout jiné záložky. Pouhá změna vizuálního wrapperu to
neřeší.

Náprava: po uložení záložky označit jako uložený pouze její formulář,
aktualizovat potřebná data a ponechat editor otevřený. Změna jazyka, která
vyžaduje navigaci, musí respektovat koncepty v ostatních formulářích.

### 2. Mobilní režim formulářů závisí na tom, kdo modal vytvořil

**P1 k ověření na mobilu, doložená rozdílná klasifikace.**

`modalUsesVisualViewport()` v `assets/ui/dialogs.js:95` vybírá pouze
`.modal-form` a `.modal-mobile-fullscreen`. Stejné selektory používá mobilní
CSS v `assets/ui/styles/061-input-not-type-checkbox-not-type-radio-not-ty.css`.

- PHP `Support/modal` přidává `.modal-form` automaticky při zadané akci.
- JS `formDialog()` ji nepřidává.
- Editor příspěvku ji přidává ručně.
- Osobní účet ji dostává v nové větvi pro více formulářů.
- JS editace uživatele, zdroje, nastavení a workspace bota ji přes
  `adminDialog()` nedostávají.
- Editace komentáře, avatar, přihlášení a nahlášení ji také nepřidávají.

Výsledkem jsou rozdílné cesty pro visualViewport, zamknutí stránky a
celoplošný formulář při zobrazení klávesnice. Samotná `.modal-fullscreen`
není pro tyto mechanismy dostačující.

Náprava: mobilní chování musí nastavovat společná factory podle typu
modalu. Ověřit klávesnici, scroll k chybě, vnořený avatar a obnovení scrollu.

### 3. Nahlášení obchází standard akcí i ochranu rozepsaného formuláře

**P2, doloženo.**

`assets/modules/status/views.js:34` skládá formulář nahlášení pomocí
`U.dialog()`. Odeslání zůstává v těle, modal nemá společnou patičku.
Pomocná funkce `form()` nastavuje ochranu změn pouze pro update/comment/
comment_update, nikoli report. Zavření tedy nechrání rozepsanou poznámku.

Náprava: kompaktní formulářový modal se společnou patičkou a ochranou změn.

### 4. Vytvoření bota má vlastní cestu a nechrání rozepsané hodnoty

**P2, doloženo.**

`App/Bots/templates/parts/modals/account-create.php` sestavuje patičku jako
HTML řetězec a předává ručně `modal-fullscreen`, `modal-panel-full` a
`admin-modal-panel`. Nepředává `data-confirm-unsaved`; ani PHP wrapper tuto
ochranu automaticky nepřidává. Vytvoření uživatele používá JS factory a
`U.form()`, takže se tyto dva administrační editory chovají rozdílně.

Náprava: společný kontrakt editoru dostupný pro serverové i klientské
vykreslení; nevynucovat přesun všech serverových formulářů do JS.

### 5. Rozměry a vnitřní odsazení jsou stále rozptýlené

**P2, doloženo.**

Aktivní rozměry zahrnují základ 560 px, wide 920 px, potvrzení 420 px,
avatar max-width 440 px, detail příspěvku 740 px a workspace 80 vw / 80 vh
od breakpointu 900 px. Pod tím workspace používá 100 vw / 100 vh.
Základní odsazení je 12 px, workspace přepisuje vodorovné na 16 px,
detail i editace příspěvku používají lokálně 14 px.

Zdroje: `056-tabs.css:131`, `058-modal-panel-full.css:1`,
`092-user-modal-panel-modal-panel-full-following-m.css:1`,
`049-avatar-upload.css:65`, modul status `051-status-time-button.css:30`
a `039-status-comment-history.css:196`.

Více velikostí má smysl. Problém je, že jejich volba není pojmenovanou
součástí společné komponenty a moduly si nastavují její rám.

Náprava: společné velikostní varianty a tokeny pro šířku, maximální výšku
a odsazení. Moduly smějí upravovat obsah, ne hlavičku/tělo/patičku rámu.

### 6. Hlavičky a akce se skládají třemi různými způsoby

**P2, doloženo.**

PHP wrapper podporuje ikonu hlavičky, `TinyCat.dialog.create()` ji do
`ui/modal` neposílá. Účty pak dodatečně přestavují titulek přes
`accountDialogTitle()`. Vytvoření bota má ikonu v titulku i tlačítkách,
obecné `editorActions()` vytváří jinou skladbu tlačítek. Avatar má vlastní
skupinu akcí s odstraněním, která je funkčně odůvodněná, ale nemá společné
pojmenované místo pro vedlejší destruktivní akci.

Náprava: společné vstupy title/icon/status a sloty secondary/destructive/
primary. Zachovat stav účtu před nickname. Potvrzení a prosté zobrazení
nemusí mít stejné akce jako editor.

### 7. Zůstaly osiřelé styly modalů

**P3, doloženo vyhledáním použití.**

- `.auth-modal-panel` a `.auth-modal-body` v
  `assets/modules/users/styles/057-auth-modal-panel.css` se stále načítají,
  ale žádná aktuální factory tyto třídy nepřidává.
- `.following-modal-panel.modal-panel-full` ve společném desktopovém
  přepisu nemá aktuálního producenta třídy; seznam sledovaných používá
  obyčejný `U.dialog()`.

Náprava: odstranit mrtvé větve společně s referencemi v manifestech.
Nepovažovat samotnou přítomnost CSS za existenci funkční varianty.

### 8. Testy ověřují jednotlivé části, nikoli celý kontrakt editoru

**P2, doloženo rozsahem testů.**

Existují kontroly společné HTML komponenty, focus/stack základu,
form-associated tlačítek, jednotlivých rendererů a přepnutí záložek.
Chybí společná matice, která by všechny editory ověřovala proti stejným
požadavkům. Aktuální testy mohou projít i přes výše uvedené rozdíly.

Doplnit zejména scénáře více rozepsaných záložek a uložení jedné z nich,
zavírání přes Escape/backdrop/křížek, mobilní klávesnici, chybu validace ve
skryté záložce a vnořené modaly. Přístupnost pozadí při otevřeném modalu
ověřit čtečkou; správce má focus trap a aria-modal, ale nepoužívá inert pro
zbytek stránky. Samotná absence inert není důkaz selhání čtečky.

## Mapa současných povrchů

| Povrch | Sestavení | Hlavní rozdíl |
| --- | --- | --- |
| Potvrzení | dialog.create | účelově kompaktní; ponechat |
| Nastavení webu | sectionedDialog, jeden formulář | persistentní; po uložení reload a obnova záložky |
| Uživatel — administrace | sectionedDialog, jeden formulář | mobilní klasifikace není automatická |
| Vlastní účet | sectionedDialog, více formulářů | zavření po dílčím uložení, odlišné endpointy jsou nutné |
| Vytvoření uživatele | sectionedDialog | persistentní, ale znovu sestavené při obnově seznamu |
| Vytvoření bota | PHP Support/modal | ruční rám a patička, chybí ochrana změn |
| Workspace bota | adminDialog + tabbedContent | vlastní řízení lazy seznamů a patičky má účel |
| Zdroj | sectionedDialog | společný editor, chybí automatická mobilní klasifikace |
| Náhled zdroje | dialog + editorActions | vnořený obsah a potvrzení před uložením |
| Avatar | dialog + vlastní patička | samostatné upload/appearance/delete operace |
| Přihlášení/registrace | dialog + auth-content | odeslání v těle, mrtvé lokální styly |
| Nahlášení | dialog + formulář | odeslání v těle, nechráněná poznámka |
| Editace příspěvku | formDialog + ruční třídy | wide, vlastní odsazení, mobilní režim ručně |
| Editace komentáře | formDialog | patička společná, mobilní režim nenastavený |
| Detail příspěvku | dialog + ruční třídy | čtecí/detailový modal, šířka a body lokálně |
| Sledovaní, embed, revize, cron detail, changelog | základní dialog/PHP wrapper | čtecí obsah, zpravidla bez ukládání |

## Doporučené sjednocení

1. Nejprve opravit ztrátu konceptů a zavést automatický mobilní režim
   editorů. U složitých formulářů zvolit explicitní politiku po uložení.
2. Nad existujícím `ui/modal` zavést jednotný kontrakt pro **potvrzení**,
   **zobrazení obsahu** a **editor**. Záložky jsou možnost editoru, nikoli
   další druh rámu. Velikost je samostatná pojmenovaná varianta.
3. Sjednotit hlavičku a akce, převést nahlášení a PHP vytvoření bota,
   odstranit lokální rámové přepisy a osiřelé CSS.
4. Zvláštní obsah avataru, náhledu a botích seznamů ponechat jako obsahové
   komponenty. Nesjednocovat rozdílné bezpečnostní endpointy účtu a admina.
5. Přidat testovací matici kontraktu a vizuální/mobilní ověření reprezentantů
   všech tří variant včetně vnoření a neuložených změn.

Audit nemění chování aplikace. Opravy je vhodné provést jako navazující
celek, nikoli pokračovat v přidávání ručních tříd jednotlivým modalům.
