# Administration lists and editors

Functional-module administration is rooted at `/admin/<httpNamespace>/...` and
its resources at `/api/<httpNamespace>/...`. Social contributes one lazy group
with four direct links: bot accounts, sources, publication queue and reports.
The shared shell and active-state normalization own opening and highlighting;
owners do not add nested submenu renderers or a second navigation registry.

Simple resource lists may declare `api` and `apply` through `TinyCat.lists.register`
and delegate response handling to `TinyCat.lists.result(selector, source, response, target)`.
The shared adapter checks the origin and endpoint boundary, renders reads and
refreshes after writes through the existing request lifecycle. Modules retain
their endpoints, components, filters and domain-specific behavior. More complex
workspaces keep their own handlers instead of forcing unrelated workflows into
this adapter. Prepared filter defaults belong to the shared `ui.filterPanel`.

All dialogs follow the authoritative [modal contract](modal-contract.md).
This includes new-record forms and read-only release notes. Creation editors
load `/api/users/creation` or `/api/social/bots/accounts/creation` on demand; refreshing
their background list never rebuilds or removes an open editor.

`/admin/users` is the reference for administration lists.

The administration content has no padding on desktop or mobile. Its actual
sections and controls own their internal spacing; do not add a second page inset.

Administration selects the core compact profile with `data-ui-density="compact"`
on the document. It does not define its own scale or control/modal token overrides.
The global `--ui-scale` factor defaults to `1` and affects every density profile.
Core multiplies it by `--ui-density-scale` (1 normally, 0.875 in the compact profile).
At a 16px browser base, the default regular/compact text sizes are 16px/14px;
`--ui-scale: 1.25` enlarges them to 20px/17.5px together. Do not introduce per-list
typography or size overrides for accessibility scaling.

Buttons, inputs, selects and pagination use shared `--control-height`,
`--control-height-compact` and control-padding tokens defined in rem units.
Administration adjusts vertical density and selects the compact height on
desktop layouts. Mobile input text keeps a 16px minimum and grows with larger
root text. The section selector uses the shared touch-height minimum. Dialog
dimensions and padding follow rem-based tokens, capped by the viewport.

The admin bar owns the page title for all administration pages, including
cron configuration, site health, updates, and URL blocking. A single outer
content card does not repeat it. Keep headings on distinct inner sections,
such as history or diagnostic issues. For shared outer cards, select
`primary: true` explicitly.

Settings are opened directly from the administration navigation in a modal.

Sidebar width and navigation row dimensions follow the root scale. Navigation
groups do not add inter-row gaps, and submenus use compact indentation. At larger
scales the navigation scrolls inside the sidebar while its header and footer remain usable.

The sidebar selects the shared dark palette with `data-theme="dark"` and uses
`--surface-panel`, distinct from the content's `--surface` in both themes. Do not
copy theme colour values into administration styles. The page
background follows the surface token (white in light mode). Cards inside main
administration content have no outer border or corner radius; tables, notices
and section separators retain their own meaning. Dialog cards retain their frame.

The scheduler task overview uses a single summary table rather than task cards.
Each row groups the task name, cadence and default limits, followed by the latest
run, visible status text, duration and a visibly labelled run action. Narrow
screens scroll the table within its wrapper without widening the document.

## List structure

- Use `Core\Ui\Admin\Views::listCard()` for server-composed list sections.
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
- In the existing desktop administration layout (at least 900px wide), the shell
  allocates the remaining height to the table wrapper. Toolbar and pagination
  stay outside the scrolling rows; column headings stick inside that wrapper.
  Keep overflow local in both directions. Mobile layouts retain natural page
  scrolling. Scheduler overview and history share the available height,
  with the overview capped at 40dvh. This rule does not alter modal layout.
- Shared pagination keeps the record range and navigation in one compact row.
  In its base mobile layout, retain previous/next controls and the current page; neighbouring
  page numbers remain available on wider layouts. Keep accessible labels and
  the existing AJAX/history destinations on every navigation control.
- Reuse the existing mobile/desktop stylesheet separation. Do not introduce
  extra layout conditions based on viewport height, hover or pointer type.
- The enclosing card or workspace owns the surface and outer frame.
  `table-wrap` contains horizontal scrolling without a second border,
  background or radius. Table headings use normal case, muted text and
  the shared subdued background; do not add per-list heading variants.
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
- First-column identities use `identity-row`: an optional status or type
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
Bot account fields contain biography only. Avatar belongs to a separate
Avatar tab using the same `users/avatar-control` as user administration;
the avatar editor saves independently and the parent tab has no competing
Save action. Do not duplicate account statistics above the editor.

Bot run history uses `/api/social/bots/accounts/{id}/runs` with its own
`#bot-detail-runs-list` target, shared list toolbar and pagination.
Search matches source names and diagnostic notes; filters select source
and result. Sorting, filters and pagination never change browser history
or another tab. Load the history on first activation and retain its state
when switching tabs. Dispose its filter form with the workspace.
The run statistic counts the full history, independently of pagination.

## Mobile tables

Administration tables keep native table elements and the same columns at every
viewport width. On narrow screens the shared `table-wrap` scrolls horizontally;
the document itself must fit the viewport. The shared mobile minimum width
preserves readable identities, data and row actions. Do not replace domain tables
with private mobile cards, a second sorting menu or duplicated row content.

Sorting remains in the original translated column headings, including on mobile.
Use `ui/sort-heading`, standard `identity-row`, `table-meta` and
`table-actions-cell` tokens. Filter and page-size controls use the same shared
toolbar and popover services as desktop. `tests/frontend/admin-viewport.cjs`
checks these table and sorting invariants against the actual administration shell.

## Mobile modal navigation

Below 900px, tabbed modals use a full-width section select with the active
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

Direct `/admin/social/bots/{id}` links open the same modal over the accounts list.
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
The removed `/admin/social/bots/detail` asset scope is consolidated into
`/admin/social/bots`, whose JS ceiling inherits the former detail's 320 KiB raw /
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
`/admin/social/bots/accounts?status=active#modal=social/bots/accounts/123&panel=detail&tab=history`
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

## Declared list and editor wiring

Standard administration wiring belongs to the owner's existing asset manifest,
in an `admin` map on the feature that delivers the relevant page. IDs are unique
across selected owners. A list declares `path`, `api` (its mutation namespace),
`endpoint` (the list resource), `target` (an ID selector), `component`, `filter`,
`filterId`, `title` (a translation key) and `icon`. Empty filter strings mean
that the list has no filter. An optional `editor` declares `creation`, `fields`
and `title`; `management` can contain one `{id}` placeholder, with update
`method` set to `PUT` or `PATCH`. Creation submits `POST`; `submit` defaults to
`common.save`. An editor may also be declared without a list, for an existing
custom list surface.

Core reads these declarations as metadata only. It derives component roots and
title translations, and delivers the selected declarations with the existing
component bundle. `admin_ref` expressions in owner components resolve to the
same declaration during catalog preparation, before either PHP or JavaScript
rendering. For example, `{"admin_ref":"example.records","field":"target"}`
resolves an explicit list target; `targetId` supplies it without the leading `#`.
The `field` may address a nested property such as `editor.creation`.

The shared client connects those lists to the existing navigation, request
lifecycle and refresh logic. Filter state stays in `data-admin-state` on an
element marked with `data-admin-list="example.records"`; hydration creates only
the filter popover content, never a resource editor. Standard editors render
the owner's declared fields only after the authorized resource has loaded.
Their history, validation, draft protection and disposal use the shared dialog
factory. Custom previews and workspace editors remain owner implementations.

PHP handlers explicitly bind their routes using `Core\Ui\Admin\Definitions`
and perform authorization before data selection. SQL sorting expressions and
filter semantics remain with the owner; `Definitions::sorting()` only combines
them with the declared transport paths. Metadata never registers data handlers,
grants permissions, chooses domain tables or changes an API response into a UI
definition. Unselected owners contribute nothing. Duplicate IDs, list targets,
page paths and editor resources are rejected.

## Optional help

Settings and other editor hints appear as shared info controls beside captions,
using `ui/help` through the shared field components. Do not add explanatory
paragraphs under individual fields or implement a separate settings help popup.
Preserve visible validation feedback and operational notices. The general
[form help contract](ui-conventions.md#form-help) defines keyboard, touch,
screen-reader descriptions and lifecycle behavior.


## Registered dashboard widgets and cache diagnostics

The dashboard host renders one shared responsive grid: one column below 900px,
two from 900px, using core density, spacing and colours. It contains registered
widgets only; cache diagnostics have their own `/admin/cache` route and menu item.
Core/Ui owns widget frames and presentation types, never domain queries.

`DashboardWidget` retains stable ID, lazy callback and order, with optional `type`,
`title`, `icon` and `visible` metadata. Supported types:

- `metrics`: `items` with `label` and formatted `value`.
- `list`: `items` with `label`, canonical `url`, optional `detail` and `time`.
- `status`: `text`, `tone` and optional `detail`.
- `custom`: owner-rendered component markup within the same frame, preserving the
  original callable contract for existing modules.

Typed widgets may provide a footer `url`. The owner translates titles, formats
values, authorizes its data and selects bounded candidates before hydration.
The host caps displayed items at eight; this does not replace SQL limits.
Visibility is checked before title/data callbacks. A failing visibility callback
fails closed; a failing data callback produces a local error widget and leaves
other widgets usable. Registration never executes these callbacks. No client
widget registry, hidden dialog, HTML domain resource or persistence table exists.

Identity contributes account statistics, Health supplies its latest stored inspection
without running a probe, and Social supplies five recent comments. Inactive owners contribute nothing. The previous aggregate
Social statistics table and its view-data adapter are retired.

Cache uses the same grid and card primitives. Single state values belong beside
the heading, especially disabled Memcached and OPcache. Metric and HTTP-header
blocks use the shared `ui/detail-table` with two cells per row and `scope="row"`.
Descriptions remain prose. Do not create one-row status tables simply to use a
table everywhere. `ui/status-badge` supports `text: true` for a visible labelled
badge: success for enabled, info for deliberately disabled, danger for failure.
Its existing icon-status default remains unchanged. Table body last-row borders
are removed from both header and data cells.

The cache page reports configured application storage, runtime Memcached and
OPcache metrics, release/on-demand assets, CSS/JS/HTML minification, declared
browser-cache rules and observed HTTP headers. It does not scan cache keys or
expose private paths. The browser samples the public homepage and its stylesheet
anonymously, without cookies, using same-origin URLs and rejecting redirects.
These samples show actual Content-Encoding, Cache-Control, Vary and ETag; they do
not prove that every URL shares that policy. Requests are deduplicated per view
and aborted on disposal; errors are distinct from uncompressed responses.
OPcache reset keeps the existing administrator/CSRF/confirmation flow and returns
to `/admin/cache`. There is no new bulk cache deletion action.

### Statistics groups

The core `core.statistics` widget presents all visible `DashboardStats` contributions
together as shared icon badges, without a group selector. Each owner provides an
ID, label, lazy count loader and metadata-only
`fields` callback. The callback maps local metric keys to translation expressions.
The shared `dashboard.statistics` component lookup compiles this metadata for PHP
and browser rendering. It must not query data or evaluate the count loader.

`GET /api/site/dashboard-stats?stats=<id>` requires administrator authorization,
checks owner visibility and loads only the selected group. It returns canonical
`id`, `items` (`key`, integer `value`) and an optional canonical `url`. No labels,
component definitions or rendered HTML belong in this response. Inactive owners
are absent and unknown or hidden groups return 404. The dashboard loads every visible group on demand and renders their metrics
through the shared badge component. Each group retains its own eight-item bound;
combining groups does not truncate later owners. A failed group is reported and
does not suppress healthy groups.

The statistics endpoint remains available for authorized owner-specific reads.
Rendering the dashboard does not depend on JavaScript or preload hidden panels.

Dashboard list items may supply `user` (the shared avatar model) or a fallback
`icon`. Their title and detail occupy the flexible middle column; time is a
trailing column on desktop and moves beneath the text on mobile. Rows use shared
spacing tokens and dividers. Statistics presentation metadata may provide an
`icon`; canonical API counts stay unchanged. Desktop grid siblings stretch to
matching height within each row. Cache cards use the same grid alignment.

User and bot account tables share `users/identity-row`, owned by core Identity.
It composes the existing small `ui/avatar`, account status and escaped username
using table identity primitives. Use canonical `avatar_url` already supplied by
account resources, without per-row lookups. Server rendering and refreshed JSON
lists use the same definition. Avatar sizing, fallbacks and updates remain in the
existing core avatar component; no list-specific avatar CSS is needed.


All table state badges include readable text; their severity symbols are supplied
by shared CSS rather than component SVG markup. The common `identity-row` and
existing inset variant serve account tables, other admin lists and dashboard
rows/metrics. Use the existing avatar surface for both account images and
owner-supplied content symbols. Its shared size derives from title line-height,
inline-control height and inter-row spacing, so the normal title/state pair and
symbol scale together. Small/large avatar variants use the same `--avatar-size`
mechanism. Do not add dashboard-specific or domain-specific icon dimensions.

Table bodies use a uniform surface and the shared hover and focus-within
highlight. Inactive descriptive cells use muted italic text, while badges and
action controls remain upright. Do not add an inactive background that hides
interaction feedback.
Bot source names link directly to their feed URL, including in the account
workspace; avoid repeating the URL as a separate metadata row.

Administrative table sections use the shared `table-layout` container. The table
uses the full panel width; its sibling toolbar, notices and pagination retain
the common content inset. Panel padding is owned by this layout, without negative
margins or page-specific overrides. Table rows have no separators or zebra fill;
hover and focus-within retain the shared highlight. Task summaries and run history
use the same identity rows and status badges.

Shared server list state lives in `Core/Ui/Admin/Views`: explicit
`paginationMeta(total, page, perPage)`, `sortState`, `sortOrder`,
`sortHeadings`, navigation URLs and the pagination presentation model.
Each feature supplies its own SQL allowlist, default order and stable final key.
HTTP page/page-size reads stay at the list entry; pagination metadata never reads
`$_GET`. The page-size choices are the constant `PER_PAGE_OPTIONS`.
