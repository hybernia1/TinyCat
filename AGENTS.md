# Modaly

Při změnách dialogů dodržuj [jednotný kontrakt modalů](docs/modal-contract.md).
Platí i pro vytváření nových záznamů a čistě čtecí detaily.

- Resource modaly načítej přes společný loader a autorizovaný JSON endpoint.
- Nepřidávej skryté modaly ani formulářové šablony do stránky. Nevytvářej
  dialogy při inicializaci, hydrataci nebo obnovení seznamu.
- Lokální potvrzení a pomocné náhledy vytvářej na vyžádání společnou factory.
  Filtry jsou popovery svázané se seznamem.
- Obnova seznamu ani resource nesmí přepsat otevřený koncept.
- Nevytvářej vlastní rám, historii, validaci, focus management nebo pravidla zavírání.

Zachovej kontroly `tools/modal-contracts.php` při buildu a regresní testy
uvedené v závazném dokumentu. Výjimku neobcházej lokální implementací.

# Popovery

Dodržuj [jednotný kontrakt popoverů](docs/popover-contract.md). Notifikace,
filtry, účet, jazyk i kontextové akce používají `TinyCat.popovers` a povrch
`.popover-panel`. Doménové moduly nesmí přidávat vlastní otevírání, zavírání,
pozicování ani rám. Rozměry variant nastavuj přes společné CSS proměnné.
Zachovej strukturální kontroly při buildu a nativní testy popoverů a filtrů.
