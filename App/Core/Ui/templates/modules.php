<?php
declare(strict_types=1);
/** @var array<string, mixed> $snapshot */
/** @var list<array<string, mixed>> $rows */
use TinyCat\Support\Components;
use TinyCat\Support\Html;
use TinyCat\Support\Localization;
?>
<div class="stack stack-gap-16">
    <p class="text-muted m-0"><?= Html::et('modules.help') ?></p>
    <?php if (!$snapshot['manageable']): ?><?= Components::render('ui/notice', ['label' => Localization::t('modules.unavailable'), 'tone' => 'warning']) ?><?php endif; ?>
    <?php foreach ($snapshot['groups'] as $group): ?>
    <section class="card"><div class="card-body cluster gap-2">
        <div class="min-w-0"><strong><?= Html::e($group['name']) ?> <span class="text-muted"><?= (int) $group['active_count'] ?> / <?= count($group['modules']) ?></span></strong><p class="table-meta table-text m-0"><?= Html::e($group['names']) ?></p></div>
        <div class="cluster">
        <?php foreach ($group['active'] ? [false] : ($group['partial'] ? [true, false] : [true]) as $enable): ?>
        <form action="/api/modules/activation" method="post" data-ajax-form data-confirm="<?= Html::et('modules.confirm') ?>">
            <?= \TinyCat\Core\Security\Csrf::field() ?><input type="hidden" name="id" value="<?= Html::e($group['id']) ?>"><input type="hidden" name="revision" value="<?= Html::e($snapshot['revision']) ?>"><input type="hidden" name="enabled" value="<?= $enable ? '1' : '0' ?>">
            <button type="submit" class="btn btn-secondary btn-sm"<?= !$snapshot['manageable'] ? ' disabled' : '' ?>><?= Html::et($enable ? 'modules.enable_group' : 'modules.disable_group') ?></button>
        </form>
        <?php endforeach; ?></div>
    </div></section>
    <?php endforeach; ?>
    <div class="table-wrap"><table class="table"><thead><tr><th><?= Html::et('modules.module') ?></th><th><?= Html::et('common.status') ?></th><th><?= Html::et('modules.dependencies') ?></th><th class="table-actions-cell"><?= Html::et('common.actions') ?></th></tr></thead><tbody>
    <?php foreach ($rows as $item): ?>
    <tr>
        <td><div class="table-identity"><span class="table-identity-icon"><?= Html::icon('settings') ?></span><div class="table-identity-body"><strong class="table-primary"><?= Html::e($item['name']) ?></strong><span class="table-meta"><?= Html::e($item['id']) ?><?php if ($item['package_version'] !== ''): ?> ? v<?= Html::e($item['package_version']) ?><?php endif; ?></span></div></div></td>
        <td><?= Components::render('ui/status-badge', ['label' => Localization::t($item['active'] ? 'modules.active' : 'modules.inactive'), 'tone' => $item['active'] ? 'ok' : 'neutral']) ?><?php if ($item['required']): ?><div class="table-meta"><?= Html::et('modules.required') ?></div><?php endif; ?></td>
        <td><span class="table-text"><?= Html::e($item['dependencies'] ?: '—') ?></span><?php if ($item['blocked'] !== ''): ?><div class="table-meta"><?= Html::et('modules.needed_by', ['modules' => $item['blocked']]) ?></div><?php endif; ?></td>
        <td class="table-actions-cell"><div class="table-actions">
            <form action="/api/modules/activation" method="post" data-ajax-form data-confirm="<?= Html::et('modules.confirm') ?>">
                <?= \TinyCat\Core\Security\Csrf::field() ?><input type="hidden" name="id" value="<?= Html::e($item['id']) ?>"><input type="hidden" name="revision" value="<?= Html::e($snapshot['revision']) ?>"><input type="hidden" name="enabled" value="<?= $item['active'] ? '0' : '1' ?>">
                <button class="btn btn-secondary btn-sm" type="submit"<?= !$snapshot['manageable'] || ($item['active'] && ($item['required'] || $item['dependents'] !== [])) ? ' disabled' : '' ?>><?= Html::et($item['active'] ? 'modules.disable' : 'modules.enable') ?></button>
            </form>
        </div></td>
    </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>
