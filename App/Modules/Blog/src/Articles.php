<?php
declare(strict_types=1);
namespace TinyCat\Blog;

use TinyCat\Database\{Connection, Keyset, Query, Transactions};
use TinyCat\Support\{Dates, Text};

/** Blog owns complete articles, their visibility and all persistent relations. */
final class Articles
{
    /** @param array<mixed> $input
     * @param array<mixed> $actor
     * @param array<mixed>|null $file */
    public static function save(array $input, array $actor, int $id = 0, ?array $file = null): int
    {
        self::authorize($actor);
        foreach (['title', 'body', 'status'] as $key) { if (isset($input[$key]) && !is_string($input[$key])) { throw new \InvalidArgumentException('Invalid article.', 422); } }
        $title = trim(Text::strip_html_tags_preserving_text((string) ($input['title'] ?? '')));
        $body = trim(Text::strip_html_tags_preserving_text((string) ($input['body'] ?? '')));
        $status = (string) ($input['status'] ?? 'published'); self::validateText($title, $body);
        if (!in_array($status, ['published', 'hidden'], true)) { throw new \InvalidArgumentException('Invalid article.', 422); }
        if ($id > 0 && self::find($id, false) === null) { throw new \DomainException('Article not found.', 404); }
        $remove = filter_var($input['remove_image'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (($file !== null || $remove) && Connection::get()->inTransaction()) { throw new \LogicException('Media changes require ownership of the commit.'); }
        $links = Links::prepare(array_slice(Links::status_links_from_text($body), 0, 3)); $image = null;
        if ($file !== null) {
            if (!Images::enabled()) { throw new \InvalidArgumentException('Article images are disabled.', 422); }
            \TinyCat\Core\Security\RequestLimits::requireAllowed('upload', (int) $actor['id']);
            try { $image = ImageStorage::upload($file); }
            catch (\RuntimeException $error) { throw new \InvalidArgumentException('Invalid article image.', 422, $error); }
        }
        $oldPath = '';
        try {
            $saved = Transactions::run(static function () use ($id, $actor, $title, $body, $status, $links, $image, $remove, &$oldPath): int {
                if ($id === 0) { return self::createPrepared((int) $actor['id'], $title, $body, $status, $links, $image, Dates::dateDb()); }
                if (Connection::one(Connection::lockingSelect('SELECT id FROM blog_articles WHERE id = ?'), [$id]) === null) { throw new \DomainException('Article not found.', 404); }
                Connection::update('blog_articles', ['title' => $title, 'body' => $body, 'excerpt' => self::excerpt($body), 'status' => $status, 'updated_at' => Dates::dateDb()], ['id' => $id]);
                Links::sync($id, $links); Tags::sync($id, $body);
                if ($image !== null || $remove) {
                    $existing = Images::find($id); $oldPath = (string) ($existing['path'] ?? '');
                    Connection::delete('blog_article_images', ['article_id' => $id]);
                    if ($image !== null) { self::storeImage($id, $image); }
                }
                return $id;
            });
        } catch (\Throwable $error) { if ($image !== null) { ImageStorage::delete((string) $image['path']); } throw $error; }
        if ($oldPath !== '') { ImageStorage::delete($oldPath); }
        return $saved;
    }

    /** Trusted feed callers prepare I/O before entering the source transaction.
     * @param list<array<mixed>> $links
     * @param array<mixed>|null $image */
    public static function createPrepared(int $author, string $title, string $body, string $status, array $links, ?array $image, string $publishedAt): int
    {
        self::validateText($title, $body);
        if ($author < 1 || !in_array($status, ['published', 'hidden'], true)) { throw new \InvalidArgumentException('Invalid article.'); }
        return Transactions::run(static function () use ($author, $title, $body, $status, $links, $image, $publishedAt): int {
            $now = Dates::dateDb();
            $id = (int) Connection::insert('blog_articles', ['author_id' => $author, 'title' => $title, 'body' => $body, 'excerpt' => self::excerpt($body), 'status' => $status, 'published_at' => $publishedAt, 'created_at' => $now, 'updated_at' => $now]);
            Links::sync($id, $links); Tags::sync($id, $body);
            if ($image !== null) { self::storeImage($id, $image); }
            return $id;
        });
    }

    /** @param array<mixed> $image */
    private static function storeImage(int $id, array $image): void
    {
        Connection::insert('blog_article_images', ['article_id' => $id, 'path' => (string) $image['path'], 'width' => (int) $image['width'], 'height' => (int) $image['height'], 'bytes' => (int) $image['bytes']]);
    }

    public static function excerpt(string $body): string { return Text::plain_text_limit(\TinyCat\Core\Text\Hashtags::normalizeText(\TinyCat\Core\Text\PostText::normalize($body)), 2000); }
    public static function validateText(string $title, string $body): void
    {
        if ($title === '' || mb_strlen($title) > 200 || trim($body) === '' || strlen($body) > 60000 || !mb_check_encoding($title . $body, 'UTF-8') || Settings::blockedHost($body) !== '') { throw new \InvalidArgumentException('Invalid article.', 422); }
        foreach (\TinyCat\Core\Text\Hashtags::tokens($body) as $token) { if (strlen(Text::slug($token)) > 32) { throw new \InvalidArgumentException('Rejected article tag.', 422); } }
    }

    /** @return array<mixed>|null */
    public static function find(int $id, bool $public = true): ?array
    {
        $article = self::query($public)->where('a.id = ?', $id)->limit(1)->one();
        if ($article === null && $public) {
            $target = Connection::value('SELECT article_id FROM blog_article_redirects WHERE old_id = ?', [$id]);
            if ($target !== null) { $article = self::query()->where('a.id = ?', (int) $target)->limit(1)->one(); }
        }
        return $article;
    }

    /** @return array<mixed> */
    public static function page(int $before = 0, string $at = '', bool $public = true): array
    {
        $query = self::query($public, true); [$condition, $params] = Keyset::beforeDateId($at, $before, 'a.published_at', 'a.id');
        if ($condition !== '') { $query->where($condition, ...$params); }
        return $query->order('a.published_at DESC, a.id DESC')->limit(21)->all();
    }

    public static function query(bool $public = true, bool $preview = false): Query
    {
        $query = new Query('SELECT a.id, a.author_id, a.title, ' . ($preview ? 'a.excerpt' : 'a.body') . ' AS body, a.status, a.published_at, a.created_at, a.updated_at, u.username AS author, u.status AS author_status, i.path AS image_path, i.width AS image_width, i.height AS image_height, i.bytes AS image_bytes FROM blog_articles a INNER JOIN users u ON u.id = a.author_id LEFT JOIN blog_article_images i ON i.article_id = a.id');
        return $public ? self::visible($query) : $query;
    }

    public static function visible(Query $query): Query { return $query->where('a.status = ?', 'published')->where('a.published_at <= ?', Dates::dateDb())->where('u.status = ?', 'active'); }

    /** @param array<mixed> $actor */
    public static function delete(int $id, array $actor): void
    {
        self::authorize($actor);
        if (Connection::get()->inTransaction()) { throw new \LogicException('Article deletion requires ownership of the commit.'); }
        $path = Transactions::run(static function () use ($id): string {
            if (Connection::one(Connection::lockingSelect('SELECT id FROM blog_articles WHERE id = ?'), [$id]) === null) { throw new \DomainException('Article not found.', 404); }
            $image = Images::find($id); $terms = array_column(Connection::all('SELECT term_id FROM blog_article_tags WHERE article_id = ?', [$id]), 'term_id'); $links = array_column(Connection::all('SELECT link_id FROM blog_article_links WHERE article_id = ?', [$id]), 'link_id');
            Connection::delete('blog_articles', ['id' => $id]); Tags::cleanup($terms); Links::cleanup($links);
            return (string) ($image['path'] ?? '');
        });
        if ($path !== '') { ImageStorage::delete($path); }
    }

    /** @param array<mixed> $actor */
    private static function authorize(array $actor): void
    {
        if (($actor['role'] ?? '') !== 'admin' || ($actor['status'] ?? '') !== 'active' || (int) ($actor['id'] ?? 0) < 1) { throw new \DomainException('Forbidden.', 403); }
    }

    /** @param array<mixed> $article
     * @return array<string, mixed> */
    public static function resource(array $article): array
    {
        return ['id' => (int) $article['id'], 'title' => (string) $article['title'], 'body' => (string) $article['body'], 'author' => (string) $article['author'], 'published_at' => (string) $article['published_at'], 'status' => (string) $article['status'], 'url' => '/articles/' . (int) $article['id'], 'image' => ['url' => Images::url($article), 'author' => (string) $article['author'], 'description' => \TinyCat\Core\Ui\Site::meta_text((string) $article['body'], 120)]] + \TinyCat\Core\Ui\RichTextResource::content((int) $article['id'], (string) $article['body'], 'article', Links::forArticle((int) $article['id']));
    }
}
