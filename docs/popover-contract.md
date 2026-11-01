# Jednotný kontrakt popoverů

Interaktivní ukotvené panely mají jednu implementaci: `assets/ui/popovers.js`
a společný povrch v `assets/ui/styles/popovers.css`. Platí pro notifikace,
filtry seznamů, archiv, účet, výběr jazyka, našeptávání i akce příspěvků a komentářů.
Doménový modul dodává obsah a jeho akce; nespravuje otevírání ani pozici.

## Struktura a použití

- Kořen má `data-popover`. Obsahuje právě jeden přímý spouštěč a jeden
  přímý panel s třídou `popover-panel`.
- Pro běžné nabídky používat `details` a `summary`. Fungují i bez JavaScriptu.
  Jiný kontejner používá přímý `button` nebo odkaz s `data-popover-trigger`.
  Odkaz si ponechává skutečný cíl pro použití bez JS a modifikované kliknutí.
- Našeptávač používá `data-popover-mode="input"` a přímý vstup s
  `data-popover-trigger`. Kliknutí do pole panel nepřepíná a šipky vstupují
  pouze do otevřených výsledků. `data-popover-match-width="true"` sladí šířku
  panelu s polem; omezování viewportem stále vlastní společný controller.
- Událost `tinycat:popover-close` ruší doménovou práci, například čekající
  hledání a debounce. Platí i při zavření dosud nenačteného našeptávače.
  Obsluha fokusu může přes `popovers.restoringFocus(input)` rozlišit návrat
  po Escape od nového hledání; nesmí znovu otevřít zavřené výsledky.
- `data-popover-close` označuje akci, která má po aktivaci panel zavřít.
  Pole filtrů a přepínače tématu panel nechávají otevřený.
- Programové ovládání je pouze `TinyCat.popovers.open(root)`, `close(root,
  restoreFocus)` a `closeAll()`. Hydrataci provádí společný lifecycle;
  ruční vložení může použít `prepare(root)`.
- Filtry dál používají `ui.filterPanel(id, form)` a společný registr formulářů.
  Obnova seznamu zachovává hodnoty, otevření, posun a fokus přes `filters`.
  Filtr nemá vlastní dialogovou historii ani modalový rám.

## Chování a přístupnost

Spouštěč má společně spravované `aria-controls`. Tlačítka a odkazy mají také
`aria-expanded`; vstup v režimu `input` si zachovává nativní roli `searchbox`,
která tento atribut nepodporuje. Výsledky jsou odkazy na cílové stránky,
nikoli nabídka hodnot přepisujících vstup; nepřidávat umělou roli `combobox`.
Formulář nebo seznam oznámení není automaticky ARIA menu: používat skutečné
odkazy, tlačítka a pole. Notifikace mají pojmenovanou oblast.

Otevření zavře ostatní nezávislé popovery. Kliknutí mimo a přesun fokusu mimo
panel jej zavřou bez přesměrování fokusu. Escape zavře aktivní popover a vrátí
fokus na spouštěč; událost spotřebuje před obsluhou nadřazeného modalu.
Tab není uzamčený. Šipky na spouštěči otevřou panel a vyberou první nebo
poslední ovladatelný prvek. Mezi akcemi fungují šipky, Home a End;
uvnitř vstupních polí zůstávají nativní klávesy včetně Enter beze změny.
Při dotyku uvnitř panelu může WebKit před kliknutím zaostřit jeho předka
(například `main[tabindex="-1"]`). Tento přesun nesmí panel předčasně skrýt;
ochrana končí kliknutím, zrušením dotyku nebo stiskem klávesy. Skutečný
dotyk mimo a klávesový přesun fokusu mimo dál panel zavírají.

Controller používá nativní top layer přes Popover API, aby obsah neřezaly
scrollující kontejnery. Při chybějícím API použije stejný controller běžný
panel. Panel zůstává potomkem původního kořene, takže zachová vazby formulářů
i příslušnost k modalu. Nevytvářet portály do `body`.

Pozici a maximální výšku určuje společný controller podle viewportu a těla
modalu. Umí otevření nad spouštěčem, vodorovné odsazení a vnitřní scroll.
Minimální i maximální šířku omezuje skutečně viditelná plocha z `visualViewport`,
nikoli pouze šířka stránky. Controller předává limit pomocí interní proměnné
`--popover-available-width`; varianty ji nepřepisují. Platí i pro panel
sladěný se šířkou vstupu při přiblížení nebo zobrazené klávesnici.
Přepočítává se při změně viewportu, scrollu předků a změně obsahu. Hydratace
nevytváří další listenery a nepřepisuje formulář. Odstranění kořene uklidí
otevřený panel; otevření nebo zavření modalu zavře související popovery.

## Vzhled a hranice

Rám, pozici, vrstvu, ohraničení, pozadí, stín a přetečení vlastní pouze
`popovers.css` a controller. Varianty dodávají obsah, rozložení a rozměry přes
`--popover-width`, `--popover-min-width`, `--popover-max-height` a
`--popover-padding`. Tím nezávisí na poloze souboru v globální kaskádě.
Nepřidávat druhý panel s vlastním pozicováním nebo mobilní verzi rámu.
Výška panelu odpovídá obsahu (`height: max-content`, `align-content: start`);
limit viewportu pouze zapíná scroll. Nechat výšku na `auto` může ve starším
WebKitu natáhnout grid i jeho řádky na celou dostupnou výšku.

Tooltipy zůstávají v `tooltips.js`: jsou neinteraktivní popisky s jinou
sémantikou a reakcí na hover/fokus. Inline disclosure a reply composer také
nejsou popovery. Pro modal platí [samostatný kontrakt](modal-contract.md).

## Ověření

`tools/ui-contracts.php` při buildu kontroluje společnou strukturu katalogových
popoverů. Runtime odmítne neplatný kořen. `node tests/frontend/popovers.cjs`
ověřuje skutečný prohlížeč, klávesnici, sdílení mezi druhy panelů, kaskádu,
mobilní rozměry, formuláře, modal a odstranění obsahu.
Mobilní matice používá 17 skutečných viewportů (šířky 320–414 px,
výšky 320–812 px, režim na šířku a desktopovou kontrolu). Zmenšení a posun
`visualViewport` pro přiblížení a klávesnici navíc simuluje; nejde o test
fyzického zařízení nebo mobilního Safari.
`node tests/frontend/popover-sizing.cjs` navíc ověřuje skutečné šablony účtu,
archivu a filtrů uživatelů, fronty, příspěvků, běhů, moderace a plánovače
ve WebKitu v pěti mobilních rozměrech včetně režimu na šířku. Kontroluje
také datumová pole a dlouhé vybrané hodnoty bez vodorovného přetékání.
CI používá Playwright 1.52.0 s WebKitem 18.4, kde se roztažení reprodukovalo.
Cestu k instalovanému modulu určuje `TINYCAT_TEST_PLAYWRIGHT_MODULE`.
`node tests/frontend/comment-modals.cjs` ověřuje dotykové otevření editoru
a historie komentáře uvnitř zaostřitelného `main`, také po posunu stránky
a změně výšky okna. Tato struktura reprodukuje přesun fokusu před kliknutím.
`node tests/frontend/popover-touch.cjs` pokrývá všechny rodiny popoverů:
profil a nastavení, tři přepínače tématu, odhlášení, jazyk, archiv, notifikace,
akce příspěvku, datumová pole, všechny filtry a výsledky vyhledávání.
Používá skutečné šablony a dotykové ovládání ve WebKitu při šířkách
320, 390 a 844 px, v zaostřitelném `main` i panelu modalu. Ověřuje právě
jedno kliknutí, zachování vstupu, odeslání formuláře a očekávané zavření
či ponechání nabídky. Neprovádí zápisy na živý server.
Při změně společné vrstvy spustit také `node tests/frontend/admin-viewport.cjs`,
`node tests/frontend/modules.cjs` a `php tools/asset-report.php --check`.
