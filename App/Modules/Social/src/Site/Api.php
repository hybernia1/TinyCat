<?php
declare(strict_types=1);
namespace TinyCat\Social\Site;

final class Api
{
    public static function register(\TinyCat\Http\Api $api): void
    {
        $api = $api->forPublicWeb();

        $api->get('/social/pages', static function (\TinyCat\Http\Request $request): array {
            $request->validated(['path' => 'string', 'feed' => 'string', 'media' => 'string', 'compact' => 'string']);
            return PublicPages::find($request->path((string) $request->input('path', '/')));
        });
        $api->get('/social/sidebar', static fn (): array => Sidebar::load());
    }
}
