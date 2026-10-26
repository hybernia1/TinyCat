<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

$statusId = max(0, (int) \TinyCat\Http\Web::get('id', 0));
$current = \TinyCat\Status\Posts::status_url($statusId);
$compact = (string) \TinyCat\Http\Web::get('compact', '') === '1';
$pageAction = $current . ($compact ? '?compact=1' : '');

if ($statusId < 1) {
    tc_status_not_found();
    return;
}

if (\TinyCat\Http\Request::current()->method() === 'GET' && \TinyCat\Http\Request::current()->path() !== $current) {
    \TinyCat\Http\Web::redirect($current, 301);
}

$authUser = \TinyCat\Users\Authentication::auth();
$item = \TinyCat\Status\Feed::public_status_item($statusId);

if ($item === null) {
    tc_status_not_found();
    return;
}

$detail = \TinyCat\Status\ViewData::status_prepare_detail_view($item, $authUser);
$item = $detail['item'];
$comments = $detail['comments'];
$scheduled = \TinyCat\Status\Posts::status_is_scheduled($item);

$statusTitle = \TinyCat\Status\ViewData::status_meta_title($item);
$statusStructuredImage = \TinyCat\Status\Images::status_image_jsonld($item) ?? (\TinyCat\Status\ViewData::status_meta_link_image($item) ?: null);
$statusImageUrl = \TinyCat\Status\Images::status_image_url($item);

\TinyCat\Support\View::layout('layout', [
    'title' => $statusTitle,
    'current' => $current,
    'meta' => [
        'title' => $statusTitle,
        'description' => \TinyCat\Status\ViewData::status_meta_description($item),
        'url' => $current,
        'image' => \TinyCat\Status\ViewData::status_meta_image($item),
        'image_type' => $statusImageUrl !== '' ? 'image/webp' : '',
        'image_width' => (int) ($item['image_width'] ?? 0),
        'image_height' => (int) ($item['image_height'] ?? 0),
        'image_alt' => $statusImageUrl !== '' ? \TinyCat\Status\Images::status_image_alt_text($item) : '',
        'type' => 'article',
        'robots' => $scheduled ? 'noindex,nofollow' : '',
        'published_time' => (string) ($item['published_at'] ?? ''),
        'author' => (string) ($item['author_name'] ?? ''),
        'jsonld' => array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'DiscussionForumPosting',
            '@id' => \TinyCat\Site\Presentation::absolute_url($current),
            'url' => \TinyCat\Site\Presentation::absolute_url($current),
            'image' => $statusStructuredImage,
            'headline' => $statusTitle,
            'articleBody' => trim((string) ($item['body'] ?? '')) !== '' ? (string) ($item['body'] ?? '') : null,
            'keywords' => \TinyCat\Status\Tags::status_tags_from_text((string) ($item['body'] ?? '')),
            'datePublished' => \TinyCat\Support\Dates::dateIso((string) ($item['published_at'] ?? '')),
            'author' => [
                '@type' => 'Person',
                'name' => (string) ($item['author_name'] ?? ''),
                'url' => \TinyCat\Site\Presentation::absolute_url(\TinyCat\Users\Profiles::author_url((int) ($item['author_id'] ?? 0))),
            ],
            'interactionStatistic' => [
                [
                    '@type' => 'InteractionCounter',
                    'interactionType' => 'https://schema.org/LikeAction',
                    'userInteractionCount' => (int) ($item['likes_count'] ?? 0),
                ],
                [
                    '@type' => 'InteractionCounter',
                    'interactionType' => 'https://schema.org/CommentAction',
                    'userInteractionCount' => (int) ($item['comments_count'] ?? 0),
                ],
            ],
        ], static fn (mixed $value): bool => $value !== null),
    ],
], static function () use ($item, $comments, $authUser, $current, $compact, $pageAction): void {
    $authorId = (int) ($item['author_id'] ?? 0);
    $authorName = trim((string) ($item['author_name'] ?? ''));
    $publishedAt = (string) ($item['published_at'] ?? '');
    $contentId = (int) ($item['id'] ?? 0);
    ?>
    <section class="public-layout">
        <div class="home-feed-section stack stack-gap-14">
            <h1 class="sr-only"><?= \TinyCat\Support\Html::et('public.status_title') ?></h1>
            <?= \TinyCat\Status\Views::card(['item' => $item, 'user' => $authUser, 'action' => $pageAction, 'open_modal' => false]) ?>
            <?= \TinyCat\Status\Views::commentsThread(['item' => $item, 'comments' => $comments, 'user' => $authUser, 'action' => $pageAction, 'context' => 'status-' . $contentId]) ?>
        </div>
        <?php if (!$compact): ?><?= \TinyCat\Site\Presentation::public_sidebar() ?><?php endif; ?>
    </section>
    <?php
});

function tc_status_not_found(): void
{
    http_response_code(404);
    $notFoundTitle = \TinyCat\Support\Localization::t('public.status_not_found');
    $notFoundCurrent = '/status';
    require \TinyCat\Support\Paths::publicPath('not-found.php');
}
