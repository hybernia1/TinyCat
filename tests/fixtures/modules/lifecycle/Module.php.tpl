<?php
declare(strict_types=1);
namespace TinyCat\Modules\@@NAMESPACE@@;

final class Module implements \TinyCat\Core\Modules\Module, \TinyCat\Core\Modules\ProvidesSchema, \TinyCat\Core\Modules\ProvidesContributions, \TinyCat\Accounts\ProvidesLifecycle
{
    public static function accountLifecycle(): \TinyCat\Accounts\Participant
    {
        return new \TinyCat\Accounts\Participant(prepareDelete: static fn (array $user): \TinyCat\Accounts\Deletion => new \TinyCat\Accounts\Deletion(
            afterCommit: static function () use ($user): void { \TinyCat\Support\Storage::write(\TinyCat\Support\Storage::path('@@SHORT@@-deleted-' . (int) $user['id'] . '.txt'), 'owned data cleanup'); },
        ));
    }

    public static function schema(): \TinyCat\Core\Modules\ModuleSchema
    {
        $root = dirname(__DIR__); $migrations = [];
        for ($version = 1; $version <= @@VERSION@@; $version++) { $migrations[] = new \TinyCat\Core\Modules\SchemaMigration($version, '@@ID@@.' . $version, $root . '/migrations/' . $version . '.php'); }
        return new \TinyCat\Core\Modules\ModuleSchema(@@VERSION@@, ['@@TABLE@@'], static function (\PDO $pdo) use ($migrations): void {
            foreach ($migrations as $migration) { $apply = require $migration->file; $apply($pdo); }
        }, $migrations);
    }

    public static function contributions(): \TinyCat\Core\Modules\Contributions
    {
        return new \TinyCat\Core\Modules\Contributions(tasks: [new \TinyCat\Core\Modules\ScheduledTask('@@ID@@.tick', static function (array $context): array {
            $count = \TinyCat\Database\Connection::exec('UPDATE @@TABLE@@ SET ticks = ticks + 1');
            return \TinyCat\Support\TaskContext::result('completed', $count, $count);
        })]);
    }

    public static function register(\TinyCat\Http\Api $api): void
    {
        $api->get('/fixture/@@SHORT@@', static fn (): array => ['items' => \TinyCat\Database\Connection::all('SELECT * FROM @@TABLE@@ ORDER BY id')]);
        \TinyCat\Http\Router::route('GET', '/fixture/@@SHORT@@', static function (): void {
            echo '<h1>' . \TinyCat\Support\Html::e(\TinyCat\Support\Localization::t('@@TABLE@@.title')) . '</h1>';
        });
    }
}
