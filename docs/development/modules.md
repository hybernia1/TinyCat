# Moduly a HTTP transport

Vestavěné moduly registruje explicitní seznam v [App/modules.php](../../App/modules.php).
[Bootstrap](../../App/bootstrap.php) skládá aplikaci; [index.php](../../index.php)
zajišťuje vstupní kontroly a dispatch. Routy se neobjevují procházením filesystemu.
Aktuální metody, adresy a datové kontrakty popisuje [HTTP API](../reference/api.md).

## Vlastnictví

Doménový `Module` registruje vlastní API a webové routy. `Api` obsluhuje JSON
resources a mutace, `Pages` autorizuje stránku a připravuje data pro vykreslení.
`App/Http` vlastní transport, autentizační a CSRF kontroly, čtení požadavků
a serializaci výsledků. Doménová pravidla zůstávají v příslušném modulu.

| Modul | Odpovědnost |
| --- | --- |
| Installation | Instalace a příprava schématu. |
| Users | Účty, přihlášení, obnova, profily, avatary a sledování. |
| Notifications | Doručování a schránka notifikací. |
| Status | Příspěvky, komentáře, reakce, feedy, tagy, odkazy a média. |
| Bots | RSS/Atom import a administrace botích účtů a zdrojů. |
| Settings | Nastavení aplikace. |
| Moderation | Reporty, omezení a blokování. |
| Search | Hledání a jeho omezení. |
| Security | CAPTCHA, CSRF, rate limits a validace nedůvěryhodných vstupů. |
| Scheduler | Plánované úlohy a jejich transport. |
| Health | Diagnostika instalace a HTTP měření. |
| Updates | Podepsané aktualizace, migrace, zálohy a rollback. |
| Site | Veřejné stránky, layout, navigace a discovery dokumenty. |

Sdílené technické služby poskytují zejména `Support`, `Database`, `Http` a `Mail`.
Seznam tříd udržuje samotný kód; tento dokument neurčuje nové hranice závislostí.
Budoucí změny vlastnictví schématu řeší [otevřený návrh 6.0](../plans/modularity-6.0.0.md).

## Data a prezentace

API vrací kanonická data, oprávnění a validační omezení. Nevrací HTML šablony,
komponentové definice ani překladové identifikátory. Výsledky používají společnou
JSON obálku a strojové kódy; podrobnosti stanoví [překladový kontrakt](../contracts/translations.md).
Identita z cesty má přednost před identitou v těle požadavku. Oprávnění se ověřují
na serveru při každém čtení a zápisu.

Stránky vykreslují počáteční HTML; AJAX používá společného JSON klienta a DOM
komponenty. Sdílené [vykreslování](partials.md), [modaly](../contracts/modal-contract.md)
a [popovery](../contracts/popover-contract.md) mají vlastní závazné kontrakty.

Klientské manifesty `assets/modules/*/module.json` deklarují routové scopes,
vstupní skripty, komponenty, styly a překladové závislosti. `ClientAssets`
a `AssetGraph` vybírají a sestavují jejich závislosti; release obsahuje
předkompilované assety a jazykové katalogy.

## Ověření změn

- `php tests/quality/module-boundaries.php` a `php tests/quality/dependencies.php`.
- `php tests/http/api-contract.php` pro transport, oprávnění a datové kontrakty.
- `php tests/quality/render-only-partials.php` pro šablony bez doménové logiky.
- `composer preflight` pro úplnou vývojovou kontrolu v připraveném prostředí.

Historická mapa již odstraněných endpointů a hotové etapy migrace byly
z dokumentace odstraněny. Jejich obsah zůstává v historii Gitu; nepředstavují
podporované aliasy aktuálního API.
