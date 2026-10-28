<?php
declare(strict_types=1);
/** @var array<mixed> $stats */
/** @var array<mixed> $counts */
/** @var array<mixed> $cache */
/** @var array<mixed> $memcached */
/** @var array<mixed> $memcachedStats */
/** @var array<mixed> $opcache */
/** @var array<mixed> $opcacheStats */
/** @var array<mixed> $opcacheConfig */
/** @var array<mixed> $recentComments */
?>
<section class="grid sm:grid-2 md:grid-4">
    <?php foreach ($stats as $item): ?>
        <?php $count = $counts[(string) $item['table']] ?? null; ?>
        <article class="card">
            <div class="card-body stack">
                <h2 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon((string) $item['icon'], 'icon text-primary') ?> <?= \TinyCat\Support\Html::e($item['label']) ?></h2>
                <?php if ($count === null): ?>
                    <p class="text-muted mb-0"><?= \TinyCat\Support\Html::et('admin.table_missing') ?></p>
                <?php else: ?>
                    <p class="text-2xl m-0"><strong><?= \TinyCat\Support\Html::e(\TinyCat\Support\Format::number($count)) ?></strong></p>
                    <p class="table-meta m-0"><code><?= \TinyCat\Support\Html::e((string) $item['table']) ?></code></p>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
</section>
<section class="card mt-6">
    <div class="card-header split">
        <h2 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('database') ?> <?= \TinyCat\Support\Html::et('admin.cache.title') ?></h2>
        <?php if ($cache['available']): ?>
            <span class="badge badge-primary"><?= \TinyCat\Support\Html::et('admin.cache.available') ?></span>
        <?php else: ?>
            <span class="badge badge-danger"><?= \TinyCat\Support\Html::et('admin.cache.fallback') ?></span>
        <?php endif; ?>
    </div>
    <div class="card-body stack gap-2">
        <p class="m-0 text-muted">
            <?= \TinyCat\Support\Html::e($cache['driver'] === 'memcached' ? \TinyCat\Support\Localization::t('admin.cache.memcached') : \TinyCat\Support\Localization::t('admin.cache.filesystem')) ?>
        </p>
        <p class="m-0 text-muted">
            <strong><?= \TinyCat\Support\Html::et('admin.cache.memcached_label') ?>:</strong>
            <?php if (!$memcached['configured']): ?>
                <?= \TinyCat\Support\Html::et('admin.cache.memcached_disabled') ?>
            <?php elseif ($memcached['available']): ?>
                <?= \TinyCat\Support\Html::et('admin.cache.memcached_running') ?>
            <?php elseif (!$memcached['extension']): ?>
                <?= \TinyCat\Support\Html::et('admin.cache.memcached_extension_missing') ?>
            <?php else: ?>
                <?= \TinyCat\Support\Html::et('admin.cache.memcached_unreachable') ?>
            <?php endif; ?>
        </p>
        <p class="m-0 text-muted">
            <strong><?= \TinyCat\Support\Html::et('admin.cache.opcache_label') ?>:</strong>
            <?= \TinyCat\Support\Html::et($opcache['enabled'] ? 'admin.cache.opcache_running' : 'admin.cache.opcache_disabled') ?>
        </p>
        <?php if ($memcached['available'] || $opcache['enabled']): ?>
            <div class="grid sm:grid-2 gap-3">
                <?php if ($memcached['available']): ?>
                    <div class="stack gap-1">
                        <strong><?= \TinyCat\Support\Html::et('admin.cache.memcached_details') ?></strong>
                        <p class="m-0 table-meta"><?= \TinyCat\Support\Html::et('admin.cache.servers', ['value' => \TinyCat\Support\Format::number($memcachedStats['servers'])]) ?> · <?= \TinyCat\Support\Html::et('admin.cache.version', ['value' => $memcachedStats['version'] !== '' ? $memcachedStats['version'] : '—']) ?></p>
                        <p class="m-0 table-meta"><?= \TinyCat\Support\Html::et('admin.cache.uptime', ['value' => \TinyCat\Support\Format::number($memcachedStats['uptime'])]) ?> · <?= \TinyCat\Support\Html::et('admin.cache.items', ['value' => \TinyCat\Support\Format::number($memcachedStats['items'])]) ?></p>
                        <p class="m-0 table-meta"><?= \TinyCat\Support\Html::et('admin.cache.memory', ['used' => \TinyCat\Support\Format::bytes($memcachedStats['bytes']), 'limit' => \TinyCat\Support\Format::bytes($memcachedStats['limit_bytes'])]) ?></p>
                        <p class="m-0 table-meta"><?= \TinyCat\Support\Html::et('admin.cache.hit_rate', ['value' => \TinyCat\Support\Format::hitRate($memcachedStats['hits'], $memcachedStats['misses'])]) ?> · <?= \TinyCat\Support\Html::et('admin.cache.evictions', ['value' => \TinyCat\Support\Format::number($memcachedStats['evictions'])]) ?></p>
                    </div>
                <?php endif; ?>
                <?php if ($opcache['enabled']): ?>
                    <div class="stack gap-1">
                        <div class="split">
                            <strong><?= \TinyCat\Support\Html::et('admin.cache.opcache_details') ?></strong>
                            <?php if ($opcache['resettable']): ?>
                                <form method="post" action="/api/site/cache-resets" data-ajax-form data-confirm="<?= \TinyCat\Support\Html::et('admin.cache.opcache_reset_confirm') ?>" data-confirm-title="<?= \TinyCat\Support\Html::et('admin.cache.opcache_reset') ?>" data-confirm-ok="<?= \TinyCat\Support\Html::et('admin.cache.opcache_reset') ?>" data-confirm-cancel="<?= \TinyCat\Support\Html::et('common.cancel') ?>">
                                    <?= \TinyCat\Security\Csrf::field() ?>
                                    <input type="hidden" name="action" value="reset-opcache">
                                    <button class="btn btn-secondary btn-sm" type="submit"><?= \TinyCat\Support\Html::icon('refresh') ?> <span><?= \TinyCat\Support\Html::et('admin.cache.opcache_reset') ?></span></button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <p class="m-0 table-meta"><?= \TinyCat\Support\Html::et('admin.cache.cached_scripts', ['value' => \TinyCat\Support\Format::number($opcacheStats['cached_scripts'])]) ?> / <?= \TinyCat\Support\Html::et('admin.cache.max_scripts', ['value' => \TinyCat\Support\Format::number($opcacheConfig['max_scripts'])]) ?></p>
                        <p class="m-0 table-meta"><?= \TinyCat\Support\Html::et('admin.cache.memory', ['used' => \TinyCat\Support\Format::bytes($opcacheStats['used_memory']), 'limit' => \TinyCat\Support\Format::bytes($opcacheConfig['memory_bytes'])]) ?> · <?= \TinyCat\Support\Html::et('admin.cache.wasted_memory', ['value' => \TinyCat\Support\Format::bytes($opcacheStats['wasted_memory'])]) ?></p>
                        <p class="m-0 table-meta"><?= \TinyCat\Support\Html::et('admin.cache.hit_rate', ['value' => \TinyCat\Support\Format::hitRate($opcacheStats['hits'], $opcacheStats['misses'])]) ?> · <?= \TinyCat\Support\Html::et('admin.cache.timestamp_checks', ['value' => $opcacheConfig['validate_timestamps'] ? \TinyCat\Support\Localization::t('admin.cache.enabled') : \TinyCat\Support\Localization::t('admin.cache.disabled')]) ?></p>
                        <p class="m-0 table-meta"><?= \TinyCat\Support\Html::et('admin.cache.revalidate_frequency', ['value' => \TinyCat\Support\Format::number($opcacheConfig['revalidate_freq'])]) ?> · <?= \TinyCat\Support\Html::et($opcacheConfig['file_cache'] ? 'admin.cache.file_cache_enabled' : 'admin.cache.file_cache_disabled') ?></p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
<section class="card mt-6">
    <div class="card-header split">
        <h2 class="text-lg m-0 cluster gap-2"><?= \TinyCat\Support\Html::icon('message-circle') ?> <?= \TinyCat\Support\Html::et('admin.recent_comments') ?></h2>
        <a class="btn btn-secondary btn-sm" href="/admin/moderation/reports"><?= \TinyCat\Support\Html::et('common.open') ?></a>
    </div>
    <div class="card-body stack">
        <?php if ($recentComments === []): ?>
            <p class="text-muted mb-0"><?= \TinyCat\Support\Html::et('admin.no_recent_comments') ?></p>
        <?php else: ?>
            <div class="stack">
                <?php foreach ($recentComments as $comment): ?>
                    <article class="card-body px-0 py-2 border-b">
                        <div class="split">
                            <strong><?= \TinyCat\Support\Html::e('@' . (string) ($comment['username'] ?? '')) ?></strong>
                            <time class="table-meta" datetime="<?= \TinyCat\Support\Html::e(\TinyCat\Support\Dates::dateIso((string) ($comment['created_at'] ?? ''))) ?>"><?= \TinyCat\Support\Html::e(\TinyCat\Support\Dates::displayDateTime((string) ($comment['created_at'] ?? ''))) ?></time>
                        </div>
                        <p class="m-0 text-muted"><?= \TinyCat\Support\Html::e(\TinyCat\Support\Text::plain_text_limit((string) ($comment['body'] ?? ''), 220)) ?></p>
                        <a class="table-meta" href="<?= \TinyCat\Support\Html::e($comment['content_url']) ?>"><?= \TinyCat\Support\Html::e(\TinyCat\Support\Text::plain_text_limit((string) ($comment['content_body'] ?? ''), 100)) ?></a>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
