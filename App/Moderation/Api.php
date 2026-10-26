<?php
declare(strict_types=1);
namespace TinyCat\Moderation;

use \TinyCat\Http\Request;
use \TinyCat\Http\Result;

final class Api
{
    public static function register(\TinyCat\Http\Api $api): void
    {

        $api->get('/moderation/report-options', static function (Request $r): array {
            $r->validated(['id' => 'required|integer']);
            $item = \TinyCat\Status\Feed::public_status_item((int) $r->input('id'));
            if ($item === null || !\TinyCat\Status\Posts::status_can_view($item, \TinyCat\Users\Authentication::auth())) {
                \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.status_not_found'), 404, 'status_not_found');
            }
            return ['reasons' => \TinyCat\Status\Posts::status_report_reasons()];
        }, 'user');
        $api->post('/moderation/reports', static function (Request $r): Result {
            $r->validated(['id' => 'required|integer', 'reason' => 'string', 'note' => 'string']);
            \TinyCat\Status\Posts::status_json_require_session_interval('report');
            return Result::from(\TinyCat\Status\Posts::status_json_report((int) $r->input('id'), \TinyCat\Users\Access::requireAuth()));
        });
        $api->get('/moderation/reports', static fn (): Result => Result::from(Actions::adminReports()), 'admin');
        $api->patch('/moderation/reports/{id:[0-9]+}', static fn (Request $r, array $p): Result => Result::from(Actions::adminReportReview((int) $p['id'])), 'admin');
        $api->get('/moderation/blocked-urls', static fn (): Result => Result::from(Actions::adminBlocking()), 'admin');
        $api->put('/moderation/blocked-urls', static fn (): Result => Result::from(Actions::adminBlockingSave()), 'admin');
    }
}
