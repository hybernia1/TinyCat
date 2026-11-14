# Notifikace a vlastnictví typů

Core vlastní schránku, příjemce, původce, stav přečtení, časy a deduplikaci.
Význam předmětu, viditelnost, text a odkaz patří registrovanému vlastníkovi typu.

## Jediná registrace

Povinné core služby i funkční moduly přispívají do stejného pole notificationTypes
v [Contributions](../../App/Core/Modules/Contributions.php). Registr
[Registries](../../App/Core/Modules/Registries.php) ověřuje unikátní ID a vlastníka.
Registrace neprovádí SQL, neotvírá session a nevyhodnocuje provozní callbacky.

[NotificationType](../../App/Core/Modules/NotificationType.php) deklaruje:

- id: globálně unikátní stabilní identifikátor, nejvýše 40 znaků;
- subject: none, optional nebo required;
- hydrate: dávkové načtení a kontrolu dostupnosti předmětů;
- target: kanonický interní odkaz;
- message: lokalizovaný prostý text pro konkrétní záznam;
- icon: název společné ikony;
- hydrationGroup: volitelnou skupinu pro společné dávkové načítání u jednoho vlastníka.

Identitu vlastníka dodává registr, není duplikovaná v každém řádku.
Nové typy mají používat jmenný prostor svého vlastníka. Publikované typy
content_comment, content_like nebo site_health_attention zachovávají své ID.

Příklad příspěvku nového modulu; callbacky se při registraci nespouštějí:

```php
new NotificationType(
    id: 'orders.updated',
    hydrate: OrderNotifications::hydrate(...),
    target: OrderNotifications::target(...),
    subject: 'required',
    message: OrderNotifications::message(...),
    icon: 'file',
);
```

## Uložený řádek

Tabulka obsahuje id, user_id, actor_id, type, subject_id, notification_key,
payload_json, read_at, created_at a updated_at. Vazby na účty zůstávají skutečné
cizí klíče. Subject je kladné číselné ID významově určené typem; databáze nemá
subject_type ani vazbu na konkrétní doménovou tabulku.

Příklad: content_comment se subject_id 845 odkazuje na komentář. Social dohledá
jeho příspěvek. Příspěvek se v řádku znovu neukládá. Pro follow a systémová
zdravotní oznámení je subject_id prázdné; význam actor_id zůstává identita původce.

Payload je doplňující kontext, nikoli druhá autoritativní kopie vazby.
Deduplikační klíč volí producent a musí mít vlastní jednoznačný jmenný prostor.
Je unikátní v rámci příjemce. Index (type, subject_id) podporuje úklid předmětů.

## Zápis a čtení

[Delivery::record](../../App/Core/Notifications/Delivery.php) před zápisem odmítne
neznámý nebo neaktivní typ, neplatnou referenci a chybějící povinný předmět.
Při refresh=false duplicitní doručení nemění původní přečtení ani obsah řádku.

[Types](../../App/Core/Notifications/Types.php) seskupuje hydrataci podle vlastníka
a skupiny. Hydratace zachovává ID, počet a pořadí řádků. Doplňuje jen dostupná
data a nesmí způsobit N+1 dotazy. Vlastník ověřuje viditelnost včetně nadřazeného
objektu a času publikace. Neexistující nebo skrytý předmět neposkytne jeho text.

[Resource](../../App/Core/Notifications/Resource.php) vrací kanonická data:
prostý text message, icon, URL, identitu původce a stav. Neposílá HTML, definice
komponent nebo překladové identifikátory. Jednotná komponenta používá tato data;
core komponenty nemají vlastní seznam sociálních nebo jiných doménových typů.

Po vypnutí nebo odebrání vlastníka záznamy zůstávají. Vrátí se obecná zpráva
a odkaz do schránky, bez doménového textu. Nové doručování takového typu se odmítá.

## Úklid a migrace

Mazání a slučování předmětů provádí vlastník ve své transakci. Dotazy musí
omezit také typy, aby stejné ID objednávky, příspěvku nebo komentáře neoznačovalo
stejný objekt. Nainstalovaný neaktivní vlastník se účastní úklidu účtu.

Core migrace 002-subjects uchová původní neprůhledné reference a přesný původní
payload v dočasné obálce payload_json, přidá subject_id a odstraní historické
sloupce content_id a comment_id. Jejich význam v core nevyhodnocuje.
Social migrace 003-notification-subjects převede pouze své typy a obnoví původní
payload. Pokud vlastník pro zachované historické reference nemá převod,
obálka zůstává zachovaná; data se při odstranění sloupců nezahodí.

ID, deduplikační klíče, časy a stav přečtení se zachovávají. Publikované migrační
soubory a checksumy se nemění. Nové instalace používají baseline fresh-v2 bez
sociálních sloupců. Převod se spouští pouze explicitní instalací/aktualizací.

## Cílené ověření

Společný test používá stejnou registraci pro systémový, sociální a ukázkový
modulový typ a ověřuje migraci, zachování historie, viditelnost i izolaci ID:

- php tests/domains/notifications/inbox.php
- php tests/domains/notifications/inbox.php --mysql

MySQL varianta vytváří a odstraňuje pouze vlastní dočasnou databázi na místní
službě. Dále se ověřují související write-path, cleanup a frontendové scénáře.
