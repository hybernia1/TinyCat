<?php
declare(strict_types=1);

namespace TinyCat\Bots;

use TinyCat\Site\Layout;
use TinyCat\Support\AdminViews;
use TinyCat\Support\Markup;
use TinyCat\Support\Html;
use TinyCat\Support\Localization;
use TinyCat\Support\View;
use TinyCat\Users\Access;
use TinyCat\Users\Avatars;


/** Module-owned HTTP pages; domain services do not render the page shell. */
final class Pages
{
    public static function queue(): void
    {
        Access::require_admin();
        $data = Queue::payload();
        $title = Localization::t('bots.queue_title');
        $content = AdminViews::listCard($title, 'clock', 'bot-queue-list', 'bot-queue-filter-modal', new Markup(\TinyCat\Support\Components::render('bots/queue', $data + ['state' => $data])));
        Layout::render(['title' => $title, 'current' => '/admin/bots/queue'], $content);
    }

    public static function accounts(): void
    {
        Access::require_admin();
        $data = Administration::accountsViewData();
        $title = Localization::t('bots.accounts_title');
        $content = AdminViews::listCard($title, 'users', 'bot-accounts-list', 'bot-accounts-filter-modal', new Markup(Views::accounts($data)));
        Layout::render([
            'title' => $title, 'current' => '/admin/bots/accounts',
            'actions' => AdminViews::modalAction('bot-account-create-modal', Localization::t('bots.new_account'), 'user-plus'),
        ], $content);
    }

    public static function sources(): void
    {
        Access::require_admin();
        $data = Administration::sourcesViewData();
        $create = Administration::sourceFormData(null);
        $filter = Administration::sourceFilterData();
        $title = Localization::t('bots.sources_title');
        $content = AdminViews::listCard($title, 'rss', 'bots-list', 'bots-filter-modal', new Markup(Views::sources($data)))
            . Views::sourceForm($create)
            . View::part('Bots/modals/source-filter', $filter);
        Layout::render([
            'title' => $title, 'current' => '/admin/bots/list',
            'actions' => AdminViews::modalAction('bot-source-create-modal', Localization::t('bots.new_source'), 'plus'),
        ], $content);
    }

    public static function detail(int $botId): void
    {
        Access::require_admin();
        $detail = Administration::accountDetailData($botId);

        if ($detail === null) {
            http_response_code(404);
            Layout::render([
                'title' => Localization::t('bots.detail_not_found'),
                'current' => '/admin/bots/accounts',
            ], static function (): void {
                ?><div class="alert alert-info"><?= Html::et('bots.detail_not_found') ?></div><?php
            });
            return;
        }

        $bot = (array) $detail['account'];
        $sources = (array) $detail['sources'];
        $runs = (array) $detail['runs'];
        $stats = (array) $detail['stats'];
        $posts = (array) $detail['posts'];
        $avatarUrl = Avatars::user_avatar_url($bot);
        $lastRun = $detail['last_run'];
        $profileUrl = (string) $detail['profile_url'];

        $statuses = AdminViews::admin_user_statuses();
        Layout::render([
            'title' => '@' . (string) $bot['username'],
            'current' => '/admin/bots/accounts',
            'asset_path' => '/admin/bots/detail',
            'actions' => '<a class="btn btn-secondary btn-sm" href="/admin/bots/accounts">' . Html::icon('arrow-left') . ' <span>' . Html::et('common.back') . '</span></a>',
        ], View::render('detail', compact('avatarUrl', 'botId', 'bot', 'sources', 'runs', 'stats', 'posts', 'lastRun', 'profileUrl', 'statuses'), __DIR__ . '/templates'));
    }
}
