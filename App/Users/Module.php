<?php
declare(strict_types=1);
namespace TinyCat\Users;

final class Module implements \TinyCat\StorageModule
{
    /** @return list<string> */
    public static function tables(): array
    {
        return ['users', 'user_followers', 'password_reset_tokens'];
    }

    public static function register(\TinyCat\Http\Api $api): void
    {
        Api::register($api);
        \TinyCat\Http\Router::route(['GET', 'HEAD'], '/author/{author_id:[0-9]+}/feed', static fn (string $author_id) => \TinyCat\Status\Syndication::respond('author', '', (int) $author_id));

        \TinyCat\Http\Router::route('GET', '/author/{author_id:[0-9]+}', static function (string $author_id): void {
            Pages::author(max(0, (int) $author_id));
        });

        \TinyCat\Http\Router::route('GET', '/avatar/{username:' . \TinyCat\Users\Usernames::username_pattern() . '}', static function (string $username): void {
            \TinyCat\Users\Avatar::respond($username);
        });

        \TinyCat\Http\Router::route('GET', '/admin/users', Pages::administration(...));
        foreach ([
            [['GET', 'POST'], '/login', Pages::login(...)],
            [['GET', 'POST'], '/logout', Pages::logout(...)],
            [['GET', 'POST'], '/register', Pages::register(...)],
            [['GET', 'POST'], '/recovery', Pages::recovery(...)],
            ['GET', '/author', Pages::author(...)],
        ] as [$methods, $path, $handler]) {
            \TinyCat\Http\Router::route($methods, $path, $handler);
        }
    }
}
