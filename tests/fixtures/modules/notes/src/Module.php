<?php
declare(strict_types=1);

namespace TinyCat\Modules\PilotNotes;

use TinyCat\Core\Modules\Resources;
use TinyCat\Core\Modules\Contributions;
use TinyCat\Core\Modules\ScheduledTask;
use TinyCat\Http\Api;
use TinyCat\Http\Request;
use TinyCat\Http\Response;

final class Module implements \TinyCat\Core\Modules\Module, \TinyCat\Core\Modules\ProvidesContributions, \TinyCat\Core\Identity\ProvidesLifecycle
{
    public static function accountLifecycle(): \TinyCat\Core\Identity\Participant
    {
        return new \TinyCat\Core\Identity\Participant(prepareDelete: static fn (array $user): \TinyCat\Core\Identity\Deletion => new \TinyCat\Core\Identity\Deletion(
            afterDelete: static function () use ($user): void { \TinyCat\Database\Connection::delete('pilot_notes', ['created_by' => (int) $user['id']]); },
        ));
    }

    public static function contributions(): Contributions
    {
        return new Contributions(tasks: [new ScheduledTask('pilot.notes.refresh', static fn (array $context): array => Notes::refresh($context),
            options: ['batch' => 2], normalize: static fn (array $options): array => ['batch' => max(1, min(100, $options['batch']))])]);
    }

    public static function register(Api $api): void
    {
        $api->get('/pilot/notes', static fn (): array => ['items' => Notes::all()]);
        $api->post('/pilot/notes', static function (Request $request) use ($api): array {
            $title = $request->payload('title');
            if (!is_string($title) || trim($title) === '' || strlen($title) > 80) { Response::validation(['title' => ['A title of 1–80 bytes is required.']]); }
            return ['id' => Notes::create($title, (int) ($api->actor()['id'] ?? 0))];
        }, 'admin');
        \TinyCat\Http\Router::route('GET', '/pilot/notes', static function (): void {
            if (!\TinyCat\Database\ModuleSchemas::ready(\TinyCat\Database\Connection::get(), ['demo.notes' => Schema::schema()])) { http_response_code(503); return; }
            echo \TinyCat\Support\View::render('index', ['items' => Notes::all(),
                'styles' => \TinyCat\Support\ClientAssets::styles('/pilot/notes'), 'scripts' => \TinyCat\Support\ClientAssets::scripts('/pilot/notes'),
            ], Resources::catalog()->get('demo.notes')->resources['templates']);
        });
    }
}
