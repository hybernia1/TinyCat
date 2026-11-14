<?php
declare(strict_types=1);

// Continue the existing disposable Blog database, exercising immutable histories
// rather than requiring a second installed application or another test matrix.
$dropBlog = static function () use ($pdo): void {
    foreach (['blog_source_articles', 'blog_feed_history', 'blog_sources', 'blog_comment_likes', 'blog_comments', 'blog_article_likes', 'blog_article_links', 'blog_links', 'blog_article_tags', 'blog_terms', 'blog_article_images', 'blog_article_redirects', 'blog_articles'] as $table) { $pdo->exec('DROP TABLE ' . $table); }
    $pdo->exec("DELETE FROM module_migrations WHERE module_id = 'tinycat.blog'");
    $pdo->exec("DELETE FROM module_installations WHERE module_id = 'tinycat.blog'");
};
$historical = static function () use ($pdo, $root): void {
    $current = [
        'tinycat.publishing' => \TinyCat\Social\Content\Schema::schema(),
        'tinycat.status' => \TinyCat\Social\Content\InteractionSchema::schema(),
        'tinycat.blog' => \TinyCat\Blog\Module::schema(),
    ];
    $tables = [
        'tinycat.publishing' => ['content', 'content_redirects', 'content_images', 'terms', 'content_tags', 'links', 'content_links'],
        'tinycat.status' => ['content_comments', 'content_likes', 'comment_likes'],
        'tinycat.blog' => ['blog_articles', 'blog_sources', 'blog_feed_history', 'blog_source_articles'],
    ];
    foreach ($current as $id => $schema) {
        $version = $id === 'tinycat.status' ? 1 : 3;
        $history = array_slice($schema->migrations, 0, $version);
        $old = new \TinyCat\Core\Modules\ModuleSchema($version, $tables[$id], static function (PDO $database) use ($history): void {
            foreach ($history as $migration) { (require $migration->file)($database); }
        }, $history);
        \TinyCat\Database\ModuleSchemas::install($pdo, [$id => $old]);
    }
    $pdo->exec("INSERT INTO content(id, author_id, body, kind, published_at) VALUES(101, 2, 'Social body #shared', 'post', '2026-01-01 12:00:00'), (102, 2, 'Article excerpt #shared', 'article', '2026-01-01 12:00:00')");
    $pdo->prepare('INSERT INTO blog_articles(content_id, title, body) VALUES(102, ?, ?)')->execute(['Retained article', str_repeat('Complete article body. ', 150)]);
    $pdo->exec("INSERT INTO content_comments(id, content_id, user_id, body, comments_diff) VALUES(41, 101, 2, 'Social comment', '[]'), (42, 102, 2, 'Article comment', '[]')");
    $pdo->exec("INSERT INTO content_comments(id, content_id, parent_id, user_id, body, comments_diff) VALUES(43, 102, 42, 2, 'Article reply', '[]')");
    $pdo->exec('INSERT INTO content_likes(content_id, user_id) VALUES(101, 2),(102, 2)');
    $pdo->exec('INSERT INTO comment_likes(comment_id, user_id) VALUES(41, 2),(42, 2)');
    $pdo->exec("INSERT INTO content_images(content_id, path, width, height, bytes) VALUES(102, '2026/01/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.webp', 20, 20, 100)");
    $pdo->exec('INSERT INTO content_redirects(old_id, content_id) VALUES(900, 102)');
    $pdo->exec("INSERT INTO terms(id, name) VALUES(7, 'shared')");
    $pdo->exec('INSERT INTO content_tags(content_id, term_id) VALUES(101, 7),(102, 7)');
    $pdo->exec("INSERT INTO links(id, normalized_url, url_hash) VALUES(8, 'https://example.test/retained', '" . hash('sha256', 'https://example.test/retained') . "')");
    $pdo->exec("INSERT INTO content_links(content_id, link_id, created_at) VALUES(101, 8, '2026-01-01 12:00:00'),(102, 8, '2026-01-01 12:00:00')");
    $pdo->exec("INSERT INTO blog_sources(id,author_id,name,feed_url,feed_hash,created_at) VALUES(9,2,'Retained source','https://example.test/feed','" . hash('sha256', 'https://example.test/feed') . "','2026-01-01 12:00:00')");
    $pdo->exec("INSERT INTO blog_feed_history(source_id,item_hash,created_at) VALUES(9,'" . str_repeat('a', 64) . "','2026-01-01 12:00:00')");
    $pdo->exec('INSERT INTO blog_source_articles(source_id,content_id) VALUES(9,102)');
};
$combined = \TinyCat\Core\Modules\Loader::fromSelection($root, require $root . '/App/available-modules.php');
\TinyCat\Core\Modules\Resources::configure($combined->catalog);
$dropBlog(); $historical();
$oldHistory = $pdo->query("SELECT module_id, version, migration, checksum FROM module_migrations WHERE module_id IN ('tinycat.publishing','tinycat.status','tinycat.blog') ORDER BY module_id, version")->fetchAll(PDO::FETCH_ASSOC);
$pdo->exec("DELETE FROM module_migrations WHERE module_id IN ('tinycat.accounts','tinycat.security','tinycat.settings','tinycat.notifications')");
$pdo->exec("DELETE FROM module_installations WHERE module_id IN ('tinycat.accounts','tinycat.security','tinycat.settings','tinycat.notifications')");
$pdo->exec('CREATE TABLE schema_migrations(migration VARCHAR(190) PRIMARY KEY, checksum CHAR(64))');
$pdo->exec("INSERT INTO schema_migrations VALUES('20261113_001_shared_interactions','083cee738cc4821c8e4d84365e2059e3e1f291e4943385def6a3213bd58228c3')");
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
    $pdo->exec('ALTER TABLE notifications ADD CONSTRAINT fk_legacy_probe_content FOREIGN KEY(content_id) REFERENCES content(id) ON DELETE CASCADE');
    $pdo->exec('ALTER TABLE notifications ADD CONSTRAINT fk_legacy_probe_comment FOREIGN KEY(comment_id) REFERENCES content_comments(id) ON DELETE CASCADE');
}
$pdo->exec("INSERT INTO notifications(user_id,actor_id,type,notification_key,content_id,comment_id) VALUES(2,2,'content_comment','retained-social',101,41)");
$combined = \TinyCat\Core\Modules\Loader::fromSelection($root, require $root . '/App/available-modules.php');
\TinyCat\Core\Modules\Resources::configure($combined->catalog);
$plan = \TinyCat\Core\Modules\Distribution::describe($root, 'fixture.platform', require $root . '/App/available-modules.php');
$schemas = \TinyCat\Core\Modules\Distribution::schemas($plan, $root, array_keys($plan['selection']));
\TinyCat\Database\ModuleSchemas::pending($pdo, $schemas);
\TinyCat\Database\ModuleSchemas::synchronize($pdo, $schemas);
$assert((string) $pdo->query('SELECT body FROM blog_articles WHERE id = 102')->fetchColumn() === str_repeat('Complete article body. ', 150), 'Legacy conversion preserves full article text and public ID');
$assert((int) $pdo->query('SELECT article_id FROM blog_article_redirects WHERE old_id = 900')->fetchColumn() === 102, 'Legacy canonical redirects survive');
foreach (['blog_comments' => 2, 'blog_comment_likes' => 1, 'blog_article_likes' => 1, 'blog_article_images' => 1, 'blog_article_tags' => 1, 'blog_article_links' => 1, 'blog_source_articles' => 1, 'blog_feed_history' => 1, 'social_posts' => 1, 'social_comments' => 1, 'social_post_likes' => 1, 'social_comment_likes' => 1, 'social_post_tags' => 1, 'social_post_links' => 1] as $table => $count) {
    $assert((int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() === $count, 'Conversion preserves owner data: ' . $table);
}
foreach ($oldHistory as $record) {
    $query = $pdo->prepare('SELECT migration, checksum FROM module_migrations WHERE module_id = ? AND version = ?'); $query->execute([$record['module_id'], $record['version']]);
    $assert($query->fetch(PDO::FETCH_ASSOC) === ['migration' => $record['migration'], 'checksum' => $record['checksum']], 'Published migration identity and checksum are unchanged');
}
\TinyCat\Database\ModuleSchemas::synchronize($pdo, $schemas);
$assert(!\TinyCat\Database\ModuleSchemas::pending($pdo, $schemas), 'Repeated owner conversion is a verified no-op');
$assert((int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE notification_key = 'retained-social'")->fetchColumn() === 1, 'Core adoption removes obsolete subject foreign keys without deleting inbox entries');

// A formerly Blog-only installation retires empty shared storage; Social can
// subsequently install its own tables without replaying obsolete DDL.
$dropBlog();
foreach (['bot_source_posts', 'bot_source_runs', 'bot_feed_history', 'bot_sources', 'content_reports', 'user_followers', 'social_comment_likes', 'social_comments', 'social_post_likes', 'social_post_links', 'social_links', 'social_post_tags', 'social_terms', 'social_post_images', 'social_post_redirects', 'social_posts'] as $table) { $pdo->exec('DROP TABLE ' . $table); }
$pdo->exec("DELETE FROM module_migrations WHERE module_id IN ('tinycat.publishing','tinycat.status','tinycat.users','tinycat.moderation','tinycat.bots')");
$pdo->exec("DELETE FROM module_installations WHERE module_id IN ('tinycat.publishing','tinycat.status','tinycat.users','tinycat.moderation','tinycat.bots')");
$historical();
$pdo->exec('DELETE FROM content WHERE id = 101');
$blogOnly = \TinyCat\Core\Modules\Loader::fromSelection($root, require $root . '/App/Modules/Blog/selection.php');
\TinyCat\Core\Modules\Resources::configure($blogOnly->catalog);
\TinyCat\Database\ModuleSchemas::synchronize($pdo, \TinyCat\Core\Modules\Distribution::definitions($blogOnly, false));
$assert(!\TinyCat\Database\SchemaInspection::exists($pdo, 'content') && !\TinyCat\Database\SchemaInspection::exists($pdo, 'social_posts'), 'Blog-only legacy conversion removes empty shared storage without installing Social');
$assert(\TinyCat\Database\ModuleSchemas::state($pdo, 'tinycat.publishing')['status'] === 'retired' && \TinyCat\Database\ModuleSchemas::state($pdo, 'tinycat.status')['status'] === 'retired', 'Retired histories remain auditable instead of being deleted');
\TinyCat\Core\Modules\Resources::configure($combined->catalog);
\TinyCat\Database\ModuleSchemas::pending($pdo, $schemas);
\TinyCat\Database\ModuleSchemas::synchronize($pdo, $schemas);
$assert((int) $pdo->query('SELECT COUNT(*) FROM social_posts')->fetchColumn() === 0 && (int) $pdo->query('SELECT COUNT(*) FROM blog_articles')->fetchColumn() === 1, 'Adding Social after Blog-only conversion creates empty independent storage and preserves articles');
$assert(\TinyCat\Database\ModuleSchemas::ready($pdo, $schemas), 'Subsequently installed Social has complete verified schema histories');
