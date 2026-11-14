# Rejstřík UI

Generováno příkazem `python tools/assets/ui-inventory.py`. Pravidla viz [UI kontrakt](../contracts/ui-conventions.md).

Nejde o vizuální audit ani úplné ověření přístupnosti. CSS scanner je lexikální; opakované selektory jsou kandidáty ke kontrole, nikoli prokázané duplicity. Seznam JS metod zachycuje syntaxi `name: function`, ne všechny exporty.

115 definic komponent, 403 CSS tříd, 132 CSS importů, 97 JS souborů, 23 UI služeb, 15 PHP partialů.

## Deklarativní komponenty

| Název | Zdroj | Závislosti |
| --- | --- | --- |
| `health/report` | [App/Core/Health/assets/components.json](../../App/Core/Health/assets/components.json) | health/scheduler-findings, ui/status-badge |
| `health/scheduler-findings` | [App/Core/Health/assets/components.json](../../App/Core/Health/assets/components.json) | — |
| `health/scheduler-run-detail` | [App/Core/Health/assets/components.json](../../App/Core/Health/assets/components.json) | health/report |
| `users/admin-fields` | [App/Core/Identity/assets/components.json](../../App/Core/Identity/assets/components.json) | ui/field, ui/select, users/avatar-control |
| `users/admin-row` | [App/Core/Identity/assets/components.json](../../App/Core/Identity/assets/components.json) | ui/account-status, ui/activation-action, ui/api-action, ui/modal-trigger |
| `users/list` | [App/Core/Identity/assets/components.json](../../App/Core/Identity/assets/components.json) | ui/empty-state, ui/list-toolbar, ui/pagination, ui/sort-heading, users/admin-row |
| `users/auth-form` | [App/Core/Identity/assets/components.json](../../App/Core/Identity/assets/components.json) | ui/captcha, ui/field |
| `users/auth-content` | [App/Core/Identity/assets/components.json](../../App/Core/Identity/assets/components.json) | users/auth-form |
| `users/filter-form` | [App/Core/Identity/assets/components.json](../../App/Core/Identity/assets/components.json) | ui/field, ui/select |
| `users/avatar-control` | [App/Core/Identity/assets/components.json](../../App/Core/Identity/assets/components.json) | ui/avatar-content |
| `notifications/copy` | [App/Core/Notifications/assets/components.json](../../App/Core/Notifications/assets/components.json) | — |
| `notifications/action` | [App/Core/Notifications/assets/components.json](../../App/Core/Notifications/assets/components.json) | — |
| `notifications/item` | [App/Core/Notifications/assets/components.json](../../App/Core/Notifications/assets/components.json) | notifications/action, notifications/copy, ui/avatar-content |
| `notifications/preview-item` | [App/Core/Notifications/assets/components.json](../../App/Core/Notifications/assets/components.json) | notifications/copy, ui/avatar-content |
| `notifications/items` | [App/Core/Notifications/assets/components.json](../../App/Core/Notifications/assets/components.json) | notifications/item, notifications/preview-item, ui/empty-state |
| `scheduler/status` | [App/Core/Tasks/assets/components.json](../../App/Core/Tasks/assets/components.json) | ui/status-badge |
| `scheduler/list` | [App/Core/Tasks/assets/components.json](../../App/Core/Tasks/assets/components.json) | scheduler/status, scheduler/task-card, ui/empty-state, ui/filter-trigger, ui/list-toolbar, ui/modal-trigger, ui/pagination, ui/sort-heading |
| `scheduler/filter-form` | [App/Core/Tasks/assets/components.json](../../App/Core/Tasks/assets/components.json) | ui/select |
| `scheduler/run-detail` | [App/Core/Tasks/assets/components.json](../../App/Core/Tasks/assets/components.json) | scheduler/status |
| `scheduler/task-card` | [App/Core/Tasks/assets/components.json](../../App/Core/Tasks/assets/components.json) | scheduler/status, ui/api-action |
| `blog/articles` | [App/Modules/Blog/assets/components.json](../../App/Modules/Blog/assets/components.json) | ui/api-action, ui/empty-state, ui/list-toolbar, ui/modal-trigger, ui/pagination, ui/sort-heading, ui/status-badge |
| `blog/article-filter` | [App/Modules/Blog/assets/components.json](../../App/Modules/Blog/assets/components.json) | ui/select |
| `blog/article-fields` | [App/Modules/Blog/assets/components.json](../../App/Modules/Blog/assets/components.json) | publishing/editor, publishing/image-field, ui/field, ui/select |
| `blog/source-fields` | [App/Modules/Blog/assets/components.json](../../App/Modules/Blog/assets/components.json) | ui/field, ui/select |
| `blog/sources` | [App/Modules/Blog/assets/components.json](../../App/Modules/Blog/assets/components.json) | ui/api-action, ui/empty-state, ui/list-toolbar, ui/modal-trigger, ui/pagination, ui/sort-heading, ui/status-badge |
| `blog/source-filter` | [App/Modules/Blog/assets/components.json](../../App/Modules/Blog/assets/components.json) | ui/select |
| `blog/source-preview` | [App/Modules/Blog/assets/components.json](../../App/Modules/Blog/assets/components.json) | — |
| `users/following-profile` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | ui/avatar |
| `status/time` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | status/permalink, ui/action-label |
| `status/image` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | publishing/attached-image, status/permalink |
| `status/delete` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | — |
| `status/menu` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | status/delete |
| `status/manage` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | status/menu, status/permalink |
| `status/like` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | publishing/like |
| `status/actions` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | status/context, status/like, ui/action-label |
| `status/card` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | publishing/link, status/actions, status/body, status/image, status/manage, status/time, ui/avatar |
| `status/comment-form` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | publishing/comment-form |
| `status/comment` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | publishing/comment |
| `status/thread` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | status/comment, status/comment-form |
| `status/composer-body` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | publishing/editor, publishing/image-field, ui/avatar |
| `status/feed-more` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | ui/load-more |
| `status/context` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | — |
| `status/permalink` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | — |
| `status/body` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | publishing/segments |
| `bots/source-fields` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | ui/field |
| `bots/sources` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | ui/account-status, ui/action-label, ui/activation-action, ui/api-action, ui/empty-state, ui/list-toolbar, ui/modal-trigger, ui/pagination, ui/sort-heading |
| `bots/accounts` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | ui/account-status, ui/activation-action, ui/api-action, ui/empty-state, ui/list-toolbar, ui/modal-trigger, ui/pagination, ui/sort-heading |
| `bots/runs` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | bots/run-status, ui/empty-state, ui/sort-heading |
| `bots/stat` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | ui/stat-card |
| `bots/run-status` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | ui/status-badge |
| `bots/posts` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | bots/post-source, ui/empty-state, ui/list-toolbar, ui/pagination, ui/sort-heading |
| `bots/queue` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | bots/post-source, ui/account-status, ui/empty-state, ui/list-toolbar, ui/modal-trigger, ui/pagination, ui/sort-heading |
| `bots/queue-filter-form` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | ui/select |
| `bots/post-source` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | — |
| `bots/source-preview` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | — |
| `bots/post-filter-form` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | ui/select |
| `bots/account-fields` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | ui/field, users/avatar-control |
| `bots/runs-list` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | bots/runs, ui/list-toolbar, ui/pagination |
| `bots/run-filter-form` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | ui/select |
| `bots/scheduler-run-detail` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | bots/run-status |
| `moderation/reports` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | moderation/status, ui/empty-state, ui/list-toolbar, ui/modal-trigger, ui/pagination, ui/sort-heading |
| `moderation/filter-form` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | ui/select |
| `moderation/history` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | moderation/status, ui/empty-state, ui/modal-trigger |
| `moderation/report-preview` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | moderation/status |
| `moderation/status` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | ui/status-badge |
| `search/page` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | site/archive-actions, status/card, status/feed-more, ui/captcha, ui/empty-state |
| `site/sidebar` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | ui/avatar, ui/empty-state |
| `site/public-page` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | search/page, site/archive-actions, site/archive-filter, status/card, status/composer-body, status/feed-more, status/thread, ui/avatar-content, users/avatar-control |
| `site/archive-filter` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | site/archive-filter-name |
| `site/archive-actions` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | site/archive-filter |
| `site/archive-filter-name` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | — |
| `site/dashboard` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | ui/admin-list, ui/stat-card |
| `site/dashboard-comments` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | ui/empty-state |
| `status/comment-menu` | [App/Modules/Social/assets/components.json](../../App/Modules/Social/assets/components.json) | status/menu |
| `ui/field` | [assets/ui/components.json](../../assets/ui/components.json) | — |
| `ui/select` | [assets/ui/components.json](../../assets/ui/components.json) | — |
| `ui/pagination` | [assets/ui/components.json](../../assets/ui/components.json) | ui/pagination-step |
| `ui/api-action` | [assets/ui/components.json](../../assets/ui/components.json) | — |
| `ui/modal` | [assets/ui/components.json](../../assets/ui/components.json) | — |
| `ui/captcha` | [assets/ui/components.json](../../assets/ui/components.json) | — |
| `ui/search-form` | [assets/ui/components.json](../../assets/ui/components.json) | — |
| `ui/per-page` | [assets/ui/components.json](../../assets/ui/components.json) | — |
| `ui/list-toolbar` | [assets/ui/components.json](../../assets/ui/components.json) | ui/filter-trigger, ui/list-summary, ui/per-page, ui/search-form |
| `ui/avatar` | [assets/ui/components.json](../../assets/ui/components.json) | ui/avatar-content |
| `ui/avatar-content` | [assets/ui/components.json](../../assets/ui/components.json) | — |
| `ui/modal-trigger` | [assets/ui/components.json](../../assets/ui/components.json) | — |
| `ui/admin-list` | [assets/ui/components.json](../../assets/ui/components.json) | ui/filter-trigger |
| `ui/account-status` | [assets/ui/components.json](../../assets/ui/components.json) | ui/status-badge |
| `ui/pagination-step` | [assets/ui/components.json](../../assets/ui/components.json) | — |
| `ui/sort-heading` | [assets/ui/components.json](../../assets/ui/components.json) | — |
| `ui/status-badge` | [assets/ui/components.json](../../assets/ui/components.json) | — |
| `ui/stat-card` | [assets/ui/components.json](../../assets/ui/components.json) | — |
| `ui/notice` | [assets/ui/components.json](../../assets/ui/components.json) | — |
| `ui/empty-state` | [assets/ui/components.json](../../assets/ui/components.json) | — |
| `ui/activation-action` | [assets/ui/components.json](../../assets/ui/components.json) | ui/api-action |
| `ui/filter-trigger` | [assets/ui/components.json](../../assets/ui/components.json) | — |
| `ui/filter-content` | [assets/ui/components.json](../../assets/ui/components.json) | — |
| `ui/list-summary` | [assets/ui/components.json](../../assets/ui/components.json) | ui/filter-chip |
| `ui/editor-actions` | [assets/ui/components.json](../../assets/ui/components.json) | — |
| `ui/filter-chip` | [assets/ui/components.json](../../assets/ui/components.json) | — |
| `ui/load-more` | [assets/ui/components.json](../../assets/ui/components.json) | — |
| `ui/action-label` | [assets/ui/components.json](../../assets/ui/components.json) | — |
| `ui/dashboard-cache` | [assets/ui/components.json](../../assets/ui/components.json) | — |
| `ui/dashboard-overview` | [assets/ui/components.json](../../assets/ui/components.json) | ui/stat-card |
| `publishing/segments` | [assets/ui/components.json](../../assets/ui/components.json) | publishing/segments |
| `publishing/link` | [assets/ui/components.json](../../assets/ui/components.json) | publishing/link-summary |
| `publishing/link-summary` | [assets/ui/components.json](../../assets/ui/components.json) | — |
| `publishing/editor` | [assets/ui/components.json](../../assets/ui/components.json) | — |
| `publishing/image-field` | [assets/ui/components.json](../../assets/ui/components.json) | — |
| `publishing/attached-image` | [assets/ui/components.json](../../assets/ui/components.json) | — |
| `publishing/body` | [assets/ui/components.json](../../assets/ui/components.json) | publishing/attached-image, publishing/link, publishing/segments |
| `publishing/comment-form` | [assets/ui/components.json](../../assets/ui/components.json) | publishing/editor, ui/avatar |
| `publishing/comment` | [assets/ui/components.json](../../assets/ui/components.json) | publishing/comment, publishing/comment-form, publishing/like, publishing/segments, ui/avatar |
| `publishing/like` | [assets/ui/components.json](../../assets/ui/components.json) | — |
| `publishing/discussion` | [assets/ui/components.json](../../assets/ui/components.json) | publishing/comment, publishing/comment-form, publishing/like |

## UI služby a metody zapsané jako `name: function`

Jde o syntaktický seznam; nezahrnuje všechny exporty a nerozhoduje o veřejnosti metody.
- [assets/ui/ajax-bindings.js](../../assets/ui/ajax-bindings.js): `current`
- [assets/ui/analytics.js](../../assets/ui/analytics.js):
- [assets/ui/avatar-rendering.js](../../assets/ui/avatar-rendering.js): `appendAvatar`
- [assets/ui/component-builders.js](../../assets/ui/component-builders.js): `text`, `field`, `select`, `hidden`, `button`, `form`, `dialog`, `editorActions`, `accountDialogTitle`, `formDialog`, `tabbedContent`, `pinDialogTabs`, `sectionedDialog`, `adminDialog`, `editorFrame`, `pagination`, `captcha`
- [assets/ui/confirm-actions.js](../../assets/ui/confirm-actions.js):
- [assets/ui/content-updates.js](../../assets/ui/content-updates.js): `replaceChildren`, `remove`, `replaceNode`
- [assets/ui/dialog-history.js](../../assets/ui/dialog-history.js): `opened`, `closed`, `restore`, `leave`, `init`, `hydrate`
- [assets/ui/dialogs.js](../../assets/ui/dialogs.js): `create`
- [assets/ui/document-navigation.js](../../assets/ui/document-navigation.js):
- [assets/ui/filters.js](../../assets/ui/filters.js): `get`, `capture`, `restore`, `remove`, `init`
- [assets/ui/form-requests.js](../../assets/ui/form-requests.js): `request`, `cancel`, `dispose`, `bind`
- [assets/ui/form-state.js](../../assets/ui/form-state.js):
- [assets/ui/global-search.js](../../assets/ui/global-search.js):
- [assets/ui/image-placeholders.js](../../assets/ui/image-placeholders.js):
- [assets/ui/image-uploads.js](../../assets/ui/image-uploads.js):
- [assets/ui/language-suggestion.js](../../assets/ui/language-suggestion.js):
- [assets/ui/list-navigation.js](../../assets/ui/list-navigation.js): `register`, `isRead`, `url`
- [assets/ui/popovers.js](../../assets/ui/popovers.js): `restoringFocus`, `capture`, `restore`, `init`, `dispose`
- [assets/ui/public.js](../../assets/ui/public.js):
- [assets/ui/settings-presentation.js](../../assets/ui/settings-presentation.js):
- [assets/ui/tabs.js](../../assets/ui/tabs.js):
- [assets/ui/toasts.js](../../assets/ui/toasts.js):
- [assets/ui/tooltips.js](../../assets/ui/tooltips.js): `dispose`, `init`

## JavaScript — soubory, importy a vstupní body

Trasy označují přímé vstupní body manifestů. Další soubory se načítají přes importní graf; `bootstrap.js` připojuje společný builder automaticky.

| Soubor | Přímé importy | Vstupní trasy |
| --- | --- | --- |
| [App/Core/Identity/assets/admin.js](../../App/Core/Identity/assets/admin.js) | [App/Core/Identity/assets/index.js](../../App/Core/Identity/assets/index.js) | /admin/users |
| [App/Core/Identity/assets/avatar-editor.js](../../App/Core/Identity/assets/avatar-editor.js) | [assets/core/dom.js](../../assets/core/dom.js), [App/Core/Identity/assets/controller.js](../../App/Core/Identity/assets/controller.js), [assets/ui/dialogs.js](../../assets/ui/dialogs.js), [assets/ui/image-uploads.js](../../assets/ui/image-uploads.js), [assets/ui/toasts.js](../../assets/ui/toasts.js) | přes importy / společný runtime |
| [App/Core/Identity/assets/controller.js](../../App/Core/Identity/assets/controller.js) | [assets/ui/list-navigation.js](../../assets/ui/list-navigation.js), [assets/ui/content-updates.js](../../assets/ui/content-updates.js), [assets/core/dates.js](../../assets/core/dates.js), [assets/core/component-renderer.js](../../assets/core/component-renderer.js), [assets/core/dom.js](../../assets/core/dom.js), [assets/core/api-client.js](../../assets/core/api-client.js), [assets/core/i18n.js](../../assets/core/i18n.js), [assets/core/lifecycle.js](../../assets/core/lifecycle.js), [assets/ui/avatar-rendering.js](../../assets/ui/avatar-rendering.js), [assets/ui/dialogs.js](../../assets/ui/dialogs.js), [assets/ui/component-builders.js](../../assets/ui/component-builders.js), [assets/ui/toasts.js](../../assets/ui/toasts.js) | přes importy / společný runtime |
| [App/Core/Identity/assets/email-availability.js](../../App/Core/Identity/assets/email-availability.js) | [assets/core/api-client.js](../../assets/core/api-client.js), [assets/core/lifecycle.js](../../assets/core/lifecycle.js), [assets/ui/component-builders.js](../../assets/ui/component-builders.js), [assets/ui/form-state.js](../../assets/ui/form-state.js) | přes importy / společný runtime |
| [App/Core/Identity/assets/index.js](../../App/Core/Identity/assets/index.js) | [App/Core/Identity/assets/user-menu.js](../../App/Core/Identity/assets/user-menu.js), [assets/core/modules.js](../../assets/core/modules.js), [assets/ui/list-navigation.js](../../assets/ui/list-navigation.js), [App/Core/Identity/assets/avatar-editor.js](../../App/Core/Identity/assets/avatar-editor.js), [App/Core/Identity/assets/controller.js](../../App/Core/Identity/assets/controller.js), [App/Core/Identity/assets/email-availability.js](../../App/Core/Identity/assets/email-availability.js) | admin, public |
| [App/Core/Identity/assets/public.js](../../App/Core/Identity/assets/public.js) | [App/Core/Identity/assets/index.js](../../App/Core/Identity/assets/index.js) | public |
| [App/Core/Identity/assets/settings-presentation.js](../../App/Core/Identity/assets/settings-presentation.js) | [assets/ui/settings-presentation.js](../../assets/ui/settings-presentation.js) | admin |
| [App/Core/Identity/assets/user-menu.js](../../App/Core/Identity/assets/user-menu.js) | [assets/core/api-client.js](../../assets/core/api-client.js), [assets/core/dom.js](../../assets/core/dom.js), [assets/core/lifecycle.js](../../assets/core/lifecycle.js), [assets/ui/toasts.js](../../assets/ui/toasts.js) | přes importy / společný runtime |
| [App/Core/Installation/assets/index.js](../../App/Core/Installation/assets/index.js) | [assets/core/lifecycle.js](../../assets/core/lifecycle.js), [assets/ui/form-requests.js](../../assets/ui/form-requests.js), [assets/ui/toasts.js](../../assets/ui/toasts.js) | /install |
| [App/Core/Mail/assets/settings-presentation.js](../../App/Core/Mail/assets/settings-presentation.js) | [assets/ui/settings-presentation.js](../../assets/ui/settings-presentation.js) | admin |
| [App/Core/Notifications/assets/controller.js](../../App/Core/Notifications/assets/controller.js) | [assets/core/dates.js](../../assets/core/dates.js), [assets/core/component-renderer.js](../../assets/core/component-renderer.js), [assets/core/dom.js](../../assets/core/dom.js), [assets/core/api-client.js](../../assets/core/api-client.js), [assets/ui/component-builders.js](../../assets/ui/component-builders.js), [assets/ui/toasts.js](../../assets/ui/toasts.js), [assets/ui/popovers.js](../../assets/ui/popovers.js) | přes importy / společný runtime |
| [App/Core/Notifications/assets/index.js](../../App/Core/Notifications/assets/index.js) | [assets/core/modules.js](../../assets/core/modules.js), [App/Core/Notifications/assets/controller.js](../../App/Core/Notifications/assets/controller.js) | public |
| [App/Core/Security/assets/captcha.js](../../App/Core/Security/assets/captcha.js) | [assets/core/dom.js](../../assets/core/dom.js), [assets/core/lifecycle.js](../../assets/core/lifecycle.js) | přes importy / společný runtime |
| [App/Core/Security/assets/index.js](../../App/Core/Security/assets/index.js) | [App/Core/Security/assets/captcha.js](../../App/Core/Security/assets/captcha.js) | public |
| [App/Core/Security/assets/settings-presentation.js](../../App/Core/Security/assets/settings-presentation.js) | [assets/ui/settings-presentation.js](../../assets/ui/settings-presentation.js) | admin |
| [App/Core/Settings/assets/controller.js](../../App/Core/Settings/assets/controller.js) | [assets/ui/image-uploads.js](../../assets/ui/image-uploads.js), [assets/ui/component-builders.js](../../assets/ui/component-builders.js), [assets/core/modules.js](../../assets/core/modules.js), [App/Core/Settings/assets/field-presentation.js](../../App/Core/Settings/assets/field-presentation.js) | přes importy / společný runtime |
| [App/Core/Settings/assets/field-presentation.js](../../App/Core/Settings/assets/field-presentation.js) | [assets/ui/settings-presentation.js](../../assets/ui/settings-presentation.js) | přes importy / společný runtime |
| [App/Core/Settings/assets/index.js](../../App/Core/Settings/assets/index.js) | [App/Core/Settings/assets/controller.js](../../App/Core/Settings/assets/controller.js) | admin |
| [App/Core/Tasks/assets/controller.js](../../App/Core/Tasks/assets/controller.js) | [assets/ui/content-updates.js](../../assets/ui/content-updates.js), [assets/core/component-renderer.js](../../assets/core/component-renderer.js), [assets/core/dates.js](../../assets/core/dates.js), [assets/ui/component-builders.js](../../assets/ui/component-builders.js) | přes importy / společný runtime |
| [App/Core/Tasks/assets/index.js](../../App/Core/Tasks/assets/index.js) | [assets/core/modules.js](../../assets/core/modules.js), [App/Core/Tasks/assets/controller.js](../../App/Core/Tasks/assets/controller.js), [assets/ui/list-navigation.js](../../assets/ui/list-navigation.js) | /admin/cron, /admin/site-health |
| [App/Core/Updates/assets/index.js](../../App/Core/Updates/assets/index.js) | [assets/core/modules.js](../../assets/core/modules.js), [assets/ui/component-builders.js](../../assets/ui/component-builders.js) | /admin/updates |
| [App/Modules/Blog/assets/index.js](../../App/Modules/Blog/assets/index.js) | [assets/core/modules.js](../../assets/core/modules.js), [assets/core/dates.js](../../assets/core/dates.js), [assets/ui/content-updates.js](../../assets/ui/content-updates.js), [assets/ui/list-navigation.js](../../assets/ui/list-navigation.js), [assets/ui/component-builders.js](../../assets/ui/component-builders.js) | /admin/articles |
| [App/Modules/Blog/assets/sources.js](../../App/Modules/Blog/assets/sources.js) | [assets/core/modules.js](../../assets/core/modules.js), [assets/core/dates.js](../../assets/core/dates.js), [assets/ui/content-updates.js](../../assets/ui/content-updates.js), [assets/ui/list-navigation.js](../../assets/ui/list-navigation.js), [assets/ui/component-builders.js](../../assets/ui/component-builders.js), [assets/ui/form-requests.js](../../assets/ui/form-requests.js) | /admin/article-sources |
| [App/Modules/Social/assets/bots/controller.js](../../App/Modules/Social/assets/bots/controller.js) | [assets/ui/content-updates.js](../../assets/ui/content-updates.js), [assets/ui/list-navigation.js](../../assets/ui/list-navigation.js), [assets/core/dates.js](../../assets/core/dates.js), [assets/core/component-renderer.js](../../assets/core/component-renderer.js), [assets/core/api-client.js](../../assets/core/api-client.js), [assets/ui/avatar-rendering.js](../../assets/ui/avatar-rendering.js), [assets/ui/dialogs.js](../../assets/ui/dialogs.js), [assets/ui/component-builders.js](../../assets/ui/component-builders.js), [assets/ui/form-state.js](../../assets/ui/form-state.js), [assets/ui/form-requests.js](../../assets/ui/form-requests.js), [assets/ui/toasts.js](../../assets/ui/toasts.js), [App/Modules/Social/assets/bots/source-preview.js](../../App/Modules/Social/assets/bots/source-preview.js), [App/Modules/Social/assets/bots/workspace.js](../../App/Modules/Social/assets/bots/workspace.js) | přes importy / společný runtime |
| [App/Modules/Social/assets/bots/index.js](../../App/Modules/Social/assets/bots/index.js) | [assets/core/modules.js](../../assets/core/modules.js), [App/Modules/Social/assets/bots/controller.js](../../App/Modules/Social/assets/bots/controller.js), [assets/ui/list-navigation.js](../../assets/ui/list-navigation.js) | /admin/bots |
| [App/Modules/Social/assets/bots/source-preview.js](../../App/Modules/Social/assets/bots/source-preview.js) | [assets/core/lifecycle.js](../../assets/core/lifecycle.js), [assets/core/component-renderer.js](../../assets/core/component-renderer.js), [assets/ui/component-builders.js](../../assets/ui/component-builders.js), [assets/ui/form-requests.js](../../assets/ui/form-requests.js) | přes importy / společný runtime |
| [App/Modules/Social/assets/bots/workspace.js](../../App/Modules/Social/assets/bots/workspace.js) | [assets/ui/content-updates.js](../../assets/ui/content-updates.js), [assets/ui/list-navigation.js](../../assets/ui/list-navigation.js), [assets/core/dom.js](../../assets/core/dom.js), [assets/core/api-client.js](../../assets/core/api-client.js), [assets/ui/component-builders.js](../../assets/ui/component-builders.js), [assets/ui/tabs.js](../../assets/ui/tabs.js) | přes importy / společný runtime |
| [App/Modules/Social/assets/content/settings-presentation.js](../../App/Modules/Social/assets/content/settings-presentation.js) | [assets/ui/settings-presentation.js](../../assets/ui/settings-presentation.js) | admin |
| [App/Modules/Social/assets/moderation/controller.js](../../App/Modules/Social/assets/moderation/controller.js) | [assets/ui/content-updates.js](../../assets/ui/content-updates.js), [assets/core/dates.js](../../assets/core/dates.js), [assets/core/component-renderer.js](../../assets/core/component-renderer.js), [assets/ui/component-builders.js](../../assets/ui/component-builders.js), [assets/ui/dialogs.js](../../assets/ui/dialogs.js), [assets/core/dom.js](../../assets/core/dom.js) | přes importy / společný runtime |
| [App/Modules/Social/assets/moderation/index.js](../../App/Modules/Social/assets/moderation/index.js) | [assets/core/modules.js](../../assets/core/modules.js), [App/Modules/Social/assets/moderation/controller.js](../../App/Modules/Social/assets/moderation/controller.js), [assets/ui/list-navigation.js](../../assets/ui/list-navigation.js) | /admin/moderation |
| [App/Modules/Social/assets/moderation/settings-presentation.js](../../App/Modules/Social/assets/moderation/settings-presentation.js) | [assets/ui/settings-presentation.js](../../assets/ui/settings-presentation.js) | admin |
| [App/Modules/Social/assets/site/index.js](../../App/Modules/Social/assets/site/index.js) | [assets/core/modules.js](../../assets/core/modules.js), [assets/ui/analytics.js](../../assets/ui/analytics.js), [App/Modules/Social/assets/site/sidebar-data.js](../../App/Modules/Social/assets/site/sidebar-data.js), [App/Modules/Social/assets/site/sticky-sidebars.js](../../App/Modules/Social/assets/site/sticky-sidebars.js), [App/Modules/Social/assets/site/views.js](../../App/Modules/Social/assets/site/views.js), [App/Modules/Social/assets/site/page-navigation.js](../../App/Modules/Social/assets/site/page-navigation.js), [assets/ui/language-suggestion.js](../../assets/ui/language-suggestion.js) | public |
| [App/Modules/Social/assets/site/page-navigation.js](../../App/Modules/Social/assets/site/page-navigation.js) | [assets/ui/document-navigation.js](../../assets/ui/document-navigation.js), [assets/core/navigation.js](../../assets/core/navigation.js), [assets/core/api-client.js](../../assets/core/api-client.js), [assets/core/component-renderer.js](../../assets/core/component-renderer.js), [assets/core/lifecycle.js](../../assets/core/lifecycle.js), [assets/ui/dialogs.js](../../assets/ui/dialogs.js) | přes importy / společný runtime |
| [App/Modules/Social/assets/site/sidebar-data.js](../../App/Modules/Social/assets/site/sidebar-data.js) | [assets/core/dom.js](../../assets/core/dom.js), [assets/core/api-client.js](../../assets/core/api-client.js), [assets/core/lifecycle.js](../../assets/core/lifecycle.js), [App/Modules/Social/assets/site/views.js](../../App/Modules/Social/assets/site/views.js) | přes importy / společný runtime |
| [App/Modules/Social/assets/site/sticky-sidebars.js](../../App/Modules/Social/assets/site/sticky-sidebars.js) | [assets/core/dom.js](../../assets/core/dom.js), [assets/core/lifecycle.js](../../assets/core/lifecycle.js) | přes importy / společný runtime |
| [App/Modules/Social/assets/site/views.js](../../App/Modules/Social/assets/site/views.js) | [assets/core/component-renderer.js](../../assets/core/component-renderer.js) | přes importy / společný runtime |
| [App/Modules/Social/assets/status/comments.js](../../App/Modules/Social/assets/status/comments.js) | [assets/ui/content/comments.js](../../assets/ui/content/comments.js), [App/Modules/Social/assets/status/controller.js](../../App/Modules/Social/assets/status/controller.js) | přes importy / společný runtime |
| [App/Modules/Social/assets/status/controller.js](../../App/Modules/Social/assets/status/controller.js) | [assets/ui/content/comments.js](../../assets/ui/content/comments.js), [assets/ui/content/runtime.js](../../assets/ui/content/runtime.js), [assets/ui/content-updates.js](../../assets/ui/content-updates.js), [assets/core/dates.js](../../assets/core/dates.js), [assets/core/component-renderer.js](../../assets/core/component-renderer.js), [assets/core/dom.js](../../assets/core/dom.js), [assets/ui/component-builders.js](../../assets/ui/component-builders.js) | přes importy / společný runtime |
| [App/Modules/Social/assets/status/feed-loader.js](../../App/Modules/Social/assets/status/feed-loader.js) | [assets/core/request-lifecycle.js](../../assets/core/request-lifecycle.js), [assets/ui/content-updates.js](../../assets/ui/content-updates.js), [assets/core/dom.js](../../assets/core/dom.js), [assets/core/api-client.js](../../assets/core/api-client.js), [assets/core/i18n.js](../../assets/core/i18n.js), [assets/core/lifecycle.js](../../assets/core/lifecycle.js), [assets/core/navigation.js](../../assets/core/navigation.js), [App/Core/Notifications/assets/controller.js](../../App/Core/Notifications/assets/controller.js), [App/Modules/Social/assets/status/controller.js](../../App/Modules/Social/assets/status/controller.js), [assets/ui/dialogs.js](../../assets/ui/dialogs.js), [assets/ui/component-builders.js](../../assets/ui/component-builders.js), [assets/ui/toasts.js](../../assets/ui/toasts.js) | přes importy / společný runtime |
| [App/Modules/Social/assets/status/form-actions.js](../../App/Modules/Social/assets/status/form-actions.js) | [assets/ui/content-updates.js](../../assets/ui/content-updates.js), [assets/core/dom.js](../../assets/core/dom.js), [assets/core/api-client.js](../../assets/core/api-client.js), [assets/core/i18n.js](../../assets/core/i18n.js), [assets/core/lifecycle.js](../../assets/core/lifecycle.js), [assets/core/navigation.js](../../assets/core/navigation.js), [App/Modules/Social/assets/status/comments.js](../../App/Modules/Social/assets/status/comments.js), [assets/ui/content/editor.js](../../assets/ui/content/editor.js), [App/Modules/Social/assets/status/feed-loader.js](../../App/Modules/Social/assets/status/feed-loader.js), [App/Modules/Social/assets/status/form-context.js](../../App/Modules/Social/assets/status/form-context.js), [App/Modules/Social/assets/status/controller.js](../../App/Modules/Social/assets/status/controller.js), [assets/ui/confirm-actions.js](../../assets/ui/confirm-actions.js), [assets/ui/dialogs.js](../../assets/ui/dialogs.js), [assets/ui/form-state.js](../../assets/ui/form-state.js), [assets/ui/form-requests.js](../../assets/ui/form-requests.js), [assets/ui/toasts.js](../../assets/ui/toasts.js) | přes importy / společný runtime |
| [App/Modules/Social/assets/status/form-context.js](../../App/Modules/Social/assets/status/form-context.js) | [assets/core/dom.js](../../assets/core/dom.js), [App/Modules/Social/assets/status/controller.js](../../App/Modules/Social/assets/status/controller.js) | přes importy / společný runtime |
| [App/Modules/Social/assets/status/index.js](../../App/Modules/Social/assets/status/index.js) | [assets/ui/content/index.js](../../assets/ui/content/index.js), [App/Modules/Social/assets/status/media-navigation.js](../../App/Modules/Social/assets/status/media-navigation.js), [assets/core/dom.js](../../assets/core/dom.js), [assets/core/modules.js](../../assets/core/modules.js), [assets/core/navigation.js](../../assets/core/navigation.js), [App/Modules/Social/assets/status/form-actions.js](../../App/Modules/Social/assets/status/form-actions.js), [App/Modules/Social/assets/status/comments.js](../../App/Modules/Social/assets/status/comments.js), [assets/ui/content/editor.js](../../assets/ui/content/editor.js), [App/Modules/Social/assets/status/feed-loader.js](../../App/Modules/Social/assets/status/feed-loader.js), [App/Modules/Social/assets/status/form-context.js](../../App/Modules/Social/assets/status/form-context.js), [assets/ui/content/media.js](../../assets/ui/content/media.js), [App/Modules/Social/assets/status/controller.js](../../App/Modules/Social/assets/status/controller.js), [assets/ui/content/translation.js](../../assets/ui/content/translation.js) | /admin/bots, public |
| [App/Modules/Social/assets/status/media-navigation.js](../../App/Modules/Social/assets/status/media-navigation.js) | [assets/ui/content/media.js](../../assets/ui/content/media.js), [App/Modules/Social/assets/status/controller.js](../../App/Modules/Social/assets/status/controller.js) | přes importy / společný runtime |
| [App/Modules/Social/assets/users/controller.js](../../App/Modules/Social/assets/users/controller.js) | [App/Core/Identity/assets/controller.js](../../App/Core/Identity/assets/controller.js) | přes importy / společný runtime |
| [App/Modules/Social/assets/users/following.js](../../App/Modules/Social/assets/users/following.js) | [assets/core/dom.js](../../assets/core/dom.js), [assets/core/api-client.js](../../assets/core/api-client.js), [assets/core/i18n.js](../../assets/core/i18n.js), [App/Modules/Social/assets/users/controller.js](../../App/Modules/Social/assets/users/controller.js), [assets/ui/component-builders.js](../../assets/ui/component-builders.js), [assets/ui/form-requests.js](../../assets/ui/form-requests.js), [assets/ui/toasts.js](../../assets/ui/toasts.js) | přes importy / společný runtime |
| [App/Modules/Social/assets/users/index.js](../../App/Modules/Social/assets/users/index.js) | [App/Core/Identity/assets/index.js](../../App/Core/Identity/assets/index.js), [App/Modules/Social/assets/users/controller.js](../../App/Modules/Social/assets/users/controller.js) | public |
| [App/Modules/Social/assets/users/public.js](../../App/Modules/Social/assets/users/public.js) | [App/Modules/Social/assets/users/index.js](../../App/Modules/Social/assets/users/index.js), [App/Modules/Social/assets/users/following.js](../../App/Modules/Social/assets/users/following.js) | public |
| [assets/bootstrap.js](../../assets/bootstrap.js) | [assets/core/lifecycle.js](../../assets/core/lifecycle.js), [assets/core/modules.js](../../assets/core/modules.js), [assets/core/navigation.js](../../assets/core/navigation.js) | přes importy / společný runtime |
| [assets/core/api-client.js](../../assets/core/api-client.js) | [assets/core/i18n.js](../../assets/core/i18n.js), [assets/core/urls.js](../../assets/core/urls.js) | přes importy / společný runtime |
| [assets/core/component-renderer.js](../../assets/core/component-renderer.js) | [assets/core/dom.js](../../assets/core/dom.js), [assets/core/api-client.js](../../assets/core/api-client.js), [assets/core/i18n.js](../../assets/core/i18n.js), [assets/core/icons.js](../../assets/core/icons.js) | přes importy / společný runtime |
| [assets/core/dates.js](../../assets/core/dates.js) | [assets/core/i18n.js](../../assets/core/i18n.js) | admin, public |
| [assets/core/dom.js](../../assets/core/dom.js) | [assets/core/urls.js](../../assets/core/urls.js) | přes importy / společný runtime |
| [assets/core/i18n.js](../../assets/core/i18n.js) |  | přes importy / společný runtime |
| [assets/core/icons.js](../../assets/core/icons.js) |  | přes importy / společný runtime |
| [assets/core/lifecycle.js](../../assets/core/lifecycle.js) |  | přes importy / společný runtime |
| [assets/core/modules.js](../../assets/core/modules.js) |  | přes importy / společný runtime |
| [assets/core/navigation.js](../../assets/core/navigation.js) | [assets/core/request-lifecycle.js](../../assets/core/request-lifecycle.js), [assets/core/dom.js](../../assets/core/dom.js), [assets/core/api-client.js](../../assets/core/api-client.js), [assets/core/lifecycle.js](../../assets/core/lifecycle.js) | přes importy / společný runtime |
| [assets/core/request-lifecycle.js](../../assets/core/request-lifecycle.js) | [assets/core/lifecycle.js](../../assets/core/lifecycle.js) | přes importy / společný runtime |
| [assets/core/urls.js](../../assets/core/urls.js) |  | přes importy / společný runtime |
| [assets/embed-frame.js](../../assets/embed-frame.js) |  | standalone |
| [assets/embed.js](../../assets/embed.js) |  | standalone |
| [assets/modules/admin/index.js](../../assets/modules/admin/index.js) | [assets/modules/admin/navigation.js](../../assets/modules/admin/navigation.js) | admin |
| [assets/modules/admin/module-packages.js](../../assets/modules/admin/module-packages.js) | [assets/core/modules.js](../../assets/core/modules.js), [assets/ui/component-builders.js](../../assets/ui/component-builders.js) | /admin/modules |
| [assets/modules/admin/navigation.js](../../assets/modules/admin/navigation.js) | [assets/core/dom.js](../../assets/core/dom.js), [assets/core/lifecycle.js](../../assets/core/lifecycle.js) | přes importy / společný runtime |
| [assets/ui/ajax-bindings.js](../../assets/ui/ajax-bindings.js) | [assets/ui/list-navigation.js](../../assets/ui/list-navigation.js), [assets/core/request-lifecycle.js](../../assets/core/request-lifecycle.js), [assets/core/dom.js](../../assets/core/dom.js), [assets/core/api-client.js](../../assets/core/api-client.js), [assets/core/i18n.js](../../assets/core/i18n.js), [assets/core/lifecycle.js](../../assets/core/lifecycle.js), [assets/core/modules.js](../../assets/core/modules.js), [assets/core/navigation.js](../../assets/core/navigation.js), [assets/ui/confirm-actions.js](../../assets/ui/confirm-actions.js), [assets/ui/dialogs.js](../../assets/ui/dialogs.js), [assets/ui/component-builders.js](../../assets/ui/component-builders.js), [assets/ui/form-state.js](../../assets/ui/form-state.js), [assets/ui/form-requests.js](../../assets/ui/form-requests.js), [assets/ui/toasts.js](../../assets/ui/toasts.js) | admin, public |
| [assets/ui/analytics.js](../../assets/ui/analytics.js) | [assets/core/dom.js](../../assets/core/dom.js), [assets/core/lifecycle.js](../../assets/core/lifecycle.js) | přes importy / společný runtime |
| [assets/ui/avatar-rendering.js](../../assets/ui/avatar-rendering.js) | [assets/core/component-renderer.js](../../assets/core/component-renderer.js), [assets/core/dom.js](../../assets/core/dom.js), [assets/core/lifecycle.js](../../assets/core/lifecycle.js), [assets/ui/component-builders.js](../../assets/ui/component-builders.js) | přes importy / společný runtime |
| [assets/ui/component-builders.js](../../assets/ui/component-builders.js) | [assets/core/component-renderer.js](../../assets/core/component-renderer.js), [assets/core/dom.js](../../assets/core/dom.js), [assets/core/i18n.js](../../assets/core/i18n.js), [assets/core/icons.js](../../assets/core/icons.js), [assets/core/lifecycle.js](../../assets/core/lifecycle.js), [assets/ui/dialogs.js](../../assets/ui/dialogs.js), [assets/ui/filters.js](../../assets/ui/filters.js) | přes importy / společný runtime |
| [assets/ui/confirm-actions.js](../../assets/ui/confirm-actions.js) | [assets/core/lifecycle.js](../../assets/core/lifecycle.js), [assets/ui/dialogs.js](../../assets/ui/dialogs.js) | admin, public |
| [assets/ui/content/comments.js](../../assets/ui/content/comments.js) | [assets/ui/component-builders.js](../../assets/ui/component-builders.js), [assets/core/dates.js](../../assets/core/dates.js), [assets/ui/content-updates.js](../../assets/ui/content-updates.js), [assets/core/dom.js](../../assets/core/dom.js), [assets/ui/content/editor.js](../../assets/ui/content/editor.js), [assets/ui/content/runtime.js](../../assets/ui/content/runtime.js), [assets/ui/dialogs.js](../../assets/ui/dialogs.js) | přes importy / společný runtime |
| [assets/ui/content/discussion.js](../../assets/ui/content/discussion.js) | [assets/core/modules.js](../../assets/core/modules.js), [assets/ui/component-builders.js](../../assets/ui/component-builders.js), [assets/ui/content-updates.js](../../assets/ui/content-updates.js), [assets/ui/content/comments.js](../../assets/ui/content/comments.js), [assets/ui/content/editor-entry.js](../../assets/ui/content/editor-entry.js) | přes importy / společný runtime |
| [assets/ui/content/editor-entry.js](../../assets/ui/content/editor-entry.js) | [assets/core/modules.js](../../assets/core/modules.js), [assets/ui/content/editor.js](../../assets/ui/content/editor.js), [assets/ui/content/media.js](../../assets/ui/content/media.js) | přes importy / společný runtime |
| [assets/ui/content/editor.js](../../assets/ui/content/editor.js) | [assets/core/dom.js](../../assets/core/dom.js), [assets/core/api-client.js](../../assets/core/api-client.js), [assets/core/navigation.js](../../assets/core/navigation.js), [assets/ui/content/runtime.js](../../assets/ui/content/runtime.js), [assets/ui/avatar-rendering.js](../../assets/ui/avatar-rendering.js), [assets/ui/component-builders.js](../../assets/ui/component-builders.js) | přes importy / společný runtime |
| [assets/ui/content/index.js](../../assets/ui/content/index.js) | [assets/ui/content/presentation.js](../../assets/ui/content/presentation.js), [assets/ui/content/editor-entry.js](../../assets/ui/content/editor-entry.js) | přes importy / společný runtime |
| [assets/ui/content/media-preview.js](../../assets/ui/content/media-preview.js) | [assets/core/modules.js](../../assets/core/modules.js), [assets/ui/content/media.js](../../assets/ui/content/media.js) | /admin/moderation |
| [assets/ui/content/media.js](../../assets/ui/content/media.js) | [assets/core/i18n.js](../../assets/core/i18n.js), [assets/core/dom.js](../../assets/core/dom.js), [assets/core/navigation.js](../../assets/core/navigation.js), [assets/ui/content/runtime.js](../../assets/ui/content/runtime.js), [assets/ui/image-uploads.js](../../assets/ui/image-uploads.js), [assets/ui/toasts.js](../../assets/ui/toasts.js) | přes importy / společný runtime |
| [assets/ui/content/presentation.js](../../assets/ui/content/presentation.js) | [assets/core/modules.js](../../assets/core/modules.js), [assets/ui/content/media.js](../../assets/ui/content/media.js), [assets/ui/content/translation.js](../../assets/ui/content/translation.js) | přes importy / společný runtime |
| [assets/ui/content/runtime.js](../../assets/ui/content/runtime.js) | [assets/core/dom.js](../../assets/core/dom.js) | přes importy / společný runtime |
| [assets/ui/content/translation.js](../../assets/ui/content/translation.js) | [assets/core/i18n.js](../../assets/core/i18n.js), [assets/core/icons.js](../../assets/core/icons.js), [assets/ui/content/runtime.js](../../assets/ui/content/runtime.js) | přes importy / společný runtime |
| [assets/ui/content-updates.js](../../assets/ui/content-updates.js) | [assets/core/lifecycle.js](../../assets/core/lifecycle.js) | přes importy / společný runtime |
| [assets/ui/dialog-history.js](../../assets/ui/dialog-history.js) | [assets/core/navigation.js](../../assets/core/navigation.js), [assets/core/lifecycle.js](../../assets/core/lifecycle.js), [assets/ui/tabs.js](../../assets/ui/tabs.js) | přes importy / společný runtime |
| [assets/ui/dialogs.js](../../assets/ui/dialogs.js) | [assets/ui/dialog-history.js](../../assets/ui/dialog-history.js), [assets/core/component-renderer.js](../../assets/core/component-renderer.js), [assets/core/dom.js](../../assets/core/dom.js), [assets/core/api-client.js](../../assets/core/api-client.js), [assets/core/i18n.js](../../assets/core/i18n.js), [assets/core/icons.js](../../assets/core/icons.js), [assets/core/lifecycle.js](../../assets/core/lifecycle.js), [assets/core/modules.js](../../assets/core/modules.js), [assets/ui/form-state.js](../../assets/ui/form-state.js), [assets/ui/form-requests.js](../../assets/ui/form-requests.js) | přes importy / společný runtime |
| [assets/ui/document-navigation.js](../../assets/ui/document-navigation.js) | [assets/core/dom.js](../../assets/core/dom.js) | přes importy / společný runtime |
| [assets/ui/filters.js](../../assets/ui/filters.js) | [assets/core/dom.js](../../assets/core/dom.js), [assets/core/lifecycle.js](../../assets/core/lifecycle.js), [assets/core/component-renderer.js](../../assets/core/component-renderer.js), [assets/ui/popovers.js](../../assets/ui/popovers.js), [assets/ui/form-requests.js](../../assets/ui/form-requests.js) | přes importy / společný runtime |
| [assets/ui/form-requests.js](../../assets/ui/form-requests.js) | [assets/core/dom.js](../../assets/core/dom.js), [assets/core/api-client.js](../../assets/core/api-client.js) | přes importy / společný runtime |
| [assets/ui/form-state.js](../../assets/ui/form-state.js) | [assets/core/dom.js](../../assets/core/dom.js), [assets/core/lifecycle.js](../../assets/core/lifecycle.js), [assets/core/navigation.js](../../assets/core/navigation.js), [assets/ui/form-requests.js](../../assets/ui/form-requests.js), [assets/ui/tabs.js](../../assets/ui/tabs.js) | přes importy / společný runtime |
| [assets/ui/global-search.js](../../assets/ui/global-search.js) | [assets/core/request-lifecycle.js](../../assets/core/request-lifecycle.js), [assets/core/dates.js](../../assets/core/dates.js), [assets/core/dom.js](../../assets/core/dom.js), [assets/core/api-client.js](../../assets/core/api-client.js), [assets/core/i18n.js](../../assets/core/i18n.js), [assets/core/lifecycle.js](../../assets/core/lifecycle.js), [assets/core/navigation.js](../../assets/core/navigation.js), [assets/ui/avatar-rendering.js](../../assets/ui/avatar-rendering.js), [assets/ui/dialogs.js](../../assets/ui/dialogs.js), [assets/ui/component-builders.js](../../assets/ui/component-builders.js), [assets/ui/popovers.js](../../assets/ui/popovers.js) | přes importy / společný runtime |
| [assets/ui/image-placeholders.js](../../assets/ui/image-placeholders.js) | [assets/core/lifecycle.js](../../assets/core/lifecycle.js) | * |
| [assets/ui/image-uploads.js](../../assets/ui/image-uploads.js) | [assets/core/dom.js](../../assets/core/dom.js), [assets/core/lifecycle.js](../../assets/core/lifecycle.js), [assets/ui/toasts.js](../../assets/ui/toasts.js) | přes importy / společný runtime |
| [assets/ui/language-suggestion.js](../../assets/ui/language-suggestion.js) | [assets/ui/popovers.js](../../assets/ui/popovers.js), [assets/core/lifecycle.js](../../assets/core/lifecycle.js) | přes importy / společný runtime |
| [assets/ui/list-navigation.js](../../assets/ui/list-navigation.js) | [assets/core/navigation.js](../../assets/core/navigation.js), [assets/core/dom.js](../../assets/core/dom.js), [assets/core/request-lifecycle.js](../../assets/core/request-lifecycle.js), [assets/core/lifecycle.js](../../assets/core/lifecycle.js) | přes importy / společný runtime |
| [assets/ui/popovers.js](../../assets/ui/popovers.js) | [assets/core/dom.js](../../assets/core/dom.js), [assets/core/lifecycle.js](../../assets/core/lifecycle.js) | * |
| [assets/ui/public.js](../../assets/ui/public.js) | [assets/core/modules.js](../../assets/core/modules.js), [assets/ui/analytics.js](../../assets/ui/analytics.js), [assets/ui/language-suggestion.js](../../assets/ui/language-suggestion.js), [assets/ui/global-search.js](../../assets/ui/global-search.js) | public |
| [assets/ui/settings-presentation.js](../../assets/ui/settings-presentation.js) |  | přes importy / společný runtime |
| [assets/ui/tabs.js](../../assets/ui/tabs.js) | [assets/core/dom.js](../../assets/core/dom.js), [assets/core/lifecycle.js](../../assets/core/lifecycle.js) | * |
| [assets/ui/toasts.js](../../assets/ui/toasts.js) | [assets/core/dom.js](../../assets/core/dom.js), [assets/core/i18n.js](../../assets/core/i18n.js), [assets/core/icons.js](../../assets/core/icons.js), [assets/core/lifecycle.js](../../assets/core/lifecycle.js) | * |
| [assets/ui/tooltips.js](../../assets/ui/tooltips.js) | [assets/core/lifecycle.js](../../assets/core/lifecycle.js) | * |

## PHP partialy

- [App/Core/Identity/templates/parts/user-menu.php](../../App/Core/Identity/templates/parts/user-menu.php)
- [App/Core/Installation/templates/parts/install/steps.php](../../App/Core/Installation/templates/parts/install/steps.php)
- [App/Core/Notifications/templates/parts/menu.php](../../App/Core/Notifications/templates/parts/menu.php)
- [App/Core/Notifications/templates/parts/page.php](../../App/Core/Notifications/templates/parts/page.php)
- [App/Core/Support/templates/parts/filter.php](../../App/Core/Support/templates/parts/filter.php)
- [App/Core/Ui/templates/parts/consent.php](../../App/Core/Ui/templates/parts/consent.php)
- [App/Core/Ui/templates/parts/flashes.php](../../App/Core/Ui/templates/parts/flashes.php)
- [App/Core/Ui/templates/parts/public-header.php](../../App/Core/Ui/templates/parts/public-header.php)
- [App/Modules/Social/templates/parts/bots/modals/account-filter.php](../../App/Modules/Social/templates/parts/bots/modals/account-filter.php)
- [App/Modules/Social/templates/parts/bots/modals/source-filter.php](../../App/Modules/Social/templates/parts/bots/modals/source-filter.php)
- [App/Modules/Social/templates/parts/site/sidebar-slot.php](../../App/Modules/Social/templates/parts/site/sidebar-slot.php)
- [App/Modules/Social/templates/parts/status/composer.php](../../App/Modules/Social/templates/parts/status/composer.php)
- [App/Modules/Social/templates/parts/status/feed.php](../../App/Modules/Social/templates/parts/status/feed.php)
- [App/Modules/Social/templates/parts/status/home-feed.php](../../App/Modules/Social/templates/parts/status/home-feed.php)
- [App/Modules/Social/templates/parts/users/follow-button.php](../../App/Modules/Social/templates/parts/users/follow-button.php)

## PHP renderery

- [App/Core/Identity/Views.php](../../App/Core/Identity/Views.php): `adminFormFields`, `adminList`, `authContent`, `authForm`, `avatar`
- [App/Core/Notifications/Views.php](../../App/Core/Notifications/Views.php): `items`
- [App/Core/Support/AdminViews.php](../../App/Core/Support/AdminViews.php): `activationNotice`, `listCard`, `modalAction`, `tc_admin_options`, `tc_admin_datetime`, `tc_admin_datetime_iso`, `admin_user_statuses`, `admin_filter_text`, `admin_per_page_options`, `admin_per_page`, `admin_page`, `admin_list_query`, `admin_list_url`, `admin_per_page_view_data`, `admin_pagination_view_data`
- [App/Modules/Social/src/Automation/Views.php](../../App/Modules/Social/src/Automation/Views.php): `accountFilter`, `accounts`, `sources`
- [App/Modules/Social/src/Moderation/Views.php](../../App/Modules/Social/src/Moderation/Views.php): `reports`
- [App/Modules/Social/src/Posts/Views.php](../../App/Modules/Social/src/Posts/Views.php): `feedMore`, `card`, `actions`, `commentForm`, `commentItem`, `commentsThread`, `composerBody`, `composer`, `homeFeed`
- [App/Modules/Social/src/Presentation/Views.php](../../App/Modules/Social/src/Presentation/Views.php): `dashboard`, `sidebar`
- [App/Modules/Social/src/Profiles/Views.php](../../App/Modules/Social/src/Profiles/Views.php): `adminFormFields`, `adminList`, `authContent`, `authForm`, `followButton`, `followingProfile`, `avatar`

## Všechny PHP šablony

- [App/Core/Health/templates/page.php](../../App/Core/Health/templates/page.php)
- [App/Core/Identity/templates/login.php](../../App/Core/Identity/templates/login.php)
- [App/Core/Identity/templates/logout.php](../../App/Core/Identity/templates/logout.php)
- [App/Core/Identity/templates/parts/user-menu.php](../../App/Core/Identity/templates/parts/user-menu.php)
- [App/Core/Identity/templates/recovery.php](../../App/Core/Identity/templates/recovery.php)
- [App/Core/Identity/templates/register.php](../../App/Core/Identity/templates/register.php)
- [App/Core/Installation/templates/parts/install/steps.php](../../App/Core/Installation/templates/parts/install/steps.php)
- [App/Core/Notifications/templates/page.php](../../App/Core/Notifications/templates/page.php)
- [App/Core/Notifications/templates/parts/menu.php](../../App/Core/Notifications/templates/parts/menu.php)
- [App/Core/Notifications/templates/parts/page.php](../../App/Core/Notifications/templates/parts/page.php)
- [App/Core/Support/templates/parts/filter.php](../../App/Core/Support/templates/parts/filter.php)
- [App/Core/Tasks/templates/actions.php](../../App/Core/Tasks/templates/actions.php)
- [App/Core/Tasks/templates/administration.php](../../App/Core/Tasks/templates/administration.php)
- [App/Core/Ui/Admin/templates/document.php](../../App/Core/Ui/Admin/templates/document.php)
- [App/Core/Ui/Admin/templates/shell.php](../../App/Core/Ui/Admin/templates/shell.php)
- [App/Core/Ui/templates/document.php](../../App/Core/Ui/templates/document.php)
- [App/Core/Ui/templates/errorFallback.php](../../App/Core/Ui/templates/errorFallback.php)
- [App/Core/Ui/templates/head.php](../../App/Core/Ui/templates/head.php)
- [App/Core/Ui/templates/modules.php](../../App/Core/Ui/templates/modules.php)
- [App/Core/Ui/templates/notFound.php](../../App/Core/Ui/templates/notFound.php)
- [App/Core/Ui/templates/parts/consent.php](../../App/Core/Ui/templates/parts/consent.php)
- [App/Core/Ui/templates/parts/flashes.php](../../App/Core/Ui/templates/parts/flashes.php)
- [App/Core/Ui/templates/parts/public-header.php](../../App/Core/Ui/templates/parts/public-header.php)
- [App/Core/Ui/templates/privacy.php](../../App/Core/Ui/templates/privacy.php)
- [App/Core/Ui/templates/public-shell.php](../../App/Core/Ui/templates/public-shell.php)
- [App/Core/Ui/templates/search.php](../../App/Core/Ui/templates/search.php)
- [App/Core/Updates/templates/actions.php](../../App/Core/Updates/templates/actions.php)
- [App/Core/Updates/templates/administration.php](../../App/Core/Updates/templates/administration.php)
- [App/Modules/Blog/templates/article.php](../../App/Modules/Blog/templates/article.php)
- [App/Modules/Blog/templates/listing.php](../../App/Modules/Blog/templates/listing.php)
- [App/Modules/Social/templates/parts/bots/modals/account-filter.php](../../App/Modules/Social/templates/parts/bots/modals/account-filter.php)
- [App/Modules/Social/templates/parts/bots/modals/source-filter.php](../../App/Modules/Social/templates/parts/bots/modals/source-filter.php)
- [App/Modules/Social/templates/parts/site/sidebar-slot.php](../../App/Modules/Social/templates/parts/site/sidebar-slot.php)
- [App/Modules/Social/templates/parts/status/composer.php](../../App/Modules/Social/templates/parts/status/composer.php)
- [App/Modules/Social/templates/parts/status/feed.php](../../App/Modules/Social/templates/parts/status/feed.php)
- [App/Modules/Social/templates/parts/status/home-feed.php](../../App/Modules/Social/templates/parts/status/home-feed.php)
- [App/Modules/Social/templates/parts/users/follow-button.php](../../App/Modules/Social/templates/parts/users/follow-button.php)
- [App/Modules/Social/templates/site/privacy-section.php](../../App/Modules/Social/templates/site/privacy-section.php)
- [App/Modules/Social/templates/site/public.php](../../App/Modules/Social/templates/site/public.php)
- [App/Modules/Social/templates/status/embed.php](../../App/Modules/Social/templates/status/embed.php)

## CSS mimo hlavní importní graf

- [App/Modules/Blog/assets/styles/blog.css](../../App/Modules/Blog/assets/styles/blog.css): `blog-article`, `blog-body`, `blog-body-input`, `blog-excerpt`, `blog-page`, `blog-title`
- [assets/embed.css](../../assets/embed.css): `embed-audio-summary`, `embed-author`, `embed-author-link`, `embed-avatar`, `embed-avatar-initial`, `embed-body`, `embed-card`, `embed-image`, `embed-link`, `embed-link-copy`, `embed-link-image`, `embed-play`, `embed-play-icon`, `embed-player`, `embed-provider`, `share-code`, `share-heading`, `share-page`, `status-audio-player`

## Vlastnictví a načítání CSS v pořadí kaskády

| Soubor | Manifest | Trasy |
| --- | --- | --- |
| [assets/ui/styles/tokens.css](../../assets/ui/styles/tokens.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/ui/styles/reset.css](../../assets/ui/styles/reset.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/ui/styles/layout.css](../../assets/ui/styles/layout.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/ui/styles/cards.css](../../assets/ui/styles/cards.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/ui/styles/check-card.css](../../assets/ui/styles/check-card.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/ui/styles/disclosure.css](../../assets/ui/styles/disclosure.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/ui/styles/visibility.css](../../assets/ui/styles/visibility.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/modules/admin/styles/admin-lists.css](../../assets/modules/admin/styles/admin-lists.css) | [assets/modules/admin/module.json](../../assets/modules/admin/module.json) | admin |
| [assets/ui/styles/field-inline.css](../../assets/ui/styles/field-inline.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/ui/styles/fields.css](../../assets/ui/styles/fields.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [App/Core/Security/assets/styles/captcha.css](../../App/Core/Security/assets/styles/captcha.css) | [App/Core/Security/assets/module.json](../../App/Core/Security/assets/module.json) | public |
| [assets/ui/styles/alerts.css](../../assets/ui/styles/alerts.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/ui/styles/badges.css](../../assets/ui/styles/badges.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/ui/styles/filter-chips.css](../../assets/ui/styles/filter-chips.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/ui/styles/badge-danger.css](../../assets/ui/styles/badge-danger.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/ui/styles/tables.css](../../assets/ui/styles/tables.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/ui/styles/content-images.css](../../assets/ui/styles/content-images.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/ui/styles/status-indicators.css](../../assets/ui/styles/status-indicators.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/ui/styles/empty-state.css](../../assets/ui/styles/empty-state.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/ui/styles/filter-menu.css](../../assets/ui/styles/filter-menu.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/ui/styles/content-images-contain.css](../../assets/ui/styles/content-images-contain.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/ui/styles/status-indicator-warning.css](../../assets/ui/styles/status-indicator-warning.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/ui/styles/tables-mobile.css](../../assets/ui/styles/tables-mobile.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/ui/styles/check-line.css](../../assets/ui/styles/check-line.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/ui/styles/navbar.css](../../assets/ui/styles/navbar.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [App/Core/Notifications/assets/styles/notification-nav.css](../../App/Core/Notifications/assets/styles/notification-nav.css) | [App/Core/Notifications/assets/module.json](../../App/Core/Notifications/assets/module.json) | public |
| [App/Modules/Social/assets/status/styles/status-email-notice.css](../../App/Modules/Social/assets/status/styles/status-email-notice.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [App/Modules/Social/assets/users/styles/profile-navigation.css](../../App/Modules/Social/assets/users/styles/profile-navigation.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [App/Core/Notifications/assets/styles/notification-preview.css](../../App/Core/Notifications/assets/styles/notification-preview.css) | [App/Core/Notifications/assets/module.json](../../App/Core/Notifications/assets/module.json) | public |
| [assets/ui/styles/global-search.css](../../assets/ui/styles/global-search.css) | [assets/ui/module.json](../../assets/ui/module.json) | public |
| [assets/ui/styles/global-search-controls.css](../../assets/ui/styles/global-search-controls.css) | [assets/ui/module.json](../../assets/ui/module.json) | public |
| [App/Modules/Social/assets/site/styles/public-list-header.css](../../App/Modules/Social/assets/site/styles/public-list-header.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [App/Modules/Social/assets/status/styles/feed-switch.css](../../App/Modules/Social/assets/status/styles/feed-switch.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [App/Modules/Social/assets/site/styles/public-layout.css](../../App/Modules/Social/assets/site/styles/public-layout.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [App/Modules/Social/assets/status/styles/home-feed.css](../../App/Modules/Social/assets/status/styles/home-feed.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [App/Modules/Social/assets/status/styles/home-feed-switch-links.css](../../App/Modules/Social/assets/status/styles/home-feed-switch-links.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [App/Modules/Social/assets/site/styles/public-sidebar.css](../../App/Modules/Social/assets/site/styles/public-sidebar.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [App/Core/Notifications/assets/styles/notifications-page.css](../../App/Core/Notifications/assets/styles/notifications-page.css) | [App/Core/Notifications/assets/module.json](../../App/Core/Notifications/assets/module.json) | public |
| [App/Core/Notifications/assets/styles/notification-items.css](../../App/Core/Notifications/assets/styles/notification-items.css) | [App/Core/Notifications/assets/module.json](../../App/Core/Notifications/assets/module.json) | public |
| [App/Modules/Social/assets/site/styles/sidebar-lists.css](../../App/Modules/Social/assets/site/styles/sidebar-lists.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [App/Modules/Social/assets/status/styles/status-feed.css](../../App/Modules/Social/assets/status/styles/status-feed.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [App/Modules/Social/assets/status/styles/composer-loading.css](../../App/Modules/Social/assets/status/styles/composer-loading.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [App/Modules/Social/assets/status/styles/status-header.css](../../App/Modules/Social/assets/status/styles/status-header.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [App/Modules/Social/assets/status/styles/status-header.css](../../App/Modules/Social/assets/status/styles/status-header.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | /admin/moderation |
| [App/Modules/Social/assets/status/styles/author-meta.css](../../App/Modules/Social/assets/status/styles/author-meta.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [App/Modules/Social/assets/status/styles/author-meta.css](../../App/Modules/Social/assets/status/styles/author-meta.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | /admin/moderation |
| [assets/ui/content/styles/status-text.css](../../assets/ui/content/styles/status-text.css) | [assets/ui/module.json](../../assets/ui/module.json) |  |
| [App/Modules/Social/assets/status/styles/status-text.css](../../App/Modules/Social/assets/status/styles/status-text.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [App/Modules/Social/assets/status/styles/status-text.css](../../App/Modules/Social/assets/status/styles/status-text.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | /admin/moderation |
| [assets/ui/styles/hashtags.css](../../assets/ui/styles/hashtags.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [App/Modules/Social/assets/status/styles/status-inline-link.css](../../App/Modules/Social/assets/status/styles/status-inline-link.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [App/Modules/Social/assets/status/styles/status-inline-link.css](../../App/Modules/Social/assets/status/styles/status-inline-link.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | /admin/moderation |
| [assets/ui/styles/inline-link-states.css](../../assets/ui/styles/inline-link-states.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/ui/content/styles/status-content.css](../../assets/ui/content/styles/status-content.css) | [assets/ui/module.json](../../assets/ui/module.json) |  |
| [assets/ui/content/styles/status-content.css](../../assets/ui/content/styles/status-content.css) | [assets/ui/module.json](../../assets/ui/module.json) |  |
| [assets/ui/content/styles/discussion-content.css](../../assets/ui/content/styles/discussion-content.css) | [assets/ui/module.json](../../assets/ui/module.json) |  |
| [assets/ui/styles/popovers.css](../../assets/ui/styles/popovers.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [App/Core/Identity/assets/styles/user-menu.css](../../App/Core/Identity/assets/styles/user-menu.css) | [App/Core/Identity/assets/module.json](../../App/Core/Identity/assets/module.json) | public, admin |
| [App/Modules/Social/assets/status/styles/comment-menu.css](../../App/Modules/Social/assets/status/styles/comment-menu.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [App/Modules/Social/assets/status/styles/comment-menu.css](../../App/Modules/Social/assets/status/styles/comment-menu.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | /admin/moderation |
| [assets/ui/styles/context-menu-trigger.css](../../assets/ui/styles/context-menu-trigger.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [App/Modules/Social/assets/status/styles/status-menu.css](../../App/Modules/Social/assets/status/styles/status-menu.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [App/Modules/Social/assets/status/styles/status-menu.css](../../App/Modules/Social/assets/status/styles/status-menu.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | /admin/moderation |
| [assets/ui/styles/context-menu-actions.css](../../assets/ui/styles/context-menu-actions.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/ui/content/styles/status-editing.css](../../assets/ui/content/styles/status-editing.css) | [assets/ui/module.json](../../assets/ui/module.json) |  |
| [assets/ui/content/styles/discussion-editing.css](../../assets/ui/content/styles/discussion-editing.css) | [assets/ui/module.json](../../assets/ui/module.json) |  |
| [App/Modules/Social/assets/status/styles/status-editing.css](../../App/Modules/Social/assets/status/styles/status-editing.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [App/Modules/Social/assets/status/styles/status-editing.css](../../App/Modules/Social/assets/status/styles/status-editing.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | /admin/bots |
| [App/Modules/Social/assets/status/styles/status-editing.css](../../App/Modules/Social/assets/status/styles/status-editing.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | /admin/moderation |
| [assets/ui/styles/avatar.css](../../assets/ui/styles/avatar.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [App/Modules/Social/assets/status/styles/status-avatars.css](../../App/Modules/Social/assets/status/styles/status-avatars.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [App/Modules/Social/assets/status/styles/status-avatars.css](../../App/Modules/Social/assets/status/styles/status-avatars.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | /admin/moderation |
| [assets/ui/styles/avatar-sizes.css](../../assets/ui/styles/avatar-sizes.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [App/Modules/Social/assets/users/styles/profile-avatar.css](../../App/Modules/Social/assets/users/styles/profile-avatar.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [App/Core/Identity/assets/styles/account-security-page.css](../../App/Core/Identity/assets/styles/account-security-page.css) | [App/Core/Identity/assets/module.json](../../App/Core/Identity/assets/module.json) | public, admin |
| [App/Core/Identity/assets/styles/account-security-card.css](../../App/Core/Identity/assets/styles/account-security-card.css) | [App/Core/Identity/assets/module.json](../../App/Core/Identity/assets/module.json) | public, admin |
| [App/Modules/Social/assets/users/styles/profile.css](../../App/Modules/Social/assets/users/styles/profile.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [assets/ui/styles/code-block.css](../../assets/ui/styles/code-block.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [App/Modules/Social/assets/users/styles/profile-details.css](../../App/Modules/Social/assets/users/styles/profile-details.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [App/Core/Identity/assets/styles/avatar-upload.css](../../App/Core/Identity/assets/styles/avatar-upload.css) | [App/Core/Identity/assets/module.json](../../App/Core/Identity/assets/module.json) | public, admin |
| [App/Modules/Social/assets/site/styles/public-content-meta.css](../../App/Modules/Social/assets/site/styles/public-content-meta.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [App/Modules/Social/assets/status/styles/status-detail.css](../../App/Modules/Social/assets/status/styles/status-detail.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [App/Modules/Social/assets/status/styles/status-detail.css](../../App/Modules/Social/assets/status/styles/status-detail.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | /admin/moderation |
| [App/Modules/Social/assets/site/styles/public-sidebar-footer.css](../../App/Modules/Social/assets/site/styles/public-sidebar-footer.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [assets/modules/admin/styles/admin-shell.css](../../assets/modules/admin/styles/admin-shell.css) | [assets/modules/admin/module.json](../../assets/modules/admin/module.json) | admin |
| [assets/ui/styles/brand-logo.css](../../assets/ui/styles/brand-logo.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/modules/admin/styles/admin-navigation-and-content.css](../../assets/modules/admin/styles/admin-navigation-and-content.css) | [assets/modules/admin/module.json](../../assets/modules/admin/module.json) | admin |
| [assets/ui/styles/tabs.css](../../assets/ui/styles/tabs.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/ui/styles/table-identity.css](../../assets/ui/styles/table-identity.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/ui/styles/pagination.css](../../assets/ui/styles/pagination.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/ui/styles/modal.css](../../assets/ui/styles/modal.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/ui/styles/modal-layout.css](../../assets/ui/styles/modal-layout.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/ui/styles/input-icon.css](../../assets/ui/styles/input-icon.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/ui/styles/modal-confirm-buttons.css](../../assets/ui/styles/modal-confirm-buttons.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [App/Core/Installation/assets/styles/install-steps.css](../../App/Core/Installation/assets/styles/install-steps.css) | [App/Core/Installation/assets/module.json](../../App/Core/Installation/assets/module.json) | /install |
| [App/Core/Installation/assets/styles/install-choices.css](../../App/Core/Installation/assets/styles/install-choices.css) | [App/Core/Installation/assets/module.json](../../App/Core/Installation/assets/module.json) | /install |
| [assets/ui/styles/result-list.css](../../assets/ui/styles/result-list.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/ui/styles/cookie-consent.css](../../assets/ui/styles/cookie-consent.css) | [assets/ui/module.json](../../assets/ui/module.json) | public |
| [assets/ui/styles/toasts.css](../../assets/ui/styles/toasts.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/ui/styles/mobile-inputs-and-dialogs.css](../../assets/ui/styles/mobile-inputs-and-dialogs.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/ui/styles/loading.css](../../assets/ui/styles/loading.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [App/Modules/Social/assets/status/styles/status-email-notice-mobile.css](../../App/Modules/Social/assets/status/styles/status-email-notice-mobile.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [assets/ui/styles/navbar-mobile.css](../../assets/ui/styles/navbar-mobile.css) | [assets/ui/module.json](../../assets/ui/module.json) | public |
| [App/Core/Notifications/assets/styles/notification-badge-mobile.css](../../App/Core/Notifications/assets/styles/notification-badge-mobile.css) | [App/Core/Notifications/assets/module.json](../../App/Core/Notifications/assets/module.json) | public |
| [assets/ui/styles/global-search-mobile.css](../../assets/ui/styles/global-search-mobile.css) | [assets/ui/module.json](../../assets/ui/module.json) | public |
| [assets/ui/styles/cards-mobile.css](../../assets/ui/styles/cards-mobile.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [App/Modules/Social/assets/site/styles/public-list-header-mobile.css](../../App/Modules/Social/assets/site/styles/public-list-header-mobile.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [App/Modules/Social/assets/status/styles/feed-switch-mobile.css](../../App/Modules/Social/assets/status/styles/feed-switch-mobile.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [App/Modules/Social/assets/status/styles/home-feed-mobile.css](../../App/Modules/Social/assets/status/styles/home-feed-mobile.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [assets/ui/content/styles/mobile.css](../../assets/ui/content/styles/mobile.css) | [assets/ui/module.json](../../assets/ui/module.json) |  |
| [assets/ui/content/styles/mobile.css](../../assets/ui/content/styles/mobile.css) | [assets/ui/module.json](../../assets/ui/module.json) |  |
| [assets/ui/content/styles/mobile.css](../../assets/ui/content/styles/mobile.css) | [assets/ui/module.json](../../assets/ui/module.json) |  |
| [assets/ui/content/styles/discussion-mobile.css](../../assets/ui/content/styles/discussion-mobile.css) | [assets/ui/module.json](../../assets/ui/module.json) |  |
| [App/Modules/Social/assets/status/styles/status-content-mobile.css](../../App/Modules/Social/assets/status/styles/status-content-mobile.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [App/Modules/Social/assets/status/styles/status-content-mobile.css](../../App/Modules/Social/assets/status/styles/status-content-mobile.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | /admin/moderation |
| [App/Modules/Social/assets/users/styles/following-mobile.css](../../App/Modules/Social/assets/users/styles/following-mobile.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [assets/ui/styles/cookie-consent-mobile.css](../../assets/ui/styles/cookie-consent-mobile.css) | [assets/ui/module.json](../../assets/ui/module.json) | public |
| [assets/ui/styles/containers-mobile.css](../../assets/ui/styles/containers-mobile.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [App/Modules/Social/assets/status/styles/status-feed-mobile.css](../../App/Modules/Social/assets/status/styles/status-feed-mobile.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [assets/modules/admin/styles/admin-content-mobile.css](../../assets/modules/admin/styles/admin-content-mobile.css) | [assets/modules/admin/module.json](../../assets/modules/admin/module.json) | admin |
| [App/Modules/Social/assets/status/styles/home-feed-switch-mobile.css](../../App/Modules/Social/assets/status/styles/home-feed-switch-mobile.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [App/Modules/Social/assets/site/styles/public-list-header-inset-mobile.css](../../App/Modules/Social/assets/site/styles/public-list-header-inset-mobile.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [App/Modules/Social/assets/status/styles/feedback-mobile.css](../../App/Modules/Social/assets/status/styles/feedback-mobile.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [assets/ui/styles/section-desktop.css](../../assets/ui/styles/section-desktop.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/ui/styles/pagination-desktop.css](../../assets/ui/styles/pagination-desktop.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [App/Core/Installation/assets/styles/install-steps-desktop.css](../../App/Core/Installation/assets/styles/install-steps-desktop.css) | [App/Core/Installation/assets/module.json](../../App/Core/Installation/assets/module.json) | /install |
| [assets/ui/styles/split-desktop.css](../../assets/ui/styles/split-desktop.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/modules/admin/styles/admin-lists-desktop.css](../../assets/modules/admin/styles/admin-lists-desktop.css) | [assets/modules/admin/module.json](../../assets/modules/admin/module.json) | admin |
| [assets/ui/styles/field-inline-desktop.css](../../assets/ui/styles/field-inline-desktop.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/ui/styles/modal-confirm-mobile.css](../../assets/ui/styles/modal-confirm-mobile.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/modules/admin/styles/admin-shell-desktop.css](../../assets/modules/admin/styles/admin-shell-desktop.css) | [assets/modules/admin/module.json](../../assets/modules/admin/module.json) | admin |
| [assets/ui/styles/grid-desktop.css](../../assets/ui/styles/grid-desktop.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [App/Modules/Social/assets/users/styles/profile-layout-desktop.css](../../App/Modules/Social/assets/users/styles/profile-layout-desktop.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [App/Modules/Social/assets/site/styles/sidebar-sticky.css](../../App/Modules/Social/assets/site/styles/sidebar-sticky.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [App/Modules/Social/assets/users/styles/profile-main-desktop.css](../../App/Modules/Social/assets/users/styles/profile-main-desktop.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [App/Modules/Social/assets/site/styles/public-layout-desktop.css](../../App/Modules/Social/assets/site/styles/public-layout-desktop.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [assets/ui/styles/modal-workspace-desktop.css](../../assets/ui/styles/modal-workspace-desktop.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/ui/styles/reduced-motion.css](../../assets/ui/styles/reduced-motion.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/ui/styles/resource-text.css](../../assets/ui/styles/resource-text.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/ui/styles/language.css](../../assets/ui/styles/language.css) | [assets/ui/module.json](../../assets/ui/module.json) | public |
| [App/Modules/Social/assets/status/styles/embed-preview.css](../../App/Modules/Social/assets/status/styles/embed-preview.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [App/Modules/Social/assets/site/styles/home-feed-toolbar.css](../../App/Modules/Social/assets/site/styles/home-feed-toolbar.css) | [App/Modules/Social/assets/module.json](../../App/Modules/Social/assets/module.json) | public |
| [assets/ui/styles/tooltip.css](../../assets/ui/styles/tooltip.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/ui/styles/identity-rows.css](../../assets/ui/styles/identity-rows.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |
| [assets/ui/styles/load-more.css](../../assets/ui/styles/load-more.css) | [assets/ui/module.json](../../assets/ui/module.json) | * |

## CSS třídy

Všechny třídy z importního grafu `assets/styles/index.css`. Počet souborů není počet duplicit; zahrnuje stavy a breakpointy.

| Třída | Soubory |
| --- | --- |
| `account-security-card` | [App/Core/Identity/assets/styles/account-security-card.css](../../App/Core/Identity/assets/styles/account-security-card.css) |
| `account-security-page` | [App/Core/Identity/assets/styles/account-security-page.css](../../App/Core/Identity/assets/styles/account-security-page.css) |
| `admin-backdrop` | [assets/modules/admin/styles/admin-shell-desktop.css](../../assets/modules/admin/styles/admin-shell-desktop.css), [assets/modules/admin/styles/admin-shell.css](../../assets/modules/admin/styles/admin-shell.css) |
| `admin-brand` | [assets/modules/admin/styles/admin-navigation-and-content.css](../../assets/modules/admin/styles/admin-navigation-and-content.css), [assets/modules/admin/styles/admin-shell.css](../../assets/modules/admin/styles/admin-shell.css) |
| `admin-content` | [assets/modules/admin/styles/admin-content-mobile.css](../../assets/modules/admin/styles/admin-content-mobile.css), [assets/modules/admin/styles/admin-navigation-and-content.css](../../assets/modules/admin/styles/admin-navigation-and-content.css), [assets/modules/admin/styles/admin-shell-desktop.css](../../assets/modules/admin/styles/admin-shell-desktop.css), [assets/ui/styles/containers-mobile.css](../../assets/ui/styles/containers-mobile.css) |
| `admin-filter-actions` | [assets/modules/admin/styles/admin-content-mobile.css](../../assets/modules/admin/styles/admin-content-mobile.css), [assets/modules/admin/styles/admin-lists-desktop.css](../../assets/modules/admin/styles/admin-lists-desktop.css), [assets/modules/admin/styles/admin-lists.css](../../assets/modules/admin/styles/admin-lists.css) |
| `admin-list-toolbar` | [assets/modules/admin/styles/admin-lists-desktop.css](../../assets/modules/admin/styles/admin-lists-desktop.css), [assets/modules/admin/styles/admin-lists.css](../../assets/modules/admin/styles/admin-lists.css) |
| `admin-main` | [assets/modules/admin/styles/admin-navigation-and-content.css](../../assets/modules/admin/styles/admin-navigation-and-content.css) |
| `admin-menu-btn` | [assets/modules/admin/styles/admin-shell-desktop.css](../../assets/modules/admin/styles/admin-shell-desktop.css) |
| `admin-nav` | [assets/modules/admin/styles/admin-navigation-and-content.css](../../assets/modules/admin/styles/admin-navigation-and-content.css) |
| `admin-nav-chevron` | [assets/modules/admin/styles/admin-navigation-and-content.css](../../assets/modules/admin/styles/admin-navigation-and-content.css) |
| `admin-nav-group` | [assets/modules/admin/styles/admin-navigation-and-content.css](../../assets/modules/admin/styles/admin-navigation-and-content.css) |
| `admin-nav-link` | [assets/modules/admin/styles/admin-navigation-and-content.css](../../assets/modules/admin/styles/admin-navigation-and-content.css) |
| `admin-nav-open` | [assets/modules/admin/styles/admin-shell-desktop.css](../../assets/modules/admin/styles/admin-shell-desktop.css), [assets/modules/admin/styles/admin-shell.css](../../assets/modules/admin/styles/admin-shell.css) |
| `admin-nav-submenu` | [assets/modules/admin/styles/admin-navigation-and-content.css](../../assets/modules/admin/styles/admin-navigation-and-content.css) |
| `admin-nav-summary` | [assets/modules/admin/styles/admin-navigation-and-content.css](../../assets/modules/admin/styles/admin-navigation-and-content.css) |
| `admin-per-page-form` | [assets/modules/admin/styles/admin-lists-desktop.css](../../assets/modules/admin/styles/admin-lists-desktop.css), [assets/modules/admin/styles/admin-lists.css](../../assets/modules/admin/styles/admin-lists.css) |
| `admin-search-form` | [assets/modules/admin/styles/admin-lists-desktop.css](../../assets/modules/admin/styles/admin-lists-desktop.css), [assets/modules/admin/styles/admin-lists.css](../../assets/modules/admin/styles/admin-lists.css) |
| `admin-shell` | [assets/modules/admin/styles/admin-shell-desktop.css](../../assets/modules/admin/styles/admin-shell-desktop.css), [assets/modules/admin/styles/admin-shell.css](../../assets/modules/admin/styles/admin-shell.css) |
| `admin-shell-page` | [assets/modules/admin/styles/admin-shell.css](../../assets/modules/admin/styles/admin-shell.css) |
| `admin-sidebar` | [assets/modules/admin/styles/admin-shell-desktop.css](../../assets/modules/admin/styles/admin-shell-desktop.css), [assets/modules/admin/styles/admin-shell.css](../../assets/modules/admin/styles/admin-shell.css) |
| `admin-sidebar-close` | [assets/modules/admin/styles/admin-shell-desktop.css](../../assets/modules/admin/styles/admin-shell-desktop.css) |
| `admin-sidebar-footer` | [assets/modules/admin/styles/admin-navigation-and-content.css](../../assets/modules/admin/styles/admin-navigation-and-content.css) |
| `admin-sidebar-header` | [assets/modules/admin/styles/admin-shell.css](../../assets/modules/admin/styles/admin-shell.css) |
| `admin-topbar` | [assets/modules/admin/styles/admin-navigation-and-content.css](../../assets/modules/admin/styles/admin-navigation-and-content.css) |
| `admin-topbar-actions` | [assets/modules/admin/styles/admin-navigation-and-content.css](../../assets/modules/admin/styles/admin-navigation-and-content.css) |
| `admin-topbar-title` | [assets/modules/admin/styles/admin-navigation-and-content.css](../../assets/modules/admin/styles/admin-navigation-and-content.css) |
| `admin-user` | [assets/modules/admin/styles/admin-navigation-and-content.css](../../assets/modules/admin/styles/admin-navigation-and-content.css) |
| `alert` | [App/Modules/Social/assets/status/styles/feedback-mobile.css](../../App/Modules/Social/assets/status/styles/feedback-mobile.css), [App/Modules/Social/assets/status/styles/home-feed-switch-mobile.css](../../App/Modules/Social/assets/status/styles/home-feed-switch-mobile.css), [assets/ui/styles/alerts.css](../../assets/ui/styles/alerts.css) |
| `alert-danger` | [assets/ui/styles/alerts.css](../../assets/ui/styles/alerts.css) |
| `alert-info` | [assets/ui/styles/alerts.css](../../assets/ui/styles/alerts.css) |
| `alert-success` | [assets/ui/styles/alerts.css](../../assets/ui/styles/alerts.css) |
| `alert-warning` | [assets/ui/styles/alerts.css](../../assets/ui/styles/alerts.css) |
| `archive-actions` | [App/Modules/Social/assets/site/styles/public-list-header.css](../../App/Modules/Social/assets/site/styles/public-list-header.css) |
| `archive-filter` | [App/Modules/Social/assets/site/styles/home-feed-toolbar.css](../../App/Modules/Social/assets/site/styles/home-feed-toolbar.css) |
| `archive-filter-menu` | [App/Modules/Social/assets/site/styles/public-list-header.css](../../App/Modules/Social/assets/site/styles/public-list-header.css) |
| `archive-filter-option` | [App/Modules/Social/assets/site/styles/public-list-header.css](../../App/Modules/Social/assets/site/styles/public-list-header.css) |
| `avatar` | [App/Modules/Social/assets/status/styles/status-avatars.css](../../App/Modules/Social/assets/status/styles/status-avatars.css), [App/Modules/Social/assets/status/styles/status-content-mobile.css](../../App/Modules/Social/assets/status/styles/status-content-mobile.css), [App/Modules/Social/assets/users/styles/profile.css](../../App/Modules/Social/assets/users/styles/profile.css), [assets/ui/styles/avatar.css](../../assets/ui/styles/avatar.css), [assets/ui/styles/identity-rows.css](../../assets/ui/styles/identity-rows.css) |
| `avatar-creator-toggle` | [App/Core/Identity/assets/styles/avatar-upload.css](../../App/Core/Identity/assets/styles/avatar-upload.css) |
| `avatar-editor` | [App/Core/Identity/assets/styles/avatar-upload.css](../../App/Core/Identity/assets/styles/avatar-upload.css) |
| `avatar-editor-appearance` | [App/Core/Identity/assets/styles/avatar-upload.css](../../App/Core/Identity/assets/styles/avatar-upload.css) |
| `avatar-editor-controls` | [App/Core/Identity/assets/styles/avatar-upload.css](../../App/Core/Identity/assets/styles/avatar-upload.css) |
| `avatar-editor-preview` | [App/Core/Identity/assets/styles/avatar-upload.css](../../App/Core/Identity/assets/styles/avatar-upload.css) |
| `avatar-editor-remove` | [App/Core/Identity/assets/styles/avatar-upload.css](../../App/Core/Identity/assets/styles/avatar-upload.css) |
| `avatar-fallback` | [assets/ui/styles/avatar.css](../../assets/ui/styles/avatar.css) |
| `avatar-sm` | [assets/ui/styles/avatar-sizes.css](../../assets/ui/styles/avatar-sizes.css) |
| `avatar-upload` | [App/Core/Identity/assets/styles/avatar-upload.css](../../App/Core/Identity/assets/styles/avatar-upload.css) |
| `avatar-upload-drop` | [App/Core/Identity/assets/styles/avatar-upload.css](../../App/Core/Identity/assets/styles/avatar-upload.css) |
| `avatar-upload-empty` | [App/Core/Identity/assets/styles/avatar-upload.css](../../App/Core/Identity/assets/styles/avatar-upload.css) |
| `avatar-upload-empty-note` | [App/Core/Identity/assets/styles/avatar-upload.css](../../App/Core/Identity/assets/styles/avatar-upload.css) |
| `avatar-upload-preview` | [App/Core/Identity/assets/styles/avatar-upload.css](../../App/Core/Identity/assets/styles/avatar-upload.css) |
| `avatar-xl` | [App/Modules/Social/assets/users/styles/profile.css](../../App/Modules/Social/assets/users/styles/profile.css), [assets/ui/styles/avatar-sizes.css](../../assets/ui/styles/avatar-sizes.css) |
| `badge` | [App/Modules/Social/assets/site/styles/sidebar-lists.css](../../App/Modules/Social/assets/site/styles/sidebar-lists.css), [assets/modules/admin/styles/admin-lists.css](../../assets/modules/admin/styles/admin-lists.css), [assets/ui/styles/badges.css](../../assets/ui/styles/badges.css), [assets/ui/styles/filter-chips.css](../../assets/ui/styles/filter-chips.css), [assets/ui/styles/reset.css](../../assets/ui/styles/reset.css) |
| `badge-danger` | [assets/ui/styles/badge-danger.css](../../assets/ui/styles/badge-danger.css) |
| `badge-primary` | [assets/ui/styles/badges.css](../../assets/ui/styles/badges.css) |
| `block` | [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css) |
| `brand-logo` | [assets/ui/styles/brand-logo.css](../../assets/ui/styles/brand-logo.css) |
| `btn` | [App/Modules/Social/assets/site/styles/public-list-header-mobile.css](../../App/Modules/Social/assets/site/styles/public-list-header-mobile.css), [App/Modules/Social/assets/status/styles/status-content-mobile.css](../../App/Modules/Social/assets/status/styles/status-content-mobile.css), [App/Modules/Social/assets/status/styles/status-editing.css](../../App/Modules/Social/assets/status/styles/status-editing.css), [App/Modules/Social/assets/status/styles/status-email-notice-mobile.css](../../App/Modules/Social/assets/status/styles/status-email-notice-mobile.css), [App/Modules/Social/assets/status/styles/status-email-notice.css](../../App/Modules/Social/assets/status/styles/status-email-notice.css), [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css), [assets/ui/styles/cards.css](../../assets/ui/styles/cards.css), [assets/ui/styles/cookie-consent-mobile.css](../../assets/ui/styles/cookie-consent-mobile.css), [assets/ui/styles/load-more.css](../../assets/ui/styles/load-more.css), [assets/ui/styles/modal-confirm-buttons.css](../../assets/ui/styles/modal-confirm-buttons.css), [assets/ui/styles/modal-confirm-mobile.css](../../assets/ui/styles/modal-confirm-mobile.css), [assets/ui/styles/popovers.css](../../assets/ui/styles/popovers.css), [assets/ui/styles/reset.css](../../assets/ui/styles/reset.css) |
| `btn-danger` | [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css) |
| `btn-ghost` | [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css) |
| `btn-group` | [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css) |
| `btn-icon` | [App/Modules/Social/assets/status/styles/status-editing.css](../../App/Modules/Social/assets/status/styles/status-editing.css), [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css) |
| `btn-primary` | [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css) |
| `btn-secondary` | [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css) |
| `btn-sm` | [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css) |
| `captcha-provider` | [App/Core/Security/assets/styles/captcha.css](../../App/Core/Security/assets/styles/captcha.css) |
| `captcha-widget` | [App/Core/Security/assets/styles/captcha.css](../../App/Core/Security/assets/styles/captcha.css) |
| `card` | [assets/ui/styles/cards.css](../../assets/ui/styles/cards.css), [assets/ui/styles/containers-mobile.css](../../assets/ui/styles/containers-mobile.css) |
| `card-body` | [assets/ui/styles/cards-mobile.css](../../assets/ui/styles/cards-mobile.css), [assets/ui/styles/cards.css](../../assets/ui/styles/cards.css) |
| `card-footer` | [assets/ui/styles/cards-mobile.css](../../assets/ui/styles/cards-mobile.css), [assets/ui/styles/cards.css](../../assets/ui/styles/cards.css) |
| `card-header` | [App/Core/Notifications/assets/styles/notifications-page.css](../../App/Core/Notifications/assets/styles/notifications-page.css), [App/Modules/Social/assets/site/styles/public-sidebar.css](../../App/Modules/Social/assets/site/styles/public-sidebar.css), [assets/ui/styles/cards-mobile.css](../../assets/ui/styles/cards-mobile.css), [assets/ui/styles/cards.css](../../assets/ui/styles/cards.css) |
| `check-card` | [assets/ui/styles/check-card.css](../../assets/ui/styles/check-card.css) |
| `check-line` | [assets/ui/styles/check-line.css](../../assets/ui/styles/check-line.css) |
| `choice-card` | [App/Core/Installation/assets/styles/install-choices.css](../../App/Core/Installation/assets/styles/install-choices.css) |
| `choice-list` | [App/Core/Installation/assets/styles/install-choices.css](../../App/Core/Installation/assets/styles/install-choices.css) |
| `choice-row` | [App/Core/Installation/assets/styles/install-choices.css](../../App/Core/Installation/assets/styles/install-choices.css) |
| `cluster` | [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css) |
| `code-block` | [assets/ui/styles/code-block.css](../../assets/ui/styles/code-block.css) |
| `container` | [assets/ui/styles/containers-mobile.css](../../assets/ui/styles/containers-mobile.css), [assets/ui/styles/layout.css](../../assets/ui/styles/layout.css) |
| `container-sm` | [assets/ui/styles/layout.css](../../assets/ui/styles/layout.css) |
| `content-image-actions` | [assets/ui/styles/content-images.css](../../assets/ui/styles/content-images.css) |
| `content-image-preview` | [assets/ui/styles/content-images.css](../../assets/ui/styles/content-images.css) |
| `content-image-preview-empty` | [assets/ui/styles/content-images.css](../../assets/ui/styles/content-images.css) |
| `context-menu` | [App/Modules/Social/assets/status/styles/comment-menu.css](../../App/Modules/Social/assets/status/styles/comment-menu.css), [assets/ui/styles/context-menu-trigger.css](../../assets/ui/styles/context-menu-trigger.css) |
| `context-menu-action` | [App/Core/Identity/assets/styles/user-menu.css](../../App/Core/Identity/assets/styles/user-menu.css), [assets/ui/styles/context-menu-actions.css](../../assets/ui/styles/context-menu-actions.css) |
| `cookie-consent` | [assets/ui/styles/cookie-consent-mobile.css](../../assets/ui/styles/cookie-consent-mobile.css), [assets/ui/styles/cookie-consent.css](../../assets/ui/styles/cookie-consent.css) |
| `cookie-consent-actions` | [assets/ui/styles/cookie-consent-mobile.css](../../assets/ui/styles/cookie-consent-mobile.css), [assets/ui/styles/cookie-consent.css](../../assets/ui/styles/cookie-consent.css) |
| `cookie-consent-copy` | [assets/ui/styles/cookie-consent.css](../../assets/ui/styles/cookie-consent.css) |
| `desktop-only` | [assets/ui/styles/visibility.css](../../assets/ui/styles/visibility.css) |
| `disclosure-body` | [assets/ui/styles/disclosure.css](../../assets/ui/styles/disclosure.css) |
| `disclosure-chevron` | [assets/ui/styles/disclosure.css](../../assets/ui/styles/disclosure.css) |
| `disclosure-heading` | [assets/ui/styles/disclosure.css](../../assets/ui/styles/disclosure.css) |
| `disclosure-item` | [assets/ui/styles/disclosure.css](../../assets/ui/styles/disclosure.css) |
| `disclosure-list` | [assets/ui/styles/disclosure.css](../../assets/ui/styles/disclosure.css) |
| `disclosure-summary` | [assets/ui/styles/disclosure.css](../../assets/ui/styles/disclosure.css) |
| `empty-state` | [assets/ui/styles/empty-state.css](../../assets/ui/styles/empty-state.css), [assets/ui/styles/tables-mobile.css](../../assets/ui/styles/tables-mobile.css) |
| `empty-state-compact` | [assets/ui/styles/empty-state.css](../../assets/ui/styles/empty-state.css) |
| `empty-state-icon` | [assets/ui/styles/empty-state.css](../../assets/ui/styles/empty-state.css) |
| `empty-state-label` | [assets/ui/styles/empty-state.css](../../assets/ui/styles/empty-state.css) |
| `feed-switch` | [App/Modules/Social/assets/status/styles/feed-switch-mobile.css](../../App/Modules/Social/assets/status/styles/feed-switch-mobile.css), [App/Modules/Social/assets/status/styles/feed-switch.css](../../App/Modules/Social/assets/status/styles/feed-switch.css) |
| `feed-switch-link` | [App/Modules/Social/assets/site/styles/home-feed-toolbar.css](../../App/Modules/Social/assets/site/styles/home-feed-toolbar.css), [App/Modules/Social/assets/status/styles/feed-switch-mobile.css](../../App/Modules/Social/assets/status/styles/feed-switch-mobile.css), [App/Modules/Social/assets/status/styles/feed-switch.css](../../App/Modules/Social/assets/status/styles/feed-switch.css), [App/Modules/Social/assets/status/styles/home-feed-switch-links.css](../../App/Modules/Social/assets/status/styles/home-feed-switch-links.css), [App/Modules/Social/assets/status/styles/home-feed-switch-mobile.css](../../App/Modules/Social/assets/status/styles/home-feed-switch-mobile.css) |
| `field` | [App/Core/Identity/assets/styles/avatar-upload.css](../../App/Core/Identity/assets/styles/avatar-upload.css), [assets/ui/styles/fields.css](../../assets/ui/styles/fields.css), [assets/ui/styles/filter-menu.css](../../assets/ui/styles/filter-menu.css) |
| `field-error` | [assets/ui/styles/fields.css](../../assets/ui/styles/fields.css) |
| `field-inline` | [assets/ui/styles/field-inline-desktop.css](../../assets/ui/styles/field-inline-desktop.css), [assets/ui/styles/field-inline.css](../../assets/ui/styles/field-inline.css) |
| `filter-chip` | [assets/ui/styles/filter-chips.css](../../assets/ui/styles/filter-chips.css) |
| `filter-menu-panel` | [assets/ui/styles/filter-menu.css](../../assets/ui/styles/filter-menu.css) |
| `following-modal-grid` | [App/Modules/Social/assets/users/styles/following-mobile.css](../../App/Modules/Social/assets/users/styles/following-mobile.css), [App/Modules/Social/assets/users/styles/profile.css](../../App/Modules/Social/assets/users/styles/profile.css) |
| `gap-1` | [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css) |
| `gap-2` | [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css) |
| `global-search` | [assets/ui/styles/global-search-controls.css](../../assets/ui/styles/global-search-controls.css), [assets/ui/styles/global-search.css](../../assets/ui/styles/global-search.css), [assets/ui/styles/navbar-mobile.css](../../assets/ui/styles/navbar-mobile.css) |
| `global-search-control` | [assets/ui/styles/global-search-controls.css](../../assets/ui/styles/global-search-controls.css) |
| `global-search-heading` | [assets/ui/styles/global-search-controls.css](../../assets/ui/styles/global-search-controls.css) |
| `global-search-input` | [assets/ui/styles/global-search-controls.css](../../assets/ui/styles/global-search-controls.css), [assets/ui/styles/global-search-mobile.css](../../assets/ui/styles/global-search-mobile.css) |
| `global-search-results` | [assets/ui/styles/global-search-controls.css](../../assets/ui/styles/global-search-controls.css) |
| `global-search-section` | [assets/ui/styles/global-search-controls.css](../../assets/ui/styles/global-search-controls.css) |
| `grid` | [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css) |
| `grid-span-full` | [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css) |
| `has-editor-suggestions` | [assets/ui/styles/modal.css](../../assets/ui/styles/modal.css) |
| `has-image` | [assets/ui/content/styles/mobile.css](../../assets/ui/content/styles/mobile.css), [assets/ui/content/styles/status-content.css](../../assets/ui/content/styles/status-content.css) |
| `has-modal` | [assets/ui/styles/reset.css](../../assets/ui/styles/reset.css) |
| `has-unread` | [App/Core/Notifications/assets/styles/notification-preview.css](../../App/Core/Notifications/assets/styles/notification-preview.css) |
| `hashtag` | [assets/ui/styles/hashtags.css](../../assets/ui/styles/hashtags.css), [assets/ui/styles/inline-link-states.css](../../assets/ui/styles/inline-link-states.css) |
| `help` | [assets/ui/styles/fields.css](../../assets/ui/styles/fields.css) |
| `hidden` | [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css) |
| `home-feed-app` | [App/Modules/Social/assets/status/styles/home-feed-mobile.css](../../App/Modules/Social/assets/status/styles/home-feed-mobile.css), [App/Modules/Social/assets/status/styles/home-feed-switch-mobile.css](../../App/Modules/Social/assets/status/styles/home-feed-switch-mobile.css), [App/Modules/Social/assets/status/styles/home-feed.css](../../App/Modules/Social/assets/status/styles/home-feed.css) |
| `home-feed-section` | [App/Core/Identity/assets/styles/avatar-upload.css](../../App/Core/Identity/assets/styles/avatar-upload.css), [App/Modules/Social/assets/status/styles/feedback-mobile.css](../../App/Modules/Social/assets/status/styles/feedback-mobile.css) |
| `home-feed-switch` | [App/Modules/Social/assets/site/styles/home-feed-toolbar.css](../../App/Modules/Social/assets/site/styles/home-feed-toolbar.css), [App/Modules/Social/assets/status/styles/home-feed-switch-links.css](../../App/Modules/Social/assets/status/styles/home-feed-switch-links.css), [App/Modules/Social/assets/status/styles/home-feed-switch-mobile.css](../../App/Modules/Social/assets/status/styles/home-feed-switch-mobile.css), [App/Modules/Social/assets/status/styles/home-feed.css](../../App/Modules/Social/assets/status/styles/home-feed.css) |
| `home-feed-toolbar` | [App/Modules/Social/assets/site/styles/home-feed-toolbar.css](../../App/Modules/Social/assets/site/styles/home-feed-toolbar.css) |
| `icon` | [App/Core/Identity/assets/styles/avatar-upload.css](../../App/Core/Identity/assets/styles/avatar-upload.css), [App/Core/Identity/assets/styles/user-menu.css](../../App/Core/Identity/assets/styles/user-menu.css), [App/Modules/Social/assets/status/styles/status-email-notice.css](../../App/Modules/Social/assets/status/styles/status-email-notice.css), [App/Modules/Social/assets/users/styles/profile.css](../../App/Modules/Social/assets/users/styles/profile.css), [assets/ui/content/styles/discussion-editing.css](../../assets/ui/content/styles/discussion-editing.css), [assets/ui/content/styles/status-content.css](../../assets/ui/content/styles/status-content.css), [assets/ui/content/styles/status-editing.css](../../assets/ui/content/styles/status-editing.css), [assets/ui/styles/disclosure.css](../../assets/ui/styles/disclosure.css), [assets/ui/styles/filter-chips.css](../../assets/ui/styles/filter-chips.css), [assets/ui/styles/global-search-controls.css](../../assets/ui/styles/global-search-controls.css), [assets/ui/styles/input-icon.css](../../assets/ui/styles/input-icon.css), [assets/ui/styles/navbar-mobile.css](../../assets/ui/styles/navbar-mobile.css), [assets/ui/styles/navbar.css](../../assets/ui/styles/navbar.css), [assets/ui/styles/reset.css](../../assets/ui/styles/reset.css), [assets/ui/styles/table-identity.css](../../assets/ui/styles/table-identity.css) |
| `icon-lg` | [assets/ui/styles/reset.css](../../assets/ui/styles/reset.css) |
| `identity-copy` | [assets/ui/styles/identity-rows.css](../../assets/ui/styles/identity-rows.css) |
| `identity-copy-truncate` | [assets/ui/styles/identity-rows.css](../../assets/ui/styles/identity-rows.css) |
| `identity-row` | [assets/ui/styles/identity-rows.css](../../assets/ui/styles/identity-rows.css) |
| `identity-row-compact` | [assets/ui/styles/identity-rows.css](../../assets/ui/styles/identity-rows.css) |
| `identity-row-inset` | [assets/ui/styles/identity-rows.css](../../assets/ui/styles/identity-rows.css) |
| `image-preview-contain` | [assets/ui/styles/content-images-contain.css](../../assets/ui/styles/content-images-contain.css) |
| `inline-flex` | [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css) |
| `input` | [assets/ui/styles/fields.css](../../assets/ui/styles/fields.css), [assets/ui/styles/input-icon.css](../../assets/ui/styles/input-icon.css) |
| `input-icon` | [assets/modules/admin/styles/admin-lists.css](../../assets/modules/admin/styles/admin-lists.css), [assets/ui/styles/input-icon.css](../../assets/ui/styles/input-icon.css) |
| `is-active` | [App/Core/Installation/assets/styles/install-steps.css](../../App/Core/Installation/assets/styles/install-steps.css), [App/Modules/Social/assets/site/styles/sidebar-lists.css](../../App/Modules/Social/assets/site/styles/sidebar-lists.css), [assets/ui/content/styles/discussion-content.css](../../assets/ui/content/styles/discussion-content.css), [assets/ui/content/styles/discussion-editing.css](../../assets/ui/content/styles/discussion-editing.css), [assets/ui/content/styles/status-content.css](../../assets/ui/content/styles/status-content.css), [assets/ui/content/styles/status-editing.css](../../assets/ui/content/styles/status-editing.css), [assets/ui/styles/pagination.css](../../assets/ui/styles/pagination.css) |
| `is-child` | [assets/ui/content/styles/discussion-content.css](../../assets/ui/content/styles/discussion-content.css), [assets/ui/content/styles/discussion-mobile.css](../../assets/ui/content/styles/discussion-mobile.css) |
| `is-complete` | [App/Core/Installation/assets/styles/install-steps.css](../../App/Core/Installation/assets/styles/install-steps.css) |
| `is-danger` | [assets/ui/styles/context-menu-actions.css](../../assets/ui/styles/context-menu-actions.css) |
| `is-image-missing` | [assets/ui/content/styles/status-content.css](../../assets/ui/content/styles/status-content.css) |
| `is-image-ready` | [assets/ui/content/styles/status-content.css](../../assets/ui/content/styles/status-content.css) |
| `is-limit` | [assets/ui/content/styles/status-editing.css](../../assets/ui/content/styles/status-editing.css) |
| `is-loading` | [App/Modules/Social/assets/status/styles/composer-loading.css](../../App/Modules/Social/assets/status/styles/composer-loading.css), [assets/ui/styles/load-more.css](../../assets/ui/styles/load-more.css), [assets/ui/styles/loading.css](../../assets/ui/styles/loading.css) |
| `is-reply` | [assets/ui/content/styles/discussion-editing.css](../../assets/ui/content/styles/discussion-editing.css), [assets/ui/content/styles/discussion-mobile.css](../../assets/ui/content/styles/discussion-mobile.css) |
| `is-resource-text` | [assets/ui/styles/resource-text.css](../../assets/ui/styles/resource-text.css) |
| `is-unread` | [App/Core/Notifications/assets/styles/notification-items.css](../../App/Core/Notifications/assets/styles/notification-items.css), [assets/ui/styles/identity-rows.css](../../assets/ui/styles/identity-rows.css) |
| `is-warning` | [assets/ui/content/styles/status-editing.css](../../assets/ui/content/styles/status-editing.css) |
| `justify-end` | [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css) |
| `label` | [App/Modules/Social/assets/status/styles/status-editing.css](../../App/Modules/Social/assets/status/styles/status-editing.css), [assets/ui/styles/field-inline.css](../../assets/ui/styles/field-inline.css), [assets/ui/styles/fields.css](../../assets/ui/styles/fields.css) |
| `language-flag` | [assets/ui/styles/language.css](../../assets/ui/styles/language.css) |
| `language-menu` | [assets/ui/styles/language.css](../../assets/ui/styles/language.css) |
| `language-menu-link` | [assets/ui/styles/language.css](../../assets/ui/styles/language.css) |
| `language-menu-list` | [assets/ui/styles/language.css](../../assets/ui/styles/language.css) |
| `language-suggestion` | [assets/ui/styles/language.css](../../assets/ui/styles/language.css) |
| `language-suggestion-close` | [assets/ui/styles/language.css](../../assets/ui/styles/language.css) |
| `language-suggestion-inner` | [assets/ui/styles/language.css](../../assets/ui/styles/language.css) |
| `link-button` | [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css) |
| `load-more` | [assets/ui/styles/load-more.css](../../assets/ui/styles/load-more.css) |
| `load-more-state` | [assets/ui/styles/load-more.css](../../assets/ui/styles/load-more.css) |
| `m-0` | [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css) |
| `max-w-auth` | [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css) |
| `max-w-auth-sm` | [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css) |
| `max-w-prose` | [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css) |
| `mb-0` | [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css) |
| `md:grid-2` | [assets/ui/styles/grid-desktop.css](../../assets/ui/styles/grid-desktop.css) |
| `md:grid-3` | [assets/ui/styles/grid-desktop.css](../../assets/ui/styles/grid-desktop.css) |
| `md:grid-4` | [assets/ui/styles/grid-desktop.css](../../assets/ui/styles/grid-desktop.css) |
| `mention-link` | [assets/ui/content/styles/status-text.css](../../assets/ui/content/styles/status-text.css), [assets/ui/styles/inline-link-states.css](../../assets/ui/styles/inline-link-states.css) |
| `min-w-0` | [assets/ui/styles/visibility.css](../../assets/ui/styles/visibility.css) |
| `modal` | [assets/ui/styles/mobile-inputs-and-dialogs.css](../../assets/ui/styles/mobile-inputs-and-dialogs.css), [assets/ui/styles/modal-confirm-buttons.css](../../assets/ui/styles/modal-confirm-buttons.css), [assets/ui/styles/modal-confirm-mobile.css](../../assets/ui/styles/modal-confirm-mobile.css), [assets/ui/styles/modal-layout.css](../../assets/ui/styles/modal-layout.css), [assets/ui/styles/modal-workspace-desktop.css](../../assets/ui/styles/modal-workspace-desktop.css), [assets/ui/styles/modal.css](../../assets/ui/styles/modal.css) |
| `modal-backdrop` | [assets/ui/styles/modal.css](../../assets/ui/styles/modal.css) |
| `modal-body` | [assets/ui/styles/mobile-inputs-and-dialogs.css](../../assets/ui/styles/mobile-inputs-and-dialogs.css), [assets/ui/styles/modal-layout.css](../../assets/ui/styles/modal-layout.css), [assets/ui/styles/modal-workspace-desktop.css](../../assets/ui/styles/modal-workspace-desktop.css) |
| `modal-confirm-message` | [assets/ui/styles/modal-layout.css](../../assets/ui/styles/modal-layout.css) |
| `modal-editor-actions` | [assets/ui/styles/modal-layout.css](../../assets/ui/styles/modal-layout.css) |
| `modal-editor-destructive` | [assets/ui/styles/modal-layout.css](../../assets/ui/styles/modal-layout.css) |
| `modal-footer` | [assets/ui/styles/modal-confirm-buttons.css](../../assets/ui/styles/modal-confirm-buttons.css), [assets/ui/styles/modal-confirm-mobile.css](../../assets/ui/styles/modal-confirm-mobile.css), [assets/ui/styles/modal-layout.css](../../assets/ui/styles/modal-layout.css), [assets/ui/styles/modal-workspace-desktop.css](../../assets/ui/styles/modal-workspace-desktop.css) |
| `modal-header` | [assets/ui/styles/modal-layout.css](../../assets/ui/styles/modal-layout.css), [assets/ui/styles/modal-workspace-desktop.css](../../assets/ui/styles/modal-workspace-desktop.css) |
| `modal-panel` | [assets/ui/styles/mobile-inputs-and-dialogs.css](../../assets/ui/styles/mobile-inputs-and-dialogs.css), [assets/ui/styles/modal-layout.css](../../assets/ui/styles/modal-layout.css), [assets/ui/styles/modal-workspace-desktop.css](../../assets/ui/styles/modal-workspace-desktop.css), [assets/ui/styles/modal.css](../../assets/ui/styles/modal.css) |
| `modal-tab-switch` | [assets/ui/styles/modal-layout.css](../../assets/ui/styles/modal-layout.css) |
| `modal-tabs` | [assets/ui/styles/modal-layout.css](../../assets/ui/styles/modal-layout.css), [assets/ui/styles/modal-workspace-desktop.css](../../assets/ui/styles/modal-workspace-desktop.css) |
| `mx-auto` | [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css) |
| `nav-link` | [assets/ui/styles/navbar.css](../../assets/ui/styles/navbar.css), [assets/ui/styles/reset.css](../../assets/ui/styles/reset.css) |
| `nav-link-icon` | [assets/ui/styles/navbar-mobile.css](../../assets/ui/styles/navbar-mobile.css), [assets/ui/styles/navbar.css](../../assets/ui/styles/navbar.css) |
| `nav-links` | [assets/ui/styles/navbar-mobile.css](../../assets/ui/styles/navbar-mobile.css), [assets/ui/styles/navbar.css](../../assets/ui/styles/navbar.css) |
| `navbar` | [assets/ui/styles/navbar.css](../../assets/ui/styles/navbar.css) |
| `navbar-brand` | [assets/ui/styles/navbar-mobile.css](../../assets/ui/styles/navbar-mobile.css), [assets/ui/styles/navbar.css](../../assets/ui/styles/navbar.css) |
| `navbar-inner` | [assets/ui/styles/navbar-mobile.css](../../assets/ui/styles/navbar-mobile.css), [assets/ui/styles/navbar.css](../../assets/ui/styles/navbar.css) |
| `notification-actions` | [App/Core/Notifications/assets/styles/notification-items.css](../../App/Core/Notifications/assets/styles/notification-items.css) |
| `notification-badge` | [App/Core/Notifications/assets/styles/notification-badge-mobile.css](../../App/Core/Notifications/assets/styles/notification-badge-mobile.css), [App/Core/Notifications/assets/styles/notification-preview.css](../../App/Core/Notifications/assets/styles/notification-preview.css) |
| `notification-item` | [App/Core/Notifications/assets/styles/notification-items.css](../../App/Core/Notifications/assets/styles/notification-items.css) |
| `notification-nav-link` | [App/Core/Notifications/assets/styles/notification-nav.css](../../App/Core/Notifications/assets/styles/notification-nav.css), [App/Core/Notifications/assets/styles/notification-preview.css](../../App/Core/Notifications/assets/styles/notification-preview.css) |
| `notification-panel` | [App/Core/Notifications/assets/styles/notification-preview.css](../../App/Core/Notifications/assets/styles/notification-preview.css) |
| `notification-preview-header` | [App/Core/Notifications/assets/styles/notification-preview.css](../../App/Core/Notifications/assets/styles/notification-preview.css) |
| `notification-preview-item` | [App/Core/Notifications/assets/styles/notification-preview.css](../../App/Core/Notifications/assets/styles/notification-preview.css) |
| `notification-preview-list` | [App/Core/Notifications/assets/styles/notification-preview.css](../../App/Core/Notifications/assets/styles/notification-preview.css) |
| `notification-preview-more` | [App/Core/Notifications/assets/styles/notification-preview.css](../../App/Core/Notifications/assets/styles/notification-preview.css) |
| `notifications-list` | [App/Core/Notifications/assets/styles/notifications-page.css](../../App/Core/Notifications/assets/styles/notifications-page.css) |
| `notifications-page` | [App/Core/Notifications/assets/styles/notifications-page.css](../../App/Core/Notifications/assets/styles/notifications-page.css) |
| `pagination` | [assets/ui/styles/modal-layout.css](../../assets/ui/styles/modal-layout.css), [assets/ui/styles/pagination-desktop.css](../../assets/ui/styles/pagination-desktop.css), [assets/ui/styles/pagination.css](../../assets/ui/styles/pagination.css) |
| `pagination-ellipsis` | [assets/ui/styles/pagination.css](../../assets/ui/styles/pagination.css) |
| `pagination-link` | [assets/ui/styles/pagination.css](../../assets/ui/styles/pagination.css) |
| `pagination-list` | [assets/ui/styles/pagination.css](../../assets/ui/styles/pagination.css) |
| `pagination-next` | [assets/ui/styles/pagination.css](../../assets/ui/styles/pagination.css) |
| `pagination-prev` | [assets/ui/styles/pagination.css](../../assets/ui/styles/pagination.css) |
| `pagination-summary` | [assets/ui/styles/pagination.css](../../assets/ui/styles/pagination.css) |
| `popover-panel` | [assets/ui/styles/popovers.css](../../assets/ui/styles/popovers.css) |
| `profile-actions` | [App/Modules/Social/assets/users/styles/profile.css](../../App/Modules/Social/assets/users/styles/profile.css) |
| `profile-avatar-button` | [App/Modules/Social/assets/users/styles/profile-avatar.css](../../App/Modules/Social/assets/users/styles/profile-avatar.css) |
| `profile-card` | [App/Modules/Social/assets/users/styles/profile.css](../../App/Modules/Social/assets/users/styles/profile.css) |
| `profile-card-body` | [App/Modules/Social/assets/users/styles/profile.css](../../App/Modules/Social/assets/users/styles/profile.css) |
| `profile-details` | [App/Modules/Social/assets/users/styles/profile.css](../../App/Modules/Social/assets/users/styles/profile.css) |
| `profile-edit-toggle` | [App/Modules/Social/assets/users/styles/profile.css](../../App/Modules/Social/assets/users/styles/profile.css) |
| `profile-editable-text` | [App/Modules/Social/assets/users/styles/profile-details.css](../../App/Modules/Social/assets/users/styles/profile-details.css) |
| `profile-email-warning` | [App/Modules/Social/assets/users/styles/profile-navigation.css](../../App/Modules/Social/assets/users/styles/profile-navigation.css) |
| `profile-following-link` | [App/Modules/Social/assets/users/styles/profile.css](../../App/Modules/Social/assets/users/styles/profile.css) |
| `profile-following-main` | [App/Modules/Social/assets/users/styles/profile.css](../../App/Modules/Social/assets/users/styles/profile.css) |
| `profile-identity` | [App/Modules/Social/assets/users/styles/profile.css](../../App/Modules/Social/assets/users/styles/profile.css) |
| `profile-identity-main` | [App/Modules/Social/assets/users/styles/profile.css](../../App/Modules/Social/assets/users/styles/profile.css) |
| `profile-layout` | [App/Modules/Social/assets/users/styles/profile-layout-desktop.css](../../App/Modules/Social/assets/users/styles/profile-layout-desktop.css), [App/Modules/Social/assets/users/styles/profile.css](../../App/Modules/Social/assets/users/styles/profile.css) |
| `profile-main` | [App/Core/Identity/assets/styles/avatar-upload.css](../../App/Core/Identity/assets/styles/avatar-upload.css), [App/Modules/Social/assets/users/styles/profile-main-desktop.css](../../App/Modules/Social/assets/users/styles/profile-main-desktop.css), [App/Modules/Social/assets/users/styles/profile.css](../../App/Modules/Social/assets/users/styles/profile.css) |
| `profile-member-since` | [App/Modules/Social/assets/users/styles/profile.css](../../App/Modules/Social/assets/users/styles/profile.css) |
| `profile-name` | [App/Modules/Social/assets/users/styles/profile.css](../../App/Modules/Social/assets/users/styles/profile.css) |
| `profile-nav-link` | [App/Modules/Social/assets/users/styles/profile-navigation.css](../../App/Modules/Social/assets/users/styles/profile-navigation.css) |
| `profile-sidebar` | [App/Modules/Social/assets/users/styles/profile.css](../../App/Modules/Social/assets/users/styles/profile.css) |
| `profile-sidebar-stack` | [App/Modules/Social/assets/site/styles/sidebar-sticky.css](../../App/Modules/Social/assets/site/styles/sidebar-sticky.css), [App/Modules/Social/assets/users/styles/profile.css](../../App/Modules/Social/assets/users/styles/profile.css) |
| `profile-stat` | [App/Modules/Social/assets/users/styles/profile.css](../../App/Modules/Social/assets/users/styles/profile.css) |
| `profile-stat-button` | [App/Modules/Social/assets/users/styles/profile.css](../../App/Modules/Social/assets/users/styles/profile.css) |
| `profile-stats` | [App/Modules/Social/assets/users/styles/profile-details.css](../../App/Modules/Social/assets/users/styles/profile-details.css), [App/Modules/Social/assets/users/styles/profile.css](../../App/Modules/Social/assets/users/styles/profile.css) |
| `public-content-meta` | [App/Modules/Social/assets/site/styles/public-content-meta.css](../../App/Modules/Social/assets/site/styles/public-content-meta.css), [App/Modules/Social/assets/status/styles/author-meta.css](../../App/Modules/Social/assets/status/styles/author-meta.css) |
| `public-layout` | [App/Modules/Social/assets/site/styles/public-layout-desktop.css](../../App/Modules/Social/assets/site/styles/public-layout-desktop.css), [App/Modules/Social/assets/site/styles/public-layout.css](../../App/Modules/Social/assets/site/styles/public-layout.css) |
| `public-list-header` | [App/Modules/Social/assets/site/styles/public-list-header-inset-mobile.css](../../App/Modules/Social/assets/site/styles/public-list-header-inset-mobile.css), [App/Modules/Social/assets/site/styles/public-list-header-mobile.css](../../App/Modules/Social/assets/site/styles/public-list-header-mobile.css), [App/Modules/Social/assets/site/styles/public-list-header.css](../../App/Modules/Social/assets/site/styles/public-list-header.css) |
| `public-list-header-actions` | [App/Modules/Social/assets/site/styles/public-list-header-mobile.css](../../App/Modules/Social/assets/site/styles/public-list-header-mobile.css) |
| `public-sidebar` | [App/Modules/Social/assets/site/styles/public-sidebar.css](../../App/Modules/Social/assets/site/styles/public-sidebar.css), [App/Modules/Social/assets/site/styles/sidebar-sticky.css](../../App/Modules/Social/assets/site/styles/sidebar-sticky.css) |
| `public-sidebar-card` | [App/Modules/Social/assets/site/styles/public-sidebar.css](../../App/Modules/Social/assets/site/styles/public-sidebar.css) |
| `public-sidebar-footer` | [App/Modules/Social/assets/site/styles/public-sidebar-footer.css](../../App/Modules/Social/assets/site/styles/public-sidebar-footer.css) |
| `publishing-text` | [assets/ui/content/styles/status-text.css](../../assets/ui/content/styles/status-text.css) |
| `result-item` | [assets/ui/styles/result-list.css](../../assets/ui/styles/result-list.css) |
| `result-list` | [assets/ui/styles/result-list.css](../../assets/ui/styles/result-list.css) |
| `section` | [App/Modules/Social/assets/site/styles/public-list-header-inset-mobile.css](../../App/Modules/Social/assets/site/styles/public-list-header-inset-mobile.css), [assets/ui/styles/cards-mobile.css](../../assets/ui/styles/cards-mobile.css), [assets/ui/styles/containers-mobile.css](../../assets/ui/styles/containers-mobile.css), [assets/ui/styles/layout.css](../../assets/ui/styles/layout.css), [assets/ui/styles/section-desktop.css](../../assets/ui/styles/section-desktop.css) |
| `select` | [assets/ui/styles/fields.css](../../assets/ui/styles/fields.css), [assets/ui/styles/modal-layout.css](../../assets/ui/styles/modal-layout.css) |
| `sidebar-user-list` | [App/Modules/Social/assets/site/styles/sidebar-lists.css](../../App/Modules/Social/assets/site/styles/sidebar-lists.css) |
| `skip-link` | [assets/ui/styles/reset.css](../../assets/ui/styles/reset.css) |
| `sm:grid-2` | [assets/ui/styles/modal-confirm-mobile.css](../../assets/ui/styles/modal-confirm-mobile.css) |
| `split` | [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css), [assets/ui/styles/cards.css](../../assets/ui/styles/cards.css), [assets/ui/styles/split-desktop.css](../../assets/ui/styles/split-desktop.css) |
| `sr-only` | [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css) |
| `stack` | [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css), [assets/ui/styles/filter-menu.css](../../assets/ui/styles/filter-menu.css), [assets/ui/styles/layout.css](../../assets/ui/styles/layout.css) |
| `stack-gap-12` | [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css) |
| `stack-gap-14` | [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css) |
| `stack-gap-16` | [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css) |
| `stack-gap-24` | [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css) |
| `stack-gap-4` | [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css) |
| `stack-gap-8` | [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css) |
| `status-audio-card` | [assets/ui/content/styles/status-content.css](../../assets/ui/content/styles/status-content.css) |
| `status-audio-player` | [assets/ui/content/styles/status-content.css](../../assets/ui/content/styles/status-content.css) |
| `status-author` | [App/Modules/Social/assets/status/styles/author-meta.css](../../App/Modules/Social/assets/status/styles/author-meta.css), [App/Modules/Social/assets/status/styles/status-header.css](../../App/Modules/Social/assets/status/styles/status-header.css), [App/Modules/Social/assets/status/styles/status-text.css](../../App/Modules/Social/assets/status/styles/status-text.css) |
| `status-body` | [App/Modules/Social/assets/status/styles/status-detail.css](../../App/Modules/Social/assets/status/styles/status-detail.css), [assets/ui/content/styles/status-text.css](../../assets/ui/content/styles/status-text.css) |
| `status-card` | [App/Modules/Social/assets/status/styles/status-feed.css](../../App/Modules/Social/assets/status/styles/status-feed.css) |
| `status-card-body` | [App/Modules/Social/assets/status/styles/status-feed.css](../../App/Modules/Social/assets/status/styles/status-feed.css) |
| `status-comment` | [assets/ui/content/styles/discussion-content.css](../../assets/ui/content/styles/discussion-content.css), [assets/ui/content/styles/discussion-mobile.css](../../assets/ui/content/styles/discussion-mobile.css) |
| `status-comment-author` | [assets/ui/content/styles/discussion-content.css](../../assets/ui/content/styles/discussion-content.css) |
| `status-comment-body` | [assets/ui/content/styles/discussion-editing.css](../../assets/ui/content/styles/discussion-editing.css) |
| `status-comment-bubble` | [assets/ui/content/styles/discussion-content.css](../../assets/ui/content/styles/discussion-content.css) |
| `status-comment-diff-added` | [assets/ui/content/styles/discussion-editing.css](../../assets/ui/content/styles/discussion-editing.css) |
| `status-comment-diff-marker` | [assets/ui/content/styles/discussion-editing.css](../../assets/ui/content/styles/discussion-editing.css) |
| `status-comment-diff-removed` | [assets/ui/content/styles/discussion-editing.css](../../assets/ui/content/styles/discussion-editing.css) |
| `status-comment-editor` | [assets/ui/content/styles/discussion-editing.css](../../assets/ui/content/styles/discussion-editing.css) |
| `status-comment-form` | [assets/ui/content/styles/discussion-editing.css](../../assets/ui/content/styles/discussion-editing.css), [assets/ui/content/styles/discussion-mobile.css](../../assets/ui/content/styles/discussion-mobile.css) |
| `status-comment-heading` | [App/Modules/Social/assets/status/styles/comment-menu.css](../../App/Modules/Social/assets/status/styles/comment-menu.css), [assets/ui/content/styles/discussion-content.css](../../assets/ui/content/styles/discussion-content.css) |
| `status-comment-heading-time` | [assets/ui/content/styles/discussion-content.css](../../assets/ui/content/styles/discussion-content.css) |
| `status-comment-history` | [assets/ui/content/styles/discussion-editing.css](../../assets/ui/content/styles/discussion-editing.css) |
| `status-comment-history-diff` | [assets/ui/content/styles/discussion-editing.css](../../assets/ui/content/styles/discussion-editing.css) |
| `status-comment-history-entry` | [assets/ui/content/styles/discussion-editing.css](../../assets/ui/content/styles/discussion-editing.css) |
| `status-comment-history-meta` | [assets/ui/content/styles/discussion-editing.css](../../assets/ui/content/styles/discussion-editing.css) |
| `status-comment-input` | [assets/ui/content/styles/discussion-editing.css](../../assets/ui/content/styles/discussion-editing.css) |
| `status-comment-input-shell` | [assets/ui/content/styles/discussion-editing.css](../../assets/ui/content/styles/discussion-editing.css) |
| `status-comment-like` | [assets/ui/content/styles/discussion-editing.css](../../assets/ui/content/styles/discussion-editing.css) |
| `status-comment-like-button` | [assets/ui/content/styles/discussion-content.css](../../assets/ui/content/styles/discussion-content.css), [assets/ui/content/styles/discussion-editing.css](../../assets/ui/content/styles/discussion-editing.css) |
| `status-comment-list` | [assets/ui/content/styles/discussion-content.css](../../assets/ui/content/styles/discussion-content.css) |
| `status-comment-main` | [assets/ui/content/styles/discussion-content.css](../../assets/ui/content/styles/discussion-content.css), [assets/ui/content/styles/discussion-editing.css](../../assets/ui/content/styles/discussion-editing.css) |
| `status-comment-meta` | [assets/ui/content/styles/discussion-editing.css](../../assets/ui/content/styles/discussion-editing.css) |
| `status-comment-replies` | [assets/ui/content/styles/discussion-editing.css](../../assets/ui/content/styles/discussion-editing.css) |
| `status-comment-submit` | [assets/ui/content/styles/discussion-editing.css](../../assets/ui/content/styles/discussion-editing.css) |
| `status-comments` | [assets/ui/content/styles/discussion-content.css](../../assets/ui/content/styles/discussion-content.css) |
| `status-comments-thread` | [App/Modules/Social/assets/status/styles/status-detail.css](../../App/Modules/Social/assets/status/styles/status-detail.css) |
| `status-compose-actions` | [App/Modules/Social/assets/status/styles/status-editing.css](../../App/Modules/Social/assets/status/styles/status-editing.css) |
| `status-compose-counter` | [App/Modules/Social/assets/status/styles/status-editing.css](../../App/Modules/Social/assets/status/styles/status-editing.css) |
| `status-compose-footer` | [App/Modules/Social/assets/status/styles/status-editing.css](../../App/Modules/Social/assets/status/styles/status-editing.css) |
| `status-compose-main` | [App/Modules/Social/assets/status/styles/status-content-mobile.css](../../App/Modules/Social/assets/status/styles/status-content-mobile.css), [App/Modules/Social/assets/status/styles/status-editing.css](../../App/Modules/Social/assets/status/styles/status-editing.css) |
| `status-compose-row` | [App/Modules/Social/assets/status/styles/status-avatars.css](../../App/Modules/Social/assets/status/styles/status-avatars.css), [App/Modules/Social/assets/status/styles/status-content-mobile.css](../../App/Modules/Social/assets/status/styles/status-content-mobile.css), [App/Modules/Social/assets/status/styles/status-editing.css](../../App/Modules/Social/assets/status/styles/status-editing.css) |
| `status-composer` | [App/Modules/Social/assets/status/styles/status-content-mobile.css](../../App/Modules/Social/assets/status/styles/status-content-mobile.css), [assets/ui/styles/cards-mobile.css](../../assets/ui/styles/cards-mobile.css) |
| `status-editor-counter` | [assets/ui/content/styles/status-editing.css](../../assets/ui/content/styles/status-editing.css) |
| `status-editor-input` | [App/Modules/Social/assets/status/styles/status-editing.css](../../App/Modules/Social/assets/status/styles/status-editing.css), [assets/ui/content/styles/discussion-editing.css](../../assets/ui/content/styles/discussion-editing.css), [assets/ui/content/styles/mobile.css](../../assets/ui/content/styles/mobile.css), [assets/ui/content/styles/status-editing.css](../../assets/ui/content/styles/status-editing.css) |
| `status-editor-meta` | [App/Modules/Social/assets/status/styles/status-editing.css](../../App/Modules/Social/assets/status/styles/status-editing.css), [assets/ui/content/styles/status-editing.css](../../assets/ui/content/styles/status-editing.css) |
| `status-editor-shell` | [assets/ui/content/styles/status-editing.css](../../assets/ui/content/styles/status-editing.css) |
| `status-editor-suggestion` | [assets/ui/content/styles/status-editing.css](../../assets/ui/content/styles/status-editing.css) |
| `status-editor-suggestion-avatar` | [assets/ui/content/styles/status-editing.css](../../assets/ui/content/styles/status-editing.css) |
| `status-editor-suggestion-text` | [assets/ui/content/styles/status-editing.css](../../assets/ui/content/styles/status-editing.css) |
| `status-editor-suggestions` | [assets/ui/content/styles/status-editing.css](../../assets/ui/content/styles/status-editing.css) |
| `status-email-notice` | [App/Modules/Social/assets/status/styles/status-email-notice-mobile.css](../../App/Modules/Social/assets/status/styles/status-email-notice-mobile.css), [App/Modules/Social/assets/status/styles/status-email-notice.css](../../App/Modules/Social/assets/status/styles/status-email-notice.css) |
| `status-email-notice-copy` | [App/Modules/Social/assets/status/styles/status-email-notice-mobile.css](../../App/Modules/Social/assets/status/styles/status-email-notice-mobile.css), [App/Modules/Social/assets/status/styles/status-email-notice.css](../../App/Modules/Social/assets/status/styles/status-email-notice.css) |
| `status-embed-preview` | [App/Modules/Social/assets/status/styles/embed-preview.css](../../App/Modules/Social/assets/status/styles/embed-preview.css) |
| `status-feed` | [App/Core/Identity/assets/styles/avatar-upload.css](../../App/Core/Identity/assets/styles/avatar-upload.css), [App/Modules/Social/assets/status/styles/status-feed-mobile.css](../../App/Modules/Social/assets/status/styles/status-feed-mobile.css), [App/Modules/Social/assets/status/styles/status-feed.css](../../App/Modules/Social/assets/status/styles/status-feed.css) |
| `status-feed-more` | [App/Modules/Social/assets/status/styles/status-feed.css](../../App/Modules/Social/assets/status/styles/status-feed.css) |
| `status-feed-spacer` | [App/Modules/Social/assets/status/styles/status-feed.css](../../App/Modules/Social/assets/status/styles/status-feed.css) |
| `status-field` | [assets/ui/content/styles/status-editing.css](../../assets/ui/content/styles/status-editing.css) |
| `status-header` | [App/Modules/Social/assets/status/styles/status-avatars.css](../../App/Modules/Social/assets/status/styles/status-avatars.css), [App/Modules/Social/assets/status/styles/status-header.css](../../App/Modules/Social/assets/status/styles/status-header.css) |
| `status-image` | [assets/ui/content/styles/status-editing.css](../../assets/ui/content/styles/status-editing.css) |
| `status-image-alt-wrap` | [assets/ui/content/styles/status-editing.css](../../assets/ui/content/styles/status-editing.css) |
| `status-image-field` | [App/Modules/Social/assets/status/styles/status-editing.css](../../App/Modules/Social/assets/status/styles/status-editing.css), [assets/ui/content/styles/status-editing.css](../../assets/ui/content/styles/status-editing.css) |
| `status-image-link` | [assets/ui/content/styles/status-editing.css](../../assets/ui/content/styles/status-editing.css) |
| `status-image-picker` | [App/Modules/Social/assets/status/styles/status-editing.css](../../App/Modules/Social/assets/status/styles/status-editing.css), [assets/ui/content/styles/status-editing.css](../../assets/ui/content/styles/status-editing.css) |
| `status-image-preview` | [App/Modules/Social/assets/status/styles/status-editing.css](../../App/Modules/Social/assets/status/styles/status-editing.css), [assets/ui/content/styles/status-editing.css](../../assets/ui/content/styles/status-editing.css) |
| `status-indicator` | [assets/ui/styles/status-indicators.css](../../assets/ui/styles/status-indicators.css), [assets/ui/styles/table-identity.css](../../assets/ui/styles/table-identity.css) |
| `status-indicator-danger` | [assets/ui/styles/status-indicators.css](../../assets/ui/styles/status-indicators.css) |
| `status-indicator-success` | [assets/ui/styles/status-indicators.css](../../assets/ui/styles/status-indicators.css) |
| `status-indicator-warning` | [assets/ui/styles/status-indicator-warning.css](../../assets/ui/styles/status-indicator-warning.css) |
| `status-inline-link` | [App/Modules/Social/assets/status/styles/status-inline-link.css](../../App/Modules/Social/assets/status/styles/status-inline-link.css), [assets/ui/styles/inline-link-states.css](../../assets/ui/styles/inline-link-states.css) |
| `status-like-icon-filled` | [assets/ui/content/styles/discussion-content.css](../../assets/ui/content/styles/discussion-content.css) |
| `status-like-icon-outline` | [assets/ui/content/styles/discussion-content.css](../../assets/ui/content/styles/discussion-content.css) |
| `status-link-account` | [assets/ui/content/styles/status-content.css](../../assets/ui/content/styles/status-content.css) |
| `status-link-card` | [assets/ui/content/styles/mobile.css](../../assets/ui/content/styles/mobile.css), [assets/ui/content/styles/status-content.css](../../assets/ui/content/styles/status-content.css) |
| `status-link-copy` | [assets/ui/content/styles/status-content.css](../../assets/ui/content/styles/status-content.css) |
| `status-link-fallback-icon` | [assets/ui/content/styles/status-content.css](../../assets/ui/content/styles/status-content.css) |
| `status-link-icon` | [assets/ui/content/styles/status-content.css](../../assets/ui/content/styles/status-content.css) |
| `status-link-image` | [assets/ui/content/styles/status-content.css](../../assets/ui/content/styles/status-content.css) |
| `status-link-media` | [assets/ui/content/styles/mobile.css](../../assets/ui/content/styles/mobile.css), [assets/ui/content/styles/status-content.css](../../assets/ui/content/styles/status-content.css) |
| `status-link-provider` | [assets/ui/content/styles/status-content.css](../../assets/ui/content/styles/status-content.css) |
| `status-manage` | [assets/ui/content/styles/status-content.css](../../assets/ui/content/styles/status-content.css) |
| `status-manage-icon` | [assets/ui/content/styles/status-content.css](../../assets/ui/content/styles/status-content.css) |
| `status-manage-menu` | [App/Modules/Social/assets/status/styles/status-menu.css](../../App/Modules/Social/assets/status/styles/status-menu.css) |
| `status-manage-top` | [assets/ui/content/styles/status-content.css](../../assets/ui/content/styles/status-content.css) |
| `status-modal-form` | [App/Modules/Social/assets/status/styles/status-editing.css](../../App/Modules/Social/assets/status/styles/status-editing.css) |
| `status-post-modal-content` | [App/Modules/Social/assets/status/styles/status-detail.css](../../App/Modules/Social/assets/status/styles/status-detail.css) |
| `status-reaction` | [assets/ui/content/styles/discussion-content.css](../../assets/ui/content/styles/discussion-content.css), [assets/ui/content/styles/status-content.css](../../assets/ui/content/styles/status-content.css) |
| `status-reactions` | [assets/ui/content/styles/status-content.css](../../assets/ui/content/styles/status-content.css) |
| `status-reply-details` | [assets/ui/content/styles/discussion-editing.css](../../assets/ui/content/styles/discussion-editing.css) |
| `status-reply-form-container` | [assets/ui/content/styles/discussion-editing.css](../../assets/ui/content/styles/discussion-editing.css) |
| `status-social-link` | [assets/ui/content/styles/status-content.css](../../assets/ui/content/styles/status-content.css) |
| `status-tags` | [assets/ui/content/styles/status-text.css](../../assets/ui/content/styles/status-text.css) |
| `status-tags-overflow` | [assets/ui/content/styles/status-text.css](../../assets/ui/content/styles/status-text.css) |
| `status-textarea` | [assets/ui/content/styles/status-editing.css](../../assets/ui/content/styles/status-editing.css) |
| `status-time-button` | [App/Modules/Social/assets/status/styles/status-detail.css](../../App/Modules/Social/assets/status/styles/status-detail.css) |
| `status-translation` | [assets/ui/content/styles/status-text.css](../../assets/ui/content/styles/status-text.css) |
| `status-translation-link` | [assets/ui/content/styles/status-text.css](../../assets/ui/content/styles/status-text.css) |
| `status-translation-output` | [assets/ui/content/styles/status-text.css](../../assets/ui/content/styles/status-text.css) |
| `status-video-card` | [assets/ui/content/styles/status-content.css](../../assets/ui/content/styles/status-content.css) |
| `status-video-copy` | [assets/ui/content/styles/status-content.css](../../assets/ui/content/styles/status-content.css) |
| `status-video-frame` | [assets/ui/content/styles/status-content.css](../../assets/ui/content/styles/status-content.css) |
| `status-video-placeholder` | [assets/ui/content/styles/status-content.css](../../assets/ui/content/styles/status-content.css) |
| `status-video-play` | [assets/ui/content/styles/status-content.css](../../assets/ui/content/styles/status-content.css) |
| `status-video-provider` | [assets/ui/content/styles/status-content.css](../../assets/ui/content/styles/status-content.css) |
| `status-video-source` | [assets/ui/content/styles/status-content.css](../../assets/ui/content/styles/status-content.css) |
| `status-video-thumb` | [assets/ui/content/styles/status-content.css](../../assets/ui/content/styles/status-content.css) |
| `step` | [App/Core/Installation/assets/styles/install-steps.css](../../App/Core/Installation/assets/styles/install-steps.css) |
| `step-marker` | [App/Core/Installation/assets/styles/install-steps.css](../../App/Core/Installation/assets/styles/install-steps.css) |
| `steps` | [App/Core/Installation/assets/styles/install-steps-desktop.css](../../App/Core/Installation/assets/styles/install-steps-desktop.css), [App/Core/Installation/assets/styles/install-steps.css](../../App/Core/Installation/assets/styles/install-steps.css) |
| `tab` | [assets/ui/styles/tabs.css](../../assets/ui/styles/tabs.css) |
| `table` | [assets/ui/styles/table-identity.css](../../assets/ui/styles/table-identity.css), [assets/ui/styles/tables-mobile.css](../../assets/ui/styles/tables-mobile.css), [assets/ui/styles/tables.css](../../assets/ui/styles/tables.css) |
| `table-actions` | [assets/ui/styles/table-identity.css](../../assets/ui/styles/table-identity.css) |
| `table-actions-cell` | [assets/modules/admin/styles/admin-lists.css](../../assets/modules/admin/styles/admin-lists.css), [assets/ui/styles/table-identity.css](../../assets/ui/styles/table-identity.css) |
| `table-identity` | [assets/ui/styles/table-identity.css](../../assets/ui/styles/table-identity.css) |
| `table-identity-body` | [assets/ui/styles/table-identity.css](../../assets/ui/styles/table-identity.css) |
| `table-meta` | [assets/ui/styles/table-identity.css](../../assets/ui/styles/table-identity.css) |
| `table-primary` | [assets/ui/styles/table-identity.css](../../assets/ui/styles/table-identity.css) |
| `table-row-inactive` | [assets/modules/admin/styles/admin-lists.css](../../assets/modules/admin/styles/admin-lists.css) |
| `table-sort` | [assets/modules/admin/styles/admin-lists.css](../../assets/modules/admin/styles/admin-lists.css) |
| `table-sort-indicator` | [assets/modules/admin/styles/admin-lists.css](../../assets/modules/admin/styles/admin-lists.css) |
| `table-text` | [assets/ui/styles/table-identity.css](../../assets/ui/styles/table-identity.css) |
| `table-text-url` | [assets/ui/styles/table-identity.css](../../assets/ui/styles/table-identity.css) |
| `table-wrap` | [assets/ui/styles/tables.css](../../assets/ui/styles/tables.css) |
| `tabs` | [assets/ui/styles/modal-layout.css](../../assets/ui/styles/modal-layout.css), [assets/ui/styles/tabs.css](../../assets/ui/styles/tabs.css) |
| `tag-link` | [assets/ui/content/styles/status-text.css](../../assets/ui/content/styles/status-text.css) |
| `text-2xl` | [App/Modules/Social/assets/site/styles/public-list-header-mobile.css](../../App/Modules/Social/assets/site/styles/public-list-header-mobile.css), [App/Modules/Social/assets/site/styles/public-list-header.css](../../App/Modules/Social/assets/site/styles/public-list-header.css), [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css) |
| `text-base` | [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css) |
| `text-danger` | [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css), [assets/ui/styles/table-identity.css](../../assets/ui/styles/table-identity.css) |
| `text-lg` | [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css) |
| `text-muted` | [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css) |
| `text-primary` | [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css) |
| `text-xl` | [assets/ui/styles/buttons.css](../../assets/ui/styles/buttons.css) |
| `textarea` | [assets/ui/styles/fields.css](../../assets/ui/styles/fields.css) |
| `toast` | [App/Modules/Social/assets/status/styles/feedback-mobile.css](../../App/Modules/Social/assets/status/styles/feedback-mobile.css), [assets/ui/styles/toasts.css](../../assets/ui/styles/toasts.css) |
| `toast-body` | [assets/ui/styles/toasts.css](../../assets/ui/styles/toasts.css) |
| `toast-close` | [assets/ui/styles/toasts.css](../../assets/ui/styles/toasts.css) |
| `toast-danger` | [assets/ui/styles/toasts.css](../../assets/ui/styles/toasts.css) |
| `toast-icon` | [assets/ui/styles/toasts.css](../../assets/ui/styles/toasts.css) |
| `toast-info` | [assets/ui/styles/toasts.css](../../assets/ui/styles/toasts.css) |
| `toast-stack` | [App/Modules/Social/assets/status/styles/feedback-mobile.css](../../App/Modules/Social/assets/status/styles/feedback-mobile.css), [assets/ui/styles/toasts.css](../../assets/ui/styles/toasts.css) |
| `toast-success` | [assets/ui/styles/toasts.css](../../assets/ui/styles/toasts.css) |
| `toast-warning` | [assets/ui/styles/toasts.css](../../assets/ui/styles/toasts.css) |
| `tooltip` | [assets/ui/styles/tooltip.css](../../assets/ui/styles/tooltip.css) |
| `topic-link` | [App/Modules/Social/assets/site/styles/sidebar-lists.css](../../App/Modules/Social/assets/site/styles/sidebar-lists.css) |
| `topic-list` | [App/Modules/Social/assets/site/styles/sidebar-lists.css](../../App/Modules/Social/assets/site/styles/sidebar-lists.css) |
| `topic-name` | [App/Modules/Social/assets/site/styles/sidebar-lists.css](../../App/Modules/Social/assets/site/styles/sidebar-lists.css) |
| `user-menu` | [App/Core/Identity/assets/styles/user-menu.css](../../App/Core/Identity/assets/styles/user-menu.css) |
| `user-menu-identity` | [App/Core/Identity/assets/styles/user-menu.css](../../App/Core/Identity/assets/styles/user-menu.css) |
| `user-menu-popover` | [App/Core/Identity/assets/styles/user-menu.css](../../App/Core/Identity/assets/styles/user-menu.css) |
| `user-menu-theme` | [App/Core/Identity/assets/styles/user-menu.css](../../App/Core/Identity/assets/styles/user-menu.css) |
| `user-menu-theme-switch` | [App/Core/Identity/assets/styles/user-menu.css](../../App/Core/Identity/assets/styles/user-menu.css) |
| `user-menu-trigger` | [App/Core/Identity/assets/styles/user-menu.css](../../App/Core/Identity/assets/styles/user-menu.css) |
