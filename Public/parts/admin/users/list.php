<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$filters = is_array($filters ?? null) ? $filters : [];
$users = is_array($users ?? null) ? $users : [];
$pagination = is_array($pagination ?? null) ? $pagination : [];
$params = is_array($params ?? null) ? $params : [];
$roles = is_array($roles ?? null) ? $roles : [];
$statuses = is_array($statuses ?? null) ? $statuses : [];
$hasFilters = (bool) ($has_filters ?? false);
?>
<div class="stack stack-gap-14">
    <div class="admin-list-toolbar">
        <form class="admin-search-form" action="<?= e(tc_admin_users_api_url([], false)) ?>" method="get" data-ajax-form data-ajax-target="#users-list" data-history="/admin/users">
            <input type="hidden" name="view" value="html">
            <?php foreach ($filters as $key => $value): ?>
                <?php if ($key !== 'q' && $value !== ''): ?>
                    <input type="hidden" name="<?= e((string) $key) ?>" value="<?= e((string) $value) ?>">
                <?php endif; ?>
            <?php endforeach; ?>
            <input type="hidden" name="per_page" value="<?= e((string) admin_per_page()) ?>">
            <label class="sr-only" for="users-search"><?= et('common.search') ?></label>
            <span class="input-icon">
                <?= icon('search') ?>
                <input class="input" id="users-search" type="search" name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>" placeholder="<?= et('users.search_placeholder') ?>">
            </span>
            <button class="btn btn-secondary admin-search-submit" type="submit"><?= icon('search') ?> <span><?= et('common.search') ?></span></button>
        </form>
        <?php if ($hasFilters): ?>
            <div class="admin-filter-actions">
                <a class="btn btn-ghost" href="<?= e(tc_admin_users_api_url(['per_page' => admin_per_page(), 'page' => 1], false)) ?>" data-ajax data-ajax-target="#users-list" data-history="<?= e(admin_list_url('/admin/users', ['per_page' => admin_per_page(), 'page' => 1], false)) ?>">
                    <?= icon('close') ?> <span><?= et('common.clear_filters') ?></span>
                </a>
            </div>
        <?php endif; ?>
        <?= part('admin/per-page', [
            'path' => '/api/admin/users',
            'target' => '#users-list',
            'params' => $params,
            'selected' => (int) ($pagination['per_page'] ?? admin_per_page()),
            'history_path' => '/admin/users',
        ]) ?>
    </div>

    <?php if ($users === []): ?>
        <div class="alert alert-info"><?= $hasFilters ? et('users.empty_filtered') : et('users.empty') ?></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th><?= et('users.table_user') ?></th>
                        <th><?= et('common.role') ?></th>
                        <th><?= et('common.status') ?></th>
                        <th><?= et('common.updated') ?></th>
                        <th><?= et('common.actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <?php $id = (int) ($user['id'] ?? 0); ?>
                        <?php $isSuperAdmin = tc_admin_user_is_super_admin($user); ?>
                        <tr>
                            <td><strong>@<?= e((string) ($user['username'] ?? '')) ?></strong></td>
                            <td><?= e((string) ($roles[$user['role']] ?? $user['role'])) ?></td>
                            <td><?= part('admin/user-status-badge', ['status' => (string) ($user['status'] ?? '')]) ?></td>
                            <td>
                                <time class="table-meta" datetime="<?= e(tc_admin_datetime_iso((string) ($user['updated_at'] ?? ''))) ?>">
                                    <?= e(tc_admin_datetime((string) ($user['updated_at'] ?? ''))) ?>
                                </time>
                            </td>
                            <td>
                                <div class="table-actions">
                                    <button class="btn btn-sm btn-ghost btn-icon" type="button" data-modal-open="user-edit-<?= e($id) ?>" aria-label="<?= et('users.edit_user', ['username' => (string) ($user['username'] ?? '')]) ?>" title="<?= et('common.edit') ?>">
                                        <?= icon('edit') ?>
                                    </button>
                                    <?php if (!$isSuperAdmin): ?>
                                        <form class="inline-flex" action="<?= e(tc_admin_users_api_url(['id' => $id])) ?>" method="post" data-ajax-form data-ajax-target="#users-list" data-confirm="<?= et('users.delete_confirm', ['username' => (string) ($user['username'] ?? '')]) ?>" data-confirm-title="<?= et('users.delete_title') ?>" data-confirm-ok="<?= et('common.delete') ?>" data-confirm-cancel="<?= et('common.cancel') ?>" data-confirm-variant="danger">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="_method" value="DELETE">
                                            <button class="btn btn-sm btn-ghost btn-icon text-danger" type="submit" aria-label="<?= et('common.delete') ?>" title="<?= et('common.delete') ?>"><?= icon('trash') ?></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= part('admin/pagination', [
            'pagination' => $pagination,
            'path' => '/api/admin/users',
            'target' => '#users-list',
            'params' => $params,
            'page_name' => 'page',
            'window' => 2,
            'history_path' => '/admin/users',
        ]) ?>
        <?php foreach ($users as $user): ?>
            <?= render('modals/user-edit', [
                'user' => $user,
                'roles' => $roles,
                'statuses' => $statuses,
            ]) ?>
        <?php endforeach; ?>
    <?php endif; ?>

    <?= render('modals/user-create') ?>
    <?= render('modals/user-filter') ?>
</div>
