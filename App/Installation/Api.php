<?php
declare(strict_types=1);
namespace TinyCat\Installation;

final class Api
{
    public static function register(\TinyCat\Http\Api $api): void
    {
        $api->get('/installation', static fn (): array => Actions::status());
        $api->post('/installation/steps/{step:[a-z]+}', static fn (\TinyCat\Http\Request $request, array $params): \TinyCat\Http\Result => Actions::run($params['step']), 'public', 200);
    }
}
