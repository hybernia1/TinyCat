<?php
declare(strict_types=1);
namespace TinyCat\Social\Presentation;

use TinyCat\Http\Request;
use TinyCat\Social\Moderation\Restrictions;
use TinyCat\Support\Cache;
use TinyCat\Support\Localization;
use TinyCat\Support\View;
use TinyCat\Core\Identity\Access;

/** HTTP authorization, actions and prepared page composition. */
final class Pages
{
    public static function dashboard(): void
    {
        \TinyCat\Core\Ui\Admin\Dashboard::respond();
    }

    public static function privacySection(): string
    {
        return View::render('privacy-section', ['siteName' => \TinyCat\Core\Ui\Site::site_name(), 'privacyLimitRows' => self::privacyLimitRows()], dirname(__DIR__, 2) . '/templates/site');
    }
    /** @return list<array<string, string>> */
    private static function privacyLimitRows(): array
    {
        $labels = [
            'new' => Localization::t('privacy.reputation_new'),
            'normal' => Localization::t('privacy.reputation_normal'),
            'trusted' => Localization::t('privacy.reputation_trusted'),
        ];
        $rules = Restrictions::moderation_action_rules();
        $rows = [];

        foreach ($labels as $key => $label) {
            $rule = (array) ($rules[$key] ?? []);
            $rows[] = [
                'label' => $label,
                'post' => self::privacyLimitLabel((array) ($rule['post'] ?? [])),
                'comment' => self::privacyLimitLabel((array) ($rule['comment'] ?? [])),
                'like' => self::privacyLimitLabel((array) ($rule['like'] ?? [])),
                'report' => self::privacyLimitLabel((array) ($rule['report'] ?? [])),
                'search' => self::privacyLimitLabel((array) ($rule['search'] ?? [])),
            ];
        }

        return $rows;
    }

    /** @param array<mixed> $rule */
    private static function privacyLimitLabel(array $rule): string
    {
        $window = max(60, (int) ($rule[0] ?? 3600));
        $limit = max(0, (int) ($rule[1] ?? 0));
        $hours = max(1, (int) ceil($window / 3600));

        return $hours === 1
            ? Localization::t('privacy.limit_per_hour', ['count' => $limit])
            : Localization::t('privacy.limit_per_hours', ['count' => $limit, 'hours' => $hours]);
    }

}
