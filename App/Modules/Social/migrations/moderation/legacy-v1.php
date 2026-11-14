<?php
declare(strict_types=1);

return static function (\PDO $pdo): bool {
    if (!\TinyCat\Database\SchemaInspection::exists($pdo, 'content_reports')) { return false; }
    \TinyCat\Database\SchemaInspection::requireMigration($pdo, '20261113_001_shared_interactions', '083cee738cc4821c8e4d84365e2059e3e1f291e4943385def6a3213bd58228c3');
    \TinyCat\Database\SchemaInspection::requireColumns($pdo, 'content_reports', ['id', 'content_id', 'reporter_id', 'reason', 'note', 'status', 'created_at', 'reviewed_at', 'reviewed_by', 'action_note']);
    return true;
};
