<?php
declare(strict_types=1);
namespace TinyCat\Notifications;

use \TinyCat\Http\Request;
use \TinyCat\Http\Response;
use \TinyCat\Http\Result;

use TinyCat\Http\Api as Registrar;
use TinyCat\Users\Api as Users;

final class Api
{
    public static function register(Registrar $api): void
    {
        $api->get('/notifications', self::listing(...), 'user');
        $api->patch('/notifications', static function (Request $request): array {
            if ($request->input('read') !== true && (string) $request->input('read', '') !== '1') {
                Response::validation(['read' => [\TinyCat\Http\Response::issue('common_request_failed', \TinyCat\Support\Localization::t('common.request_failed'))]]);
            }
            $id = (int) Users::actor()['id'];
            Inbox::applyAction($id, 'read_all');
            return Resource::state($id);
        });
        foreach (['patch', 'delete'] as $method) {
            $api->$method('/notifications/{id:[0-9]+}', static function (Request $request, array $params): array {
                $userId = (int) Users::actor()['id'];
                $id = (int) $params['id'];
                $item = \TinyCat\Database\Connection::find('notifications', ['id' => $id, 'user_id' => $userId]);
                if ($item === null) {
                    Response::error(\TinyCat\Support\Localization::t('common.not_found'), 404, 'notification_not_found');
                }
                Inbox::applyAction($userId, $request->method() === 'DELETE' ? 'delete' : 'read', $id);
                $data = Resource::state($userId) + ['id' => $id, 'deleted' => $request->method() === 'DELETE'];
                if ($request->method() === 'PATCH' && (string) $request->input('open', '') === '1') { $data['redirect'] = Inbox::targetUrl($item); }
                return $data;
            });
        }
    }

    private static function listing(Request $request): Result
    {
        $id = (int) Users::actor()['id'];
        $batch = Inbox::page($id, (string) $request->input('cursor_at', ''), max(0, (int) $request->input('cursor_id', 0)));
        return new Result(Resource::state($id) + ['items' => array_map(Resource::item(...), $batch['items'])], null,
            ['pagination' => ['next_url' => (string) $batch['next_url'], 'done' => (bool) $batch['done']]]);
    }
}
