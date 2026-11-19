<?php
declare(strict_types=1);
namespace TinyCat\Social\Presentation;

final class Api
{
    public static function register(\TinyCat\Http\Api $api): void
    {

        $api->get('/site/pages', static function (\TinyCat\Http\Request $request): array {
            $request->validated(['path' => 'string', 'feed' => 'string', 'media' => 'string', 'compact' => 'string', 'q' => 'string', 'type' => 'string']);
            return PublicPages::find($request->path((string) $request->input('path', '/')));
        });
        $api->get('/site/sidebar', static fn (): array => Sidebar::load());
    }
}
