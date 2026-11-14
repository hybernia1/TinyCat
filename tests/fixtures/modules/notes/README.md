# Notes module pilot fixture

This is an integration fixture, not an enabled application feature. The test
copies this directory into an isolated workspace and adds only its manifest
to the explicit module selection.

The module owns `pilot_notes`, its current schema and two historical schema
steps. Its API uses the shared authorization, CSRF and JSON transport. The
page uses the shared template/component renderer; it defines no custom modal
or popover infrastructure. A registered scheduled task refreshes a bounded
batch through the existing scheduler and history.

Asset entries are filled with the copied module's project-relative paths by
the harness. Extra fixture locales use English text so the full shipping
catalog can exercise locale parity; this is not a translated production module.

Selecting a module is separate from installing its schema. Dropping it from
the next process removes its code and contributions from the application,
while leaving database data and migration records intact.

The contribution provider also implements the optional Accounts lifecycle
extension. Its prepared deletion removes only notes owned by the deleted
account, inside that account's transaction. Definitions perform no SQL;
the account lifecycle regression invokes this integration explicitly.
