<?php
declare(strict_types=1);
namespace TinyCat\Moderation;

final class Actions
{
    /**
     * @return array<mixed>
     */
    public static function adminReports(): array
    {
        return \TinyCat\Moderation\Reports::tc_admin_moderation_reports_payload();
    }

    /**
     * @return array<mixed>
     */
    public static function adminReportReview(int $reportId): array
    {
        \TinyCat\Http\Request::current()->validated(['decision' => 'required|string']);
        \TinyCat\Moderation\Reports::tc_admin_moderation_review_report(
            $reportId,
            (string) \TinyCat\Http\Request::current()->input('decision', '')
        );
        return \TinyCat\Moderation\Reports::tc_admin_moderation_reports_payload() + ['message' => \TinyCat\Support\Localization::t('moderation.messages.report_reviewed')];
    }

    /**
     * @return array<mixed>
     */
    public static function adminBlocking(): array
    {
        return \TinyCat\Moderation\Blocking::tc_admin_moderation_blocking_payload();
    }

    /**
     * @return array<mixed>
     */
    public static function adminBlockingSave(): array
    {
        \TinyCat\Http\Request::current()->validated(['blocked_urls' => 'string']);
        $rules = \TinyCat\Moderation\Blocking::moderation_blocked_url_rules((string) \TinyCat\Http\Request::current()->input('blocked_urls', ''));
        \TinyCat\Settings\Store::setSetting('moderation.blocked_urls', implode(', ', $rules), 'string', 'moderation');
        return \TinyCat\Moderation\Blocking::tc_admin_moderation_blocking_payload(true) + ['message' => \TinyCat\Support\Localization::t('moderation.messages.url_blocker_saved')];
    }

}
