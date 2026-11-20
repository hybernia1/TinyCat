<?php
declare(strict_types=1);
namespace TinyCat\Core\Health;
use TinyCat\Core\Health\Service;

final class Api
{
    public static function register(\TinyCat\Http\Api $api): void
    {
        $api->get('/site-health', Service::snapshot(...), 'admin');
        $api->post('/site-health/checks', static function (): \TinyCat\Http\Result {
            $user = \TinyCat\Core\Identity\Authentication::auth();
            $execution = \TinyCat\Http\Session::withoutLock(static fn (): array => \TinyCat\Core\Tasks\Execution::run('site-health', [], 'admin', (int) ($user['id'] ?? 0)));
            if (!$execution['ok']) { \TinyCat\Http\Response::error('Health inspection could not finish.', 500, 'health_check_failed'); }
            if ($execution['tasks']['site-health']['status'] === 'already_running') { \TinyCat\Http\Response::error('A health inspection is already running.', 409, 'health_check_running'); }
            $healthy = ($execution['tasks']['site-health']['summary']['health']['status'] ?? 'unknown') === 'ok';
            $type = $healthy ? 'success' : 'warning';
            $message = \TinyCat\Support\Localization::t($healthy ? 'health.notification_ok' : 'health.notification_attention');
            \TinyCat\Http\Session::flash($type, $message);
            return new \TinyCat\Http\Result(['execution' => $execution, 'redirect' => '/admin/site-health'], $message, ['type' => $type], 'health_check_finished');
        }, 'admin');
    }
}
