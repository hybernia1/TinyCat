<?php
declare(strict_types=1);
namespace TinyCat\Blog;

final class Api
{
    public static function register(\TinyCat\Http\Api $api): void
    {
        Discussion::register($api);
        FeedAdministration::register($api);
        $api->get('/api/articles/{id:[1-9][0-9]*}/links/{hash:[a-f0-9]{64}}', static function (\TinyCat\Http\Request $request, array $params): array {
            $article = Articles::find((int) $params['id']);
            return \TinyCat\Core\Links\Outbound::response($request, $article !== null ? \TinyCat\Core\Links\Outbound::resolve($params['hash'], (string) $article['body'], static fn (): array => Links::forArticle((int) $article['id']), Settings::tagged(...)) : null);
        });
        $api->get('/api/articles/management', static fn (\TinyCat\Http\Request $request): array => Administration::listing($request->query()), 'admin');
        $api->get('/api/articles/creation', static fn (): array => Administration::editor(), 'admin');
        $api->get('/api/articles/{id:[1-9][0-9]*}/management', static fn (\TinyCat\Http\Request $request, array $params): array => Administration::editor((int) $params['id']), 'admin');
        $api->get('/api/articles/{id:[1-9][0-9]*}', static function (\TinyCat\Http\Request $request, array $params): array {
            $article = Articles::find((int) $params['id']);
            if ($article === null) { \TinyCat\Http\Response::error('Not found.', 404, 'article_not_found'); }
            return Articles::resource($article);
        });
        $save = static function (\TinyCat\Http\Request $request, array $params) use ($api): array {
            $request->validated(['title' => 'required|string|max:200', 'body' => 'required|string', 'status' => 'string|in:published,hidden']);
            try {
                $id = Articles::save($request->payload(), $api->actor() ?? [], (int) ($params['id'] ?? 0), Images::uploaded());
                return Articles::resource(Articles::find($id, false) ?? throw new \LogicException('Saved article missing.'));
            } catch (\InvalidArgumentException) { \TinyCat\Http\Response::error('Invalid article.', 422, 'article_invalid'); }
            catch (\DomainException $error) { \TinyCat\Http\Response::error($error->getMessage(), $error->getCode(), 'article_unavailable'); }
        };
        $api->post('/api/articles', $save, 'admin');
        $api->put('/api/articles/{id:[1-9][0-9]*}', $save, 'admin');
        $api->delete('/api/articles/{id:[1-9][0-9]*}', static function (\TinyCat\Http\Request $request, array $params) use ($api): array {
            try { Articles::delete((int) $params['id'], $api->actor() ?? []); }
            catch (\DomainException $error) { \TinyCat\Http\Response::error($error->getMessage(), $error->getCode(), 'article_unavailable'); }
            return ['id' => (int) $params['id']];
        }, 'admin');
    }
}
