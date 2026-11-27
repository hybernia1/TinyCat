<?php
declare(strict_types=1);
/** @var string $content */
/** @var string $actions */
/** @var string $appName */
/** @var array<mixed> $nav */
/** @var string $siteLogoUrl */
/** @var string $title */
/** @var array<mixed> $authUser */
/** @var string $profileUrl */
/** @var bool $profileNeedsEmail */
/** @var string $userMenu */
/** @var string $notificationMenu */
?>
        <div class="admin-shell" data-admin-shell>
            <aside class="admin-sidebar" id="admin-sidebar" data-admin-sidebar data-theme="dark">
                <div class="admin-sidebar-header">
                    <div class="admin-sidebar-tools">
                    <a class="admin-brand nav-link nav-link-icon" href="/" aria-label="<?= \TinyCat\Core\Ui\Html::e($appName) ?>">
                        <?php if ($siteLogoUrl !== ''): ?>
                            <img class="brand-logo" src="<?= \TinyCat\Core\Ui\Html::e($siteLogoUrl) ?>" alt="<?= \TinyCat\Core\Ui\Html::et('common.site_logo_alt', ['site' => $appName]) ?>">
                        <?php else: ?>
                            <?= \TinyCat\Core\Ui\Html::icon('home') ?>
                        <?php endif; ?>
                    </a>
                    <?= $notificationMenu ?>
                    <?= $userMenu ?>
                    </div>
                    <button class="btn btn-icon admin-sidebar-close" type="button" data-admin-nav-toggle aria-controls="admin-sidebar" aria-expanded="false" aria-label="<?= \TinyCat\Core\Ui\Html::et('common.close') ?>">
                        <?= \TinyCat\Core\Ui\Html::icon('close') ?>
                    </button>
                </div>

                <nav class="admin-nav" aria-label="<?= \TinyCat\Core\Ui\Html::et('common.admin') ?>">
                    <?php foreach ((array) $nav as $item): ?>
                        <?php $children = $item['children']; if ($children !== []): ?>
                            <details class="admin-nav-group"<?= $item['group_active'] ? ' open data-active="true"' : '' ?>>
                                <summary class="admin-nav-link admin-nav-summary">
                                    <?= \TinyCat\Core\Ui\Html::icon((string) ($item['icon'] ?? 'folder')) ?>
                                    <span><?= \TinyCat\Core\Ui\Html::e((string) ($item['label'] ?? '')) ?></span>
                                    <?= \TinyCat\Core\Ui\Html::icon('chevron-down', 'icon admin-nav-chevron') ?>
                                </summary>
                                <div class="admin-nav-submenu">
                                    <?php foreach ($children as $child): ?>
                                        <a class="admin-nav-link" href="<?= \TinyCat\Core\Ui\Html::e($child['href']) ?>"<?= $child['active'] ? ' aria-current="page"' : '' ?>>
                                            <?= \TinyCat\Core\Ui\Html::icon((string) ($child['icon'] ?? 'link')) ?>
                                            <span><?= \TinyCat\Core\Ui\Html::e((string) ($child['label'] ?? $child['href'])) ?></span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                            <?php continue; ?>
                        <?php endif; ?>
                        <a class="admin-nav-link" href="<?= \TinyCat\Core\Ui\Html::e($item['href']) ?>"<?= $item['active'] ? ' aria-current="page"' : '' ?><?= !empty($item['modal']) ? ' data-modal-open="' . \TinyCat\Core\Ui\Html::e($item['modal']) . '"' : '' ?><?= !empty($item['modal_url']) ? ' data-modal-url="' . \TinyCat\Core\Ui\Html::e($item['modal_url']) . '"' : '' ?>>
                            <?= \TinyCat\Core\Ui\Html::icon((string) ($item['icon'] ?? 'link')) ?>
                            <span><?= \TinyCat\Core\Ui\Html::e((string) ($item['label'] ?? $item['href'])) ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <div class="admin-sidebar-footer">
                    <small>TinyCat v<?= \TinyCat\Core\Ui\Html::e(\TinyCat\Support\Version::CURRENT) ?></small>
                </div>
            </aside>

            <button class="admin-backdrop" type="button" data-admin-nav-close aria-label="<?= \TinyCat\Core\Ui\Html::et('common.close') ?>"></button>

            <div class="admin-main">
                <header class="admin-topbar">
                    <button class="btn btn-icon admin-menu-btn" type="button" data-admin-nav-toggle aria-controls="admin-sidebar" aria-expanded="false" aria-label="<?= \TinyCat\Core\Ui\Html::et('common.menu') ?>">
                        <?= \TinyCat\Core\Ui\Html::icon('menu') ?>
                    </button>
                    <div class="admin-topbar-title">
                        <strong><?php if (($titleIcon ?? '') !== ''): ?><?= \TinyCat\Core\Ui\Html::icon($titleIcon) ?> <?php endif; ?><?= \TinyCat\Core\Ui\Html::e($title) ?></strong>
                    </div>
                    <?php if ($actions !== ''): ?>
                        <div class="admin-topbar-actions">
                            <?= $actions ?>
                        </div>
                    <?php endif; ?>
                </header>

                <main class="admin-content" id="main-content" tabindex="-1">
                    <div class="stack stack-gap-24">
                        <?= $content ?>
                    </div>
                </main>
            </div>
        </div>
