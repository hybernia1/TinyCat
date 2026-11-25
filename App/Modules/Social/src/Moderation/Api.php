<?php
declare(strict_types=1);
namespace TinyCat\Social\Moderation;

use \TinyCat\Http\Request;
use \TinyCat\Http\Result;

final class Api
{
    public static function register(\TinyCat\Http\Api $api): void
    {
        $api->get('/moderation/blocked-urls', \TinyCat\Social\Posts\Policy::resource(...), 'admin');
        $api->put('/moderation/blocked-urls', \TinyCat\Social\Posts\Policy::save(...), 'admin');

        $api->get('/moderation/report-options', static function (Request $r): array {
            $r->validated(['id' => 'required|integer']);
            $item = \TinyCat\Social\Posts\Feed::public_status_item((int) $r->input('id'));
            if ($item === null || !\TinyCat\Social\Posts\Repository::visible($item)) {
                \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('account.messages.status_not_found'), 404, 'status_not_found');
            }
            return ['reasons' => array_keys(\TinyCat\Social\Posts\Posts::status_report_reasons()), 'note_max_length' => \TinyCat\Social\Posts\Posts::REPORT_NOTE_MAX_LENGTH];
        }, 'user');
        $api->post('/moderation/reports', static function (Request $r): Result {
            $r->validated(['id' => 'required|integer', 'reason' => 'string', 'note' => 'string|max:' . \TinyCat\Social\Posts\Posts::REPORT_NOTE_MAX_LENGTH]);
            \TinyCat\Social\Posts\Actions::status_json_require_session_interval('report');
            $data = \TinyCat\Social\Posts\Actions::status_json_report((int) $r->input('id'), \TinyCat\Core\Identity\Access::requireAuth());
            [$key, $intervalMs] = \TinyCat\Social\Moderation\ActionPolicy::cooldown('report');
            \TinyCat\Core\Security\RateLimiter::session_action_retry_after('status_' . $key, $intervalMs);
            return Result::from($data);
        });
        $api->get(\TinyCat\Core\Ui\Admin\Definitions::route('social.moderation-reports', 'endpoint'), static fn (): Result => Result::from(self::adminReports()), 'admin');
        $api->get('/moderation/reports/{id:[0-9]+}', static fn (Request $r, array $p): Result => Result::from(Reports::detail((int) $p['id'])), 'admin');
        $api->patch('/moderation/reports/{id:[0-9]+}', static fn (Request $r, array $p): Result => Result::from(self::adminReportReview((int) $p['id'])), 'admin');
        $api->post('/moderation/posts/{id:[0-9]+}/publication', static fn (Request $r, array $p): Result => Result::from(self::adminPostPublish((int) $p['id'])), 'admin', 200);
    }

    /** @return array<mixed> */
    private static function adminPostPublish(int $contentId): array
    {
        Reports::publish($contentId);
        return Reports::tc_admin_moderation_reports_payload() + [
            'message' => \TinyCat\Support\Localization::t('moderation.messages.content_published'), 'code' => 'content_published',
        ];
    }

    /**
     * @return array<mixed>
     */
    private static function adminReports(): array
    {
        return \TinyCat\Social\Moderation\Reports::tc_admin_moderation_reports_payload();
    }

    /**
     * @return array<mixed>
     */
    private static function adminReportReview(int $reportId): array
    {
        \TinyCat\Http\Request::current()->validated(['decision' => 'required|string']);
        \TinyCat\Social\Moderation\Reports::tc_admin_moderation_review_report(
            $reportId,
            (string) \TinyCat\Http\Request::current()->input('decision', '')
        );
        return \TinyCat\Social\Moderation\Reports::tc_admin_moderation_reports_payload() + ['message' => \TinyCat\Support\Localization::t('moderation.messages.report_reviewed'), 'code' => 'report_reviewed'];
    }
}
