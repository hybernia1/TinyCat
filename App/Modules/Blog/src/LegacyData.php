<?php
declare(strict_types=1);
namespace TinyCat\Blog;

use PDO;
use TinyCat\Database\{ModuleSchemas, SchemaInspection};

/** Frozen version-four transfer from the former shared publication aggregate. */
final class LegacyData
{
    public static function migrate(PDO $pdo): void
    {
        SchemaInspection::requireColumns($pdo, 'blog_articles', ['title', 'body']);
        if (in_array('id', SchemaInspection::columns($pdo, 'blog_articles'), true)) {
            if (SchemaInspection::exists($pdo, 'blog_articles_legacy_v3')) { throw new \RuntimeException('Restore the interrupted article conversion before retrying.'); }
            foreach (Schema::tables() as $table) { if (!SchemaInspection::exists($pdo, $table)) { throw new \RuntimeException('Incomplete article conversion: ' . $table); } }
            return;
        }
        SchemaInspection::requireColumns($pdo, 'blog_articles', ['content_id', 'title', 'body']);
        SchemaInspection::requireColumns($pdo, 'content', ['id', 'kind', 'author_id', 'body', 'status', 'published_at']);
        SchemaInspection::requireColumns($pdo, 'blog_source_articles', ['source_id', 'content_id']);
        foreach (['blog_article_images', 'blog_article_redirects', 'blog_terms', 'blog_article_tags', 'blog_links', 'blog_article_links', 'blog_comments', 'blog_article_likes', 'blog_comment_likes', 'blog_articles_legacy_v3', 'blog_source_articles_legacy_v3'] as $table) { if (SchemaInspection::exists($pdo, $table)) { throw new \RuntimeException('Article destination already exists: ' . $table); } }
        if ((int) \TinyCat\Database\Connection::queryOn($pdo, "SELECT COUNT(*) FROM blog_articles a LEFT JOIN content c ON c.id = a.content_id WHERE c.id IS NULL OR c.kind <> 'article'")->fetchColumn() !== 0
            || (int) \TinyCat\Database\Connection::queryOn($pdo, "SELECT COUNT(*) FROM content c LEFT JOIN blog_articles a ON a.content_id = c.id WHERE c.kind = 'article' AND a.content_id IS NULL")->fetchColumn() !== 0) { throw new \RuntimeException('Historical article identities do not agree with their publication owner.'); }
        foreach (['content_images' => ['content_id', 'path', 'width', 'height', 'bytes'], 'content_redirects' => ['old_id', 'content_id'], 'content_comments' => ['id', 'content_id', 'parent_id', 'user_id', 'body', 'comments_diff', 'created_at'], 'content_likes' => ['content_id', 'user_id'], 'comment_likes' => ['comment_id', 'user_id'], 'terms' => ['id', 'name'], 'content_tags' => ['content_id', 'term_id'], 'links' => ['id', 'normalized_url', 'url_hash', 'provider', 'link_type', 'title', 'description', 'image_url', 'video_id', 'audio_url', 'created_at', 'updated_at'], 'content_links' => ['content_id', 'link_id', 'created_at']] as $table => $columns) { SchemaInspection::requireColumns($pdo, $table, $columns); }
        self::validateSubjects($pdo);
        $articleNext = SchemaInspection::nextId($pdo, 'content'); $commentNext = SchemaInspection::nextId($pdo, 'content_comments');
        $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        if (!$sqlite && $pdo->inTransaction()) { throw new \RuntimeException('Article schema conversion requires ownership of the DDL operation.'); }
        $transaction = $sqlite && !$pdo->inTransaction();
        if ($transaction) { $pdo->beginTransaction(); }
        try {
            SchemaInspection::rename($pdo, ['blog_articles' => 'blog_articles_legacy_v3', 'blog_source_articles' => 'blog_source_articles_legacy_v3']);
            $install = require dirname(__DIR__) . '/migrations/fresh-v4.php'; $install($pdo);
            self::copy($pdo, 'blog_articles', 'id, author_id, title, body, excerpt, status, published_at, created_at, updated_at', 'SELECT c.id, c.author_id, a.title, a.body, c.body, c.status, c.published_at, c.published_at, c.published_at FROM content c INNER JOIN blog_articles_legacy_v3 a ON a.content_id = c.id', 'id', 'c.id');
            self::copy($pdo, 'blog_article_images', 'article_id, path, width, height, bytes', 'SELECT i.content_id, i.path, i.width, i.height, i.bytes FROM content_images i INNER JOIN blog_articles_legacy_v3 a ON a.content_id = i.content_id', 'article_id', 'i.content_id');
            self::copy($pdo, 'blog_article_redirects', 'old_id, article_id', 'SELECT r.old_id, r.content_id FROM content_redirects r INNER JOIN blog_articles_legacy_v3 a ON a.content_id = r.content_id', 'old_id', 'r.old_id');
            self::copy($pdo, 'blog_terms', 'id, name', 'SELECT t.id, t.name FROM terms t WHERE EXISTS (SELECT 1 FROM content_tags r INNER JOIN blog_articles_legacy_v3 a ON a.content_id = r.content_id WHERE r.term_id = t.id)', 'id', 't.id');
            self::copy($pdo, 'blog_article_tags', 'article_id, term_id', 'SELECT r.content_id, r.term_id FROM content_tags r INNER JOIN blog_articles_legacy_v3 a ON a.content_id = r.content_id', 'article_id, term_id', 'r.content_id, r.term_id');
            self::copy($pdo, 'blog_links', 'id, normalized_url, url_hash, provider, link_type, title, description, image_url, video_id, audio_url, created_at, updated_at', 'SELECT l.id, l.normalized_url, l.url_hash, l.provider, l.link_type, l.title, l.description, l.image_url, l.video_id, l.audio_url, l.created_at, l.updated_at FROM links l WHERE EXISTS (SELECT 1 FROM content_links r INNER JOIN blog_articles_legacy_v3 a ON a.content_id = r.content_id WHERE r.link_id = l.id)', 'id', 'l.id');
            self::copy($pdo, 'blog_article_links', 'article_id, link_id, created_at', 'SELECT r.content_id, r.link_id, r.created_at FROM content_links r INNER JOIN blog_articles_legacy_v3 a ON a.content_id = r.content_id', 'article_id, link_id', 'r.content_id, r.link_id');
            // Root comments are inserted first; replies retain their IDs, parents and edit history.
            $comments = 'SELECT c.id, c.content_id, c.parent_id, c.user_id, c.body, c.comments_diff, c.created_at FROM content_comments c INNER JOIN blog_articles_legacy_v3 a ON a.content_id = c.content_id';
            $pdo->exec('INSERT INTO blog_comments (id, article_id, parent_id, user_id, body, comments_diff, created_at) ' . $comments . ' ORDER BY CASE WHEN c.parent_id IS NULL OR c.parent_id = 0 THEN 0 ELSE 1 END, c.id');
            self::compare($pdo, $comments . ' ORDER BY c.id', 'SELECT id, article_id, parent_id, user_id, body, comments_diff, created_at FROM blog_comments ORDER BY id');
            self::copy($pdo, 'blog_article_likes', 'article_id, user_id', 'SELECT r.content_id, r.user_id FROM content_likes r INNER JOIN blog_articles_legacy_v3 a ON a.content_id = r.content_id', 'article_id, user_id', 'r.content_id, r.user_id');
            self::copy($pdo, 'blog_comment_likes', 'comment_id, user_id', 'SELECT r.comment_id, r.user_id FROM comment_likes r INNER JOIN content_comments c ON c.id = r.comment_id INNER JOIN blog_articles_legacy_v3 a ON a.content_id = c.content_id', 'comment_id, user_id', 'r.comment_id, r.user_id');
            self::copy($pdo, 'blog_source_articles', 'source_id, article_id', 'SELECT source_id, content_id FROM blog_source_articles_legacy_v3', 'source_id, article_id', 'source_id, content_id');
            SchemaInspection::reserveIds($pdo, 'blog_articles', $articleNext); SchemaInspection::reserveIds($pdo, 'blog_comments', $commentNext);
            self::settings($pdo);
            $pdo->exec('DROP TABLE blog_source_articles_legacy_v3'); $pdo->exec('DROP TABLE blog_articles_legacy_v3');
            $pdo->exec("DELETE FROM content WHERE kind = 'article'");
            self::releaseEmptyCoreStorage($pdo);
            if ($transaction) { $pdo->commit(); }
        } catch (\Throwable $error) { if ($transaction && $pdo->inTransaction()) { $pdo->rollBack(); } throw $error; }
    }

    private static function validateSubjects(PDO $pdo): void
    {
        $cross = "SELECT COUNT(*) FROM content_comments child INNER JOIN content_comments parent ON parent.id = child.parent_id INNER JOIN content c ON c.id = child.content_id INNER JOIN content p ON p.id = parent.content_id WHERE (c.kind = 'article' OR p.kind = 'article') AND (child.content_id <> parent.content_id OR c.kind <> p.kind OR parent.parent_id IS NOT NULL AND parent.parent_id <> 0)";
        if ((int) \TinyCat\Database\Connection::queryOn($pdo, $cross)->fetchColumn() !== 0) { throw new \RuntimeException('Historical article comments have cross-owner or unsupported ancestry.'); }
        if (SchemaInspection::exists($pdo, 'content_reports') && (int) \TinyCat\Database\Connection::queryOn($pdo, 'SELECT COUNT(*) FROM content_reports r INNER JOIN blog_articles a ON a.content_id = r.content_id')->fetchColumn() !== 0) { throw new \RuntimeException('A historical moderation producer still references articles.'); }
        foreach (['bot_source_posts', 'bot_source_runs'] as $table) {
            if (SchemaInspection::exists($pdo, $table) && in_array('content_id', SchemaInspection::columns($pdo, $table), true) && (int) \TinyCat\Database\Connection::queryOn($pdo, 'SELECT COUNT(*) FROM ' . $table . ' r INNER JOIN blog_articles a ON a.content_id = r.content_id')->fetchColumn() !== 0) { throw new \RuntimeException('A historical automation producer still references articles.'); }
        }
        if (SchemaInspection::exists($pdo, 'notifications')) {
            $columns = SchemaInspection::columns($pdo, 'notifications'); $conditions = [];
            if (in_array('content_id', $columns, true)) { $conditions[] = 'n.content_id IN (SELECT content_id FROM blog_articles)'; }
            if (in_array('comment_id', $columns, true)) { $conditions[] = 'n.comment_id IN (SELECT c.id FROM content_comments c INNER JOIN blog_articles a ON a.content_id = c.content_id)'; }
            if ($conditions !== [] && (int) \TinyCat\Database\Connection::queryOn($pdo, 'SELECT COUNT(*) FROM notifications n WHERE ' . implode(' OR ', $conditions))->fetchColumn() !== 0) { throw new \RuntimeException('A historical notification producer still references articles; convert its subject before upgrading Blog.'); }
        }
    }

    private static function copy(PDO $pdo, string $table, string $columns, string $select, string $targetOrder, string $sourceOrder): void
    {
        $pdo->exec('INSERT INTO ' . $table . ' (' . $columns . ') ' . $select);
        self::compare($pdo, $select . ' ORDER BY ' . $sourceOrder, 'SELECT ' . $columns . ' FROM ' . $table . ' ORDER BY ' . $targetOrder);
    }
    private static function compare(PDO $pdo, string $source, string $target): void
    {
        $digest = static function (string $sql) use ($pdo): string {
            $hash = hash_init('sha256'); $query = \TinyCat\Database\Connection::queryOn($pdo, $sql);
            while ($row = $query->fetch(PDO::FETCH_NUM)) { hash_update($hash, json_encode(array_map(static fn (mixed $value): ?string => $value === null ? null : (string) $value, $row), JSON_THROW_ON_ERROR) . "\n"); }
            return hash_final($hash);
        };
        if (!hash_equals($digest($source), $digest($target))) { throw new \RuntimeException('Historical article transfer did not preserve every selected value.'); }
    }

    private static function settings(PDO $pdo): void
    {
        if (!SchemaInspection::exists($pdo, 'settings')) { return; }
        $keys = ['content_images.enabled' => ['blog.images.enabled', 'bool', true], 'content_images.max_upload_kb' => ['blog.images.max_upload_kb', 'int', 100], 'moderation.blocked_urls' => ['blog.blocked_urls', 'string', ''], 'content_links.utm.enabled' => ['blog.links.utm.enabled', 'bool', true], 'content_links.utm.source' => ['blog.links.utm.source', 'string', ''], 'content_links.utm.medium' => ['blog.links.utm.medium', 'string', 'referral'], 'content_links.utm.campaign' => ['blog.links.utm.campaign', 'string', '']];
        $exists = $pdo->prepare('SELECT 1 FROM settings WHERE setting_key = ?');
        $find = $pdo->prepare('SELECT setting_value, setting_type, autoload FROM settings WHERE setting_key = ?'); $insert = $pdo->prepare('INSERT INTO settings(setting_key, setting_group, setting_value, setting_type, autoload) VALUES (?, ?, ?, ?, ?)');
        foreach ($keys as $old => [$new, $type, $default]) {
            $exists->execute([$new]); if ($exists->fetchColumn() !== false) { continue; }
            $find->execute([$old]); $row = $find->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                $value = \TinyCat\Support\Config::get($old, $default);
                $row = ['setting_value' => $type === 'bool' ? (in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true) ? '1' : '0') : ($type === 'int' ? (string) (int) $value : \TinyCat\Support\Values::string($value)), 'setting_type' => $type, 'autoload' => 1];
            }
            $insert->execute([$new, 'blog', $row['setting_value'], $row['setting_type'], $row['autoload']]);
        }
    }

    private static function releaseEmptyCoreStorage(PDO $pdo): void
    {
        $root = \TinyCat\Support\Paths::basePath(); $plan = \TinyCat\Core\Modules\Distribution::read($root); $state = \TinyCat\Core\Modules\Deployment::read($root);
        $social = $plan !== null && $state !== null
            ? in_array('tinycat.social', \TinyCat\Core\Modules\Upgrade::ids($plan, $state['installed']), true)
            : \TinyCat\Core\Modules\Resources::catalog()->has('tinycat.social');
        if ($social) { return; }
        if ((int) \TinyCat\Database\Connection::queryOn($pdo, 'SELECT COUNT(*) FROM content')->fetchColumn() !== 0) { throw new \RuntimeException('Unregistered historical content still has an owner.'); }
        foreach (['tinycat.publishing' => 3, 'tinycat.status' => 1] as $id => $version) {
            $installation = ModuleSchemas::state($pdo, $id);
            if ($installation !== null) {
                if ((int) $installation['version'] !== $version || $installation['status'] !== 'ready') { throw new \RuntimeException('Unsupported historical publication retirement: ' . $id); }
                $pdo->prepare("UPDATE module_installations SET status = 'retired' WHERE module_id = ?")->execute([$id]);
            }
        }
        foreach (['comment_likes', 'content_comments', 'content_likes', 'content_tags', 'content_links', 'content_images', 'content_redirects', 'content', 'terms', 'links'] as $table) { $pdo->exec('DROP TABLE ' . $table); }
    }
}
