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


/** Module-owned HTTP pages; domain services do not render the page shell. */
final class Pages
{
    public static function queue(): void
    {
        Access::require_admin();
        $data = Queue::payload();
        $title = Localization::t('bots.queue_title');
        $content = AdminViews::listCard($title, 'clock', 'bot-queue-list', 'bot-queue-filter-modal', new Markup(\TinyCat\Support\Components::render('bots/queue', $data + ['state' => $data])), primary: true);
        Layout::render(['title' => $title, 'current' => '/admin/bots/queue'], $content);
    }

    public static function accounts(?int $openBot = null): void
    {
        Access::require_admin();
        $data = Administration::accountsViewData();
        $title = Localization::t('bots.accounts_title');
        $content = AdminViews::listCard($title, 'users', 'bot-accounts-list', 'bot-accounts-filter-modal', new Markup(Views::accounts($data)), primary: true)
            . Views::accountCreate() . Views::accountFilter($data);
        if ($openBot !== null) {
            $content .= '<span hidden data-bot-detail-open="' . Html::e($openBot) . '" data-bot-detail-query="' . Html::e((string) ($_SERVER['QUERY_STRING'] ?? '')) . '"></span>';
        }
        Layout::render([
            'title' => $title, 'current' => '/admin/bots/accounts',
            'actions' => AdminViews::modalAction('bot-account-create-modal', Localization::t('bots.new_account'), 'user-plus'),
        ], $content);
    }

    public static function sources(): void
    {
        Access::require_admin();
        $data = Administration::sourcesViewData();
        $filter = Administration::sourceFilterData() + ['filters' => $data['filters']];
        $title = Localization::t('bots.sources_title');
        $content = AdminViews::listCard($title, 'rss', 'bots-list', 'bots-filter-modal', new Markup(Views::sources($data)), primary: true)
            . View::part('Bots/modals/source-filter', $filter);
        Layout::render([
            'title' => $title, 'current' => '/admin/bots/list',
            'actions' => AdminViews::modalAction('bot-source-create-modal', Localization::t('bots.new_source'), 'plus', AdminViews::admin_list_url('/api/bots/sources/management', ['bot' => $data['filter_bot_id']])),
        ], $content);
    }

    public static function detail(int $botId): void
    {
        Access::require_admin();
        if (Administration::accountById($botId) === null) {
            http_response_code(404);
            Layout::render([
                'title' => Localization::t('bots.detail_not_found'),
                'current' => '/admin/bots/accounts',
            ], static function (): void {
                ?><div class="alert alert-info"><?= Html::et('bots.detail_not_found') ?></div><?php
            });
            return;
        }

        self::accounts($botId);
    }
}
