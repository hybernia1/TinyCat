<?php
declare(strict_types=1);
namespace TinyCat\Core\Health;
use TinyCat\Core\Health\Checks;
use TinyCat\Core\Health\Service;

final class ServiceProvider implements \TinyCat\Core\Modules\StorageModule, \TinyCat\Core\Modules\ProvidesContributions
{
    public static function contributions(): \TinyCat\Core\Modules\Contributions
    {
        $probes = [];
        foreach (Checks::localDefinitions() as $id => $run) { $probes[] = new \TinyCat\Core\Modules\HealthProbe($id, $run, count($probes) * 10); }
        return new \TinyCat\Core\Modules\Contributions(temporaryDirectories: [
            new \TinyCat\Core\Modules\TemporaryDirectory('health-uploads', static fn (): string => \TinyCat\Support\Paths::basePath('uploads/.health-probes'), 50),
            new \TinyCat\Core\Modules\TemporaryDirectory('health-storage', static fn (): string => \TinyCat\Support\Paths::basePath('storage/health-probes'), 60),
        ],
            tasks: [new \TinyCat\Core\Modules\ScheduledTask('site-health', static fn (array $context): array => Service::run($context),
                admin: ['icon' => 'shield', 'title' => 'health.title', 'help' => 'health.intro', 'schedule' => 'health.schedule', 'cadence' => 'health.schedule_short'], order: 30)],
            widgets: [new \TinyCat\Core\Modules\DashboardWidget('core.health', self::widget(...), 10, type: 'status', title: static fn (): string => \TinyCat\Support\Localization::t('health.title'), icon: 'shield')],
            health: $probes,
            componentLookups: [new \TinyCat\Core\Modules\ComponentLookup('health.tasks', self::taskLabels(...))],
            notificationTypes: \TinyCat\Core\Health\Alerts::types(),
        );
    }

    /** @return array<string, array{label: string|array{t: string}}> */
    public static function taskLabels(): array
    {
        $labels = [];
        foreach (\TinyCat\Core\Modules\Registrations::current()->entries->tasks as $task) {
            $title = $task->admin['title'] ?? '';
            $labels[$task->id] = ['label' => $title !== '' ? ['t' => $title] : $task->id];
        }
        return $labels;
    }

    /** @return array<string, mixed> */
    public static function widget(): array
    {
        $snapshot = Service::snapshot();
        $status = $snapshot['status'];
        return ['text' => \TinyCat\Support\Localization::t('health.status.' . $status), 'tone' => match ($status) { 'ok' => 'success', 'critical' => 'danger', 'warning' => 'warning', default => 'info' }, 'detail' => !empty($snapshot['report']['checked_at']) ? \TinyCat\Support\Dates::displayDateTime($snapshot['report']['checked_at']) : \TinyCat\Support\Localization::t('health.empty'), 'url' => self::historyUrl($snapshot['history'][0]['id'] ?? null)];
    }

    /** @return list<string> */
    public static function tables(): array { return []; }

    public static function historyUrl(?int $runId = null): string
    {
        return \TinyCat\Core\Ui\Admin\Definitions::get('core.scheduler-runs')['path'] . '?task=site-health' . ($runId !== null && $runId > 0 ? '#modal=scheduler/runs/' . $runId : '');
    }

    public static function register(\TinyCat\Http\Api $api): void
    {
        Api::register($api);
    }
}
