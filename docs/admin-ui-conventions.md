# Administration lists and editors

All dialogs follow the authoritative [modal contract](modal-contract.md).
This includes new-record forms and read-only release notes. Creation editors
load `/api/users/creation` or `/api/bots/accounts/creation` on demand; refreshing
their background list never rebuilds or removes an open editor.

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
  Show only small neutral chips for search text and active filter labels
  and selected values. Do not prefix them with a result-count sentence.
  Hide the complete summary row when it contains no badges.
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
- First-column identities use `table-identity`: an optional status or type
  icon followed by `table-identity-body`. The body stacks the main
  `table-primary` name and `table-meta` details with a shared 4px gap.
  Metadata aligns with the name, not underneath the icon. Keep icons on
  the first text line and allow long names and URLs to wrap.
- Primary names use the same weight and line height whether plain text
  or a link. Preserve links for explicit navigation (a related account,
  public post or external URL); do not automatically turn every first
  cell into an edit link. Editing stays in the action column.
- Mark action headers and cells explicitly with `table-actions-cell`.
  They stay compact, right aligned, with `table-actions` on one line.
  A last column containing ordinary data stays left aligned; never apply
  action styling using `:last-child`. Empty-state colspan cells also
  retain their own layout.
- Long identity names, excerpts and diagnostic notes use a visual two-line
  clamp; URL metadata uses a single-line ellipsis. Shared tokens
  `--table-text-width` (24rem) and `--table-text-lines` (2) control the
  limit. Apply `table-text` to long free text outside an identity and
  `table-text-url` to its URL links. Keep the complete text in the DOM
  and the escaped `title`, and preserve the original link destination.
  Never slice strings in resources or truncate numbers, statuses or dates.

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

## Mobile tables

Below 600px, simple tables become full-width row cards through the shared
`tables` lifecycle service. The first cell is the identity; other values
receive labels from the translated column headings. Identity and metadata
use the available card width. Action buttons have 44px touch targets and
may wrap within the action footer. Sort links remain available above the
cards. At 600px and above the original column layout is retained. Identity
content keeps a 12rem minimum there, so wider tables scroll locally instead
of squeezing names down to a few characters.
Mobile sorting is collapsed into a single control beside Filters. Reuse
the same small ghost/secondary button and `popover-panel` surface,
dimensions and positioning. The active option is highlighted; choosing it
reverses its single direction arrow. Clone the original server-prepared links
so filters, pagination resets, AJAX targets and modal history policy survive.
Desktop column headings remain the sorting controls on larger screens.

Keep native table elements, explicit table/row/cell roles and `headers`
associations. Visual labels are hidden from assistive technology to avoid
duplicating column headings. Rehydration updates labels without duplicating
wrappers or replacing the controls inside a cell. Empty-state colspan rows
retain their content and do not receive field labels.

Tables with multiple header rows or complex spans keep a readable minimum
width and local horizontal scrolling. Use `data-table-layout="scroll"` for
comparison tables that require columns to remain side by side. Mobile
styles activate only after the service has prepared a simple table, so
the scroll layout also remains usable without JavaScript.

## Mobile modal navigation

Below 600px, tabbed modals use a full-width section select with the active
section icon instead of a horizontally clipped tab strip. Keep the full
translated section names and a minimum 44px control height. The switch is
in the existing navigation row below the header, outside the scrolling body.
Desktop tabs remain in their tablist; the mobile select is its sibling.

Both controls call the same tab activation code. Keep selected options,
icons, panels, lazy loading and the footer save target synchronized, including
when validation reveals a different section. Selecting a section must not
submit a form or steal focus from the mobile select.

## Tooltips

Use `data-tooltip` for plain-text hints. The shared tooltip service also
converts existing body `title` attributes during hydration and when a
dynamic trigger is first used. Semantic iframe titles remain unchanged.
Without JavaScript, existing title attributes retain the native fallback.

The tooltip uses shared surface, text, border, radius and shadow tokens.
Its popover top layer escapes table and modal clipping, while placement
stays within the visual viewport. It opens after a short pointer hover,
immediately on keyboard focus or a touch tap, and remains hoverable.
Escape dismisses the hint before the enclosing modal or menu; scrolling,
outside taps, tab changes and modal closure dismiss stale hints.

Clipped, otherwise non-interactive table text becomes keyboard focusable.
Table hints that merely repeat a fully visible label stay hidden.
`aria-describedby` is added only while the hint is open, preserving any
existing descriptions. Hints render through `textContent`, never HTML;
links and buttons keep their normal click action.

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

Use short field names such as Bot, Source, Status and Role rather than
instructions such as Filter by bot. Use `common.all` for the neutral option
under a named field. Omit repeated bot names from source options when the
list is already scoped to that bot; retain them to disambiguate global lists.

Administration filters use a dismissible dropdown beside the toolbar,
following the frontend archive filter interaction. `ui/filter-trigger`
provides native details/summary controls; `ui.filterPanel(id, form)` mounts
the existing GET form into the dropdown. Server-rendered filter forms use
`Support/filter`. Keep the form in the shared filter registry across list
replacement, then remount it during hydration so selections survive.
Both frontend and administration follow the [shared popover contract](popover-contract.md),
using `data-popover`, `.popover-panel` and `TinyCat.popovers`. Inherit surface, border, radius and
shadow tokens from that primitive; filter-specific CSS defines only form
layout and dimensions. Use standard fields and 12px spacing; do not add a
Clear-all action. Active summary badges use `ui/filter-chip` buttons with a
close icon. Removing one chip clears only that field, resets the page to one
and preserves other filters, sorting and page size. Search chips also clear
the matching search input. Keyboard removal moves focus to the next chip
or the search input when none remain. The trigger is ghost by default and secondary when filters
are active; search, sorting and page size do not count as active filters.
The open panel stays within the viewport and the modal body. Its height
uses the available space, opening above the trigger when needed; longer
forms scroll inside the panel. Recalculate placement on resize and ancestor
scroll, and after restoring a filter during list replacement.
Changes to visible filter controls submit immediately through the shared
`data-submit-on-change` behavior; do not add an Apply button. Chip removal
uses the same form transport and retains the target and history policy.
Automatic updates keep the dropdown open across list/form replacement,
including its scroll position and focus on the changed field. Respect a
menu explicitly closed while a request was pending. Errors leave it open. Escape closes
the menu and returns focus to its summary, without closing an enclosing
editor. Filter controls must not carry modal-close attributes.
Sorting choices likewise keep their menu open across an AJAX refresh,
including scroll and keyboard focus. `data-menu-key` scopes restoration to
the list target; a menu dismissed during the request must stay closed.

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

The bot workspace moves each list tab's existing pagination into the modal
footer. Keep the search/filter/per-page toolbar and result summary above the
table. Store independent pagination nodes for Sources, History and Posts;
restore account actions on the Account tab and hide the footer for a single
page. Refresh the footer after list replacement, including background post
refreshes, without changing AJAX targets or browser history. Paging reveals
the start of the new rows inside the modal. Other modal types retain their
current pagination until this workspace trial is reviewed.

## Tabbed entity dialogs

Tab navigation occupies a separate full-width row between the modal header
and scrolling body. It is not nested inside the title/close header.
Use `pinDialogTabs()` to move only the tab buttons and make the panel their
shared tab root; fields and forms remain in the scrolling body. Both editor
factories and the bot workspace use this helper. Never overlay tab navigation
on the scrolling content or compensate with a guessed tab-height offset.
Horizontal tab scrolling remains available on narrow screens.

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

Settings use a disposable resource editor loaded from administrator-only
`GET /api/settings/editor` when the user opens it. The admin shell contains
only `data-modal-open` and `data-modal-url`; it must not embed the form, a
template with its fields, or create the dialog during hydration. The settings
module implements `modal(url, response, id)` and delegates opening to
`dialog.openResource`. Tabs, history, focus, cancellation, validation and the
unsaved-change guard belong to the shared services.
One multipart PATCH form retains image uploads, SMTP controls and password
masking. A successful save keeps the editor open, marks the submitted form
clean and clears submitted file/password/removal controls. Closing disposes
the dialog; reopening fetches current values. Reload/deep links restore it
from `#modal=settings/editor&tab=...`, without session-storage drafts.
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

## Viewport containment

Keep the page within the viewport and horizontal scrolling inside `.table-wrap`.
The wrapper is a positioned containing block so absolutely positioned accessible
labels in wide action columns cannot expand the document. Do not hide page overflow
to mask this issue. Long filter-summary badges wrap within the available width.
On narrow screens, filter panels align with the left edge of the full action row;
desktop panels retain their right alignment.

`node tests/frontend/admin-viewport.cjs` renders the real admin shell and source
list at five viewport widths with open/closed filters and long summary values.
It checks document bounds and preserves local table overflow. Use
`TINYCAT_TEST_CHROME` for an explicit Chromium executable; otherwise a supported
local installation is discovered or the render test reports a skip.

Filter state must agree between the query, popover and removable chips. Normalize unavailable bot/source choices before querying; source choices follow the selected bot. Removing a chip resets only that field to its neutral option, preserves search, sorting and page size, and returns to page 1. Hidden transport fields and unchecked controls do not mark filters active. Page size belongs only in the list toolbar.

## Modal URLs and browser history

Resource modals use the shared dialog-history
service. Opening adds one entry; selecting a tab replaces that entry. For example,
`/admin/bots/accounts?status=active#modal=bots/accounts/123&panel=detail&tab=history`
keeps list filters in the page query and modal identity/tab in the fragment.
Ordinary anchors are restored on close. Do not put form drafts or credentials in
URLs, history state or browser storage.

Back closes the top modal through the shared unsaved-change guard. Forward,
direct links and reload restore the editor/tab using the existing authorized
JSON resource and module renderer. Same-page traversal keeps the list DOM,
filters, scroll and opener focus; cross-page traversal first restores the page.
An avatar or other nested editor adds one step, while short-lived confirmation
and preview dialogs do not create bookmark URLs. Standalone confirmations are
cancelled before ordinary page history restoration. Confirmation cancellation
restores the original history position and draft. Stale resource reads are
cancelled and cannot reopen a dismissed modal.

Use `dialog.open`, `openResource`, `requestClose` and `close`; avoid module-owned
`pushState`, close redirects or session-storage tab restoration. Internal history
reconciliation uses `{history: false}` to prevent recursive history changes.
Legacy full-page bot links provide only their list fallback to `openResource`.

## Validation in tabbed editors

Moving editor sections into tabs must retain controls outside those sections,
including hidden status, scope and CSRF inputs. Native constraint failures and
JSON validation errors use the shared form-state service: mark all failing
controls, reveal the first failing tab, synchronize its mobile selector, focus
the field and scroll only the modal body. Preserve existing help descriptions.
Editing clears only that field's feedback. Unknown or non-editable field errors
must remain visible as a focusable form-level alert.

Custom editors expose their visible input with `data-validation-for="field_name"`.
The form-state service applies and clears error state on both the submitted
control and that proxy; UI modules do not duplicate validation or tab logic.
