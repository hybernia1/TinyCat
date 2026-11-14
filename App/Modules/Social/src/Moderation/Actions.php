<?php
declare(strict_types=1);
namespace TinyCat\Social\Moderation;

final class Actions
{
    /** @return array<mixed> */
    public static function adminPostPublish(int $contentId): array
    {
        Reports::publish($contentId);
        return Reports::tc_admin_moderation_reports_payload() + [
            'message' => \TinyCat\Support\Localization::t('moderation.messages.content_published'), 'code' => 'content_published',
        ];
    }

    /**
     * @return array<mixed>
     */
    public static function adminReports(): array
    {
        return \TinyCat\Social\Moderation\Reports::tc_admin_moderation_reports_payload();
    }

    /**
     * @return array<mixed>
     */
    public static function adminReportReview(int $reportId): array
    {
        \TinyCat\Http\Request::current()->validated(['decision' => 'required|string']);
        \TinyCat\Social\Moderation\Reports::tc_admin_moderation_review_report(
            $reportId,
            (string) \TinyCat\Http\Request::current()->input('decision', '')
        );
        return \TinyCat\Social\Moderation\Reports::tc_admin_moderation_reports_payload() + ['message' => \TinyCat\Support\Localization::t('moderation.messages.report_reviewed'), 'code' => 'report_reviewed'];
    }

}
