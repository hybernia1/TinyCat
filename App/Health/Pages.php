<?php
declare(strict_types=1);
namespace TinyCat\Health;

final class Pages
{
    public static function show(): void
    {
        \TinyCat\Users\Access::require_admin();
        $data = Service::snapshot();
        $checks = $data['report']['checks'] ?? [];
        $http = null;
        foreach ($checks as $check) { if ($check['id'] === 'http') { $http = $check; break; } }
        $issues = array_values(array_filter($checks, static fn (array $check): bool => in_array($check['status'], ['warning', 'critical'], true)));
        usort($issues, static fn (array $left, array $right): int => ($left['status'] === 'critical' ? 0 : 1) <=> ($right['status'] === 'critical' ? 0 : 1));
        $passed = count(array_filter($checks, static fn (array $check): bool => $check['status'] === 'ok'));
        $actions = \TinyCat\Support\Components::render('ui/api-action', ['url' => '/api/site-health/checks', 'method' => 'POST', 'label' => \TinyCat\Support\Localization::t('health.run'), 'button_class' => 'btn btn-primary btn-sm', 'icon' => 'refresh', 'show_label' => true]);
        \TinyCat\Site\Layout::render(['title' => \TinyCat\Support\Localization::t('health.title'), 'current' => '/admin/site-health', 'actions' => $actions], \TinyCat\Support\AdminViews::listCard(\TinyCat\Support\Localization::t('health.title'), 'shield', 'site-health', '', new \TinyCat\Support\Markup(\TinyCat\Support\View::render('page', ['snapshot' => $data, 'checks' => $checks, 'issues' => $issues, 'http' => $http, 'passed' => $passed], __DIR__ . '/templates')), primary: true));
    }
}
