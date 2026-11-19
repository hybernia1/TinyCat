<?php
declare(strict_types=1);

namespace TinyCat\Social\Automation;

use TinyCat\Core\Ui\Page as Layout;
use TinyCat\Support\AdminViews;
use TinyCat\Support\Markup;
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
        $title = Localization::t($creation['title']);
        $content = AdminViews::listCard($title, $creation['icon'], substr($creation['target'], 1), $creation['filterId'], new Markup(\TinyCat\Support\Components::render($creation['component'], $data)), primary: true);
        Layout::render([
            'title' => $title, 'current' => $creation['path'],
            'actions' => AdminViews::modalAction(\TinyCat\Core\Ui\Admin\Definitions::modalId($creation['editor']['creation']), Localization::t($creation['editor']['title']), 'user-plus', $creation['editor']['creation']),
        ], $content);
    }

    public static function sources(): void
    {
        Access::require_admin();
        $data = Administration::payload();
        $definition = \TinyCat\Core\Ui\Admin\Definitions::get('social.bot-sources');
        $title = Localization::t($definition['title']);
        $content = AdminViews::listCard($title, $definition['icon'], substr($definition['target'], 1), $definition['filterId'], new Markup(\TinyCat\Support\Components::render($definition['component'], $data)), primary: true);
        Layout::render([
            'title' => $title, 'current' => $definition['path'],
            'actions' => AdminViews::modalAction('bot-source-create-modal', Localization::t('bots.new_source'), 'plus', AdminViews::admin_list_url('/api/bots/sources/management', ['bot' => $data['filter_bot_user_id']])),
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
            ], \TinyCat\Support\Components::render('ui/notice', ['tone' => 'info', 'label' => Localization::t('bots.detail_not_found')]));
            return;
        }

        $query = \TinyCat\Http\Request::current()->query();
        unset($query['view'], $query['modal']);
        $query['panel'] = 'detail';
        \TinyCat\Http\Web::redirect('/admin/bots/accounts#modal=bots/accounts/' . $botId . '&' . http_build_query($query));
    }
}
