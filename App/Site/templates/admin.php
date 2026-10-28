<?php
declare(strict_types=1);
/** @var string $content */
/** @var string $actions */
/** @var string $adminIdentity */
/** @var string $appName */
/** @var array<mixed> $nav */
/** @var string $siteLogoUrl */
/** @var string $title */
/** @var array<mixed> $authUser */
/** @var string $profileUrl */
/** @var bool $profileNeedsEmail */
/** @var string $userMenu */
?>
        <div class="admin-shell" data-admin-shell>
            <aside class="admin-sidebar" id="admin-sidebar" data-admin-sidebar>
                <div class="admin-sidebar-header">
                    <a class="admin-brand" href="/">
                        <?php if ($siteLogoUrl !== ''): ?>
                            <img class="brand-logo" src="<?= \TinyCat\Support\Html::e($siteLogoUrl) ?>" alt="<?= \TinyCat\Support\Html::et('common.site_logo_alt', ['site' => $appName]) ?>">
                        <?php else: ?>
                            <?= \TinyCat\Support\Html::icon('dashboard', 'icon-lg') ?>
                        <?php endif; ?>
                        <span>
                            <strong<?= $siteLogoUrl !== '' ? ' aria-hidden="true"' : '' ?>><?= \TinyCat\Support\Html::e($appName) ?></strong>
                            <small><?= \TinyCat\Support\Html::et('common.admin') ?></small>
                        </span>
                    </a>
                    <button class="btn btn-icon admin-sidebar-close" type="button" data-admin-nav-toggle aria-controls="admin-sidebar" aria-expanded="false" aria-label="<?= \TinyCat\Support\Html::et('common.close') ?>">
                        <?= \TinyCat\Support\Html::icon('close') ?>
                    </button>
                </div>

                <nav class="admin-nav" aria-label="<?= \TinyCat\Support\Html::et('common.admin') ?>">
                    <?php foreach ((array) $nav as $item): ?>
                        <?php $children = $item['children']; if ($children !== []): ?>
                            <details class="admin-nav-group"<?= $item['group_active'] ? ' open data-active="true"' : '' ?>>
                                <summary class="admin-nav-link admin-nav-summary">
                                    <?= \TinyCat\Support\Html::icon((string) ($item['icon'] ?? 'folder')) ?>
                                    <span><?= \TinyCat\Support\Html::e((string) ($item['label'] ?? '')) ?></span>
                                    <?= \TinyCat\Support\Html::icon('chevron-down', 'icon admin-nav-chevron') ?>
                                </summary>
                                <div class="admin-nav-submenu">
                                    <?php foreach ($children as $child): ?>
                                        <a class="admin-nav-link" href="<?= \TinyCat\Support\Html::e($child['href']) ?>"<?= $child['active'] ? ' aria-current="page"' : '' ?>>
                                            <?= \TinyCat\Support\Html::icon((string) ($child['icon'] ?? 'link')) ?>
                                            <span><?= \TinyCat\Support\Html::e((string) ($child['label'] ?? $child['href'])) ?></span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                            <?php continue; ?>
                        <?php endif; ?>
                        <a class="admin-nav-link" href="<?= \TinyCat\Support\Html::e($item['href']) ?>"<?= $item['active'] ? ' aria-current="page"' : '' ?>>
                            <?= \TinyCat\Support\Html::icon((string) ($item['icon'] ?? 'link')) ?>
                            <span><?= \TinyCat\Support\Html::e((string) ($item['label'] ?? $item['href'])) ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <div class="admin-sidebar-footer">
                    <div class="admin-user">
                        <span class="avatar avatar-sm"><?= \TinyCat\Users\Views::avatar(['user' => $authUser]) ?></span>
                        <span>
                            <strong><?= \TinyCat\Support\Html::e($adminIdentity) ?></strong>
                            <small>TinyCat v<?= \TinyCat\Support\Html::e(\TinyCat\Support\Version::CURRENT) ?></small>
                        </span>
                    </div>
                </div>
            </aside>

            <button class="admin-backdrop" type="button" data-admin-nav-close aria-label="<?= \TinyCat\Support\Html::et('common.close') ?>"></button>

            <div class="admin-main">
                <header class="admin-topbar">
                    <button class="btn btn-icon admin-menu-btn" type="button" data-admin-nav-toggle aria-controls="admin-sidebar" aria-expanded="false" aria-label="<?= \TinyCat\Support\Html::et('common.menu') ?>">
                        <?= \TinyCat\Support\Html::icon('menu') ?>
                    </button>
                    <div class="admin-topbar-title">
                        <strong><?= \TinyCat\Support\Html::e($title) ?></strong>
                    </div>
                    <?php if ($actions !== ''): ?>
                        <div class="admin-topbar-actions">
                            <?= $actions ?>
                        </div>
                    <?php endif; ?>
                    <?= $userMenu ?>
                </header>

                <main class="admin-content" id="main-content" tabindex="-1">
                    <div class="stack stack-gap-24">
                        <?= $content ?>
                    </div>
                </main>
            </div>
        </div>
