# Moderation conventions audit — 2026-11-01

Scope: the reporting form, grouped administration list, tabbed review dialog,
individual report previews, preserved post/comment previews, review writes and
public visibility of hidden content in the current working tree.

References: [administration UI](admin-ui-conventions.md),
[shared rendering](development/partials.md), [API](api.md), and
[query selection](query-selection-standard.md).

## Corrections

- Detail reads now pass through `Moderation/Resource`; raw database rows and
  moderation lock metadata are not exposed to the client. Report timestamps
  use `Dates::dateIso()` like the other resources.
- Table excerpts retain the complete text. The shared identity styles perform
  visual clamping, and tooltips retain the full value.
- Report history uses native tables, `table-identity`, `table-meta`, compact
  eye actions, the shared empty state, and em dashes for missing values.
- All report states use one `moderation/status` component backed by
  `ui/status-badge`.
- Review decisions use `ui/editor-actions`: destructive action at the leading
  edge, Cancel and the primary decision at the trailing edge. Reviewed records
  are ordinary content dialogs when no action remains. Hidden posts expose
  the explicit publication action in the shared footer.
- The reporting form and API share a server-owned 1,000-character note limit.
  Oversized requests receive a field error instead of losing text silently.
- External submit buttons use the actual form ID attribute, avoiding the named
  `id` control collision reproduced by the native Chrome regression test.
- Existing notifications preserve their records but no longer reveal excerpts
  of hidden posts or link to their unavailable public detail.

## Preserved behavior

The grouped list retains shared searching, sorting, filtering, page size and
pagination. Review writes retain their transaction and serialize by content ID.
The existing `remove` decision sets the independent post status to `hidden`,
retaining its reports, image, comments and relationships; the existing 24-hour
author restriction is unchanged. Dismissal changes only report status. The
three edit-lock columns are replaced by one `content.status` field. Authors may
edit their posts subject to account restrictions; editing a hidden post never
publishes it. Only explicit administrative publication restores visibility.
New reports after review append history; repeated open reports from the same
user update one open row. Submission and review share the parent post lock,
and reporting never changes visibility.

The evidence preview reuses `Status/Resource`, `status/card`, `status/thread`,
and nested comment rendering. Its scope loads the shared media controller and
styles without the public feed controller. Preview controls do not expose
editing, commenting or login prompts. The evidence read remains behind
administrator authorization; owners have a separate authorized edit read.
Public feeds, search, syndication, embeds,
sitemaps, link resolution and interactions retain visibility filtering.

## Verification

The visibility migration is tested on isolated MySQL and SQLite schemas,
including old lock foreign keys, hidden-state preservation, repeat execution,
report history, new reporting cycles and database defaults. Runtime regression
checks cover re-reporting, the absence of automatic hiding, private edits,
preservation of hidden status, and explicit administrative publication.

After this simplification, all 63 standard test groups completed successfully
across the standard run and follow-up runs. A timing-sensitive tooltip hover
check interrupted the initial preflight; the unchanged test passed separately
with all 252 viewport cases. Remaining groups, including installation and
update/rollback rehearsals, passed. Focused results: 205 write-path checks,
17 migration checks, 4,361 API checks and 72 native modal checks. PHPStan, lint,
translations, style and security checks passed. Local Memcached integration
was skipped because its server was unavailable.

Regression coverage includes Chrome submission and nested previews, mobile
table containment, returning from a report preview to its parent tab, canonical
resource fields and dates, full table text, shared footers and empty states,
preserved post/image/discussion data, public visibility and API authorization.
The standard preflight also checks translations, lint, PHPStan, security,
module boundaries, asset scopes, query budgets, MySQL behavior and release
installation/update rehearsals.

Native browser checks are automated desktop Chrome checks, including a mobile
viewport. They do not constitute a manual screen-reader or physical mobile
keyboard audit.
