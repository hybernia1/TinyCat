<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

if (!(bool) config('install.installed', false)) {
    redirect('/install');
}

require_auth();

if (get('api') === 'list') {
    api_ok(tc_admin_menu_response_payload());
}

if (get('api') === 'create') {
    api_endpoint('POST', static function (): never {
        csrf_require();
        $payload = tc_admin_menu_payload();
        $id = (int) insert('menu_items', tc_admin_menu_insert_payload($payload));

        api_created(tc_admin_menu_response_payload($id), t('menu.messages.created'));
    });
}

if (get('api') === 'update') {
    api_endpoint('PATCH', static function (): never {
        csrf_require();
        $id = max(1, (int) get('id'));

        if (!tc_admin_menu_exists($id)) {
            api_error(t('menu.messages.not_found'), 404, 'menu_item_not_found');
        }

        update('menu_items', tc_admin_menu_update_payload(tc_admin_menu_payload()), ['id' => $id]);

        api_ok(tc_admin_menu_response_payload($id), t('menu.messages.saved'));
    });
}

if (get('api') === 'delete') {
    api_endpoint('DELETE', static function (): never {
        csrf_require();
        $id = max(1, (int) get('id'));

        if (!tc_admin_menu_exists($id)) {
            api_error(t('menu.messages.not_found'), 404, 'menu_item_not_found');
        }

        delete('menu_items', ['id' => $id]);

        api_ok(tc_admin_menu_response_payload(), t('menu.messages.deleted'));
    });
}

if (get('api') === 'reorder') {
    api_endpoint('POST', static function (): never {
        csrf_require();
        tc_admin_menu_reorder((string) post('order', ''));

        api_ok(tc_admin_menu_response_payload(), t('menu.messages.reordered'));
    });
}

layout('layout', [
    'title' => t('menu.meta_title'),
    'current' => '/admin/menu',
    'actions' => tc_admin_menu_actions(),
], static function (): void {
    ?>
    <section class="card">
        <div class="card-header">
            <h2 class="text-lg m-0 cluster gap-2"><?= icon('menu') ?> <?= et('menu.list_title') ?></h2>
        </div>
        <div class="card-body" id="menu-list">
            <?= tc_admin_menu_html() ?>
        </div>
    </section>
    <?php
});

function tc_admin_menu_actions(): string
{
    return '<button class="btn btn-primary btn-sm" type="button" data-modal-open="menu-create-modal">' . icon('plus') . ' <span>' . et('menu.new_item') . '</span></button>';
}

function tc_admin_menu_api_url(string $api, array $params = []): string
{
    $query = [
        'api' => $api,
        'view' => 'html',
    ];

    foreach ($params as $key => $value) {
        if ($value !== '' && $value !== null) {
            $query[$key] = $value;
        }
    }

    return '/admin/menu?' . http_build_query($query);
}

function tc_admin_menu_targets(): array
{
    return [
        '_self' => t('menu.targets.self'),
        '_blank' => t('menu.targets.blank'),
    ];
}

function tc_admin_menu_items(): array
{
    return all('SELECT * FROM menu_items ORDER BY position ASC, id ASC');
}

function tc_admin_menu_response_payload(?int $id = null): array
{
    return wants_partial()
        ? ['html' => tc_admin_menu_html()]
        : tc_admin_menu_api_payload($id);
}

function tc_admin_menu_api_payload(?int $id = null): array
{
    $items = array_map('tc_admin_menu_resource', tc_admin_menu_items());
    $payload = [
        'items' => $items,
        'count' => count($items),
    ];

    if ($id !== null) {
        $payload['id'] = $id;
        $payload['item'] = tc_admin_menu_resource(find('menu_items', ['id' => $id]) ?? []);
    }

    return $payload;
}

function tc_admin_menu_resource(array $item): array
{
    if ($item === []) {
        return [];
    }

    return [
        'id' => (int) ($item['id'] ?? 0),
        'label' => (string) ($item['label'] ?? ''),
        'url' => (string) ($item['url'] ?? ''),
        'target' => (string) ($item['target'] ?? '_self'),
        'is_active' => (bool) ($item['is_active'] ?? true),
        'position' => (int) ($item['position'] ?? 0),
        'created_at' => (string) ($item['created_at'] ?? ''),
        'updated_at' => (string) ($item['updated_at'] ?? ''),
    ];
}

function tc_admin_menu_exists(int $id): bool
{
    return total('menu_items', ['id' => $id]) > 0;
}

function tc_admin_menu_payload(): array
{
    $data = api_validated([
        'label' => 'required|string|max:120',
        'url' => 'required|string|max:255',
        'target' => 'nullable|string|max:20',
    ]);

    $url = trim((string) $data['url']);
    $target = (string) ($data['target'] ?? '_self');

    if (!tc_admin_menu_valid_url($url)) {
        api_validation(['url' => [t('menu.messages.invalid_url')]]);
    }

    if (!array_key_exists($target, tc_admin_menu_targets())) {
        $target = '_self';
    }

    $activeInput = input('is_active', null);

    return [
        'label' => trim((string) $data['label']),
        'url' => $url,
        'target' => $target,
        'is_active' => $activeInput === null ? 0 : (int) (bool) $activeInput,
    ];
}

function tc_admin_menu_insert_payload(array $payload): array
{
    return [
        'label' => (string) $payload['label'],
        'url' => (string) $payload['url'],
        'target' => (string) $payload['target'],
        'is_active' => (int) $payload['is_active'],
        'position' => tc_admin_menu_next_position(),
    ];
}

function tc_admin_menu_update_payload(array $payload): array
{
    return [
        'label' => (string) $payload['label'],
        'url' => (string) $payload['url'],
        'target' => (string) $payload['target'],
        'is_active' => (int) $payload['is_active'],
    ];
}

function tc_admin_menu_valid_url(string $url): bool
{
    if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
        return false;
    }

    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
        return true;
    }

    if (str_starts_with($url, '#') && strlen($url) > 1) {
        return true;
    }

    return preg_match('/^(https?:\/\/|mailto:|tel:)/i', $url) === 1;
}

function tc_admin_menu_next_position(): int
{
    return ((int) val('SELECT COALESCE(MAX(position), 0) FROM menu_items')) + 10;
}

function tc_admin_menu_reorder(string $order): void
{
    $ids = array_values(array_unique(array_filter(
        array_map('intval', explode(',', $order)),
        static fn (int $id): bool => $id > 0
    )));

    if ($ids === []) {
        api_validation(['order' => [t('menu.messages.order_required')]]);
    }

    $existing = array_map(
        static fn (array $row): int => (int) $row['id'],
        all('SELECT id FROM menu_items ORDER BY position ASC, id ASC')
    );
    $known = array_flip($existing);
    $ordered = [];

    foreach ($ids as $id) {
        if (isset($known[$id])) {
            $ordered[] = $id;
        }
    }

    foreach ($existing as $id) {
        if (!in_array($id, $ordered, true)) {
            $ordered[] = $id;
        }
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $position = 10;

        foreach ($ordered as $id) {
            update('menu_items', ['position' => $position], ['id' => $id]);
            $position += 10;
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

function tc_admin_menu_html(): string
{
    $items = tc_admin_menu_items();
    $orderValue = implode(',', array_map(static fn (array $item): string => (string) $item['id'], $items));

    ob_start();
    ?>
    <div class="stack" style="--stack-gap: 14px;">
        <?php if ($items === []): ?>
            <div class="alert alert-info"><?= et('menu.empty') ?></div>
        <?php else: ?>
            <div class="sortable-list menu-list" data-sortable data-sortable-input="#menu-order">
                <?php foreach ($items as $index => $item): ?>
                    <?= tc_admin_menu_item_html($item, $index + 1) ?>
                <?php endforeach; ?>
            </div>
            <form class="choice-row justify-end" action="<?= e(tc_admin_menu_api_url('reorder')) ?>" method="post" data-ajax-form data-ajax-target="#menu-list">
                <?= csrf_field() ?>
                <input type="hidden" id="menu-order" name="order" value="<?= e($orderValue) ?>">
                <button class="btn btn-secondary" type="submit"><?= icon('save') ?> <span><?= et('menu.save_order') ?></span></button>
            </form>
            <?php foreach ($items as $item): ?>
                <?= tc_admin_menu_modal($item) ?>
            <?php endforeach; ?>
        <?php endif; ?>
        <?= tc_admin_menu_create_modal() ?>
    </div>
    <?php

    return trim((string) ob_get_clean());
}

function tc_admin_menu_item_html(array $item, int $index): string
{
    $id = (int) $item['id'];
    $label = (string) $item['label'];
    $url = (string) $item['url'];
    $target = (string) ($item['target'] ?? '_self');
    $newWindow = $target === '_blank';
    $active = (bool) $item['is_active'];

    ob_start();
    ?>
    <article class="sortable-item menu-list-item" data-sortable-item data-id="<?= e($id) ?>" draggable="true">
        <span class="drag-handle" title="<?= et('menu.drag_handle') ?>"><?= icon('menu') ?></span>
        <span class="menu-item-index" data-sortable-index><?= e($index) ?></span>
        <div class="menu-item-main">
            <div class="menu-item-title">
                <strong><?= e($label) ?></strong>
                <span class="badge<?= $active ? ' badge-primary' : '' ?>"><?= $active ? et('menu.statuses.active') : et('menu.statuses.hidden') ?></span>
            </div>
            <a class="menu-item-url" href="<?= e($url) ?>"<?= $newWindow ? ' target="_blank" rel="noopener"' : '' ?>><?= e($url) ?></a>
            <div class="menu-item-meta">
                <span class="table-meta"><?= e(tc_admin_menu_target_label($target)) ?></span>
            </div>
        </div>
        <div class="table-actions">
            <button class="btn btn-sm btn-ghost btn-icon" type="button" data-modal-open="menu-edit-<?= e($id) ?>" aria-label="<?= et('menu.edit_item', ['label' => $label]) ?>" title="<?= et('common.edit') ?>">
                <?= icon('edit') ?>
            </button>
            <form class="inline-flex" action="<?= e(tc_admin_menu_api_url('delete', ['id' => $id])) ?>" method="post" data-ajax-form data-ajax-target="#menu-list" data-confirm="<?= et('menu.delete_confirm', ['label' => $label]) ?>" data-confirm-title="<?= et('menu.delete_title') ?>" data-confirm-ok="<?= et('common.delete') ?>" data-confirm-cancel="<?= et('common.cancel') ?>" data-confirm-variant="danger">
                <?= csrf_field() ?>
                <input type="hidden" name="_method" value="DELETE">
                <button class="btn btn-sm btn-ghost btn-icon text-danger" type="submit" aria-label="<?= et('common.delete') ?>" title="<?= et('common.delete') ?>">
                    <?= icon('trash') ?>
                </button>
            </form>
        </div>
    </article>
    <?php

    return trim((string) ob_get_clean());
}

function tc_admin_menu_target_label(string $target): string
{
    $targets = tc_admin_menu_targets();

    return (string) ($targets[$target] ?? $targets['_self']);
}

function tc_admin_menu_form_fields(?array $item = null): string
{
    $target = (string) ($item['target'] ?? '_self');
    $active = $item === null ? true : (bool) ($item['is_active'] ?? true);

    ob_start();
    ?>
    <div class="grid sm:grid-2">
        <label class="field">
            <span class="label"><?= et('menu.label') ?></span>
            <input class="input" name="label" value="<?= e((string) ($item['label'] ?? '')) ?>" required>
        </label>
        <label class="field">
            <span class="label"><?= et('menu.target') ?></span>
            <select class="select" name="target">
                <?php foreach (tc_admin_menu_targets() as $value => $label): ?>
                    <option value="<?= e($value) ?>"<?= $target === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
    <label class="field">
        <span class="label"><?= et('menu.url') ?></span>
        <input class="input" name="url" value="<?= e((string) ($item['url'] ?? '')) ?>" placeholder="/kontakt" required>
    </label>
    <label class="check-line">
        <input type="checkbox" name="is_active" value="1"<?= $active ? ' checked' : '' ?>>
        <span><?= et('menu.is_active') ?></span>
    </label>
    <?php

    return trim((string) ob_get_clean());
}

function tc_admin_menu_create_modal(): string
{
    return render('modals/menu-create');
}

function tc_admin_menu_modal(array $item): string
{
    return render('modals/menu-edit', ['item' => $item]);
}
