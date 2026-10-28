<?php
declare(strict_types=1);
namespace TinyCat\Scheduler;

use TinyCat\Http\Request;
use TinyCat\Http\Result;

final class Api
{
    public static function register(\TinyCat\Http\Api $api): void
    {
        $api->get('/scheduler/triggers/{task:[a-z-]+}', static function (Request $request, array $params): Result {
            $task = $params['task'];
            if (!isset(Tasks::definitions()[$task])) { \TinyCat\Http\Response::error('Unknown scheduled task.', 404, 'scheduler_unknown_task'); }
            if ($request->method() === 'HEAD') {
                return new Result(['task' => $task, 'available' => true], null, [], 'scheduler_healthy');
            }
            $result = \TinyCat\Http\Session::withoutLock(static fn (): array => Execution::run($task));
            if (!$result['ok']) { \TinyCat\Http\Response::error('Scheduled execution failed.', 500, 'scheduler_run_failed', ['execution' => $result]); }
            return new Result($result, null, [], 'scheduler_run_finished');
        }, 'scheduler_trigger');
        $api->get('/scheduler/health', static function (Request $request): Result {
            $request->validated(['task' => 'string']);
            return new Result(Execution::health((string) $request->input('task', 'all')), null, [], 'scheduler_healthy');
        }, 'scheduler');
        $api->post('/scheduler/executions', static function (Request $request): Result {
            $request->validated(['task' => 'string']);
            $options = $request->input('options', []);
            if (!is_array($options) || ($options !== [] && array_is_list($options))) { \TinyCat\Http\Response::validation(['options' => ['Task options must be an object.']]); }
            $result = \TinyCat\Http\Session::withoutLock(static fn (): array => Execution::run((string) $request->input('task', 'all'), $options));
            if (!$result['ok']) { \TinyCat\Http\Response::error('Scheduled execution failed.', 500, 'scheduler_run_failed', ['execution' => $result]); }
            return new Result($result, null, [], 'scheduler_run_finished');
        }, 'scheduler', 200);
        $api->get('/scheduler/tasks', Actions::listing(...), 'admin');
        $api->get('/scheduler/runs/{id:[0-9]+}', static fn (Request $request, array $params): array => Actions::detail((int) $params['id']), 'admin');
        $api->post('/scheduler/tasks/{task:[a-z-]+}/runs', static fn (Request $request, array $params): Result => Actions::run((string) $params['task']), 'admin');
        $api->post('/scheduler/tokens', Actions::rotateToken(...), 'admin');
    }
}
