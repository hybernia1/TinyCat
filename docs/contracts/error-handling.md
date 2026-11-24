# Zpracování chyb

Core vlastní klasifikaci, transport a diagnostiku chyb pro celou aplikaci.
Modul vlastní doménová pravidla a bezpečné veřejné kódy jejich odmítnutí.
Režim diagnostiky se nastavuje pouze přes `app.debug` podle
[provozního návodu](../operations/debugging.md).

API, router, odpověď ani runner úloh nemají lokální debug přepínač. Dřívější
parametry `debug` a `Router::debug()` jsou odstraněné; modul nastavování režimu
nepřebírá. Zachována zůstává pouze kompatibilita instalační konfigurace
`true`/`false` s režimy `'on'`/`'off'`.

## Očekávané odmítnutí a technické selhání

- Validace používá společné `Response::validation()` a strukturované issues.
- Očekávané odmítnutí akce používá `Response::error()` na HTTP hranici nebo
  explicitní `TinyCat\Http\HttpException` s bezpečnou zprávou, HTTP stavem,
  stabilním veřejným kódem a případnými datovými detaily.
- Doménová služba může mít vlastní typované odmítnutí. Její adaptér překládá
  pouze známé případy do veřejného výsledku; neznámé výjimky předává dál.
- Ostatní výjimky jsou technická selhání. Společná `Core\Runtime\Failure`
  jim přiřazuje HTTP 500, `server_error` a bezpečnou zprávu. Číselné pole
  `Exception::getCode()` ani obecný `InvalidArgumentException` nemají význam HTTP.

`HttpException` je veřejný transportní kontrakt. Do zprávy a detailů nepatří
SQL, cesty, přihlašovací údaje ani text neznámé vnořené výjimky. Chyba jeho
definice je `LogicException` a tedy technické selhání. Stavy 5xx lze explicitně
použít i pro bezpečné očekávané odmítnutí, například plánovanou nedostupnost.

API, HTML a CLI sdílejí klasifikaci a diagnostickou politiku. API zachovává
[společnou obálku](../reference/api.md), HTML svůj chybový rám a CLI nenulový
návratový kód. Nečekané selhání se zaznamená přes `Failure::report()`;
`Failure::message()` poskytuje bezpečnou zprávu podle režimu debugu. Očekávané
veřejné odmítnutí se do technického logu nezapisuje.

## Pravidla pro catch

1. Bez místní obnovy nech výjimku dojít na společnou hranici. Nepřidávej catch
   jen kvůli opětovnému vyhození nebo druhému logování.
2. Rollback a úklid zachovávají původní výjimku a znovu ji vyhodí. Doménové
   zápisy zůstávají atomické a soubory se odstraňují až podle vlastníka transakce.
3. Rozhodovací kontroly nesmějí při technickém selhání vrátit povolující výsledek.
   Jde zejména o oprávnění, limity, jedinečnost a způsobilost publikace.
4. Doplňkové přehledy, cache a jiné obnovitelné části mohou zachovat fallback,
   ale neočekávanou výjimku před návratem ohlásí přes `Failure::report()`.
5. Následný efekt po již dokončeném zápisu nesmí předstírat rollback. Zachová
   svůj dosavadní fallback nebo retry a ohlásí příčinu selhání.
6. Úzké zachycení očekávaného odmítnutí vstupu (například `InvalidImage`) se
   nesmí rozšířit na celý `Throwable`; chyba disku či implementace není HTTP 422.

Stejný objekt výjimky zapisovač zaznamená jednou. Neznámé výjimky nepřeváděj
na prázdné výsledky bez diagnostiky. Výjimkou jsou minimální nouzové pokusy
o sestavení samotné chybové stránky; její dostupnost nesmí záviset na DB,
překladači nebo fungujícím hlavním layoutu.

Historie úloh a importů zůstává vlastníkům. Veřejný výsledek používá bezpečnou
zprávu; původní technická příčina patří do společné diagnostiky. Historie sama
nenahrazuje ohlášení výjimky. Přímé PHP `error_log()` v aplikaci nepoužívej.

## Host a testy

Runtime respektuje již registrovaný exception handler hostitele. CLI testy
registrují vlastní handler přes společnou podporu testů ještě před konfigurací
aplikace, aby assertion zůstala viditelná i při produkčním `debug=off`.
Tato výjimka neodkrývá produkční HTTP chyby ani nemění nastavení instalace.

Regrese: `php tests/run.php --only=tests/infrastructure/debug.php,tests/infrastructure/failures.php`.
První skupina kontroluje transport a režimy v oddělených procesech, druhá
selhání rozhodovacích kontrol, obnovitelný fallback a hranice diagnostiky.
Browserové chyby používají klientský lifecycle a API transport; neodesílají
se automaticky do serverového debug logu. Samostatná aplikace `feeds/`
má vlastní runtime a tento kontrakt ji nepřipojuje k TinyCat.
