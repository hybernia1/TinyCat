# Release 5.18.0: dead-code and compatibility review

Baseline: release 5.17.0 (`a30946e`). This review covers the pending UI cleanup,
resource dialog migration and shared popovers, not the separate 6.0.0 proposal.

## Removed and enforced

- Retired settings, bot creation and generic server modal templates. Settings,
  user creation, bot-account creation and release notes use authorized resources.
- Replaced numbered CSS and generic JS names with responsibility-based paths;
  updated imports, manifests, tests and documentation together.
- Removed duplicated notification dismissal, comment-menu positioning and search
  popover framing in favor of the shared controller.
- Removed the unreachable `next` replacement branch and its unused hydration
  binding from the following controller.
- Removed 15 unused CSS rules: badge success/warning variants, `px-4`, `w-full`,
  check-card body, account form footer, login submit row, obsolete status link,
  comment-open/delete/login and sidebar footer-inner selectors. Dynamic
  pagination, status-indicator and toast class families remain in use.
- Registered all 127 removed tracked runtime paths in the 5.18.0 update deletion
  inventory. Signed artifact tests require eligible deletion entries to be
  declared and absent from the package; upgrade rehearsals check their removal.

## Checks and limits

The PHP inventory reports no unreferenced private methods or global functions.
PHPStan level 8 checks types and call sites. UI contracts validate duplicate
definitions, dependencies, shared popover structure and delivery ownership;
the asset report rejects unreachable JS/CSS sources. Render-only template and
modal lifecycle guards reject retired eager implementations.

Selector review also checks PHP templates and dynamic JS/JSON producers. It is
not a claim that every possible branch is covered by static reachability.
Compatibility required by stored data, migrations, signed updates and existing
history behavior is retained. Historical audits and the 6.0.0 design proposal
are repository documentation and are excluded from production packages.

No new migration, signing key, managed root or configuration setting is required.
The release keeps the minimum supported update source at 5.16.0. Full preflight,
extracted signed artifact checks, fresh installation and production-key update
and rollback rehearsals must pass before publication.
