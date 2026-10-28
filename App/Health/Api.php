<?php
declare(strict_types=1);
namespace TinyCat\Health;

final class Api
{
    public static function register(\TinyCat\Http\Api $api): void
    {
        $api->get('/site-health', Service::snapshot(...), 'admin');
        $api->post('/site-health/checks', static function (): \TinyCat\Http\Result {
            $user = \TinyCat\Users\Authentication::auth();
            $execution = \TinyCat\Http\Session::withoutLock(static fn (): array => \TinyCat\Scheduler\Execution::run('site-health', [], 'admin', (int) ($user['id'] ?? 0)));
            if (!$execution['ok']) { \TinyCat\Http\Response::error('Health inspection could not finish.', 500, 'health_check_failed'); }
            if ($execution['tasks']['site-health']['status'] === 'already_running') { \TinyCat\Http\Response::error('A health inspection is already running.', 409, 'health_check_running'); }
            return new \TinyCat\Http\Result(['execution' => $execution, 'redirect' => '/admin/site-health'], code: 'health_check_finished');
        }, 'admin');
    }
}
