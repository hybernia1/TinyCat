<?php
declare(strict_types=1);

return static function (\PDO $pdo): bool {
    if (!\TinyCat\Database\SchemaInspection::exists($pdo, 'blog_articles')) { return false; }
    if (in_array('id', \TinyCat\Database\SchemaInspection::columns($pdo, 'blog_articles'), true)) { return false; }
    \TinyCat\Database\SchemaInspection::requireColumns($pdo, 'blog_articles', ['content_id', 'title', 'body']);
    \TinyCat\Database\SchemaInspection::requireMigration($pdo, '20261113_001_shared_interactions', '083cee738cc4821c8e4d84365e2059e3e1f291e4943385def6a3213bd58228c3');
    \TinyCat\Blog\LegacyData::migrate($pdo);
    return true;
};
