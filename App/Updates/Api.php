<?php
declare(strict_types=1);
namespace TinyCat\Updates;

use TinyCat\Http\Result;

final class Api
{
    public static function register(\TinyCat\Http\Api $api): void
    {

        $api->post('/updates/checks', static fn (): array => ['release' => \TinyCat\Updates\Manager::check(true)], 'admin');
        $api->post('/updates/installations', static function (): Result {
            $result = Result::from(Manager::installLatest());
            return new Result(['installation' => $result->data], $result->message);
        }, 'admin');
        $api->delete('/updates/maintenance', static function (): array { \TinyCat\Updates\Manager::disableMaintenance(); return ['disabled' => true]; }, 'admin');
    }
}
