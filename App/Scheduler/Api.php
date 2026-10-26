<?php
declare(strict_types=1);
namespace TinyCat\Scheduler;

use TinyCat\Http\Request;
use TinyCat\Http\Result;

final class Api
{
    public static function register(\TinyCat\Http\Api $api): void
    {
        $api->get('/scheduler/tasks', Actions::listing(...), 'admin');
        $api->get('/scheduler/runs/{id:[0-9]+}', static fn (Request $request, array $params): array => Actions::detail((int) $params['id']), 'admin');
        $api->post('/scheduler/tasks/{task:[a-z-]+}/runs', static fn (Request $request, array $params): Result => Actions::run((string) $params['task']), 'admin');
        $api->post('/scheduler/tokens', Actions::rotateToken(...), 'admin');
    }
}
