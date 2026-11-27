<?php
declare(strict_types=1);

namespace TinyCat\Social\Bots;

use TinyCat\Core\Ui\Page as Layout;
use TinyCat\Core\Ui\Admin\Views as AdminViews;
use TinyCat\Support\Localization;
use TinyCat\Core\Identity\Access;


/** Module-owned HTTP pages; domain services do not render the page shell. */
final class Pages
{
    public static function queue(): void
    {
        Access::require_admin();
        $data = Queue::payload();
        \TinyCat\Core\Ui\Admin\Definitions::page('social.bot-queue', $data);
    }

    public static function accounts(): void
    {
        Access::require_admin();
        $data = Administration::accountsPayload();
        $creation = \TinyCat\Core\Ui\Admin\Definitions::get('social.bot-creation');
        $actions = AdminViews::modalAction(\TinyCat\Core\Ui\Admin\Definitions::modalId($creation['editor']['creation']), Localization::t($creation['editor']['title']), 'user-plus', $creation['editor']['creation']);
        \TinyCat\Core\Ui\Admin\Definitions::page('social.bot-creation', $data, $actions);
    }

    public static function sources(): void
    {
        Access::require_admin();
        $data = Administration::payload();
        $actions = AdminViews::modalAction('bot-source-create-modal', Localization::t('bots.new_source'), 'plus', AdminViews::admin_list_url('/api/social/bots/sources/management', ['bot' => $data['filter_bot_user_id']]));
        \TinyCat\Core\Ui\Admin\Definitions::page('social.bot-sources', $data, $actions);
    }

    public static function detail(int $botId): void
    {
        Access::require_admin();
        if (Administration::accountById($botId) === null) {
            http_response_code(404);
            Layout::render([
                'title' => Localization::t('bots.detail_not_found'),
                'current' => '/admin/social/bots/accounts',
            ], \TinyCat\Core\Ui\Components::render('ui/notice', ['tone' => 'info', 'label' => Localization::t('bots.detail_not_found')]));
            return;
        }

        $query = array_intersect_key(\TinyCat\Http\Request::current()->query(), array_flip(['tab', 'q', 'source', 'publication', 'status', 'sort', 'direction', 'page', 'per_page']));
        $query['panel'] = 'detail';
        \TinyCat\Http\Web::redirect('/admin/social/bots/accounts#modal=social/bots/accounts/' . $botId . '&' . http_build_query($query));
    }
}
