<?php
declare(strict_types=1);

return static function (\PDO $pdo): bool {
    if (!\TinyCat\Database\SchemaInspection::exists($pdo, 'bot_sources')) { return false; }
    \TinyCat\Database\SchemaInspection::requireMigration($pdo, '20261113_001_shared_interactions', '083cee738cc4821c8e4d84365e2059e3e1f291e4943385def6a3213bd58228c3');
    \TinyCat\Database\SchemaInspection::requireColumns($pdo, 'bot_sources', ['id', 'bot_user_id', 'feed_url', 'feed_hash', 'post_template', 'enabled', 'interval_minutes', 'publication_interval_minutes']);
    \TinyCat\Database\SchemaInspection::requireColumns($pdo, 'bot_feed_history', ['bot_user_id', 'feed_hash', 'item_hash', 'created_at']);
    \TinyCat\Database\SchemaInspection::requireColumns($pdo, 'bot_source_posts', ['source_id', 'content_id']);
    \TinyCat\Database\SchemaInspection::requireColumns($pdo, 'bot_source_runs', ['id', 'source_id', 'status']);
    return true;
};
