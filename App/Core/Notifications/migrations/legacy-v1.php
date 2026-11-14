<?php
declare(strict_types=1);

// Explicit adoption of an authenticated legacy global installation.
return static function (\PDO $pdo): bool {
    if (!\TinyCat\Database\SchemaInspection::exists($pdo, 'notifications')) { return false; }
    \TinyCat\Database\SchemaInspection::requireMigration($pdo, '20261113_001_shared_interactions', '083cee738cc4821c8e4d84365e2059e3e1f291e4943385def6a3213bd58228c3');
    \TinyCat\Database\SchemaInspection::requireColumns($pdo, 'notifications', ['id', 'user_id', 'actor_id', 'type', 'notification_key', 'payload_json', 'read_at', 'created_at', 'updated_at']);
    \TinyCat\Database\SchemaInspection::dropReferences($pdo, 'notifications', ['content_id', 'comment_id']);
    return true;
};
