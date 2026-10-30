# Administration lists and editors

`/admin/users` is the reference for administration lists.

The admin bar owns the page title for all administration pages, including
cron configuration, site health, updates, and URL blocking. A single outer
content card does not repeat it. Keep headings on distinct inner sections,
such as history or diagnostic issues. For shared outer cards, select
`primary: true` explicitly.

Settings are opened directly from the administration navigation in a modal.

## List structure

- Use `AdminViews::listCard()` for server-composed list sections.
- For a single main list, pass `primary: true`: the admin bar owns the
  visible page title, and the card omits its repeated header. The shared
  toolbar groups search, filters, and page size in one row on desktop.
- For independently titled sections on pages with multiple lists, retain
  the card header and its filter trigger. Do not also put that trigger in
  the section toolbar.
- Select this mode explicitly when composing the page, never by comparing
  translated titles. Toolbar controls stack naturally on narrow screens.
- Inside `card-body`, use one `stack stack-gap-14` containing
  `ui/list-toolbar`, `table-wrap > table.table`, and `ui/pagination`.
- `ui/list-toolbar` includes the shared `ui/list-summary` below its controls.
  Show the full filtered result count from pagination metadata, search text,
  and small neutral chips for active filter labels and selected values.
  Filter summaries are scoped by list target and rebuilt from the matching
  filter form after hydration. Exclude sorting, page size and hidden state.
  Reuse standard cluster, typography and badge tokens; do not add another card.
- Use `table-meta` for secondary cell content such as URLs, notes, errors,
  and supplementary timestamps. Within tables it uses 0.8125rem text,
  normal weight, a 1.4 line height, and safe wrapping for long URLs.
  Links inherit the muted metadata color; errors retain `text-danger`.
  Keep primary names and standalone data columns at the normal table size.
- Render missing cell values as an em dash (`—`), without explanatory
  sentences. Empty lists use `ui/empty-state` and the shared concise
  `common.no_records` label. Do not replace actionable errors or real
  status values with missing-value placeholders.
- Main empty lists use the shared centered `empty-state` layout with a
  40px icon, larger label and generous vertical space. Pass `label` and
  `icon` to `ui/empty-state` for contextual messages. Keep compact sidebar
  widgets and notification previews compact; missing individual cell
  values remain an em dash.
- Keep row actions in `table-actions`. Use small ghost icon buttons;
  destructive actions use `text-danger` and confirmation.
- Keep dialogs outside the DOM region replaced by list requests.
- Use the same components for initial PHP rendering and AJAX updates.
- Preserve search, filters, sorting, and page size when refreshing a list
  after a mutation. Changing filters starts at the first page.

The cron overview contains sibling task cards followed by a history card.
Task cards must not be nested inside the history card. Bot details use a tabbed modal; their source and post lists retain separate
query state.

Bot run history uses `/api/bots/accounts/{id}/runs` with its own
`#bot-detail-runs-list` target, shared list toolbar and pagination.
Search matches source names and diagnostic notes; filters select source
and result. Sorting, filters and pagination never change browser history
or another tab. Load the history on first activation and retain its state
when switching tabs. Dispose its filter form with the workspace.
The run statistic counts the full history, independently of pagination.

## Editors

- Use `TinyCat.ui.adminDialog()` for JavaScript administration editors.
  It selects `data-modal-kind="editor"` and `data-modal-size="workspace"`.
- Server-rendered editor dialogs use the same attributes and component.
- Modal kinds are `content`, `editor` and `confirm`. Sizes are `default`,
  `compact`, `wide`, `reading`, `workspace` and `confirm`; dimensions and
  frame padding belong to shared `--modal-*` tokens. Modules do not add
  private panel sizing or body padding.
- Every editor receives the same mobile viewport and keyboard handling.
  Read-only content can opt into mobile fullscreen separately.
- Load existing records through an administrator-only management endpoint
  when opening an editor. Do not edit a cached list-row snapshot.
- After a successful mutation, reload the current list using its current
  URL parameters. Refresh bot detail data when editing within that detail.
- Keep source preview and confirmation before saving a feed configuration.
- Read-only detail dialogs remain ordinary dialogs.

## Filters

Administration filters use a dismissible dropdown beside the toolbar,
following the frontend archive filter interaction. `ui/filter-trigger`
provides native details/summary controls; `ui.filterPanel(id, form)` mounts
the existing GET form into the dropdown. Server-rendered filter forms use
`Support/filter`. Keep the form in the shared filter registry across list
replacement, then remount it during hydration so selections survive.
Both frontend and administration use the shared `popover-menu` and
`context-menu-popover` primitives. Inherit surface, border, radius and
shadow tokens from that primitive; filter-specific CSS defines only form
layout and dimensions. Use standard fields, 12px spacing, and small ghost
Clear actions. The trigger is ghost by default and secondary when filters
are active; search, sorting and page size do not count as active filters.
Changes to visible filter controls submit immediately through the shared
`data-submit-on-change` behavior; do not add an Apply button. Clear remains
available. Both retain the list's target, query state and history policy.
Automatic updates keep the dropdown open across list/form replacement,
including its scroll position and focus on the changed field. Respect a
menu explicitly closed while a request was pending. Errors leave it open. Escape closes
the menu and returns focus to its summary, without closing an enclosing
editor. Filter controls must not carry modal-close attributes.

## Consolidation summary

Bot source lists now share search, page size, pagination, and remote
editing with the standard lists. Bot account dialogs are outside the
replaceable list body. User, bot account, and source editors share the
administration frame. Bot details have one tabbed workspace. Cron task cards and run history are siblings, and cron history
uses the shared list toolbar. Table overflow containment is owned by
`table-wrap` instead of individual list components.

## Avatars

Public profiles and administration use `users/avatar-control`, opening
`/api/users/{id}/avatar` through the existing frontend avatar dialog.
Avatar upload, generated appearance, and removal are separate saves;
account forms contain no competing avatar file inputs or removal flags.
Updating the avatar refreshes its visible instances without replacing
an account editor or discarding its unsaved fields.

## Modal actions

Editor actions belong in the real `modal-footer`, outside the scrollable
body. Keep one primary save/create action on the right and Cancel beside
it. Use `TinyCat.ui.formDialog()` for single-form editors and
`editorActions()` for specialized footers. Both JavaScript and PHP render
`ui/editor-actions`, with optional destructive content at the leading edge.
`adminDialog()` selects the workspace size for the same editor contract.
Login, registration, reporting and search verification follow this footer
contract too. A CAPTCHA challenge has no draft that requires a discard prompt.

Footer submits use the HTML `form` attribute. Form loading must include
associated controls through `form.elements`, so external save buttons
are disabled and restored together with the fields. Tabbed profiles have
one `data-tab-submit` action targeting the active panel. Avatar dialogs
retain their upload/appearance form selection and separate remove action.
Source preview confirmation also belongs in the footer.

## Tabbed entity dialogs

Bot account pencils and bot links in sources/queue open a bot-only detail
modal with Account, Sources, History, and Posts tabs. The account uses the
shared editor footer, which is hidden on the other tabs. Switching tabs
preserves drafts. Sources and posts load on demand and have isolated
list targets, query state, and filters; they do not push browser history
or update the list behind the modal. Mutations refresh affected tabs,
and closing refreshes the background list.

Direct `/admin/bots/{id}` links open the same modal over the accounts list.
There is no standalone detail page or duplicate bot account editor.

Use `TinyCat.ui.tabbedContent()` for tabbed detail layouts. For one form
split across sections, use `sectionedDialog()` with a
`data-editor-sections` root and child `data-editor-section` nodes carrying
`data-editor-label` and `data-editor-icon`. All fields stay in the same
form and drafts survive tab changes. `data-editor-editable="false"`
hides the footer for sections with their own independent actions.

User administration groups Account, Access and Avatar. Source create and
edit share Source, Schedule and Template sections. Native validation and
server field errors reveal the affected tab before focusing its field.
Simple one-purpose editors continue using `formDialog()`.

Frontend account editing uses the same `sectionedDialog()` as
administration, with no separate profile modal assembly. The account menu
targets `account-editor-modal-{id}` and the `panel=account` management view.
Account, Email and Security keep
their own forms and endpoints; the shared footer targets the form inside
the selected panel. Avatar opens the common avatar editor and hides the
profile footer. Capability checks still control access to Security, and
the frontend does not expose administrator role or activation controls.
Use modal-specific form IDs so independent editors cannot share a submit
target. Retain focus links, drafts and validation across tabs.
Saving an individual account tab leaves the editor open. Password saving
clears its fields; it does not close other drafts. Locale navigation uses
the shared leave guard. Close-on-success must not discard dirty sibling forms.

## Settings

Settings use a persistent sectioned administration modal without a standalone
page. Existing server-rendered field types are supplied through a template
in the authenticated admin shell; there is no
separate settings tab implementation. One multipart PATCH form retains
image uploads, SMTP controls, password masking and shared validation.
A successful save reloads the current page and restores the active tab
from session storage, so branding, localization and asset settings take
effect and uploaded files/password fields reset safely.
Blocked websites belong to the Security settings tab and use the shared save action; their rules are normalized by the moderation service.
Settings and frontend profile forms use the standard responsive grid,
`grid-span-full`, fields and shared image previews. They do not own
parallel settings/profile grid styles. Every settings section icon must
exist in the shared SVG sprite.

## Asset scope and review

The bot workspace loads only account metadata and aggregate counts at
open. Source, post and run lists load through their paginated endpoints;
do not preload retired page snapshots or the public profile URL.
Bot administration reuses the post composer without public feed-card,
thread or notification-list definitions and without public-only styles.
The removed `/admin/bots/detail` asset scope is consolidated into
`/admin/bots`, whose JS ceiling inherits the former detail's 320 KiB raw /
64 KiB gzip limits. Its CSS ceiling is 50 KiB raw / 10 KiB gzip. Reviewed
shared editor/filter growth is recorded per scope in `asset-budgets.json`;
keep four scripts, one stylesheet and no unreachable asset sources.

## Activation

Status results use `ui/status-badge` as a success `check-circle`, danger
`x-circle`, or warning `alert` triangle, without visible status text.
Colors come from the shared success, danger and warning tokens; result
rows must not force a color onto all child icons. The localized label is preserved in
`title` and `aria-label`. Account activity uses the same indicators.
Progress, skipped work and unknown states retain distinct labels;
do not present them as completed successes or failures.

User, bot account, and source lists omit the Status column. Use the shared
`ui/activation-action` in `table-actions`, with explicit desired state,
localized Activate/Deactivate labels, and the same play/pause icons.
Inactive records use `table-row-inactive`: muted content and a soft
background, while action buttons retain normal contrast. Keep the actual
status available to assistive technology and retain status filters.

Activation is independent of editing. Editors must omit existing status
and enabled values so saving a draft cannot undo a newer activation action.
New records start active. Account deactivation uses the existing `ban`
state; waiting and banned accounts can both be activated. The protected
administrator cannot be deactivated. Deactivating a bot also disables its
sources; reactivation leaves individual sources disabled until enabled.
