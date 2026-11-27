# Audit velikostí CSS v Core a Socialu

Datum: 27. listopadu 2026. Stav: pracovní zdroje včetně aktuální úpravy administrace a společného navigačního badge. Audit popisuje současný stav a návrh; nezavádí nový CSS kontrakt.

## Implemented follow-up (27 November 2026)

The inventory and findings below describe the original state. The follow-up
keeps the existing root scale and compact multiplier, with no new global tokens.
Core navigation, badges, account/notification/search/language menus, empty states,
result lists, galleries and small form layouts now use rem and existing spacing
or control tokens. Navigation icons follow text in em. Badge geometry is shared
between desktop and mobile.

Avatar presentation accepts `size`, with scoped `sm`/`xl` classes and compatible
legacy aliases. Compact identity rows now reduce the symbol and matching column.
Small buttons and menu actions use scoped `sm`; icon buttons share one local
height/width metric. Social no longer repeats composer button dimensions.
Composer grid and avatar share one metric; status headers use the default avatar.
Public list titles own their typography without overriding `text-2xl`.

Intentional exceptions: the profile avatar keeps 4.75rem for the narrow profile
panel, shared by editable/read-only states, without redefining general `xl`.
The composer avatar aligns with its control. Content/media layouts remain Social
owned and scale in rem; viewport caps remain relative to the viewport. Hairlines,
decorative effects and explicit accessibility floors remain independent.

CSS rule fingerprints were reviewed and regenerated without weakening the full
inventory assertion. Only exceeded CSS delivery budgets were adjusted for the
longer shared-variable notation and compatibility selectors; script/stylesheet
counts remain unchanged. Targeted reports live under `.work/tests/runs/`.
A full preflight was explicitly excluded by the user.

Verification completed for this follow-up:

- PHP syntax for the two changed presentation classes, Composer style and
  PHPStan analysis passed. Catalog formatting, generated UI inventory and
  diff whitespace checks passed.
- [Boundaries, render-only, UI contracts, presentation and isolated MySQL archive regression](../../.work/tests/runs/20261127-112436-e50fbc9e06/report.json) passed.
- [Native UI scale and component-size matrix](../../.work/tests/runs/20261127-112539-1020035b12/report.json) passed, including fallback avatars, unknown size,
  small square buttons and both densities at 75/100/125/150/200%.
- [Modal validation and admin viewport matrix](../../.work/tests/runs/20261127-112214-b37c077f5b/report.json),
  [embed, tag layout, language, sidebar and stylesheet inventory](../../.work/tests/runs/20261127-112455-88d760967e/report.json),
  and [final native popover, sizing and touch regressions](../../.work/tests/runs/20261127-112617-dba13b6980/report.json) passed.
  Comment resource dialogs and account menu behavior also passed in the earlier
  targeted run; its later identity assertion failure was corrected and rerun above.
- Asset delivery passed all 24 scopes; the [measured report](../../.work/audits/ui-scale/asset-report.json)
  records final sizes. Six raw CSS limits increased by 256 or 384 bytes;
  compressed CSS, JavaScript and request-count limits were not increased.
- Runtime files were synchronized and assets rebuilt in the external Laragon
  installation. Live homepage, profile, status detail, admin users, notifications
  and the user-creation dialog were checked on mobile and desktop across all
  five scales. No page/dialog horizontal overflow was observed. Public profile
  screenshots at 75% and 200% and the admin fixture at 75% were visually reviewed.

The full preflight, complete frontend suite, release/update/rollback tests and
production deployment were not performed. Live checks did not submit forms or
change data. Earlier failed targeted reports are not final verification.

## Zadání navazující implementace

Uživatel po auditu požaduje dotáhnout Core i Social do společně škálovatelného systému v jednom navazujícím pracovním vlákně. Škálování, znovupoužití komponent a sjednocení velikostních variant se mají řešit společně podle skutečného účelu prvků. Níže popsané pořadí je pracovní návrh, nikoli omezení tohoto cíle.

Základní omezení: **nevytvářet token pro každou dnešní hardcoded hodnotu nebo kontextovou výjimku.** Přednost má malá srozumitelná soustava již existujících spacing, typografických a kontrolních tokenů. Nový token má vyjadřovat opakovanou nebo samostatně odůvodněnou metriku; pouze přesunutá lokální konstanta není sjednocení. Nejprve hledat, které varianty a jednorázové třídy lze odstranit využitím existující komponenty.

Social může zachovat několik doložených doménových výjimek pro vlastní obsah a layout. Výjimka musí mít konkrétní důvod a stále respektovat globální měřítko, pokud nejde o výslovně zachované viewportové nebo přístupnostní minimum. Cílem není nový katalog variant pro každý kontext ani přepis všech rozměrů na individuálně pojmenované proměnné.

Ověřování má podle výslovného pokynu uživatele používat cílené kontroly; celý preflight se nemá spouštět automaticky. Průběžná synchronizace souvisejících runtime změn na lokální Laragon zůstává součástí práce.

**Hlavním cílem je jedno globální měřítko UI, například 75 %, které upraví rozměry celého rozhraní bez oprav jednotlivých tříd.** Existující `--ui-scale` je správný vstup. Je potřeba důsledně připojit geometrii, mezery, typografii a ikony k jeho škálovatelné soustavě a odstranit pevné rozměry, které ji obcházejí. Přejmenování `avatar-sm` na `avatar sm` je vedlejší úklid; samo tento cíl nesplní.

## Upřesněný cíl globálního měřítka

Současný root již podporuje například:

```css
:root { --ui-scale: 0.75; }
```

Při základu prohlížeče 16 px a běžné hustotě se tím 1rem změní na 12 px. Rozměr 2.5rem se změní ze 40 na 30 px bez úpravy jeho komponenty. Samostatných 40px zůstane 40 px. Právě druhá skupina je hlavním cílem navazujícího refaktoru.

Hodnota 75 % se nesmí ještě jednou násobit v každém rozměrovém tokenu, protože root ji už obsahuje. Dvojí násobení by UI zmenšovalo na 56.25 % před započtením hustoty. Stávající compact profil navíc násobí root hodnotou 0.875: globálních 75 % proto v administraci znamená výsledných 65.625 % základu prohlížeče. Rozhodnout, zda tuto záměrnou rozdílnou hustotu zachovat; velikost jednotlivé komponenty ji nemá potají kompenzovat.

Navazující práce má probíhat v tomto pořadí:

1. Zachovat jeden vstup `--ui-scale` a přesně popsat jeho skládání s hustotou a nastavením prohlížeče.
2. Rozměry UI a spacing navázat na sdílené tokeny v rem; ikony navázané na text v em. Například pevný 7px padding, pokud má přesně zachovat výchozí vzhled, odpovídá 0.4375rem při 16px základu. Je-li to opakovaná nebo významová metrika, patří do pojmenovaného tokenu, nikoli jako další lokální konstanta. Výběr nejbližšího existujícího spacing tokenu už znamená změnu výchozího rozložení a vyžaduje vědomé rozhodnutí.
3. Odstranit lokální pevné přepisy a zkontrolovat vzájemně související metriky, například výšku tlačítka a šířku jeho ikonové varianty nebo velikost avataru a sloupec gridu.
4. Ověřit reflow, overflow a ovládání při 75/100/125/150/200 % a obou hustotách. Teprve potom řešit případné přejmenování modifikátorů.

Nejde o vizuální transformaci nebo CSS zoom. Layout se má přepočítat přirozeně. Zachovat explicitní výjimky: breakpointy určující dostupný viewport, jemné rámečky, obrázkové intrinsické rozměry a přístupnostní minima nemusí klesat s UI měřítkem. Pokud zůstane například minimální dotyková plocha 44 px nebo text mobilního vstupu alespoň 16 px, nejde o přesné proporcionální zmenšení každého pixelu; jde o jednotně řízené UI s pojmenovanými ochrannými limity.

## Rozsah a metoda

Statická kontrola všech 78 CSS souborů pod `App/Core` a 44 pod `App/Modules/Social`, jejich manifestů, PHP prezentace, komponentových katalogů a souvisejícího JavaScriptu. Počty zahrnují nalezené zdrojové soubory, nikoli velikost nebo počet současně doručovaných assetů. Generované katalogy a historické release balíčky nejsou cílem migrace.

Kontrola použití zahrnovala zdroje PHP, JSON a JS pod `App`. Uvedené počty jsou doslovné textové výskyty názvu, nikoli počet instancí na stránce ani důkaz dosažitelnosti. Dynamické skládání tříd, externí témata a samostatné moduly mohou přidat další konzumenty.

Byla ověřena návaznost na [UI konvence](../contracts/ui-conventions.md#ui-scale), [administraci](../contracts/admin-ui-conventions.md), [modaly](../contracts/modal-contract.md) a [popovery](../contracts/popover-contract.md). Během tohoto auditu nebyla měněna implementace ani spuštěn aplikační preflight. Níže uvedené rozměry jsou odvozené z CSS; nejde o nová měření ve všech prohlížečích.

## Názvosloví

`xs`, `sm`, `md`, `lg`, `xl` obvykle znamenají extra small, small, medium, large a extra large. V `avatar-sm` jde o **velikostní modifikátor** komponenty. `text-lg` je typografická utilita. `sm:grid-2` je responzivní varianta; zde `sm` neznamená menší komponentu.

Jednotný slovník neznamená jednotný počet pixelů pro všechny komponenty. Malý avatar, malé tlačítko a malý text mají rozdílné rozměry a mění jiné vlastnosti.

## Současný inventář

Rozměry v tabulce předpokládají základ prohlížeče 16 px, `--ui-scale: 1` a běžnou hustotu, pokud není uvedeno jinak.

| Rodina | Současné varianty a hodnoty | Konzumenti a důsledky |
| --- | --- | --- |
| Avatar | Základ 2.75rem / 44 px; `avatar-sm` 2.125rem / 34 px; `avatar-xl` 6rem / 96 px | [Základ](../../App/Core/Ui/assets/styles/avatar.css), [velikosti](../../App/Core/Ui/assets/styles/avatar-sizes.css). `avatar-sm`: 13 doslovných výskytů v pěti zdrojových souborech; `avatar-xl`: tři ve dvou. |
| Avatar profilu Socialu | `avatar-xl`, ale šířka 4.75rem / 76 px | [Profil](../../App/Modules/Social/assets/users/styles/profile.css) přepisuje Core variantu přímo přes `width` a `height`. |
| Tlačítko | Základ používá `--control-height` 2.5rem / 40 px; `btn-sm` používá 2.25rem / 36 px a menší padding i písmo | [Tlačítka](../../App/Core/Ui/assets/styles/buttons.css). 63 doslovných výskytů `btn-sm` v deseti zdrojových souborech. Používá se i na `.context-menu-action` bez `.btn`. |
| Ikona | `.icon` 1em; `.icon-lg` 1.25em | [Reset](../../App/Core/Ui/assets/styles/reset.css). `.icon-lg` není úplná náhrada základní `.icon`: sama nedeklaruje například `display` a `flex`. V kontrolovaných PHP/JSON/JS nebyl nalezen doslovný výskyt `icon-lg`; to není důvod k automatickému odstranění. |
| Text | `text-base` 1rem, `text-lg` 1.125rem, `text-xl` 1.375rem, `text-2xl` 1.75rem | [Utility v buttons.css](../../App/Core/Ui/assets/styles/buttons.css). Social hodnotu `text-2xl` lokálně mění. |
| Šířky | `.container`, `.container-sm` 45rem, `.max-w-auth-sm` 32.5rem | [Layout](../../App/Core/Ui/assets/styles/layout.css), [utility](../../App/Core/Ui/assets/styles/buttons.css). Jde o šířku layoutu, nikoli velikost ovladače. |
| Řádek identity | `.identity-row`, `.identity-row-compact`, `.identity-row-inset` | [Styly](../../App/Core/Ui/assets/styles/identity-rows.css). `compact` mění padding, `inset` jej odstraňuje. Rozměr avataru je odvozený od tokenu identity. |
| Prázdný stav | `.empty-state`, `.empty-state-compact` | [Styly](../../App/Core/Ui/assets/styles/empty-state.css). Varianta mění výšku bloku, padding, mezery, ikonu i typografii. |
| Dialog | `data-modal-size="compact/wide/reading/workspace/confirm"` a základní velikost | [Tokeny](../../App/Core/Ui/assets/styles/tokens.css), [modal](../../App/Core/Ui/assets/styles/modal.css), [factory](../../App/Core/Ui/assets/dialogs.js). Část názvů popisuje účel a chování. |
| Responzivní grid | `sm:grid-2`, `md:grid-2/3/4` | [Grid](../../App/Core/Ui/assets/styles/grid-desktop.css). Všechny uvedené varianty nyní začínají na 900 px. |
| Navigační badge | `.nav-badge` se společným desktopovým a mobilním rozměrem | [Společný styl](../../App/Core/Ui/assets/styles/nav-badge.css). Profil a notifikace už sdílejí jednu definici, ale její geometrie zůstává v px. |

## Zjištění podle priority

### 1. Velikostní názvy dnes negarantují stejný význam napříč kontexty

**Priorita: vysoká pro návrh sjednocení.** [Social profil](../../App/Modules/Social/assets/users/styles/profile.css) používá `.profile-identity .avatar-xl` a nastaví 76 px místo Core 96 px. Přímé `width` navíc obchází existující `--avatar-size`. Při kompaktní hustotě jsou obě rem hodnoty opět škálované, ale stále rozdílné: 66.5 px proti 84 px při nezměněném základu prohlížeče.

Podobně [veřejné záhlaví Socialu](../../App/Modules/Social/assets/site/styles/public-list-header.css) přepisuje `.text-2xl` z 1.75rem na `clamp(1.25rem, 2vw, 1.5rem)`. [Mobilní záhlaví](../../App/Modules/Social/assets/site/styles/public-list-header-mobile.css) pak používá stejnou typografickou utilitu také jako strukturální selektor pro `min-width` a zalamování.

Před přejmenováním rozhodnout, zda má být profil skutečně `xl`, jiný existující rozměr, nebo účelová varianta. Pro nadpis zavést vlastní třídu typu `.public-list-title`; velikost a layout se pak nebudou vázat na náhodně zvolenou utilitu. Číselné přiřazení nových velikostí nemá vzniknout pouhým odhadem.

### 2. Škálování už existuje, ale některá geometrie jej obchází

**Priorita: vysoká vzhledem k cíli škálovatelnosti.** [Root tokeny](../../App/Core/Ui/assets/styles/tokens.css) skládají základ prohlížeče, `--ui-scale` a `--ui-density-scale`. Administrace používá hustotu 0.875; kontrolní a modální rozměry převážně stojí na rem tokenech. To je vhodný základ, který je potřeba zachovat.

Vedle něj jsou pevné rozměry: [navigace](../../App/Core/Ui/assets/styles/navbar.css) má ikonový odkaz 40 px a ikonu 20 px, [profilová nabídka](../../App/Core/Identity/assets/styles/user-menu.css) má minimální výšku 38 px, [badge](../../App/Core/Ui/assets/styles/nav-badge.css) má 18/17 px, [prázdné stavy](../../App/Core/Ui/assets/styles/empty-state.css) mají padding a ikony v px. Písmo přitom často roste v rem. Při zvýšení UI scale se tedy může text zvětšit rychleji než jeho okolní geometrie. U badge jde konkrétně o pevnou výšku s rostoucím písmem; nejde o ověřené tvrzení, že už v konkrétním měřítku dochází k ořezu.

Převádět geometrii cíleně na rem/em a účelové tokeny. **Nepřevádět mechanicky všechny px:** vlasové rámečky, viewport breakpointy, 16px minimum mobilního vstupu a minimální dotykové cíle mají vlastní důvod. [Mobilní vstupy](../../App/Core/Ui/assets/styles/mobile-inputs-and-dialogs.css) a [UI scale kontrakt](../contracts/ui-conventions.md#ui-scale) tyto výjimky už popisují.

### 3. API avataru není připravené na více velikostí

**Priorita: vysoká pro rozšiřování komponent.** [Komponenta ui/avatar](../../App/Core/Ui/assets/components.json) má boolean `small`, který skládá `avatar-sm`. Vedle toho Core Identity a Social používají přímo zapsané třídy `avatar avatar-sm` a `avatar avatar-xl`; například [Identity katalog](../../App/Core/Identity/assets/components.json) a [Social katalog](../../App/Modules/Social/assets/components.json).

Zavést prezentační parametr `size` s povolenými hodnotami, výchozí velikostí a jedním mapováním na výsledné třídy. Při migraci buď převést všechny interní volající současně, nebo dočasně podporovat `small` jako jednoznačný kompatibilní vstup. Doménové HTTP resources tím nemají získat CSS třídy ani velikostní politiku; změna patří do prezentačních komponent.

### 4. Kombinace velikosti a typu tlačítka není jednoznačně modelovaná

**Priorita: střední.** [buttons.css](../../App/Core/Ui/assets/styles/buttons.css) u `btn-sm` zmenšuje minimální výšku, ale `.btn-icon` bere šířku ze základního `--control-height`. V běžné hustotě tak deklarace pro `btn-sm btn-icon` kombinují šířku 40 px s minimální výškou 36 px. Nejde o měření každého výsledného tlačítka; výslednou výšku může ovlivnit obsah a další pravidla.

Stejný `btn-sm` používá i `.context-menu-action`, která nemá základ `.btn`. Náhrada pouze selektorem `.btn.sm` by proto část současných konzumentů minula.

Velikost má nastavovat lokální metriky tlačítka včetně výšky, paddingu a textu; ikonová varianta má číst stejnou výslednou výšku i pro šířku. Pro akce nabídky explicitně rozhodnout mezi samostatným `.context-menu-action.sm` a společným kontraktem ovladače. Nepředpokládat, že každá současná `btn-sm` je tlačítko `.btn`.

### 5. Kontrakt identity neodpovídá implementaci compact varianty

**Priorita: střední.** [UI konvence](../contracts/ui-conventions.md) uvádějí avatar 36 px pro `identity-row-compact` a 44 px pro běžný řádek. [CSS identity](../../App/Core/Ui/assets/styles/identity-rows.css) však u compact varianty mění jen padding. Rozměr avataru v obou variantách odvozuje od `--identity-symbol-size`; přímý potomek `.avatar` zdědí tento rozměr i přes vlastní velikostní třídu.

Je potřeba rozhodnout mezi skutečně menším avatarem a pouze hustším řádkem a sladit dokumentaci s výsledkem. Třídu `compact` nelze bez tohoto rozhodnutí převést na univerzální `sm`: hustota a velikost prvku jsou dvě různé osy.

### 6. Nesměšovat velikost, hustotu, typografii a breakpoint

**Priorita: střední.** `sm:grid-2` a `md:grid-*` dnes sdílejí hranici 900 px. Zavedení `sm` jako samostatné třídy nesmí měnit tento breakpoint ani naznačovat, že již existuje několik různých responzivních pásem. Změna breakpointů je samostatné rozhodnutí.

`text-lg` ponechat typografickou utilitou: `text lg` by bez přidaného rozlišení nebylo jasné, zda zvětšuje písmo, nebo celý komponent. U dialogů ponechat stávající factory, `data-modal-size` a účelové varianty; `workspace` mění i rozložení a mobilní chování, nikoli jen šířku.

### 7. Social částečně přepisuje metriky místo skládání komponent

**Priorita: střední.** [Avatary statusů](../../App/Modules/Social/assets/status/styles/status-avatars.css) deklarují základní 2.75rem znovu a pro composer používají rozměr ovladače. [Mobilní composer](../../App/Modules/Social/assets/status/styles/status-content-mobile.css) znovu nastavuje šířku i výšku avataru přes kontextový selektor.

Ne všechny takové rozměry jsou chyba: composer může oprávněně zarovnávat avatar s ovladačem. Je však vhodnější vyjádřit tento účel jedním lokálním `--avatar-size` a stejným tokenem sloupce gridu, než opakovat `width` a `height` v několika selektorech. Core má vlastnit mechanismus a obecné velikosti; Social dál vlastní své rozložení.

## Doporučený kontrakt

### Podporovat zápis avatar sm v rámci komponenty

Pro TinyCat je požadovaný zápis realizovatelný bez globálních pravidel `.sm`, `.lg` apod.:

```html
<span class="avatar sm">...</span>
<button class="btn sm btn-primary">...</button>
<svg class="icon lg">...</svg>
```

```css
/* Návrh, nikoli současná implementace. Hodnoty nejprve schválit. */
.avatar { --avatar-size: var(--avatar-md); }
.avatar.sm { --avatar-size: var(--avatar-sm); }
.avatar.xl { --avatar-size: var(--avatar-xl); }

.btn { --button-height: var(--control-height); }
.btn.sm { --button-height: var(--control-height-compact); }
/* Výška a ikonová šířka potom obě čtou --button-height. */
```

Modifikátor nastavuje jen metriky vlastní rodiny. `.sm` samostatně nedeklaruje šířku, padding, písmo ani univerzální děděný `--size`. Při vnořeném velkém tlačítku s malou ikonou tím nevznikne nechtěná společná velikost.

`xs/sm/md/lg/xl` tvoří slovník; každá rodina podporuje pouze smysluplnou podmnožinu. Není nutné hned přidat pět variant všem komponentám. `md` může být výchozí stav bez další třídy.

Pojmenování `size-sm` je alternativou s nižší pravděpodobností kolize s cizími styly, ale je delší. Pro nynější kontrolované TinyCat komponenty dává `.avatar.sm` smysl. U témat a externích modulů musí být smluvně zakázána globální velikostní pravidla nad samotnou `.sm`; CSS izolaci před cizím stylesheetem tento zápis sám neposkytuje.

### Čtyři samostatné osy

| Osa | Vlastník a doporučený zápis |
| --- | --- |
| Globální přístupnost a měřítko | Core `--ui-scale`; respektovat základ prohlížeče. |
| Hustota stránky | Core `data-ui-density="compact"`; nepoužívat jako náhradu velikosti avataru. |
| Velikost komponenty | `size` v prezentačním API, `.avatar.sm` / `.btn.sm`, rodinné tokeny. |
| Typografie a responzivní layout | `text-lg`, `sm:grid-2` a účelové layouty; oddělené významy. |

## Navržená migrace

1. **Nejprve rozměrová mapa.** Schválit velikosti avatarů, tlačítek a ikon; určit záměr profilového avataru, identity compact a čtvercového ikonového tlačítka. Zaznamenat odděleně pravidla pro dotyk, hustotu a UI scale.
2. **Core komponenty a jejich API.** Připravit rodinné tokeny a scoped modifikátory. Převést `small` na `size`, explicitní třídy v Core katalozích a PHP skládání tlačítek. Nezaměnit přitom `.context-menu-action` za `.btn`.
3. **Social a kontextové přepisy.** Převést katalog, profil, statusy, composer a náhledy. Odstranit přepisy `avatar-xl` a vazby layoutu na `text-2xl` až po rozhodnutí o jejich cílových rozměrech. Prověřit embed jako samostatný dokument.
4. **Dokončení a kompatibilita.** Pokud je nutná přechodná kompatibilita témat/modulů, ponechat dočasné aliasy se stejným blokem deklarací, ne druhou implementací. Potom aktualizovat kontrakty, inventář, fixture a release mazací plán při skutečném odstranění souborů.

Přejmenování `.avatar-sm` na `.avatar.sm` zvyšuje specificitu z jedné třídy na dvě. Je potřeba zkontrolovat kontextové přepisy a pořadí stylů; prostý hromadný replace není ekvivalentní migrace. Zachovat rovněž potřebnou základní `.icon` při případném převodu `icon-lg` na `icon lg`.

## Ověření pro budoucí implementaci

Pro samotný audit: diff, odkazy a ověření tvrzení proti zdrojům. Implementace zde nebyla měněna, takže nový preflight nepřidává pokrytí auditu.

Pro následnou migraci použít cílené existující kontroly podle zasažených rodin: UI kontrakty, asset report, komponentovou paritu a stylesheet inventář; při PHP změnách lint a render-only kontrolu. Browserové scénáře vybrat pro avatary a účet, veřejný profil, composer a komentáře, administraci, popovery a modaly. Ověřit regular/compact, UI scale 100/125/150/200 %, mobil i desktop, dlouhé popisky, fallback avataru a ikonová tlačítka.

Důkaz správnosti má být zachované chování a použitelnost, nikoli test kopírující hodnoty CSS. Existující seznam scénářů poskytuje [frontendový runner](../../tests/frontend/run.php); rozměrové kombinace již ověřuje například [admin viewport](../../tests/frontend/admin-viewport.cjs). Případné nové regrese mají pokrýt konkrétní dosud neověřený problém.

## Rozhodnutí navržené k navazující práci

Prioritou je dokončit škálování přes existující `--ui-scale`, nikoli přejmenování tříd. První etapa má převést pevnou geometrii navigace, profilu, badge a dalších sdílených prvků na škálovatelné tokeny a odstranit odpovídající přepisy Socialu. Výchozí vzhled zachovat, pokud není výslovně rozhodnuto jinak; úspěch posoudit i při 75 %.

Společný slovník velikostí a komponentově omezený zápis `avatar sm` může následovat jako samostatný úklid API. U avatarů řešit rozdíl profilového `xl` a vstup `small`; u tlačítek akce nabídek a ikonové varianty. Globální `.sm` s univerzální fyzickou velikostí nezavádět. Ani jedna z těchto jmenných změn není podmínkou fungování globálního měřítka.
