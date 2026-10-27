<?php
declare(strict_types=1);
namespace TinyCat\Site;

final class Api
{
    public static function register(\TinyCat\Http\Api $api): void
    {

        $api->get('/site/pages', static function (\TinyCat\Http\Request $request): array {
            $request->validated(['path' => 'string', 'feed' => 'string', 'compact' => 'string', 'q' => 'string', 'type' => 'string']);
            return PublicPages::find($request->path((string) $request->input('path', '/')));
        });
        $api->get('/site/sidebar', static fn (): array => ['tags' => \TinyCat\Status\Tags::public_trending_tags(8, 7, true), 'authors' => array_map(static fn (array $user): array => \TinyCat\Users\Resource::profile($user + ['username' => $user['name'] ?? '']) + ['posts_count' => (int) ($user['posts_count'] ?? 0)], \TinyCat\Users\Repository::public_top_authors(5, 7, true)), 'footer' => \TinyCat\Support\RichText::nodes(\TinyCat\Site\Presentation::site_footer_html())]);
        $api->post('/site/cache-resets', static fn (): array => ['reset' => \TinyCat\Support\Cache::resetOpcache()], 'admin', 200);
    }
}
