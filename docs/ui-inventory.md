# Rejstřík UI

Generováno příkazem `python tools/ui-inventory.py`. Rozsah a omezení viz [audit](ui-audit.md).

96 definic komponent, 399 CSS tříd, 126 CSS importů, 77 JS souborů, 17 UI služeb, 13 PHP partialů.

## Deklarativní komponenty

| Název | Zdroj | Závislosti |
| --- | --- | --- |
| `bots/source-fields` | [assets/modules/bots/components.json](../assets/modules/bots/components.json) | ui/field |
| `bots/sources` | [assets/modules/bots/components.json](../assets/modules/bots/components.json) | ui/account-status, ui/activation-action, ui/api-action, ui/empty-state, ui/list-toolbar, ui/modal-trigger, ui/pagination, ui/sort-heading |
| `bots/accounts` | [assets/modules/bots/components.json](../assets/modules/bots/components.json) | ui/account-status, ui/activation-action, ui/api-action, ui/empty-state, ui/list-toolbar, ui/modal-trigger, ui/pagination, ui/sort-heading |
| `bots/runs` | [assets/modules/bots/components.json](../assets/modules/bots/components.json) | bots/run-status, ui/empty-state, ui/sort-heading |
| `bots/stat` | [assets/modules/bots/components.json](../assets/modules/bots/components.json) | ui/stat-card |
| `bots/run-status` | [assets/modules/bots/components.json](../assets/modules/bots/components.json) | ui/status-badge |
| `bots/posts` | [assets/modules/bots/components.json](../assets/modules/bots/components.json) | bots/post-source, ui/empty-state, ui/list-toolbar, ui/pagination, ui/sort-heading |
| `bots/queue` | [assets/modules/bots/components.json](../assets/modules/bots/components.json) | bots/post-source, ui/account-status, ui/empty-state, ui/list-toolbar, ui/modal-trigger, ui/pagination, ui/sort-heading |
| `bots/queue-filter-form` | [assets/modules/bots/components.json](../assets/modules/bots/components.json) | ui/select |
| `bots/post-source` | [assets/modules/bots/components.json](../assets/modules/bots/components.json) | — |
| `bots/source-preview` | [assets/modules/bots/components.json](../assets/modules/bots/components.json) | — |
| `bots/post-filter-form` | [assets/modules/bots/components.json](../assets/modules/bots/components.json) | ui/select |
| `bots/account-fields` | [assets/modules/bots/components.json](../assets/modules/bots/components.json) | ui/field, users/avatar-control |
| `bots/runs-list` | [assets/modules/bots/components.json](../assets/modules/bots/components.json) | bots/runs, ui/list-toolbar, ui/pagination |
| `bots/run-filter-form` | [assets/modules/bots/components.json](../assets/modules/bots/components.json) | ui/select |
| `health/report` | [assets/modules/health/components.json](../assets/modules/health/components.json) | health/scheduler-findings, ui/status-badge |
| `health/scheduler-findings` | [assets/modules/health/components.json](../assets/modules/health/components.json) | — |
| `moderation/reports` | [assets/modules/moderation/components.json](../assets/modules/moderation/components.json) | moderation/status, ui/empty-state, ui/list-toolbar, ui/modal-trigger, ui/pagination, ui/sort-heading |
| `moderation/filter-form` | [assets/modules/moderation/components.json](../assets/modules/moderation/components.json) | ui/select |
| `moderation/history` | [assets/modules/moderation/components.json](../assets/modules/moderation/components.json) | moderation/status, ui/empty-state, ui/modal-trigger |
| `moderation/report-preview` | [assets/modules/moderation/components.json](../assets/modules/moderation/components.json) | moderation/status |
| `moderation/status` | [assets/modules/moderation/components.json](../assets/modules/moderation/components.json) | ui/status-badge |
| `notifications/copy` | [assets/modules/notifications/components.json](../assets/modules/notifications/components.json) | — |
| `notifications/action` | [assets/modules/notifications/components.json](../assets/modules/notifications/components.json) | — |
| `notifications/item` | [assets/modules/notifications/components.json](../assets/modules/notifications/components.json) | notifications/action, notifications/copy, ui/avatar-content |
| `notifications/preview-item` | [assets/modules/notifications/components.json](../assets/modules/notifications/components.json) | notifications/copy, ui/avatar-content |
| `notifications/items` | [assets/modules/notifications/components.json](../assets/modules/notifications/components.json) | notifications/item, notifications/preview-item, ui/empty-state |
| `scheduler/status` | [assets/modules/scheduler/components.json](../assets/modules/scheduler/components.json) | ui/status-badge |
| `scheduler/list` | [assets/modules/scheduler/components.json](../assets/modules/scheduler/components.json) | scheduler/status, scheduler/task-card, ui/empty-state, ui/filter-trigger, ui/list-toolbar, ui/modal-trigger, ui/pagination, ui/sort-heading |
| `scheduler/filter-form` | [assets/modules/scheduler/components.json](../assets/modules/scheduler/components.json) | ui/select |
| `scheduler/run-detail` | [assets/modules/scheduler/components.json](../assets/modules/scheduler/components.json) | bots/run-status, health/report, scheduler/status |
| `scheduler/task-card` | [assets/modules/scheduler/components.json](../assets/modules/scheduler/components.json) | scheduler/status, ui/api-action |
| `search/page` | [assets/modules/search/components.json](../assets/modules/search/components.json) | site/archive-actions, status/card, status/feed-more, ui/captcha, ui/empty-state |
| `site/sidebar` | [assets/modules/site/components.json](../assets/modules/site/components.json) | ui/avatar, ui/empty-state |
| `site/public-page` | [assets/modules/site/components.json](../assets/modules/site/components.json) | search/page, site/archive-actions, site/archive-filter, status/card, status/composer-body, status/feed-more, status/thread, ui/avatar-content, users/avatar-control |
| `site/archive-filter` | [assets/modules/site/components.json](../assets/modules/site/components.json) | site/archive-filter-name |
| `site/archive-actions` | [assets/modules/site/components.json](../assets/modules/site/components.json) | site/archive-filter |
| `site/archive-filter-name` | [assets/modules/site/components.json](../assets/modules/site/components.json) | — |
| `site/dashboard` | [assets/modules/site/components.json](../assets/modules/site/components.json) | ui/admin-list, ui/stat-card |
| `site/dashboard-comments` | [assets/modules/site/components.json](../assets/modules/site/components.json) | ui/empty-state |
| `site/dashboard-cache` | [assets/modules/site/components.json](../assets/modules/site/components.json) | — |
| `status/segments` | [assets/modules/status/components.json](../assets/modules/status/components.json) | — |
| `status/time` | [assets/modules/status/components.json](../assets/modules/status/components.json) | status/permalink |
| `status/image` | [assets/modules/status/components.json](../assets/modules/status/components.json) | — |
| `status/link` | [assets/modules/status/components.json](../assets/modules/status/components.json) | status/link-summary |
| `status/delete` | [assets/modules/status/components.json](../assets/modules/status/components.json) | — |
| `status/menu` | [assets/modules/status/components.json](../assets/modules/status/components.json) | status/delete |
| `status/manage` | [assets/modules/status/components.json](../assets/modules/status/components.json) | status/menu, status/permalink |
| `status/like` | [assets/modules/status/components.json](../assets/modules/status/components.json) | status/context |
| `status/actions` | [assets/modules/status/components.json](../assets/modules/status/components.json) | status/context, status/like |
| `status/card` | [assets/modules/status/components.json](../assets/modules/status/components.json) | status/actions, status/image, status/link, status/manage, status/segments, status/time, ui/avatar |
| `status/editor` | [assets/modules/status/components.json](../assets/modules/status/components.json) | — |
| `status/comment-form` | [assets/modules/status/components.json](../assets/modules/status/components.json) | status/editor, ui/avatar |
| `status/comment` | [assets/modules/status/components.json](../assets/modules/status/components.json) | status/comment, status/comment-form, status/like, status/menu, status/segments, ui/avatar |
| `status/thread` | [assets/modules/status/components.json](../assets/modules/status/components.json) | status/comment, status/comment-form |
| `status/image-field` | [assets/modules/status/components.json](../assets/modules/status/components.json) | — |
| `status/composer-body` | [assets/modules/status/components.json](../assets/modules/status/components.json) | status/editor, status/image-field, ui/avatar |
| `status/feed-more` | [assets/modules/status/components.json](../assets/modules/status/components.json) | ui/load-more |
| `status/context` | [assets/modules/status/components.json](../assets/modules/status/components.json) | — |
| `status/permalink` | [assets/modules/status/components.json](../assets/modules/status/components.json) | — |
| `status/link-summary` | [assets/modules/status/components.json](../assets/modules/status/components.json) | — |
| `users/following-profile` | [assets/modules/users/components.json](../assets/modules/users/components.json) | ui/avatar |
| `users/admin-fields` | [assets/modules/users/components.json](../assets/modules/users/components.json) | ui/field, ui/select, users/avatar-control |
| `users/admin-row` | [assets/modules/users/components.json](../assets/modules/users/components.json) | ui/account-status, ui/activation-action, ui/api-action, ui/modal-trigger |
| `users/list` | [assets/modules/users/components.json](../assets/modules/users/components.json) | ui/empty-state, ui/list-toolbar, ui/pagination, ui/sort-heading, users/admin-row |
| `users/auth-form` | [assets/modules/users/components.json](../assets/modules/users/components.json) | ui/captcha, ui/field |
| `users/auth-content` | [assets/modules/users/components.json](../assets/modules/users/components.json) | users/auth-form |
| `users/filter-form` | [assets/modules/users/components.json](../assets/modules/users/components.json) | ui/field, ui/select |
| `users/avatar-control` | [assets/modules/users/components.json](../assets/modules/users/components.json) | ui/avatar-content |
| `ui/field` | [assets/ui/components.json](../assets/ui/components.json) | — |
| `ui/select` | [assets/ui/components.json](../assets/ui/components.json) | — |
| `ui/pagination` | [assets/ui/components.json](../assets/ui/components.json) | ui/pagination-step |
| `ui/api-action` | [assets/ui/components.json](../assets/ui/components.json) | — |
| `ui/modal` | [assets/ui/components.json](../assets/ui/components.json) | — |
| `ui/captcha` | [assets/ui/components.json](../assets/ui/components.json) | — |
| `ui/search-form` | [assets/ui/components.json](../assets/ui/components.json) | — |
| `ui/per-page` | [assets/ui/components.json](../assets/ui/components.json) | — |
| `ui/list-toolbar` | [assets/ui/components.json](../assets/ui/components.json) | ui/filter-trigger, ui/list-summary, ui/per-page, ui/search-form |
| `ui/avatar` | [assets/ui/components.json](../assets/ui/components.json) | ui/avatar-content |
| `ui/avatar-content` | [assets/ui/components.json](../assets/ui/components.json) | — |
| `ui/modal-trigger` | [assets/ui/components.json](../assets/ui/components.json) | — |
| `ui/admin-list` | [assets/ui/components.json](../assets/ui/components.json) | ui/filter-trigger |
| `ui/account-status` | [assets/ui/components.json](../assets/ui/components.json) | ui/status-badge |
| `ui/pagination-step` | [assets/ui/components.json](../assets/ui/components.json) | — |
| `ui/sort-heading` | [assets/ui/components.json](../assets/ui/components.json) | — |
| `ui/status-badge` | [assets/ui/components.json](../assets/ui/components.json) | — |
| `ui/stat-card` | [assets/ui/components.json](../assets/ui/components.json) | — |
| `ui/notice` | [assets/ui/components.json](../assets/ui/components.json) | — |
| `ui/empty-state` | [assets/ui/components.json](../assets/ui/components.json) | — |
| `ui/activation-action` | [assets/ui/components.json](../assets/ui/components.json) | ui/api-action |
| `ui/filter-trigger` | [assets/ui/components.json](../assets/ui/components.json) | — |
| `ui/filter-content` | [assets/ui/components.json](../assets/ui/components.json) | — |
| `ui/list-summary` | [assets/ui/components.json](../assets/ui/components.json) | ui/filter-chip |
| `ui/editor-actions` | [assets/ui/components.json](../assets/ui/components.json) | — |
| `ui/filter-chip` | [assets/ui/components.json](../assets/ui/components.json) | — |
| `ui/load-more` | [assets/ui/components.json](../assets/ui/components.json) | — |

## UI služby a metody zapsané jako `name: function`

Jde o syntaktický seznam; nezahrnuje všechny exporty a nerozhoduje o veřejnosti metody.
- [assets/ui/ajax-bindings.js](../assets/ui/ajax-bindings.js): `current`
- [assets/ui/avatar-rendering.js](../assets/ui/avatar-rendering.js): `appendAvatar`
- [assets/ui/component-builders.js](../assets/ui/component-builders.js): `text`, `field`, `select`, `hidden`, `button`, `form`, `dialog`, `editorActions`, `accountDialogTitle`, `formDialog`, `tabbedContent`, `pinDialogTabs`, `sectionedDialog`, `adminDialog`, `editorFrame`, `pagination`, `captcha`
- [assets/ui/confirm-actions.js](../assets/ui/confirm-actions.js): 
- [assets/ui/content-updates.js](../assets/ui/content-updates.js): `replaceChildren`, `remove`, `replaceNode`
- [assets/ui/dialog-history.js](../assets/ui/dialog-history.js): `opened`, `closed`, `restore`, `leave`, `init`, `hydrate`
- [assets/ui/dialogs.js](../assets/ui/dialogs.js): `create`
- [assets/ui/filters.js](../assets/ui/filters.js): `get`, `capture`, `restore`, `remove`, `init`
- [assets/ui/form-requests.js](../assets/ui/form-requests.js): `request`, `cancel`, `dispose`, `bind`
- [assets/ui/form-state.js](../assets/ui/form-state.js): 
- [assets/ui/image-placeholders.js](../assets/ui/image-placeholders.js): 
- [assets/ui/image-uploads.js](../assets/ui/image-uploads.js): 
- [assets/ui/list-navigation.js](../assets/ui/list-navigation.js): `register`, `isRead`, `url`
- [assets/ui/popovers.js](../assets/ui/popovers.js): `restoringFocus`, `capture`, `restore`, `init`, `dispose`
- [assets/ui/tabs.js](../assets/ui/tabs.js): 
- [assets/ui/toasts.js](../assets/ui/toasts.js): 
- [assets/ui/tooltips.js](../assets/ui/tooltips.js): `dispose`, `init`

## JavaScript — soubory, importy a vstupní body

Trasy označují přímé vstupní body manifestů. Další soubory se načítají přes importní graf; `bootstrap.js` připojuje společný builder automaticky.

| Soubor | Přímé importy | Vstupní trasy |
| --- | --- | --- |
| [assets/bootstrap.js](../assets/bootstrap.js) | [assets/core/lifecycle.js](../assets/core/lifecycle.js), [assets/core/modules.js](../assets/core/modules.js), [assets/core/navigation.js](../assets/core/navigation.js) | přes importy / společný runtime |
| [assets/core/api-client.js](../assets/core/api-client.js) | [assets/core/i18n.js](../assets/core/i18n.js), [assets/core/urls.js](../assets/core/urls.js) | přes importy / společný runtime |
| [assets/core/component-renderer.js](../assets/core/component-renderer.js) | [assets/core/dom.js](../assets/core/dom.js), [assets/core/api-client.js](../assets/core/api-client.js), [assets/core/i18n.js](../assets/core/i18n.js), [assets/core/icons.js](../assets/core/icons.js) | přes importy / společný runtime |
| [assets/core/dates.js](../assets/core/dates.js) | [assets/core/i18n.js](../assets/core/i18n.js) | admin, public |
| [assets/core/dom.js](../assets/core/dom.js) | [assets/core/urls.js](../assets/core/urls.js) | přes importy / společný runtime |
| [assets/core/i18n.js](../assets/core/i18n.js) |  | přes importy / společný runtime |
| [assets/core/icons.js](../assets/core/icons.js) |  | přes importy / společný runtime |
| [assets/core/lifecycle.js](../assets/core/lifecycle.js) |  | přes importy / společný runtime |
| [assets/core/modules.js](../assets/core/modules.js) |  | přes importy / společný runtime |
| [assets/core/navigation.js](../assets/core/navigation.js) | [assets/core/request-lifecycle.js](../assets/core/request-lifecycle.js), [assets/core/dom.js](../assets/core/dom.js), [assets/core/api-client.js](../assets/core/api-client.js), [assets/core/lifecycle.js](../assets/core/lifecycle.js) | přes importy / společný runtime |
| [assets/core/request-lifecycle.js](../assets/core/request-lifecycle.js) | [assets/core/lifecycle.js](../assets/core/lifecycle.js) | přes importy / společný runtime |
| [assets/core/urls.js](../assets/core/urls.js) |  | přes importy / společný runtime |
| [assets/embed-frame.js](../assets/embed-frame.js) |  | standalone |
| [assets/embed.js](../assets/embed.js) |  | standalone |
| [assets/modules/admin/index.js](../assets/modules/admin/index.js) | [assets/modules/admin/navigation.js](../assets/modules/admin/navigation.js) | admin |
| [assets/modules/admin/navigation.js](../assets/modules/admin/navigation.js) | [assets/core/dom.js](../assets/core/dom.js), [assets/core/lifecycle.js](../assets/core/lifecycle.js) | přes importy / společný runtime |
| [assets/modules/bots/controller.js](../assets/modules/bots/controller.js) | [assets/ui/content-updates.js](../assets/ui/content-updates.js), [assets/ui/list-navigation.js](../assets/ui/list-navigation.js), [assets/core/dates.js](../assets/core/dates.js), [assets/core/component-renderer.js](../assets/core/component-renderer.js), [assets/core/api-client.js](../assets/core/api-client.js), [assets/ui/avatar-rendering.js](../assets/ui/avatar-rendering.js), [assets/ui/dialogs.js](../assets/ui/dialogs.js), [assets/ui/component-builders.js](../assets/ui/component-builders.js), [assets/ui/form-state.js](../assets/ui/form-state.js), [assets/ui/form-requests.js](../assets/ui/form-requests.js), [assets/ui/toasts.js](../assets/ui/toasts.js), [assets/modules/bots/source-preview.js](../assets/modules/bots/source-preview.js), [assets/modules/bots/workspace.js](../assets/modules/bots/workspace.js) | přes importy / společný runtime |
| [assets/modules/bots/index.js](../assets/modules/bots/index.js) | [assets/core/modules.js](../assets/core/modules.js), [assets/modules/bots/controller.js](../assets/modules/bots/controller.js), [assets/ui/list-navigation.js](../assets/ui/list-navigation.js) | /admin/bots |
| [assets/modules/bots/source-preview.js](../assets/modules/bots/source-preview.js) | [assets/core/lifecycle.js](../assets/core/lifecycle.js), [assets/core/component-renderer.js](../assets/core/component-renderer.js), [assets/ui/component-builders.js](../assets/ui/component-builders.js), [assets/ui/form-requests.js](../assets/ui/form-requests.js) | přes importy / společný runtime |
| [assets/modules/bots/workspace.js](../assets/modules/bots/workspace.js) | [assets/ui/content-updates.js](../assets/ui/content-updates.js), [assets/ui/list-navigation.js](../assets/ui/list-navigation.js), [assets/core/dom.js](../assets/core/dom.js), [assets/core/api-client.js](../assets/core/api-client.js), [assets/ui/component-builders.js](../assets/ui/component-builders.js), [assets/ui/tabs.js](../assets/ui/tabs.js) | přes importy / společný runtime |
| [assets/modules/installation/index.js](../assets/modules/installation/index.js) | [assets/core/lifecycle.js](../assets/core/lifecycle.js), [assets/ui/form-requests.js](../assets/ui/form-requests.js), [assets/ui/toasts.js](../assets/ui/toasts.js) | /install |
| [assets/modules/moderation/controller.js](../assets/modules/moderation/controller.js) | [assets/ui/content-updates.js](../assets/ui/content-updates.js), [assets/core/dates.js](../assets/core/dates.js), [assets/core/component-renderer.js](../assets/core/component-renderer.js), [assets/ui/component-builders.js](../assets/ui/component-builders.js), [assets/ui/dialogs.js](../assets/ui/dialogs.js), [assets/core/dom.js](../assets/core/dom.js) | přes importy / společný runtime |
| [assets/modules/moderation/index.js](../assets/modules/moderation/index.js) | [assets/core/modules.js](../assets/core/modules.js), [assets/modules/moderation/controller.js](../assets/modules/moderation/controller.js), [assets/ui/list-navigation.js](../assets/ui/list-navigation.js) | /admin/moderation |
| [assets/modules/notifications/controller.js](../assets/modules/notifications/controller.js) | [assets/core/dates.js](../assets/core/dates.js), [assets/core/component-renderer.js](../assets/core/component-renderer.js), [assets/core/dom.js](../assets/core/dom.js), [assets/core/api-client.js](../assets/core/api-client.js), [assets/ui/component-builders.js](../assets/ui/component-builders.js), [assets/ui/toasts.js](../assets/ui/toasts.js), [assets/ui/popovers.js](../assets/ui/popovers.js) | přes importy / společný runtime |
| [assets/modules/notifications/index.js](../assets/modules/notifications/index.js) | [assets/core/modules.js](../assets/core/modules.js), [assets/modules/notifications/controller.js](../assets/modules/notifications/controller.js) | public |
| [assets/modules/scheduler/controller.js](../assets/modules/scheduler/controller.js) | [assets/ui/content-updates.js](../assets/ui/content-updates.js), [assets/core/component-renderer.js](../assets/core/component-renderer.js), [assets/core/dates.js](../assets/core/dates.js), [assets/ui/component-builders.js](../assets/ui/component-builders.js) | přes importy / společný runtime |
| [assets/modules/scheduler/index.js](../assets/modules/scheduler/index.js) | [assets/core/modules.js](../assets/core/modules.js), [assets/modules/scheduler/controller.js](../assets/modules/scheduler/controller.js), [assets/ui/list-navigation.js](../assets/ui/list-navigation.js) | /admin/cron, /admin/site-health |
| [assets/modules/search/global-search.js](../assets/modules/search/global-search.js) | [assets/core/request-lifecycle.js](../assets/core/request-lifecycle.js), [assets/core/dates.js](../assets/core/dates.js), [assets/core/dom.js](../assets/core/dom.js), [assets/core/api-client.js](../assets/core/api-client.js), [assets/core/i18n.js](../assets/core/i18n.js), [assets/core/lifecycle.js](../assets/core/lifecycle.js), [assets/core/navigation.js](../assets/core/navigation.js), [assets/ui/avatar-rendering.js](../assets/ui/avatar-rendering.js), [assets/ui/dialogs.js](../assets/ui/dialogs.js), [assets/ui/component-builders.js](../assets/ui/component-builders.js), [assets/ui/popovers.js](../assets/ui/popovers.js) | přes importy / společný runtime |
| [assets/modules/search/index.js](../assets/modules/search/index.js) | [assets/core/modules.js](../assets/core/modules.js), [assets/modules/search/global-search.js](../assets/modules/search/global-search.js) | public |
| [assets/modules/security/captcha.js](../assets/modules/security/captcha.js) | [assets/core/dom.js](../assets/core/dom.js), [assets/core/lifecycle.js](../assets/core/lifecycle.js) | přes importy / společný runtime |
| [assets/modules/security/index.js](../assets/modules/security/index.js) | [assets/modules/security/captcha.js](../assets/modules/security/captcha.js) | public |
| [assets/modules/settings/controller.js](../assets/modules/settings/controller.js) | [assets/ui/image-uploads.js](../assets/ui/image-uploads.js), [assets/ui/component-builders.js](../assets/ui/component-builders.js), [assets/core/modules.js](../assets/core/modules.js), [assets/modules/settings/field-presentation.js](../assets/modules/settings/field-presentation.js) | přes importy / společný runtime |
| [assets/modules/settings/field-presentation.js](../assets/modules/settings/field-presentation.js) |  | přes importy / společný runtime |
| [assets/modules/settings/index.js](../assets/modules/settings/index.js) | [assets/modules/settings/controller.js](../assets/modules/settings/controller.js) | admin |
| [assets/modules/site/analytics.js](../assets/modules/site/analytics.js) | [assets/core/dom.js](../assets/core/dom.js), [assets/core/lifecycle.js](../assets/core/lifecycle.js) | přes importy / společný runtime |
| [assets/modules/site/index.js](../assets/modules/site/index.js) | [assets/core/modules.js](../assets/core/modules.js), [assets/modules/site/analytics.js](../assets/modules/site/analytics.js), [assets/modules/site/sidebar-data.js](../assets/modules/site/sidebar-data.js), [assets/modules/site/sticky-sidebars.js](../assets/modules/site/sticky-sidebars.js), [assets/modules/site/views.js](../assets/modules/site/views.js), [assets/modules/site/page-navigation.js](../assets/modules/site/page-navigation.js), [assets/modules/site/language-suggestion.js](../assets/modules/site/language-suggestion.js) | public |
| [assets/modules/site/language-suggestion.js](../assets/modules/site/language-suggestion.js) | [assets/ui/popovers.js](../assets/ui/popovers.js), [assets/core/lifecycle.js](../assets/core/lifecycle.js) | přes importy / společný runtime |
| [assets/modules/site/page-navigation.js](../assets/modules/site/page-navigation.js) | [assets/core/navigation.js](../assets/core/navigation.js), [assets/core/api-client.js](../assets/core/api-client.js), [assets/core/component-renderer.js](../assets/core/component-renderer.js), [assets/core/lifecycle.js](../assets/core/lifecycle.js), [assets/ui/dialogs.js](../assets/ui/dialogs.js) | přes importy / společný runtime |
| [assets/modules/site/sidebar-data.js](../assets/modules/site/sidebar-data.js) | [assets/core/dom.js](../assets/core/dom.js), [assets/core/api-client.js](../assets/core/api-client.js), [assets/core/lifecycle.js](../assets/core/lifecycle.js), [assets/modules/site/views.js](../assets/modules/site/views.js) | přes importy / společný runtime |
| [assets/modules/site/sticky-sidebars.js](../assets/modules/site/sticky-sidebars.js) | [assets/core/dom.js](../assets/core/dom.js), [assets/core/lifecycle.js](../assets/core/lifecycle.js) | přes importy / společný runtime |
| [assets/modules/site/views.js](../assets/modules/site/views.js) | [assets/core/component-renderer.js](../assets/core/component-renderer.js) | přes importy / společný runtime |
| [assets/modules/status/comments.js](../assets/modules/status/comments.js) | [assets/ui/content-updates.js](../assets/ui/content-updates.js), [assets/core/dom.js](../assets/core/dom.js), [assets/modules/status/editor.js](../assets/modules/status/editor.js), [assets/modules/status/form-context.js](../assets/modules/status/form-context.js), [assets/modules/status/controller.js](../assets/modules/status/controller.js), [assets/ui/dialogs.js](../assets/ui/dialogs.js) | přes importy / společný runtime |
| [assets/modules/status/controller.js](../assets/modules/status/controller.js) | [assets/ui/content-updates.js](../assets/ui/content-updates.js), [assets/core/dates.js](../assets/core/dates.js), [assets/core/component-renderer.js](../assets/core/component-renderer.js), [assets/core/dom.js](../assets/core/dom.js), [assets/ui/component-builders.js](../assets/ui/component-builders.js) | přes importy / společný runtime |
| [assets/modules/status/editor.js](../assets/modules/status/editor.js) | [assets/core/dom.js](../assets/core/dom.js), [assets/core/api-client.js](../assets/core/api-client.js), [assets/core/navigation.js](../assets/core/navigation.js), [assets/modules/status/controller.js](../assets/modules/status/controller.js), [assets/ui/avatar-rendering.js](../assets/ui/avatar-rendering.js), [assets/ui/component-builders.js](../assets/ui/component-builders.js) | přes importy / společný runtime |
| [assets/modules/status/feed-loader.js](../assets/modules/status/feed-loader.js) | [assets/core/request-lifecycle.js](../assets/core/request-lifecycle.js), [assets/ui/content-updates.js](../assets/ui/content-updates.js), [assets/core/dom.js](../assets/core/dom.js), [assets/core/api-client.js](../assets/core/api-client.js), [assets/core/i18n.js](../assets/core/i18n.js), [assets/core/lifecycle.js](../assets/core/lifecycle.js), [assets/core/navigation.js](../assets/core/navigation.js), [assets/modules/notifications/controller.js](../assets/modules/notifications/controller.js), [assets/modules/status/controller.js](../assets/modules/status/controller.js), [assets/ui/dialogs.js](../assets/ui/dialogs.js), [assets/ui/component-builders.js](../assets/ui/component-builders.js), [assets/ui/toasts.js](../assets/ui/toasts.js) | přes importy / společný runtime |
| [assets/modules/status/form-actions.js](../assets/modules/status/form-actions.js) | [assets/ui/content-updates.js](../assets/ui/content-updates.js), [assets/core/dom.js](../assets/core/dom.js), [assets/core/api-client.js](../assets/core/api-client.js), [assets/core/i18n.js](../assets/core/i18n.js), [assets/core/lifecycle.js](../assets/core/lifecycle.js), [assets/core/navigation.js](../assets/core/navigation.js), [assets/modules/status/comments.js](../assets/modules/status/comments.js), [assets/modules/status/editor.js](../assets/modules/status/editor.js), [assets/modules/status/feed-loader.js](../assets/modules/status/feed-loader.js), [assets/modules/status/form-context.js](../assets/modules/status/form-context.js), [assets/modules/status/controller.js](../assets/modules/status/controller.js), [assets/ui/confirm-actions.js](../assets/ui/confirm-actions.js), [assets/ui/dialogs.js](../assets/ui/dialogs.js), [assets/ui/form-state.js](../assets/ui/form-state.js), [assets/ui/form-requests.js](../assets/ui/form-requests.js), [assets/ui/toasts.js](../assets/ui/toasts.js) | přes importy / společný runtime |
| [assets/modules/status/form-context.js](../assets/modules/status/form-context.js) | [assets/core/dom.js](../assets/core/dom.js), [assets/modules/status/controller.js](../assets/modules/status/controller.js) | přes importy / společný runtime |
| [assets/modules/status/index.js](../assets/modules/status/index.js) | [assets/core/dom.js](../assets/core/dom.js), [assets/core/modules.js](../assets/core/modules.js), [assets/core/navigation.js](../assets/core/navigation.js), [assets/modules/status/form-actions.js](../assets/modules/status/form-actions.js), [assets/modules/status/comments.js](../assets/modules/status/comments.js), [assets/modules/status/editor.js](../assets/modules/status/editor.js), [assets/modules/status/feed-loader.js](../assets/modules/status/feed-loader.js), [assets/modules/status/form-context.js](../assets/modules/status/form-context.js), [assets/modules/status/media.js](../assets/modules/status/media.js), [assets/modules/status/controller.js](../assets/modules/status/controller.js), [assets/modules/status/translation.js](../assets/modules/status/translation.js) | /admin/bots, public |
| [assets/modules/status/media-preview.js](../assets/modules/status/media-preview.js) | [assets/core/modules.js](../assets/core/modules.js), [assets/modules/status/media.js](../assets/modules/status/media.js) | /admin/moderation |
| [assets/modules/status/media.js](../assets/modules/status/media.js) | [assets/core/dom.js](../assets/core/dom.js), [assets/core/navigation.js](../assets/core/navigation.js), [assets/modules/status/controller.js](../assets/modules/status/controller.js), [assets/ui/image-uploads.js](../assets/ui/image-uploads.js), [assets/ui/toasts.js](../assets/ui/toasts.js) | přes importy / společný runtime |
| [assets/modules/status/translation.js](../assets/modules/status/translation.js) | [assets/core/i18n.js](../assets/core/i18n.js), [assets/core/icons.js](../assets/core/icons.js), [assets/modules/status/controller.js](../assets/modules/status/controller.js) | přes importy / společný runtime |
| [assets/modules/updates/index.js](../assets/modules/updates/index.js) | [assets/core/modules.js](../assets/core/modules.js), [assets/ui/component-builders.js](../assets/ui/component-builders.js) | /admin/updates |
| [assets/modules/users/admin.js](../assets/modules/users/admin.js) | [assets/modules/users/index.js](../assets/modules/users/index.js) | /admin/users |
| [assets/modules/users/avatar-editor.js](../assets/modules/users/avatar-editor.js) | [assets/core/dom.js](../assets/core/dom.js), [assets/modules/users/controller.js](../assets/modules/users/controller.js), [assets/ui/dialogs.js](../assets/ui/dialogs.js), [assets/ui/image-uploads.js](../assets/ui/image-uploads.js), [assets/ui/toasts.js](../assets/ui/toasts.js) | přes importy / společný runtime |
| [assets/modules/users/controller.js](../assets/modules/users/controller.js) | [assets/ui/list-navigation.js](../assets/ui/list-navigation.js), [assets/ui/content-updates.js](../assets/ui/content-updates.js), [assets/core/dates.js](../assets/core/dates.js), [assets/core/component-renderer.js](../assets/core/component-renderer.js), [assets/core/dom.js](../assets/core/dom.js), [assets/core/api-client.js](../assets/core/api-client.js), [assets/core/i18n.js](../assets/core/i18n.js), [assets/core/lifecycle.js](../assets/core/lifecycle.js), [assets/ui/avatar-rendering.js](../assets/ui/avatar-rendering.js), [assets/ui/dialogs.js](../assets/ui/dialogs.js), [assets/ui/component-builders.js](../assets/ui/component-builders.js), [assets/ui/toasts.js](../assets/ui/toasts.js) | přes importy / společný runtime |
| [assets/modules/users/email-availability.js](../assets/modules/users/email-availability.js) | [assets/core/api-client.js](../assets/core/api-client.js), [assets/core/lifecycle.js](../assets/core/lifecycle.js), [assets/ui/component-builders.js](../assets/ui/component-builders.js), [assets/ui/form-state.js](../assets/ui/form-state.js) | přes importy / společný runtime |
| [assets/modules/users/following.js](../assets/modules/users/following.js) | [assets/core/dom.js](../assets/core/dom.js), [assets/core/api-client.js](../assets/core/api-client.js), [assets/core/i18n.js](../assets/core/i18n.js), [assets/modules/users/controller.js](../assets/modules/users/controller.js), [assets/ui/component-builders.js](../assets/ui/component-builders.js), [assets/ui/form-requests.js](../assets/ui/form-requests.js), [assets/ui/toasts.js](../assets/ui/toasts.js) | přes importy / společný runtime |
| [assets/modules/users/index.js](../assets/modules/users/index.js) | [assets/modules/users/user-menu.js](../assets/modules/users/user-menu.js), [assets/core/modules.js](../assets/core/modules.js), [assets/ui/list-navigation.js](../assets/ui/list-navigation.js), [assets/modules/users/avatar-editor.js](../assets/modules/users/avatar-editor.js), [assets/modules/users/controller.js](../assets/modules/users/controller.js), [assets/modules/users/email-availability.js](../assets/modules/users/email-availability.js) | admin, public |
| [assets/modules/users/public.js](../assets/modules/users/public.js) | [assets/modules/users/index.js](../assets/modules/users/index.js), [assets/modules/users/following.js](../assets/modules/users/following.js) | public |
| [assets/modules/users/user-menu.js](../assets/modules/users/user-menu.js) | [assets/core/api-client.js](../assets/core/api-client.js), [assets/core/dom.js](../assets/core/dom.js), [assets/core/lifecycle.js](../assets/core/lifecycle.js), [assets/ui/toasts.js](../assets/ui/toasts.js) | přes importy / společný runtime |
| [assets/ui/ajax-bindings.js](../assets/ui/ajax-bindings.js) | [assets/ui/list-navigation.js](../assets/ui/list-navigation.js), [assets/core/request-lifecycle.js](../assets/core/request-lifecycle.js), [assets/core/dom.js](../assets/core/dom.js), [assets/core/api-client.js](../assets/core/api-client.js), [assets/core/i18n.js](../assets/core/i18n.js), [assets/core/lifecycle.js](../assets/core/lifecycle.js), [assets/core/modules.js](../assets/core/modules.js), [assets/core/navigation.js](../assets/core/navigation.js), [assets/ui/confirm-actions.js](../assets/ui/confirm-actions.js), [assets/ui/dialogs.js](../assets/ui/dialogs.js), [assets/ui/component-builders.js](../assets/ui/component-builders.js), [assets/ui/form-state.js](../assets/ui/form-state.js), [assets/ui/form-requests.js](../assets/ui/form-requests.js), [assets/ui/toasts.js](../assets/ui/toasts.js) | admin, public |
| [assets/ui/avatar-rendering.js](../assets/ui/avatar-rendering.js) | [assets/core/component-renderer.js](../assets/core/component-renderer.js), [assets/core/dom.js](../assets/core/dom.js), [assets/core/lifecycle.js](../assets/core/lifecycle.js), [assets/ui/component-builders.js](../assets/ui/component-builders.js) | přes importy / společný runtime |
| [assets/ui/component-builders.js](../assets/ui/component-builders.js) | [assets/core/component-renderer.js](../assets/core/component-renderer.js), [assets/core/dom.js](../assets/core/dom.js), [assets/core/i18n.js](../assets/core/i18n.js), [assets/core/icons.js](../assets/core/icons.js), [assets/core/lifecycle.js](../assets/core/lifecycle.js), [assets/ui/dialogs.js](../assets/ui/dialogs.js), [assets/ui/filters.js](../assets/ui/filters.js) | přes importy / společný runtime |
| [assets/ui/confirm-actions.js](../assets/ui/confirm-actions.js) | [assets/core/lifecycle.js](../assets/core/lifecycle.js), [assets/ui/dialogs.js](../assets/ui/dialogs.js) | admin, public |
| [assets/ui/content-updates.js](../assets/ui/content-updates.js) | [assets/core/lifecycle.js](../assets/core/lifecycle.js) | přes importy / společný runtime |
| [assets/ui/dialog-history.js](../assets/ui/dialog-history.js) | [assets/core/navigation.js](../assets/core/navigation.js), [assets/core/lifecycle.js](../assets/core/lifecycle.js), [assets/ui/tabs.js](../assets/ui/tabs.js) | přes importy / společný runtime |
| [assets/ui/dialogs.js](../assets/ui/dialogs.js) | [assets/ui/dialog-history.js](../assets/ui/dialog-history.js), [assets/core/component-renderer.js](../assets/core/component-renderer.js), [assets/core/dom.js](../assets/core/dom.js), [assets/core/api-client.js](../assets/core/api-client.js), [assets/core/i18n.js](../assets/core/i18n.js), [assets/core/icons.js](../assets/core/icons.js), [assets/core/lifecycle.js](../assets/core/lifecycle.js), [assets/core/modules.js](../assets/core/modules.js), [assets/ui/form-state.js](../assets/ui/form-state.js), [assets/ui/form-requests.js](../assets/ui/form-requests.js) | přes importy / společný runtime |
| [assets/ui/filters.js](../assets/ui/filters.js) | [assets/core/dom.js](../assets/core/dom.js), [assets/core/lifecycle.js](../assets/core/lifecycle.js), [assets/core/component-renderer.js](../assets/core/component-renderer.js), [assets/ui/popovers.js](../assets/ui/popovers.js), [assets/ui/form-requests.js](../assets/ui/form-requests.js) | přes importy / společný runtime |
| [assets/ui/form-requests.js](../assets/ui/form-requests.js) | [assets/core/dom.js](../assets/core/dom.js), [assets/core/api-client.js](../assets/core/api-client.js) | přes importy / společný runtime |
| [assets/ui/form-state.js](../assets/ui/form-state.js) | [assets/core/dom.js](../assets/core/dom.js), [assets/core/lifecycle.js](../assets/core/lifecycle.js), [assets/core/navigation.js](../assets/core/navigation.js), [assets/ui/form-requests.js](../assets/ui/form-requests.js), [assets/ui/tabs.js](../assets/ui/tabs.js) | přes importy / společný runtime |
| [assets/ui/image-placeholders.js](../assets/ui/image-placeholders.js) | [assets/core/lifecycle.js](../assets/core/lifecycle.js) | * |
| [assets/ui/image-uploads.js](../assets/ui/image-uploads.js) | [assets/core/dom.js](../assets/core/dom.js), [assets/core/lifecycle.js](../assets/core/lifecycle.js), [assets/ui/toasts.js](../assets/ui/toasts.js) | přes importy / společný runtime |
| [assets/ui/list-navigation.js](../assets/ui/list-navigation.js) | [assets/core/navigation.js](../assets/core/navigation.js), [assets/core/dom.js](../assets/core/dom.js), [assets/core/request-lifecycle.js](../assets/core/request-lifecycle.js), [assets/core/lifecycle.js](../assets/core/lifecycle.js) | přes importy / společný runtime |
| [assets/ui/popovers.js](../assets/ui/popovers.js) | [assets/core/dom.js](../assets/core/dom.js), [assets/core/lifecycle.js](../assets/core/lifecycle.js) | * |
| [assets/ui/tabs.js](../assets/ui/tabs.js) | [assets/core/dom.js](../assets/core/dom.js), [assets/core/lifecycle.js](../assets/core/lifecycle.js) | * |
| [assets/ui/toasts.js](../assets/ui/toasts.js) | [assets/core/dom.js](../assets/core/dom.js), [assets/core/i18n.js](../assets/core/i18n.js), [assets/core/icons.js](../assets/core/icons.js), [assets/core/lifecycle.js](../assets/core/lifecycle.js) | * |
| [assets/ui/tooltips.js](../assets/ui/tooltips.js) | [assets/core/lifecycle.js](../assets/core/lifecycle.js) | * |

## PHP partialy

- [App/Bots/templates/parts/modals/account-filter.php](../App/Bots/templates/parts/modals/account-filter.php)
- [App/Bots/templates/parts/modals/source-filter.php](../App/Bots/templates/parts/modals/source-filter.php)
- [App/Notifications/templates/parts/menu.php](../App/Notifications/templates/parts/menu.php)
- [App/Notifications/templates/parts/page.php](../App/Notifications/templates/parts/page.php)
- [App/Site/templates/parts/flashes.php](../App/Site/templates/parts/flashes.php)
- [App/Site/templates/parts/install/steps.php](../App/Site/templates/parts/install/steps.php)
- [App/Site/templates/parts/sidebar-slot.php](../App/Site/templates/parts/sidebar-slot.php)
- [App/Site/templates/parts/user-menu.php](../App/Site/templates/parts/user-menu.php)
- [App/Status/templates/parts/composer.php](../App/Status/templates/parts/composer.php)
- [App/Status/templates/parts/feed.php](../App/Status/templates/parts/feed.php)
- [App/Status/templates/parts/home-feed.php](../App/Status/templates/parts/home-feed.php)
- [App/Support/templates/parts/filter.php](../App/Support/templates/parts/filter.php)
- [App/Users/templates/parts/follow-button.php](../App/Users/templates/parts/follow-button.php)

## PHP renderery

- [App/Bots/Views.php](../App/Bots/Views.php): `accountFilter`, `accounts`, `sources`
- [App/Moderation/Views.php](../App/Moderation/Views.php): `reports`
- [App/Notifications/Views.php](../App/Notifications/Views.php): `items`
- [App/Site/Views.php](../App/Site/Views.php): `dashboard`, `sidebar`
- [App/Status/Views.php](../App/Status/Views.php): `feedMore`, `card`, `actions`, `commentForm`, `commentItem`, `commentsThread`, `composerBody`, `composer`, `homeFeed`
- [App/Support/AdminViews.php](../App/Support/AdminViews.php): `activationNotice`, `listCard`, `modalAction`, `tc_admin_options`, `tc_admin_datetime`, `tc_admin_datetime_iso`, `admin_user_statuses`, `admin_filter_text`, `admin_per_page_options`, `admin_per_page`, `admin_page`, `admin_list_query`, `admin_list_url`, `admin_per_page_view_data`, `admin_pagination_view_data`
- [App/Users/Views.php](../App/Users/Views.php): `adminFormFields`, `adminList`, `authContent`, `authForm`, `followButton`, `followingProfile`, `avatar`

## Všechny PHP šablony

- [App/Bots/templates/parts/modals/account-filter.php](../App/Bots/templates/parts/modals/account-filter.php)
- [App/Bots/templates/parts/modals/source-filter.php](../App/Bots/templates/parts/modals/source-filter.php)
- [App/Health/templates/page.php](../App/Health/templates/page.php)
- [App/Notifications/templates/page.php](../App/Notifications/templates/page.php)
- [App/Notifications/templates/parts/menu.php](../App/Notifications/templates/parts/menu.php)
- [App/Notifications/templates/parts/page.php](../App/Notifications/templates/parts/page.php)
- [App/Scheduler/templates/actions.php](../App/Scheduler/templates/actions.php)
- [App/Scheduler/templates/administration.php](../App/Scheduler/templates/administration.php)
- [App/Site/templates/admin.php](../App/Site/templates/admin.php)
- [App/Site/templates/document.php](../App/Site/templates/document.php)
- [App/Site/templates/errorFallback.php](../App/Site/templates/errorFallback.php)
- [App/Site/templates/head.php](../App/Site/templates/head.php)
- [App/Site/templates/notFound.php](../App/Site/templates/notFound.php)
- [App/Site/templates/parts/flashes.php](../App/Site/templates/parts/flashes.php)
- [App/Site/templates/parts/install/steps.php](../App/Site/templates/parts/install/steps.php)
- [App/Site/templates/parts/sidebar-slot.php](../App/Site/templates/parts/sidebar-slot.php)
- [App/Site/templates/parts/user-menu.php](../App/Site/templates/parts/user-menu.php)
- [App/Site/templates/privacy.php](../App/Site/templates/privacy.php)
- [App/Site/templates/public.php](../App/Site/templates/public.php)
- [App/Status/templates/embed.php](../App/Status/templates/embed.php)
- [App/Status/templates/parts/composer.php](../App/Status/templates/parts/composer.php)
- [App/Status/templates/parts/feed.php](../App/Status/templates/parts/feed.php)
- [App/Status/templates/parts/home-feed.php](../App/Status/templates/parts/home-feed.php)
- [App/Support/templates/parts/filter.php](../App/Support/templates/parts/filter.php)
- [App/Updates/templates/actions.php](../App/Updates/templates/actions.php)
- [App/Updates/templates/administration.php](../App/Updates/templates/administration.php)
- [App/Users/templates/login.php](../App/Users/templates/login.php)
- [App/Users/templates/logout.php](../App/Users/templates/logout.php)
- [App/Users/templates/parts/follow-button.php](../App/Users/templates/parts/follow-button.php)
- [App/Users/templates/recovery.php](../App/Users/templates/recovery.php)
- [App/Users/templates/register.php](../App/Users/templates/register.php)

## CSS mimo hlavní importní graf

- [assets/embed.css](../assets/embed.css): `embed-audio-summary`, `embed-author`, `embed-author-link`, `embed-avatar`, `embed-avatar-initial`, `embed-body`, `embed-card`, `embed-image`, `embed-link`, `embed-link-copy`, `embed-link-image`, `embed-play`, `embed-play-icon`, `embed-player`, `embed-provider`, `share-code`, `share-heading`, `share-page`, `status-audio-player`

## Vlastnictví a načítání CSS v pořadí kaskády

| Soubor | Manifest | Trasy |
| --- | --- | --- |
| [assets/ui/styles/tokens.css](../assets/ui/styles/tokens.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/ui/styles/reset.css](../assets/ui/styles/reset.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/ui/styles/layout.css](../assets/ui/styles/layout.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/ui/styles/cards.css](../assets/ui/styles/cards.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/ui/styles/check-card.css](../assets/ui/styles/check-card.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/ui/styles/disclosure.css](../assets/ui/styles/disclosure.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/ui/styles/visibility.css](../assets/ui/styles/visibility.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/modules/admin/styles/admin-lists.css](../assets/modules/admin/styles/admin-lists.css) | [assets/modules/admin/module.json](../assets/modules/admin/module.json) | admin |
| [assets/ui/styles/field-inline.css](../assets/ui/styles/field-inline.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/ui/styles/fields.css](../assets/ui/styles/fields.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/modules/security/styles/captcha.css](../assets/modules/security/styles/captcha.css) | [assets/modules/security/module.json](../assets/modules/security/module.json) | public |
| [assets/ui/styles/alerts.css](../assets/ui/styles/alerts.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/ui/styles/badges.css](../assets/ui/styles/badges.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/ui/styles/filter-chips.css](../assets/ui/styles/filter-chips.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/ui/styles/badge-danger.css](../assets/ui/styles/badge-danger.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/ui/styles/tables.css](../assets/ui/styles/tables.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/ui/styles/content-images.css](../assets/ui/styles/content-images.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/ui/styles/status-indicators.css](../assets/ui/styles/status-indicators.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/ui/styles/empty-state.css](../assets/ui/styles/empty-state.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/ui/styles/filter-menu.css](../assets/ui/styles/filter-menu.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/ui/styles/content-images-contain.css](../assets/ui/styles/content-images-contain.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/ui/styles/status-indicator-warning.css](../assets/ui/styles/status-indicator-warning.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/ui/styles/tables-mobile.css](../assets/ui/styles/tables-mobile.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/ui/styles/check-line.css](../assets/ui/styles/check-line.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/ui/styles/navbar.css](../assets/ui/styles/navbar.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/modules/notifications/styles/notification-nav.css](../assets/modules/notifications/styles/notification-nav.css) | [assets/modules/notifications/module.json](../assets/modules/notifications/module.json) | public |
| [assets/modules/status/styles/status-email-notice.css](../assets/modules/status/styles/status-email-notice.css) | [assets/modules/status/module.json](../assets/modules/status/module.json) | public |
| [assets/modules/users/styles/profile-navigation.css](../assets/modules/users/styles/profile-navigation.css) | [assets/modules/users/module.json](../assets/modules/users/module.json) | public, admin |
| [assets/modules/notifications/styles/notification-preview.css](../assets/modules/notifications/styles/notification-preview.css) | [assets/modules/notifications/module.json](../assets/modules/notifications/module.json) | public |
| [assets/modules/search/styles/global-search.css](../assets/modules/search/styles/global-search.css) | [assets/modules/search/module.json](../assets/modules/search/module.json) | public |
| [assets/modules/search/styles/global-search-controls.css](../assets/modules/search/styles/global-search-controls.css) | [assets/modules/search/module.json](../assets/modules/search/module.json) | public |
| [assets/modules/site/styles/public-list-header.css](../assets/modules/site/styles/public-list-header.css) | [assets/modules/site/module.json](../assets/modules/site/module.json) | public |
| [assets/modules/status/styles/feed-switch.css](../assets/modules/status/styles/feed-switch.css) | [assets/modules/status/module.json](../assets/modules/status/module.json) | public |
| [assets/modules/site/styles/public-layout.css](../assets/modules/site/styles/public-layout.css) | [assets/modules/site/module.json](../assets/modules/site/module.json) | public |
| [assets/modules/status/styles/home-feed.css](../assets/modules/status/styles/home-feed.css) | [assets/modules/status/module.json](../assets/modules/status/module.json) | public |
| [assets/modules/status/styles/home-feed-switch-links.css](../assets/modules/status/styles/home-feed-switch-links.css) | [assets/modules/status/module.json](../assets/modules/status/module.json) | public |
| [assets/modules/site/styles/public-sidebar.css](../assets/modules/site/styles/public-sidebar.css) | [assets/modules/site/module.json](../assets/modules/site/module.json) | public |
| [assets/modules/notifications/styles/notifications-page.css](../assets/modules/notifications/styles/notifications-page.css) | [assets/modules/notifications/module.json](../assets/modules/notifications/module.json) | public |
| [assets/modules/notifications/styles/notification-items.css](../assets/modules/notifications/styles/notification-items.css) | [assets/modules/notifications/module.json](../assets/modules/notifications/module.json) | public |
| [assets/modules/site/styles/sidebar-lists.css](../assets/modules/site/styles/sidebar-lists.css) | [assets/modules/site/module.json](../assets/modules/site/module.json) | public |
| [assets/modules/status/styles/status-feed.css](../assets/modules/status/styles/status-feed.css) | [assets/modules/status/module.json](../assets/modules/status/module.json) | public |
| [assets/modules/status/styles/composer-loading.css](../assets/modules/status/styles/composer-loading.css) | [assets/modules/status/module.json](../assets/modules/status/module.json) | public |
| [assets/modules/status/styles/status-header.css](../assets/modules/status/styles/status-header.css) | [assets/modules/status/module.json](../assets/modules/status/module.json) | public |
| [assets/modules/status/styles/status-header.css](../assets/modules/status/styles/status-header.css) | [assets/modules/status/module.json](../assets/modules/status/module.json) | /admin/moderation |
| [assets/modules/status/styles/author-meta.css](../assets/modules/status/styles/author-meta.css) | [assets/modules/status/module.json](../assets/modules/status/module.json) | public |
| [assets/modules/status/styles/author-meta.css](../assets/modules/status/styles/author-meta.css) | [assets/modules/status/module.json](../assets/modules/status/module.json) | /admin/moderation |
| [assets/modules/status/styles/status-text.css](../assets/modules/status/styles/status-text.css) | [assets/modules/status/module.json](../assets/modules/status/module.json) | public |
| [assets/modules/status/styles/status-text.css](../assets/modules/status/styles/status-text.css) | [assets/modules/status/module.json](../assets/modules/status/module.json) | /admin/moderation |
| [assets/ui/styles/hashtags.css](../assets/ui/styles/hashtags.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/modules/status/styles/status-inline-link.css](../assets/modules/status/styles/status-inline-link.css) | [assets/modules/status/module.json](../assets/modules/status/module.json) | public |
| [assets/modules/status/styles/status-inline-link.css](../assets/modules/status/styles/status-inline-link.css) | [assets/modules/status/module.json](../assets/modules/status/module.json) | /admin/moderation |
| [assets/ui/styles/inline-link-states.css](../assets/ui/styles/inline-link-states.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css) | [assets/modules/status/module.json](../assets/modules/status/module.json) | public |
| [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css) | [assets/modules/status/module.json](../assets/modules/status/module.json) | /admin/moderation |
| [assets/ui/styles/popovers.css](../assets/ui/styles/popovers.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/modules/users/styles/user-menu.css](../assets/modules/users/styles/user-menu.css) | [assets/modules/users/module.json](../assets/modules/users/module.json) | public |
| [assets/modules/status/styles/comment-menu.css](../assets/modules/status/styles/comment-menu.css) | [assets/modules/status/module.json](../assets/modules/status/module.json) | public |
| [assets/modules/status/styles/comment-menu.css](../assets/modules/status/styles/comment-menu.css) | [assets/modules/status/module.json](../assets/modules/status/module.json) | /admin/moderation |
| [assets/ui/styles/context-menu-trigger.css](../assets/ui/styles/context-menu-trigger.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/modules/status/styles/status-menu.css](../assets/modules/status/styles/status-menu.css) | [assets/modules/status/module.json](../assets/modules/status/module.json) | public |
| [assets/modules/status/styles/status-menu.css](../assets/modules/status/styles/status-menu.css) | [assets/modules/status/module.json](../assets/modules/status/module.json) | /admin/moderation |
| [assets/ui/styles/context-menu-actions.css](../assets/ui/styles/context-menu-actions.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) | [assets/modules/status/module.json](../assets/modules/status/module.json) | public |
| [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) | [assets/modules/status/module.json](../assets/modules/status/module.json) | /admin/bots |
| [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) | [assets/modules/status/module.json](../assets/modules/status/module.json) | /admin/moderation |
| [assets/ui/styles/avatar.css](../assets/ui/styles/avatar.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/modules/status/styles/status-avatars.css](../assets/modules/status/styles/status-avatars.css) | [assets/modules/status/module.json](../assets/modules/status/module.json) | public |
| [assets/modules/status/styles/status-avatars.css](../assets/modules/status/styles/status-avatars.css) | [assets/modules/status/module.json](../assets/modules/status/module.json) | /admin/moderation |
| [assets/ui/styles/avatar-sizes.css](../assets/ui/styles/avatar-sizes.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/modules/users/styles/profile-avatar.css](../assets/modules/users/styles/profile-avatar.css) | [assets/modules/users/module.json](../assets/modules/users/module.json) | public, admin |
| [assets/modules/users/styles/account-security-page.css](../assets/modules/users/styles/account-security-page.css) | [assets/modules/users/module.json](../assets/modules/users/module.json) | public, admin |
| [assets/modules/users/styles/account-security-card.css](../assets/modules/users/styles/account-security-card.css) | [assets/modules/users/module.json](../assets/modules/users/module.json) | public, admin |
| [assets/modules/users/styles/profile.css](../assets/modules/users/styles/profile.css) | [assets/modules/users/module.json](../assets/modules/users/module.json) | public, admin |
| [assets/ui/styles/code-block.css](../assets/ui/styles/code-block.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/modules/users/styles/profile-details.css](../assets/modules/users/styles/profile-details.css) | [assets/modules/users/module.json](../assets/modules/users/module.json) | public, admin |
| [assets/modules/users/styles/avatar-upload.css](../assets/modules/users/styles/avatar-upload.css) | [assets/modules/users/module.json](../assets/modules/users/module.json) | public, admin |
| [assets/modules/site/styles/public-content-meta.css](../assets/modules/site/styles/public-content-meta.css) | [assets/modules/site/module.json](../assets/modules/site/module.json) | public |
| [assets/modules/status/styles/status-detail.css](../assets/modules/status/styles/status-detail.css) | [assets/modules/status/module.json](../assets/modules/status/module.json) | public |
| [assets/modules/status/styles/status-detail.css](../assets/modules/status/styles/status-detail.css) | [assets/modules/status/module.json](../assets/modules/status/module.json) | /admin/moderation |
| [assets/modules/site/styles/public-sidebar-footer.css](../assets/modules/site/styles/public-sidebar-footer.css) | [assets/modules/site/module.json](../assets/modules/site/module.json) | public |
| [assets/modules/admin/styles/admin-shell.css](../assets/modules/admin/styles/admin-shell.css) | [assets/modules/admin/module.json](../assets/modules/admin/module.json) | admin |
| [assets/ui/styles/brand-logo.css](../assets/ui/styles/brand-logo.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/modules/admin/styles/admin-navigation-and-content.css](../assets/modules/admin/styles/admin-navigation-and-content.css) | [assets/modules/admin/module.json](../assets/modules/admin/module.json) | admin |
| [assets/ui/styles/tabs.css](../assets/ui/styles/tabs.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/ui/styles/table-identity.css](../assets/ui/styles/table-identity.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/ui/styles/pagination.css](../assets/ui/styles/pagination.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/ui/styles/modal.css](../assets/ui/styles/modal.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/ui/styles/modal-layout.css](../assets/ui/styles/modal-layout.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/ui/styles/input-icon.css](../assets/ui/styles/input-icon.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/ui/styles/modal-confirm-buttons.css](../assets/ui/styles/modal-confirm-buttons.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/modules/installation/styles/install-steps.css](../assets/modules/installation/styles/install-steps.css) | [assets/modules/installation/module.json](../assets/modules/installation/module.json) | /install |
| [assets/modules/installation/styles/install-choices.css](../assets/modules/installation/styles/install-choices.css) | [assets/modules/installation/module.json](../assets/modules/installation/module.json) | /install |
| [assets/ui/styles/result-list.css](../assets/ui/styles/result-list.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/modules/site/styles/cookie-consent.css](../assets/modules/site/styles/cookie-consent.css) | [assets/modules/site/module.json](../assets/modules/site/module.json) | public |
| [assets/ui/styles/toasts.css](../assets/ui/styles/toasts.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/ui/styles/mobile-inputs-and-dialogs.css](../assets/ui/styles/mobile-inputs-and-dialogs.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/ui/styles/loading.css](../assets/ui/styles/loading.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/modules/status/styles/status-email-notice-mobile.css](../assets/modules/status/styles/status-email-notice-mobile.css) | [assets/modules/status/module.json](../assets/modules/status/module.json) | public |
| [assets/modules/site/styles/navbar-mobile.css](../assets/modules/site/styles/navbar-mobile.css) | [assets/modules/site/module.json](../assets/modules/site/module.json) | public |
| [assets/modules/notifications/styles/notification-badge-mobile.css](../assets/modules/notifications/styles/notification-badge-mobile.css) | [assets/modules/notifications/module.json](../assets/modules/notifications/module.json) | public |
| [assets/modules/search/styles/global-search-mobile.css](../assets/modules/search/styles/global-search-mobile.css) | [assets/modules/search/module.json](../assets/modules/search/module.json) | public |
| [assets/ui/styles/cards-mobile.css](../assets/ui/styles/cards-mobile.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/modules/site/styles/public-list-header-mobile.css](../assets/modules/site/styles/public-list-header-mobile.css) | [assets/modules/site/module.json](../assets/modules/site/module.json) | public |
| [assets/modules/status/styles/feed-switch-mobile.css](../assets/modules/status/styles/feed-switch-mobile.css) | [assets/modules/status/module.json](../assets/modules/status/module.json) | public |
| [assets/modules/status/styles/home-feed-mobile.css](../assets/modules/status/styles/home-feed-mobile.css) | [assets/modules/status/module.json](../assets/modules/status/module.json) | public |
| [assets/modules/status/styles/status-content-mobile.css](../assets/modules/status/styles/status-content-mobile.css) | [assets/modules/status/module.json](../assets/modules/status/module.json) | public |
| [assets/modules/status/styles/status-content-mobile.css](../assets/modules/status/styles/status-content-mobile.css) | [assets/modules/status/module.json](../assets/modules/status/module.json) | /admin/moderation |
| [assets/modules/users/styles/following-mobile.css](../assets/modules/users/styles/following-mobile.css) | [assets/modules/users/module.json](../assets/modules/users/module.json) | public, admin |
| [assets/modules/site/styles/cookie-consent-mobile.css](../assets/modules/site/styles/cookie-consent-mobile.css) | [assets/modules/site/module.json](../assets/modules/site/module.json) | public |
| [assets/ui/styles/containers-mobile.css](../assets/ui/styles/containers-mobile.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/modules/status/styles/status-feed-mobile.css](../assets/modules/status/styles/status-feed-mobile.css) | [assets/modules/status/module.json](../assets/modules/status/module.json) | public |
| [assets/modules/admin/styles/admin-content-mobile.css](../assets/modules/admin/styles/admin-content-mobile.css) | [assets/modules/admin/module.json](../assets/modules/admin/module.json) | admin |
| [assets/modules/status/styles/home-feed-switch-mobile.css](../assets/modules/status/styles/home-feed-switch-mobile.css) | [assets/modules/status/module.json](../assets/modules/status/module.json) | public |
| [assets/modules/site/styles/public-list-header-inset-mobile.css](../assets/modules/site/styles/public-list-header-inset-mobile.css) | [assets/modules/site/module.json](../assets/modules/site/module.json) | public |
| [assets/modules/status/styles/feedback-mobile.css](../assets/modules/status/styles/feedback-mobile.css) | [assets/modules/status/module.json](../assets/modules/status/module.json) | public |
| [assets/ui/styles/section-desktop.css](../assets/ui/styles/section-desktop.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/ui/styles/pagination-desktop.css](../assets/ui/styles/pagination-desktop.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/modules/installation/styles/install-steps-desktop.css](../assets/modules/installation/styles/install-steps-desktop.css) | [assets/modules/installation/module.json](../assets/modules/installation/module.json) | /install |
| [assets/ui/styles/split-desktop.css](../assets/ui/styles/split-desktop.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/modules/admin/styles/admin-lists-desktop.css](../assets/modules/admin/styles/admin-lists-desktop.css) | [assets/modules/admin/module.json](../assets/modules/admin/module.json) | admin |
| [assets/ui/styles/field-inline-desktop.css](../assets/ui/styles/field-inline-desktop.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/ui/styles/modal-confirm-mobile.css](../assets/ui/styles/modal-confirm-mobile.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/modules/admin/styles/admin-shell-desktop.css](../assets/modules/admin/styles/admin-shell-desktop.css) | [assets/modules/admin/module.json](../assets/modules/admin/module.json) | admin |
| [assets/ui/styles/grid-desktop.css](../assets/ui/styles/grid-desktop.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/modules/users/styles/profile-layout-desktop.css](../assets/modules/users/styles/profile-layout-desktop.css) | [assets/modules/users/module.json](../assets/modules/users/module.json) | public, admin |
| [assets/modules/site/styles/sidebar-sticky.css](../assets/modules/site/styles/sidebar-sticky.css) | [assets/modules/site/module.json](../assets/modules/site/module.json) | public |
| [assets/modules/users/styles/profile-main-desktop.css](../assets/modules/users/styles/profile-main-desktop.css) | [assets/modules/users/module.json](../assets/modules/users/module.json) | public, admin |
| [assets/modules/site/styles/public-layout-desktop.css](../assets/modules/site/styles/public-layout-desktop.css) | [assets/modules/site/module.json](../assets/modules/site/module.json) | public |
| [assets/ui/styles/modal-workspace-desktop.css](../assets/ui/styles/modal-workspace-desktop.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/ui/styles/reduced-motion.css](../assets/ui/styles/reduced-motion.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/ui/styles/resource-text.css](../assets/ui/styles/resource-text.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/modules/site/styles/language.css](../assets/modules/site/styles/language.css) | [assets/modules/site/module.json](../assets/modules/site/module.json) | public |
| [assets/modules/status/styles/embed-preview.css](../assets/modules/status/styles/embed-preview.css) | [assets/modules/status/module.json](../assets/modules/status/module.json) | public |
| [assets/modules/site/styles/home-feed-toolbar.css](../assets/modules/site/styles/home-feed-toolbar.css) | [assets/modules/site/module.json](../assets/modules/site/module.json) | public |
| [assets/ui/styles/tooltip.css](../assets/ui/styles/tooltip.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/ui/styles/identity-rows.css](../assets/ui/styles/identity-rows.css) | [assets/ui/module.json](../assets/ui/module.json) | * |
| [assets/ui/styles/load-more.css](../assets/ui/styles/load-more.css) | [assets/ui/module.json](../assets/ui/module.json) | * |

## CSS třídy

Všechny třídy z importního grafu `assets/styles/index.css`. Počet souborů není počet duplicit; zahrnuje stavy a breakpointy.

| Třída | Soubory |
| --- | --- |
| `account-security-card` | [assets/modules/users/styles/account-security-card.css](../assets/modules/users/styles/account-security-card.css) |
| `account-security-page` | [assets/modules/users/styles/account-security-page.css](../assets/modules/users/styles/account-security-page.css) |
| `admin-backdrop` | [assets/modules/admin/styles/admin-shell-desktop.css](../assets/modules/admin/styles/admin-shell-desktop.css), [assets/modules/admin/styles/admin-shell.css](../assets/modules/admin/styles/admin-shell.css) |
| `admin-brand` | [assets/modules/admin/styles/admin-navigation-and-content.css](../assets/modules/admin/styles/admin-navigation-and-content.css), [assets/modules/admin/styles/admin-shell.css](../assets/modules/admin/styles/admin-shell.css) |
| `admin-content` | [assets/modules/admin/styles/admin-content-mobile.css](../assets/modules/admin/styles/admin-content-mobile.css), [assets/modules/admin/styles/admin-navigation-and-content.css](../assets/modules/admin/styles/admin-navigation-and-content.css), [assets/modules/admin/styles/admin-shell-desktop.css](../assets/modules/admin/styles/admin-shell-desktop.css), [assets/ui/styles/containers-mobile.css](../assets/ui/styles/containers-mobile.css) |
| `admin-filter-actions` | [assets/modules/admin/styles/admin-content-mobile.css](../assets/modules/admin/styles/admin-content-mobile.css), [assets/modules/admin/styles/admin-lists-desktop.css](../assets/modules/admin/styles/admin-lists-desktop.css), [assets/modules/admin/styles/admin-lists.css](../assets/modules/admin/styles/admin-lists.css) |
| `admin-list-toolbar` | [assets/modules/admin/styles/admin-lists-desktop.css](../assets/modules/admin/styles/admin-lists-desktop.css), [assets/modules/admin/styles/admin-lists.css](../assets/modules/admin/styles/admin-lists.css) |
| `admin-main` | [assets/modules/admin/styles/admin-navigation-and-content.css](../assets/modules/admin/styles/admin-navigation-and-content.css) |
| `admin-menu-btn` | [assets/modules/admin/styles/admin-shell-desktop.css](../assets/modules/admin/styles/admin-shell-desktop.css) |
| `admin-nav` | [assets/modules/admin/styles/admin-navigation-and-content.css](../assets/modules/admin/styles/admin-navigation-and-content.css) |
| `admin-nav-chevron` | [assets/modules/admin/styles/admin-navigation-and-content.css](../assets/modules/admin/styles/admin-navigation-and-content.css) |
| `admin-nav-group` | [assets/modules/admin/styles/admin-navigation-and-content.css](../assets/modules/admin/styles/admin-navigation-and-content.css) |
| `admin-nav-link` | [assets/modules/admin/styles/admin-navigation-and-content.css](../assets/modules/admin/styles/admin-navigation-and-content.css) |
| `admin-nav-open` | [assets/modules/admin/styles/admin-shell-desktop.css](../assets/modules/admin/styles/admin-shell-desktop.css), [assets/modules/admin/styles/admin-shell.css](../assets/modules/admin/styles/admin-shell.css) |
| `admin-nav-submenu` | [assets/modules/admin/styles/admin-navigation-and-content.css](../assets/modules/admin/styles/admin-navigation-and-content.css) |
| `admin-nav-summary` | [assets/modules/admin/styles/admin-navigation-and-content.css](../assets/modules/admin/styles/admin-navigation-and-content.css) |
| `admin-per-page-form` | [assets/modules/admin/styles/admin-lists-desktop.css](../assets/modules/admin/styles/admin-lists-desktop.css), [assets/modules/admin/styles/admin-lists.css](../assets/modules/admin/styles/admin-lists.css) |
| `admin-search-form` | [assets/modules/admin/styles/admin-lists-desktop.css](../assets/modules/admin/styles/admin-lists-desktop.css), [assets/modules/admin/styles/admin-lists.css](../assets/modules/admin/styles/admin-lists.css) |
| `admin-shell` | [assets/modules/admin/styles/admin-shell-desktop.css](../assets/modules/admin/styles/admin-shell-desktop.css), [assets/modules/admin/styles/admin-shell.css](../assets/modules/admin/styles/admin-shell.css) |
| `admin-shell-page` | [assets/modules/admin/styles/admin-shell.css](../assets/modules/admin/styles/admin-shell.css) |
| `admin-sidebar` | [assets/modules/admin/styles/admin-shell-desktop.css](../assets/modules/admin/styles/admin-shell-desktop.css), [assets/modules/admin/styles/admin-shell.css](../assets/modules/admin/styles/admin-shell.css) |
| `admin-sidebar-close` | [assets/modules/admin/styles/admin-shell-desktop.css](../assets/modules/admin/styles/admin-shell-desktop.css) |
| `admin-sidebar-footer` | [assets/modules/admin/styles/admin-navigation-and-content.css](../assets/modules/admin/styles/admin-navigation-and-content.css) |
| `admin-sidebar-header` | [assets/modules/admin/styles/admin-shell.css](../assets/modules/admin/styles/admin-shell.css) |
| `admin-topbar` | [assets/modules/admin/styles/admin-navigation-and-content.css](../assets/modules/admin/styles/admin-navigation-and-content.css) |
| `admin-topbar-actions` | [assets/modules/admin/styles/admin-navigation-and-content.css](../assets/modules/admin/styles/admin-navigation-and-content.css) |
| `admin-topbar-title` | [assets/modules/admin/styles/admin-navigation-and-content.css](../assets/modules/admin/styles/admin-navigation-and-content.css) |
| `admin-user` | [assets/modules/admin/styles/admin-navigation-and-content.css](../assets/modules/admin/styles/admin-navigation-and-content.css) |
| `alert` | [assets/modules/status/styles/feedback-mobile.css](../assets/modules/status/styles/feedback-mobile.css), [assets/modules/status/styles/home-feed-switch-mobile.css](../assets/modules/status/styles/home-feed-switch-mobile.css), [assets/ui/styles/alerts.css](../assets/ui/styles/alerts.css) |
| `alert-danger` | [assets/ui/styles/alerts.css](../assets/ui/styles/alerts.css) |
| `alert-info` | [assets/ui/styles/alerts.css](../assets/ui/styles/alerts.css) |
| `alert-success` | [assets/ui/styles/alerts.css](../assets/ui/styles/alerts.css) |
| `alert-warning` | [assets/ui/styles/alerts.css](../assets/ui/styles/alerts.css) |
| `archive-actions` | [assets/modules/site/styles/public-list-header.css](../assets/modules/site/styles/public-list-header.css) |
| `archive-filter` | [assets/modules/site/styles/home-feed-toolbar.css](../assets/modules/site/styles/home-feed-toolbar.css) |
| `archive-filter-menu` | [assets/modules/site/styles/public-list-header.css](../assets/modules/site/styles/public-list-header.css) |
| `archive-filter-option` | [assets/modules/site/styles/public-list-header.css](../assets/modules/site/styles/public-list-header.css) |
| `avatar` | [assets/modules/status/styles/status-avatars.css](../assets/modules/status/styles/status-avatars.css), [assets/modules/status/styles/status-content-mobile.css](../assets/modules/status/styles/status-content-mobile.css), [assets/modules/users/styles/profile.css](../assets/modules/users/styles/profile.css), [assets/ui/styles/avatar.css](../assets/ui/styles/avatar.css), [assets/ui/styles/identity-rows.css](../assets/ui/styles/identity-rows.css) |
| `avatar-creator-toggle` | [assets/modules/users/styles/avatar-upload.css](../assets/modules/users/styles/avatar-upload.css) |
| `avatar-editor` | [assets/modules/users/styles/avatar-upload.css](../assets/modules/users/styles/avatar-upload.css) |
| `avatar-editor-appearance` | [assets/modules/users/styles/avatar-upload.css](../assets/modules/users/styles/avatar-upload.css) |
| `avatar-editor-controls` | [assets/modules/users/styles/avatar-upload.css](../assets/modules/users/styles/avatar-upload.css) |
| `avatar-editor-preview` | [assets/modules/users/styles/avatar-upload.css](../assets/modules/users/styles/avatar-upload.css) |
| `avatar-editor-remove` | [assets/modules/users/styles/avatar-upload.css](../assets/modules/users/styles/avatar-upload.css) |
| `avatar-fallback` | [assets/ui/styles/avatar.css](../assets/ui/styles/avatar.css) |
| `avatar-sm` | [assets/ui/styles/avatar-sizes.css](../assets/ui/styles/avatar-sizes.css) |
| `avatar-upload` | [assets/modules/users/styles/avatar-upload.css](../assets/modules/users/styles/avatar-upload.css) |
| `avatar-upload-drop` | [assets/modules/users/styles/avatar-upload.css](../assets/modules/users/styles/avatar-upload.css) |
| `avatar-upload-empty` | [assets/modules/users/styles/avatar-upload.css](../assets/modules/users/styles/avatar-upload.css) |
| `avatar-upload-empty-note` | [assets/modules/users/styles/avatar-upload.css](../assets/modules/users/styles/avatar-upload.css) |
| `avatar-upload-preview` | [assets/modules/users/styles/avatar-upload.css](../assets/modules/users/styles/avatar-upload.css) |
| `avatar-xl` | [assets/modules/users/styles/profile.css](../assets/modules/users/styles/profile.css), [assets/ui/styles/avatar-sizes.css](../assets/ui/styles/avatar-sizes.css) |
| `badge` | [assets/modules/admin/styles/admin-lists.css](../assets/modules/admin/styles/admin-lists.css), [assets/modules/site/styles/sidebar-lists.css](../assets/modules/site/styles/sidebar-lists.css), [assets/ui/styles/badges.css](../assets/ui/styles/badges.css), [assets/ui/styles/filter-chips.css](../assets/ui/styles/filter-chips.css), [assets/ui/styles/reset.css](../assets/ui/styles/reset.css) |
| `badge-danger` | [assets/ui/styles/badge-danger.css](../assets/ui/styles/badge-danger.css) |
| `badge-primary` | [assets/ui/styles/badges.css](../assets/ui/styles/badges.css) |
| `block` | [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css) |
| `brand-logo` | [assets/ui/styles/brand-logo.css](../assets/ui/styles/brand-logo.css) |
| `btn` | [assets/modules/site/styles/cookie-consent-mobile.css](../assets/modules/site/styles/cookie-consent-mobile.css), [assets/modules/site/styles/public-list-header-mobile.css](../assets/modules/site/styles/public-list-header-mobile.css), [assets/modules/status/styles/status-content-mobile.css](../assets/modules/status/styles/status-content-mobile.css), [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css), [assets/modules/status/styles/status-email-notice-mobile.css](../assets/modules/status/styles/status-email-notice-mobile.css), [assets/modules/status/styles/status-email-notice.css](../assets/modules/status/styles/status-email-notice.css), [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css), [assets/ui/styles/cards.css](../assets/ui/styles/cards.css), [assets/ui/styles/load-more.css](../assets/ui/styles/load-more.css), [assets/ui/styles/modal-confirm-buttons.css](../assets/ui/styles/modal-confirm-buttons.css), [assets/ui/styles/modal-confirm-mobile.css](../assets/ui/styles/modal-confirm-mobile.css), [assets/ui/styles/popovers.css](../assets/ui/styles/popovers.css), [assets/ui/styles/reset.css](../assets/ui/styles/reset.css) |
| `btn-danger` | [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css) |
| `btn-ghost` | [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css) |
| `btn-group` | [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css) |
| `btn-icon` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css), [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css) |
| `btn-primary` | [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css) |
| `btn-secondary` | [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css) |
| `btn-sm` | [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css) |
| `captcha-provider` | [assets/modules/security/styles/captcha.css](../assets/modules/security/styles/captcha.css) |
| `captcha-widget` | [assets/modules/security/styles/captcha.css](../assets/modules/security/styles/captcha.css) |
| `card` | [assets/ui/styles/cards.css](../assets/ui/styles/cards.css), [assets/ui/styles/containers-mobile.css](../assets/ui/styles/containers-mobile.css) |
| `card-body` | [assets/ui/styles/cards-mobile.css](../assets/ui/styles/cards-mobile.css), [assets/ui/styles/cards.css](../assets/ui/styles/cards.css) |
| `card-footer` | [assets/ui/styles/cards-mobile.css](../assets/ui/styles/cards-mobile.css), [assets/ui/styles/cards.css](../assets/ui/styles/cards.css) |
| `card-header` | [assets/modules/site/styles/public-sidebar.css](../assets/modules/site/styles/public-sidebar.css), [assets/ui/styles/cards-mobile.css](../assets/ui/styles/cards-mobile.css), [assets/ui/styles/cards.css](../assets/ui/styles/cards.css) |
| `check-card` | [assets/ui/styles/check-card.css](../assets/ui/styles/check-card.css) |
| `check-line` | [assets/ui/styles/check-line.css](../assets/ui/styles/check-line.css) |
| `choice-card` | [assets/modules/installation/styles/install-choices.css](../assets/modules/installation/styles/install-choices.css) |
| `choice-list` | [assets/modules/installation/styles/install-choices.css](../assets/modules/installation/styles/install-choices.css) |
| `choice-row` | [assets/modules/installation/styles/install-choices.css](../assets/modules/installation/styles/install-choices.css) |
| `cluster` | [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css) |
| `code-block` | [assets/ui/styles/code-block.css](../assets/ui/styles/code-block.css) |
| `container` | [assets/ui/styles/containers-mobile.css](../assets/ui/styles/containers-mobile.css), [assets/ui/styles/layout.css](../assets/ui/styles/layout.css) |
| `container-sm` | [assets/ui/styles/layout.css](../assets/ui/styles/layout.css) |
| `content-image-actions` | [assets/ui/styles/content-images.css](../assets/ui/styles/content-images.css) |
| `content-image-preview` | [assets/ui/styles/content-images.css](../assets/ui/styles/content-images.css) |
| `content-image-preview-empty` | [assets/ui/styles/content-images.css](../assets/ui/styles/content-images.css) |
| `context-menu` | [assets/modules/status/styles/comment-menu.css](../assets/modules/status/styles/comment-menu.css), [assets/ui/styles/context-menu-trigger.css](../assets/ui/styles/context-menu-trigger.css) |
| `context-menu-action` | [assets/modules/users/styles/user-menu.css](../assets/modules/users/styles/user-menu.css), [assets/ui/styles/context-menu-actions.css](../assets/ui/styles/context-menu-actions.css) |
| `cookie-consent` | [assets/modules/site/styles/cookie-consent-mobile.css](../assets/modules/site/styles/cookie-consent-mobile.css), [assets/modules/site/styles/cookie-consent.css](../assets/modules/site/styles/cookie-consent.css) |
| `cookie-consent-actions` | [assets/modules/site/styles/cookie-consent-mobile.css](../assets/modules/site/styles/cookie-consent-mobile.css), [assets/modules/site/styles/cookie-consent.css](../assets/modules/site/styles/cookie-consent.css) |
| `cookie-consent-copy` | [assets/modules/site/styles/cookie-consent.css](../assets/modules/site/styles/cookie-consent.css) |
| `desktop-only` | [assets/ui/styles/visibility.css](../assets/ui/styles/visibility.css) |
| `disclosure-body` | [assets/ui/styles/disclosure.css](../assets/ui/styles/disclosure.css) |
| `disclosure-chevron` | [assets/ui/styles/disclosure.css](../assets/ui/styles/disclosure.css) |
| `disclosure-heading` | [assets/ui/styles/disclosure.css](../assets/ui/styles/disclosure.css) |
| `disclosure-item` | [assets/ui/styles/disclosure.css](../assets/ui/styles/disclosure.css) |
| `disclosure-list` | [assets/ui/styles/disclosure.css](../assets/ui/styles/disclosure.css) |
| `disclosure-summary` | [assets/ui/styles/disclosure.css](../assets/ui/styles/disclosure.css) |
| `empty-state` | [assets/ui/styles/empty-state.css](../assets/ui/styles/empty-state.css) |
| `empty-state-compact` | [assets/ui/styles/empty-state.css](../assets/ui/styles/empty-state.css) |
| `empty-state-icon` | [assets/ui/styles/empty-state.css](../assets/ui/styles/empty-state.css) |
| `empty-state-label` | [assets/ui/styles/empty-state.css](../assets/ui/styles/empty-state.css) |
| `feed-switch` | [assets/modules/status/styles/feed-switch-mobile.css](../assets/modules/status/styles/feed-switch-mobile.css), [assets/modules/status/styles/feed-switch.css](../assets/modules/status/styles/feed-switch.css) |
| `feed-switch-link` | [assets/modules/site/styles/home-feed-toolbar.css](../assets/modules/site/styles/home-feed-toolbar.css), [assets/modules/status/styles/feed-switch-mobile.css](../assets/modules/status/styles/feed-switch-mobile.css), [assets/modules/status/styles/feed-switch.css](../assets/modules/status/styles/feed-switch.css), [assets/modules/status/styles/home-feed-switch-links.css](../assets/modules/status/styles/home-feed-switch-links.css), [assets/modules/status/styles/home-feed-switch-mobile.css](../assets/modules/status/styles/home-feed-switch-mobile.css) |
| `field` | [assets/modules/users/styles/avatar-upload.css](../assets/modules/users/styles/avatar-upload.css), [assets/ui/styles/fields.css](../assets/ui/styles/fields.css) |
| `field-error` | [assets/ui/styles/fields.css](../assets/ui/styles/fields.css) |
| `field-inline` | [assets/ui/styles/field-inline-desktop.css](../assets/ui/styles/field-inline-desktop.css), [assets/ui/styles/field-inline.css](../assets/ui/styles/field-inline.css) |
| `filter-chip` | [assets/ui/styles/filter-chips.css](../assets/ui/styles/filter-chips.css) |
| `filter-menu-panel` | [assets/ui/styles/filter-menu.css](../assets/ui/styles/filter-menu.css) |
| `following-modal-grid` | [assets/modules/users/styles/following-mobile.css](../assets/modules/users/styles/following-mobile.css), [assets/modules/users/styles/profile.css](../assets/modules/users/styles/profile.css) |
| `gap-1` | [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css) |
| `gap-2` | [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css) |
| `global-search` | [assets/modules/search/styles/global-search-controls.css](../assets/modules/search/styles/global-search-controls.css), [assets/modules/search/styles/global-search.css](../assets/modules/search/styles/global-search.css), [assets/modules/site/styles/navbar-mobile.css](../assets/modules/site/styles/navbar-mobile.css) |
| `global-search-control` | [assets/modules/search/styles/global-search-controls.css](../assets/modules/search/styles/global-search-controls.css) |
| `global-search-heading` | [assets/modules/search/styles/global-search-controls.css](../assets/modules/search/styles/global-search-controls.css) |
| `global-search-input` | [assets/modules/search/styles/global-search-controls.css](../assets/modules/search/styles/global-search-controls.css), [assets/modules/search/styles/global-search-mobile.css](../assets/modules/search/styles/global-search-mobile.css) |
| `global-search-results` | [assets/modules/search/styles/global-search-controls.css](../assets/modules/search/styles/global-search-controls.css) |
| `global-search-section` | [assets/modules/search/styles/global-search-controls.css](../assets/modules/search/styles/global-search-controls.css) |
| `grid` | [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css) |
| `grid-span-full` | [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css) |
| `has-editor-suggestions` | [assets/ui/styles/modal.css](../assets/ui/styles/modal.css) |
| `has-image` | [assets/modules/status/styles/status-content-mobile.css](../assets/modules/status/styles/status-content-mobile.css), [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css) |
| `has-modal` | [assets/ui/styles/reset.css](../assets/ui/styles/reset.css) |
| `has-unread` | [assets/modules/notifications/styles/notification-preview.css](../assets/modules/notifications/styles/notification-preview.css) |
| `hashtag` | [assets/ui/styles/hashtags.css](../assets/ui/styles/hashtags.css), [assets/ui/styles/inline-link-states.css](../assets/ui/styles/inline-link-states.css) |
| `help` | [assets/ui/styles/fields.css](../assets/ui/styles/fields.css) |
| `hidden` | [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css) |
| `home-feed-app` | [assets/modules/status/styles/home-feed-mobile.css](../assets/modules/status/styles/home-feed-mobile.css), [assets/modules/status/styles/home-feed-switch-mobile.css](../assets/modules/status/styles/home-feed-switch-mobile.css), [assets/modules/status/styles/home-feed.css](../assets/modules/status/styles/home-feed.css) |
| `home-feed-section` | [assets/modules/status/styles/feedback-mobile.css](../assets/modules/status/styles/feedback-mobile.css), [assets/modules/users/styles/avatar-upload.css](../assets/modules/users/styles/avatar-upload.css) |
| `home-feed-switch` | [assets/modules/site/styles/home-feed-toolbar.css](../assets/modules/site/styles/home-feed-toolbar.css), [assets/modules/status/styles/home-feed-switch-links.css](../assets/modules/status/styles/home-feed-switch-links.css), [assets/modules/status/styles/home-feed-switch-mobile.css](../assets/modules/status/styles/home-feed-switch-mobile.css), [assets/modules/status/styles/home-feed.css](../assets/modules/status/styles/home-feed.css) |
| `home-feed-toolbar` | [assets/modules/site/styles/home-feed-toolbar.css](../assets/modules/site/styles/home-feed-toolbar.css) |
| `icon` | [assets/modules/search/styles/global-search-controls.css](../assets/modules/search/styles/global-search-controls.css), [assets/modules/site/styles/navbar-mobile.css](../assets/modules/site/styles/navbar-mobile.css), [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css), [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css), [assets/modules/status/styles/status-email-notice.css](../assets/modules/status/styles/status-email-notice.css), [assets/modules/users/styles/avatar-upload.css](../assets/modules/users/styles/avatar-upload.css), [assets/modules/users/styles/profile.css](../assets/modules/users/styles/profile.css), [assets/modules/users/styles/user-menu.css](../assets/modules/users/styles/user-menu.css), [assets/ui/styles/disclosure.css](../assets/ui/styles/disclosure.css), [assets/ui/styles/filter-chips.css](../assets/ui/styles/filter-chips.css), [assets/ui/styles/input-icon.css](../assets/ui/styles/input-icon.css), [assets/ui/styles/navbar.css](../assets/ui/styles/navbar.css), [assets/ui/styles/reset.css](../assets/ui/styles/reset.css), [assets/ui/styles/table-identity.css](../assets/ui/styles/table-identity.css) |
| `icon-lg` | [assets/ui/styles/reset.css](../assets/ui/styles/reset.css) |
| `identity-copy` | [assets/ui/styles/identity-rows.css](../assets/ui/styles/identity-rows.css) |
| `identity-copy-truncate` | [assets/ui/styles/identity-rows.css](../assets/ui/styles/identity-rows.css) |
| `identity-row` | [assets/ui/styles/identity-rows.css](../assets/ui/styles/identity-rows.css) |
| `identity-row-compact` | [assets/ui/styles/identity-rows.css](../assets/ui/styles/identity-rows.css) |
| `identity-row-inset` | [assets/ui/styles/identity-rows.css](../assets/ui/styles/identity-rows.css) |
| `image-preview-contain` | [assets/ui/styles/content-images-contain.css](../assets/ui/styles/content-images-contain.css) |
| `inline-flex` | [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css) |
| `input` | [assets/ui/styles/fields.css](../assets/ui/styles/fields.css), [assets/ui/styles/input-icon.css](../assets/ui/styles/input-icon.css) |
| `input-icon` | [assets/modules/admin/styles/admin-lists.css](../assets/modules/admin/styles/admin-lists.css), [assets/ui/styles/input-icon.css](../assets/ui/styles/input-icon.css) |
| `is-active` | [assets/modules/installation/styles/install-steps.css](../assets/modules/installation/styles/install-steps.css), [assets/modules/site/styles/sidebar-lists.css](../assets/modules/site/styles/sidebar-lists.css), [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css), [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css), [assets/ui/styles/pagination.css](../assets/ui/styles/pagination.css) |
| `is-child` | [assets/modules/status/styles/status-content-mobile.css](../assets/modules/status/styles/status-content-mobile.css), [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css) |
| `is-complete` | [assets/modules/installation/styles/install-steps.css](../assets/modules/installation/styles/install-steps.css) |
| `is-danger` | [assets/ui/styles/context-menu-actions.css](../assets/ui/styles/context-menu-actions.css) |
| `is-image-missing` | [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css) |
| `is-image-ready` | [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css) |
| `is-limit` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `is-loading` | [assets/modules/status/styles/composer-loading.css](../assets/modules/status/styles/composer-loading.css), [assets/ui/styles/load-more.css](../assets/ui/styles/load-more.css), [assets/ui/styles/loading.css](../assets/ui/styles/loading.css) |
| `is-reply` | [assets/modules/status/styles/status-content-mobile.css](../assets/modules/status/styles/status-content-mobile.css), [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `is-resource-text` | [assets/ui/styles/resource-text.css](../assets/ui/styles/resource-text.css) |
| `is-unread` | [assets/modules/notifications/styles/notification-items.css](../assets/modules/notifications/styles/notification-items.css), [assets/ui/styles/identity-rows.css](../assets/ui/styles/identity-rows.css) |
| `is-warning` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `justify-end` | [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css) |
| `label` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css), [assets/ui/styles/field-inline.css](../assets/ui/styles/field-inline.css), [assets/ui/styles/fields.css](../assets/ui/styles/fields.css) |
| `language-flag` | [assets/modules/site/styles/language.css](../assets/modules/site/styles/language.css) |
| `language-menu` | [assets/modules/site/styles/language.css](../assets/modules/site/styles/language.css) |
| `language-menu-link` | [assets/modules/site/styles/language.css](../assets/modules/site/styles/language.css) |
| `language-menu-list` | [assets/modules/site/styles/language.css](../assets/modules/site/styles/language.css) |
| `language-suggestion` | [assets/modules/site/styles/language.css](../assets/modules/site/styles/language.css) |
| `language-suggestion-close` | [assets/modules/site/styles/language.css](../assets/modules/site/styles/language.css) |
| `language-suggestion-inner` | [assets/modules/site/styles/language.css](../assets/modules/site/styles/language.css) |
| `link-button` | [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css) |
| `load-more` | [assets/ui/styles/load-more.css](../assets/ui/styles/load-more.css) |
| `load-more-state` | [assets/ui/styles/load-more.css](../assets/ui/styles/load-more.css) |
| `m-0` | [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css) |
| `max-w-auth` | [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css) |
| `max-w-auth-sm` | [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css) |
| `max-w-prose` | [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css) |
| `mb-0` | [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css) |
| `md:grid-2` | [assets/ui/styles/grid-desktop.css](../assets/ui/styles/grid-desktop.css) |
| `md:grid-3` | [assets/ui/styles/grid-desktop.css](../assets/ui/styles/grid-desktop.css) |
| `md:grid-4` | [assets/ui/styles/grid-desktop.css](../assets/ui/styles/grid-desktop.css) |
| `mention-link` | [assets/modules/status/styles/status-text.css](../assets/modules/status/styles/status-text.css), [assets/ui/styles/inline-link-states.css](../assets/ui/styles/inline-link-states.css) |
| `min-w-0` | [assets/ui/styles/visibility.css](../assets/ui/styles/visibility.css) |
| `modal` | [assets/ui/styles/mobile-inputs-and-dialogs.css](../assets/ui/styles/mobile-inputs-and-dialogs.css), [assets/ui/styles/modal-confirm-buttons.css](../assets/ui/styles/modal-confirm-buttons.css), [assets/ui/styles/modal-confirm-mobile.css](../assets/ui/styles/modal-confirm-mobile.css), [assets/ui/styles/modal-layout.css](../assets/ui/styles/modal-layout.css), [assets/ui/styles/modal-workspace-desktop.css](../assets/ui/styles/modal-workspace-desktop.css), [assets/ui/styles/modal.css](../assets/ui/styles/modal.css) |
| `modal-backdrop` | [assets/ui/styles/modal.css](../assets/ui/styles/modal.css) |
| `modal-body` | [assets/ui/styles/mobile-inputs-and-dialogs.css](../assets/ui/styles/mobile-inputs-and-dialogs.css), [assets/ui/styles/modal-layout.css](../assets/ui/styles/modal-layout.css), [assets/ui/styles/modal-workspace-desktop.css](../assets/ui/styles/modal-workspace-desktop.css) |
| `modal-confirm-message` | [assets/ui/styles/modal-layout.css](../assets/ui/styles/modal-layout.css) |
| `modal-editor-actions` | [assets/ui/styles/modal-layout.css](../assets/ui/styles/modal-layout.css) |
| `modal-editor-destructive` | [assets/ui/styles/modal-layout.css](../assets/ui/styles/modal-layout.css) |
| `modal-footer` | [assets/ui/styles/modal-confirm-buttons.css](../assets/ui/styles/modal-confirm-buttons.css), [assets/ui/styles/modal-confirm-mobile.css](../assets/ui/styles/modal-confirm-mobile.css), [assets/ui/styles/modal-layout.css](../assets/ui/styles/modal-layout.css), [assets/ui/styles/modal-workspace-desktop.css](../assets/ui/styles/modal-workspace-desktop.css) |
| `modal-header` | [assets/ui/styles/modal-layout.css](../assets/ui/styles/modal-layout.css), [assets/ui/styles/modal-workspace-desktop.css](../assets/ui/styles/modal-workspace-desktop.css) |
| `modal-panel` | [assets/ui/styles/mobile-inputs-and-dialogs.css](../assets/ui/styles/mobile-inputs-and-dialogs.css), [assets/ui/styles/modal-layout.css](../assets/ui/styles/modal-layout.css), [assets/ui/styles/modal-workspace-desktop.css](../assets/ui/styles/modal-workspace-desktop.css), [assets/ui/styles/modal.css](../assets/ui/styles/modal.css) |
| `modal-tab-switch` | [assets/ui/styles/modal-layout.css](../assets/ui/styles/modal-layout.css) |
| `modal-tabs` | [assets/ui/styles/modal-layout.css](../assets/ui/styles/modal-layout.css), [assets/ui/styles/modal-workspace-desktop.css](../assets/ui/styles/modal-workspace-desktop.css) |
| `mx-auto` | [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css) |
| `nav-link` | [assets/ui/styles/navbar.css](../assets/ui/styles/navbar.css), [assets/ui/styles/reset.css](../assets/ui/styles/reset.css) |
| `nav-link-icon` | [assets/modules/site/styles/navbar-mobile.css](../assets/modules/site/styles/navbar-mobile.css), [assets/ui/styles/navbar.css](../assets/ui/styles/navbar.css) |
| `nav-links` | [assets/modules/site/styles/navbar-mobile.css](../assets/modules/site/styles/navbar-mobile.css), [assets/ui/styles/navbar.css](../assets/ui/styles/navbar.css) |
| `navbar` | [assets/ui/styles/navbar.css](../assets/ui/styles/navbar.css) |
| `navbar-brand` | [assets/modules/site/styles/navbar-mobile.css](../assets/modules/site/styles/navbar-mobile.css), [assets/ui/styles/navbar.css](../assets/ui/styles/navbar.css) |
| `navbar-inner` | [assets/modules/site/styles/navbar-mobile.css](../assets/modules/site/styles/navbar-mobile.css), [assets/ui/styles/navbar.css](../assets/ui/styles/navbar.css) |
| `notification-actions` | [assets/modules/notifications/styles/notification-items.css](../assets/modules/notifications/styles/notification-items.css) |
| `notification-badge` | [assets/modules/notifications/styles/notification-badge-mobile.css](../assets/modules/notifications/styles/notification-badge-mobile.css), [assets/modules/notifications/styles/notification-preview.css](../assets/modules/notifications/styles/notification-preview.css) |
| `notification-item` | [assets/modules/notifications/styles/notification-items.css](../assets/modules/notifications/styles/notification-items.css) |
| `notification-nav-link` | [assets/modules/notifications/styles/notification-nav.css](../assets/modules/notifications/styles/notification-nav.css), [assets/modules/notifications/styles/notification-preview.css](../assets/modules/notifications/styles/notification-preview.css) |
| `notification-panel` | [assets/modules/notifications/styles/notification-preview.css](../assets/modules/notifications/styles/notification-preview.css) |
| `notification-preview-header` | [assets/modules/notifications/styles/notification-preview.css](../assets/modules/notifications/styles/notification-preview.css) |
| `notification-preview-item` | [assets/modules/notifications/styles/notification-preview.css](../assets/modules/notifications/styles/notification-preview.css) |
| `notification-preview-list` | [assets/modules/notifications/styles/notification-preview.css](../assets/modules/notifications/styles/notification-preview.css) |
| `notification-preview-more` | [assets/modules/notifications/styles/notification-preview.css](../assets/modules/notifications/styles/notification-preview.css) |
| `notifications-list` | [assets/modules/notifications/styles/notifications-page.css](../assets/modules/notifications/styles/notifications-page.css) |
| `notifications-page` | [assets/modules/notifications/styles/notifications-page.css](../assets/modules/notifications/styles/notifications-page.css) |
| `pagination` | [assets/ui/styles/modal-layout.css](../assets/ui/styles/modal-layout.css), [assets/ui/styles/pagination-desktop.css](../assets/ui/styles/pagination-desktop.css), [assets/ui/styles/pagination.css](../assets/ui/styles/pagination.css) |
| `pagination-ellipsis` | [assets/ui/styles/pagination.css](../assets/ui/styles/pagination.css) |
| `pagination-link` | [assets/ui/styles/pagination.css](../assets/ui/styles/pagination.css) |
| `pagination-list` | [assets/ui/styles/pagination.css](../assets/ui/styles/pagination.css) |
| `pagination-next` | [assets/ui/styles/pagination.css](../assets/ui/styles/pagination.css) |
| `pagination-prev` | [assets/ui/styles/pagination.css](../assets/ui/styles/pagination.css) |
| `pagination-summary` | [assets/ui/styles/pagination.css](../assets/ui/styles/pagination.css) |
| `popover-panel` | [assets/ui/styles/popovers.css](../assets/ui/styles/popovers.css) |
| `profile-actions` | [assets/modules/users/styles/profile.css](../assets/modules/users/styles/profile.css) |
| `profile-avatar-button` | [assets/modules/users/styles/profile-avatar.css](../assets/modules/users/styles/profile-avatar.css) |
| `profile-card` | [assets/modules/users/styles/profile.css](../assets/modules/users/styles/profile.css) |
| `profile-card-body` | [assets/modules/users/styles/profile.css](../assets/modules/users/styles/profile.css) |
| `profile-details` | [assets/modules/users/styles/profile.css](../assets/modules/users/styles/profile.css) |
| `profile-edit-toggle` | [assets/modules/users/styles/profile.css](../assets/modules/users/styles/profile.css) |
| `profile-editable-text` | [assets/modules/users/styles/profile-details.css](../assets/modules/users/styles/profile-details.css) |
| `profile-email-warning` | [assets/modules/users/styles/profile-navigation.css](../assets/modules/users/styles/profile-navigation.css) |
| `profile-following-link` | [assets/modules/users/styles/profile.css](../assets/modules/users/styles/profile.css) |
| `profile-following-main` | [assets/modules/users/styles/profile.css](../assets/modules/users/styles/profile.css) |
| `profile-identity` | [assets/modules/users/styles/profile.css](../assets/modules/users/styles/profile.css) |
| `profile-identity-main` | [assets/modules/users/styles/profile.css](../assets/modules/users/styles/profile.css) |
| `profile-layout` | [assets/modules/users/styles/profile-layout-desktop.css](../assets/modules/users/styles/profile-layout-desktop.css), [assets/modules/users/styles/profile.css](../assets/modules/users/styles/profile.css) |
| `profile-main` | [assets/modules/users/styles/avatar-upload.css](../assets/modules/users/styles/avatar-upload.css), [assets/modules/users/styles/profile-main-desktop.css](../assets/modules/users/styles/profile-main-desktop.css), [assets/modules/users/styles/profile.css](../assets/modules/users/styles/profile.css) |
| `profile-member-since` | [assets/modules/users/styles/profile.css](../assets/modules/users/styles/profile.css) |
| `profile-name` | [assets/modules/users/styles/profile.css](../assets/modules/users/styles/profile.css) |
| `profile-nav-link` | [assets/modules/users/styles/profile-navigation.css](../assets/modules/users/styles/profile-navigation.css) |
| `profile-sidebar` | [assets/modules/users/styles/profile.css](../assets/modules/users/styles/profile.css) |
| `profile-sidebar-stack` | [assets/modules/site/styles/sidebar-sticky.css](../assets/modules/site/styles/sidebar-sticky.css), [assets/modules/users/styles/profile.css](../assets/modules/users/styles/profile.css) |
| `profile-stat` | [assets/modules/users/styles/profile.css](../assets/modules/users/styles/profile.css) |
| `profile-stat-button` | [assets/modules/users/styles/profile.css](../assets/modules/users/styles/profile.css) |
| `profile-stats` | [assets/modules/users/styles/profile-details.css](../assets/modules/users/styles/profile-details.css), [assets/modules/users/styles/profile.css](../assets/modules/users/styles/profile.css) |
| `public-content-meta` | [assets/modules/site/styles/public-content-meta.css](../assets/modules/site/styles/public-content-meta.css), [assets/modules/status/styles/author-meta.css](../assets/modules/status/styles/author-meta.css) |
| `public-layout` | [assets/modules/site/styles/public-layout-desktop.css](../assets/modules/site/styles/public-layout-desktop.css), [assets/modules/site/styles/public-layout.css](../assets/modules/site/styles/public-layout.css) |
| `public-list-header` | [assets/modules/site/styles/public-list-header-inset-mobile.css](../assets/modules/site/styles/public-list-header-inset-mobile.css), [assets/modules/site/styles/public-list-header-mobile.css](../assets/modules/site/styles/public-list-header-mobile.css), [assets/modules/site/styles/public-list-header.css](../assets/modules/site/styles/public-list-header.css) |
| `public-list-header-actions` | [assets/modules/site/styles/public-list-header-mobile.css](../assets/modules/site/styles/public-list-header-mobile.css) |
| `public-sidebar` | [assets/modules/site/styles/public-sidebar.css](../assets/modules/site/styles/public-sidebar.css), [assets/modules/site/styles/sidebar-sticky.css](../assets/modules/site/styles/sidebar-sticky.css) |
| `public-sidebar-card` | [assets/modules/site/styles/public-sidebar.css](../assets/modules/site/styles/public-sidebar.css) |
| `public-sidebar-footer` | [assets/modules/site/styles/public-sidebar-footer.css](../assets/modules/site/styles/public-sidebar-footer.css) |
| `result-item` | [assets/ui/styles/result-list.css](../assets/ui/styles/result-list.css) |
| `result-list` | [assets/ui/styles/result-list.css](../assets/ui/styles/result-list.css) |
| `section` | [assets/modules/site/styles/public-list-header-inset-mobile.css](../assets/modules/site/styles/public-list-header-inset-mobile.css), [assets/ui/styles/cards-mobile.css](../assets/ui/styles/cards-mobile.css), [assets/ui/styles/containers-mobile.css](../assets/ui/styles/containers-mobile.css), [assets/ui/styles/layout.css](../assets/ui/styles/layout.css), [assets/ui/styles/section-desktop.css](../assets/ui/styles/section-desktop.css) |
| `select` | [assets/ui/styles/fields.css](../assets/ui/styles/fields.css), [assets/ui/styles/modal-layout.css](../assets/ui/styles/modal-layout.css) |
| `sidebar-user-list` | [assets/modules/site/styles/sidebar-lists.css](../assets/modules/site/styles/sidebar-lists.css) |
| `skip-link` | [assets/ui/styles/reset.css](../assets/ui/styles/reset.css) |
| `sm:grid-2` | [assets/ui/styles/modal-confirm-mobile.css](../assets/ui/styles/modal-confirm-mobile.css) |
| `split` | [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css), [assets/ui/styles/cards.css](../assets/ui/styles/cards.css), [assets/ui/styles/split-desktop.css](../assets/ui/styles/split-desktop.css) |
| `sr-only` | [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css) |
| `stack` | [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css), [assets/ui/styles/filter-menu.css](../assets/ui/styles/filter-menu.css), [assets/ui/styles/layout.css](../assets/ui/styles/layout.css) |
| `stack-gap-12` | [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css) |
| `stack-gap-14` | [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css) |
| `stack-gap-16` | [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css) |
| `stack-gap-24` | [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css) |
| `stack-gap-4` | [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css) |
| `stack-gap-8` | [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css) |
| `status-audio-card` | [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css) |
| `status-audio-player` | [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css) |
| `status-author` | [assets/modules/status/styles/author-meta.css](../assets/modules/status/styles/author-meta.css), [assets/modules/status/styles/status-header.css](../assets/modules/status/styles/status-header.css), [assets/modules/status/styles/status-text.css](../assets/modules/status/styles/status-text.css) |
| `status-body` | [assets/modules/status/styles/status-detail.css](../assets/modules/status/styles/status-detail.css), [assets/modules/status/styles/status-text.css](../assets/modules/status/styles/status-text.css) |
| `status-card` | [assets/modules/status/styles/status-feed.css](../assets/modules/status/styles/status-feed.css) |
| `status-card-body` | [assets/modules/status/styles/status-feed.css](../assets/modules/status/styles/status-feed.css) |
| `status-comment` | [assets/modules/status/styles/status-content-mobile.css](../assets/modules/status/styles/status-content-mobile.css), [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css) |
| `status-comment-author` | [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css) |
| `status-comment-body` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-comment-bubble` | [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css) |
| `status-comment-diff-added` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-comment-diff-marker` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-comment-diff-removed` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-comment-editor` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-comment-form` | [assets/modules/status/styles/status-content-mobile.css](../assets/modules/status/styles/status-content-mobile.css), [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-comment-heading` | [assets/modules/status/styles/comment-menu.css](../assets/modules/status/styles/comment-menu.css), [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css) |
| `status-comment-heading-time` | [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css) |
| `status-comment-history` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-comment-history-diff` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-comment-history-entry` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-comment-history-meta` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-comment-input` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-comment-input-shell` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-comment-like` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-comment-like-button` | [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css), [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-comment-list` | [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css) |
| `status-comment-main` | [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css), [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-comment-meta` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-comment-replies` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-comment-submit` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-comments` | [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css) |
| `status-comments-thread` | [assets/modules/status/styles/status-detail.css](../assets/modules/status/styles/status-detail.css) |
| `status-compose-actions` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-compose-counter` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-compose-footer` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-compose-main` | [assets/modules/status/styles/status-content-mobile.css](../assets/modules/status/styles/status-content-mobile.css), [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-compose-row` | [assets/modules/status/styles/status-avatars.css](../assets/modules/status/styles/status-avatars.css), [assets/modules/status/styles/status-content-mobile.css](../assets/modules/status/styles/status-content-mobile.css), [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-composer` | [assets/modules/status/styles/status-content-mobile.css](../assets/modules/status/styles/status-content-mobile.css), [assets/ui/styles/cards-mobile.css](../assets/ui/styles/cards-mobile.css) |
| `status-editor-counter` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-editor-input` | [assets/modules/status/styles/status-content-mobile.css](../assets/modules/status/styles/status-content-mobile.css), [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-editor-meta` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-editor-shell` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-editor-suggestion` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-editor-suggestion-avatar` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-editor-suggestion-text` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-editor-suggestions` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-email-notice` | [assets/modules/status/styles/status-email-notice-mobile.css](../assets/modules/status/styles/status-email-notice-mobile.css), [assets/modules/status/styles/status-email-notice.css](../assets/modules/status/styles/status-email-notice.css) |
| `status-email-notice-copy` | [assets/modules/status/styles/status-email-notice-mobile.css](../assets/modules/status/styles/status-email-notice-mobile.css), [assets/modules/status/styles/status-email-notice.css](../assets/modules/status/styles/status-email-notice.css) |
| `status-embed-preview` | [assets/modules/status/styles/embed-preview.css](../assets/modules/status/styles/embed-preview.css) |
| `status-feed` | [assets/modules/status/styles/status-feed-mobile.css](../assets/modules/status/styles/status-feed-mobile.css), [assets/modules/status/styles/status-feed.css](../assets/modules/status/styles/status-feed.css), [assets/modules/users/styles/avatar-upload.css](../assets/modules/users/styles/avatar-upload.css) |
| `status-feed-more` | [assets/modules/status/styles/status-feed.css](../assets/modules/status/styles/status-feed.css) |
| `status-feed-spacer` | [assets/modules/status/styles/status-feed.css](../assets/modules/status/styles/status-feed.css) |
| `status-field` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-header` | [assets/modules/status/styles/status-avatars.css](../assets/modules/status/styles/status-avatars.css), [assets/modules/status/styles/status-header.css](../assets/modules/status/styles/status-header.css) |
| `status-image` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-image-alt-wrap` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-image-field` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-image-link` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-image-picker` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-image-preview` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-indicator` | [assets/ui/styles/status-indicators.css](../assets/ui/styles/status-indicators.css), [assets/ui/styles/table-identity.css](../assets/ui/styles/table-identity.css) |
| `status-indicator-danger` | [assets/ui/styles/status-indicators.css](../assets/ui/styles/status-indicators.css) |
| `status-indicator-success` | [assets/ui/styles/status-indicators.css](../assets/ui/styles/status-indicators.css) |
| `status-indicator-warning` | [assets/ui/styles/status-indicator-warning.css](../assets/ui/styles/status-indicator-warning.css) |
| `status-inline-link` | [assets/modules/status/styles/status-inline-link.css](../assets/modules/status/styles/status-inline-link.css), [assets/ui/styles/inline-link-states.css](../assets/ui/styles/inline-link-states.css) |
| `status-like-icon-filled` | [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css) |
| `status-like-icon-outline` | [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css) |
| `status-link-account` | [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css) |
| `status-link-card` | [assets/modules/status/styles/status-content-mobile.css](../assets/modules/status/styles/status-content-mobile.css), [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css) |
| `status-link-copy` | [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css) |
| `status-link-fallback-icon` | [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css) |
| `status-link-icon` | [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css) |
| `status-link-image` | [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css) |
| `status-link-media` | [assets/modules/status/styles/status-content-mobile.css](../assets/modules/status/styles/status-content-mobile.css), [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css) |
| `status-link-provider` | [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css) |
| `status-manage` | [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css) |
| `status-manage-icon` | [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css) |
| `status-manage-menu` | [assets/modules/status/styles/status-menu.css](../assets/modules/status/styles/status-menu.css) |
| `status-manage-top` | [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css) |
| `status-modal-form` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-post-modal-content` | [assets/modules/status/styles/status-detail.css](../assets/modules/status/styles/status-detail.css) |
| `status-reaction` | [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css) |
| `status-reactions` | [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css) |
| `status-reply-details` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-reply-form-container` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-social-link` | [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css) |
| `status-textarea` | [assets/modules/status/styles/status-editing.css](../assets/modules/status/styles/status-editing.css) |
| `status-time-button` | [assets/modules/status/styles/status-detail.css](../assets/modules/status/styles/status-detail.css) |
| `status-translation` | [assets/modules/status/styles/status-text.css](../assets/modules/status/styles/status-text.css) |
| `status-translation-link` | [assets/modules/status/styles/status-text.css](../assets/modules/status/styles/status-text.css) |
| `status-translation-output` | [assets/modules/status/styles/status-text.css](../assets/modules/status/styles/status-text.css) |
| `status-video-card` | [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css) |
| `status-video-copy` | [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css) |
| `status-video-frame` | [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css) |
| `status-video-placeholder` | [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css) |
| `status-video-play` | [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css) |
| `status-video-provider` | [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css) |
| `status-video-source` | [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css) |
| `status-video-thumb` | [assets/modules/status/styles/status-content.css](../assets/modules/status/styles/status-content.css) |
| `step` | [assets/modules/installation/styles/install-steps.css](../assets/modules/installation/styles/install-steps.css) |
| `step-marker` | [assets/modules/installation/styles/install-steps.css](../assets/modules/installation/styles/install-steps.css) |
| `steps` | [assets/modules/installation/styles/install-steps-desktop.css](../assets/modules/installation/styles/install-steps-desktop.css), [assets/modules/installation/styles/install-steps.css](../assets/modules/installation/styles/install-steps.css) |
| `tab` | [assets/ui/styles/tabs.css](../assets/ui/styles/tabs.css) |
| `table` | [assets/ui/styles/table-identity.css](../assets/ui/styles/table-identity.css), [assets/ui/styles/tables-mobile.css](../assets/ui/styles/tables-mobile.css), [assets/ui/styles/tables.css](../assets/ui/styles/tables.css) |
| `table-actions` | [assets/ui/styles/table-identity.css](../assets/ui/styles/table-identity.css) |
| `table-actions-cell` | [assets/modules/admin/styles/admin-lists.css](../assets/modules/admin/styles/admin-lists.css), [assets/ui/styles/table-identity.css](../assets/ui/styles/table-identity.css) |
| `table-identity` | [assets/ui/styles/table-identity.css](../assets/ui/styles/table-identity.css) |
| `table-identity-body` | [assets/ui/styles/table-identity.css](../assets/ui/styles/table-identity.css) |
| `table-meta` | [assets/ui/styles/table-identity.css](../assets/ui/styles/table-identity.css) |
| `table-primary` | [assets/ui/styles/table-identity.css](../assets/ui/styles/table-identity.css) |
| `table-row-inactive` | [assets/modules/admin/styles/admin-lists.css](../assets/modules/admin/styles/admin-lists.css) |
| `table-sort` | [assets/modules/admin/styles/admin-lists.css](../assets/modules/admin/styles/admin-lists.css) |
| `table-sort-indicator` | [assets/modules/admin/styles/admin-lists.css](../assets/modules/admin/styles/admin-lists.css) |
| `table-text` | [assets/ui/styles/table-identity.css](../assets/ui/styles/table-identity.css) |
| `table-text-url` | [assets/ui/styles/table-identity.css](../assets/ui/styles/table-identity.css) |
| `table-wrap` | [assets/ui/styles/tables.css](../assets/ui/styles/tables.css) |
| `tabs` | [assets/ui/styles/modal-layout.css](../assets/ui/styles/modal-layout.css), [assets/ui/styles/tabs.css](../assets/ui/styles/tabs.css) |
| `text-2xl` | [assets/modules/site/styles/public-list-header-mobile.css](../assets/modules/site/styles/public-list-header-mobile.css), [assets/modules/site/styles/public-list-header.css](../assets/modules/site/styles/public-list-header.css), [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css) |
| `text-base` | [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css) |
| `text-danger` | [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css), [assets/ui/styles/table-identity.css](../assets/ui/styles/table-identity.css) |
| `text-lg` | [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css) |
| `text-muted` | [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css) |
| `text-primary` | [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css) |
| `text-xl` | [assets/ui/styles/buttons.css](../assets/ui/styles/buttons.css) |
| `textarea` | [assets/ui/styles/fields.css](../assets/ui/styles/fields.css) |
| `toast` | [assets/modules/status/styles/feedback-mobile.css](../assets/modules/status/styles/feedback-mobile.css), [assets/ui/styles/toasts.css](../assets/ui/styles/toasts.css) |
| `toast-body` | [assets/ui/styles/toasts.css](../assets/ui/styles/toasts.css) |
| `toast-close` | [assets/ui/styles/toasts.css](../assets/ui/styles/toasts.css) |
| `toast-danger` | [assets/ui/styles/toasts.css](../assets/ui/styles/toasts.css) |
| `toast-icon` | [assets/ui/styles/toasts.css](../assets/ui/styles/toasts.css) |
| `toast-info` | [assets/ui/styles/toasts.css](../assets/ui/styles/toasts.css) |
| `toast-stack` | [assets/modules/status/styles/feedback-mobile.css](../assets/modules/status/styles/feedback-mobile.css), [assets/ui/styles/toasts.css](../assets/ui/styles/toasts.css) |
| `toast-success` | [assets/ui/styles/toasts.css](../assets/ui/styles/toasts.css) |
| `toast-warning` | [assets/ui/styles/toasts.css](../assets/ui/styles/toasts.css) |
| `tooltip` | [assets/ui/styles/tooltip.css](../assets/ui/styles/tooltip.css) |
| `topic-link` | [assets/modules/site/styles/sidebar-lists.css](../assets/modules/site/styles/sidebar-lists.css) |
| `topic-list` | [assets/modules/site/styles/sidebar-lists.css](../assets/modules/site/styles/sidebar-lists.css) |
| `topic-name` | [assets/modules/site/styles/sidebar-lists.css](../assets/modules/site/styles/sidebar-lists.css) |
| `user-menu` | [assets/modules/users/styles/user-menu.css](../assets/modules/users/styles/user-menu.css) |
| `user-menu-identity` | [assets/modules/users/styles/user-menu.css](../assets/modules/users/styles/user-menu.css) |
| `user-menu-popover` | [assets/modules/users/styles/user-menu.css](../assets/modules/users/styles/user-menu.css) |
| `user-menu-theme` | [assets/modules/users/styles/user-menu.css](../assets/modules/users/styles/user-menu.css) |
| `user-menu-theme-switch` | [assets/modules/users/styles/user-menu.css](../assets/modules/users/styles/user-menu.css) |
| `user-menu-trigger` | [assets/modules/users/styles/user-menu.css](../assets/modules/users/styles/user-menu.css) |
