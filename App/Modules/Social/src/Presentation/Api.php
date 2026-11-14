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
        $api->get('/site/sidebar', static fn (): array => ['tags' => \TinyCat\Social\Posts\Tags::public_trending_tags(8, 7, true), 'authors' => array_map(static fn (array $user): array => \TinyCat\Social\Profiles\Resource::profile($user + ['username' => $user['name'] ?? '']) + ['posts_count' => (int) ($user['posts_count'] ?? 0)], \TinyCat\Social\Profiles\Repository::public_top_authors(5, 7, true)), 'privacy_url' => \TinyCat\Http\LocaleRouting::url('/privacy'), 'footer' => \TinyCat\Support\RichText::nodes(\TinyCat\Core\Ui\Site::site_footer_html())]);
    }
}
