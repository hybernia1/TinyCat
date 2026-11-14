<?php
declare(strict_types=1);
namespace TinyCat\Social\Moderation;

use \TinyCat\Http\Request;
use \TinyCat\Http\Result;

final class Api
{
    public static function register(\TinyCat\Http\Api $api): void
    {

        $api->get('/moderation/report-options', static function (Request $r): array {
            $r->validated(['id' => 'required|integer']);
            $item = \TinyCat\Social\Posts\Feed::public_status_item((int) $r->input('id'));
            if ($item === null || !\TinyCat\Social\Posts\Posts::status_can_view($item)) {
                \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.status_not_found'), 404, 'status_not_found');
            }
            return ['reasons' => array_keys(\TinyCat\Social\Posts\Posts::status_report_reasons()), 'note_max_length' => \TinyCat\Social\Posts\Posts::REPORT_NOTE_MAX_LENGTH];
        }, 'user');
        $api->post('/moderation/reports', static function (Request $r): Result {
            $r->validated(['id' => 'required|integer', 'reason' => 'string', 'note' => 'string|max:' . \TinyCat\Social\Posts\Posts::REPORT_NOTE_MAX_LENGTH]);
            \TinyCat\Social\Posts\Actions::status_json_require_session_interval('report');
            $data = \TinyCat\Social\Posts\Actions::status_json_report((int) $r->input('id'), \TinyCat\Core\Identity\Access::requireAuth());
            [$key, $intervalMs] = \TinyCat\Social\Posts\Posts::status_session_interval_rule('report');
            \TinyCat\Core\Security\RateLimiter::session_action_retry_after('status_' . $key, $intervalMs);
            return Result::from($data);
        });
        $api->get('/moderation/reports', static fn (): Result => Result::from(Actions::adminReports()), 'admin');
        $api->get('/moderation/reports/{id:[0-9]+}', static fn (Request $r, array $p): Result => Result::from(Reports::detail((int) $p['id'])), 'admin');
        $api->patch('/moderation/reports/{id:[0-9]+}', static fn (Request $r, array $p): Result => Result::from(Actions::adminReportReview((int) $p['id'])), 'admin');
        $api->post('/moderation/posts/{id:[0-9]+}/publication', static fn (Request $r, array $p): Result => Result::from(Actions::adminPostPublish((int) $p['id'])), 'admin', 200);
    }
}
