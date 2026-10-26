<?php
declare(strict_types=1);
namespace TinyCat\Users;

final class Module implements \TinyCat\Module
{
    public static function register(\TinyCat\Http\Api $api): void
    {
        Api::register($api);
        \TinyCat\Http\Router::route('GET', '/author/{author_id:[0-9]+}/feed', static function (string $author_id): void {
            $rssType = 'author';
            $rssAuthorId = max(0, (int) $author_id);

            require \TinyCat\Support\Paths::publicPath('rss.php');
        });

        \TinyCat\Http\Router::route('GET', '/author/{author_id:[0-9]+}', static function (string $author_id): void {
            $_GET['id'] = (string) max(0, (int) $author_id);

            require \TinyCat\Support\Paths::publicPath('author.php');
        });

        \TinyCat\Http\Router::route('GET', '/avatar/{username:' . \TinyCat\Users\Usernames::username_pattern() . '}', static function (string $username): void {
            \TinyCat\Users\Avatar::respond($username);
        });

        foreach ([
            [['GET', 'POST'], '/login', 'login.php'],
            [['GET', 'POST'], '/logout', 'logout.php'],
            [['GET', 'POST'], '/register', 'register.php'],
            [['GET', 'POST'], '/recovery', 'recovery.php'],
            ['GET', '/admin/users', 'admin/users.php'],
            ['GET', '/author', 'author.php'],
        ] as [$methods, $path, $file]) {
            \TinyCat\Http\Router::route($methods, $path, static function () use ($file): void { require \TinyCat\Support\Paths::publicPath($file); });
        }
    }
}
