<?php
declare(strict_types=1);

// Preserve historical opaque references; their producer performs semantic conversion.
return static function (\PDO $pdo): void {
    $sqlite = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $read = static function (string $sql) use ($pdo): array {
        $statement = $pdo->query($sql);
        if ($statement === false) { throw new \RuntimeException('Cannot inspect notification schema.'); }
        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    };
    $columns = array_column($read($sqlite ? 'PRAGMA table_info(notifications)' : "SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications'"), 'name');
    if ($columns === []) { return; }
    if (!in_array('subject_id', $columns, true)) { $pdo->exec('ALTER TABLE notifications ADD COLUMN subject_id ' . ($sqlite ? 'INTEGER' : 'BIGINT UNSIGNED') . ' NULL'); }
    $legacy = array_values(array_intersect(['content_id', 'comment_id'], $columns));
    if ($legacy !== []) {
        $last = 0;
        $select = $pdo->prepare('SELECT id,payload_json,updated_at,' . implode(',', $legacy) . ' FROM notifications WHERE id>? ORDER BY id LIMIT 500');
        $update = $pdo->prepare('UPDATE notifications SET payload_json=?,updated_at=? WHERE id=?');
        do {
            $select->execute([$last]); $rows = $select->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $last = (int) $row['id']; $references = [];
                foreach ($legacy as $column) { if ((int) $row[$column] > 0) { $references[$column] = (int) $row[$column]; } }
                if ($references === []) { continue; }
                $payload = $row['payload_json'] === null ? null : json_decode($row['payload_json'], true);
                if (!is_array($payload) || !isset($payload['_tinycat_legacy_subject']) || !array_key_exists('_tinycat_legacy_payload', $payload)) {
                    $payload = ['_tinycat_legacy_subject' => [], '_tinycat_legacy_payload' => $row['payload_json']];
                }
                foreach ($references as $column => $id) {
                    if (isset($payload['_tinycat_legacy_subject'][$column]) && $payload['_tinycat_legacy_subject'][$column] !== $id) { throw new \RuntimeException('Conflicting legacy notification reference payload.'); }
                    $payload['_tinycat_legacy_subject'][$column] = $id;
                }
                $update->execute([json_encode($payload, JSON_THROW_ON_ERROR), $row['updated_at'], $row['id']]);
            }
        } while (count($rows) === 500);
        \TinyCat\Database\SchemaInspection::dropReferences($pdo, 'notifications', $legacy);
        if ($sqlite) {
            foreach ($read('PRAGMA index_list(notifications)') as $index) {
                $name = '"' . str_replace('"', '""', $index['name']) . '"';
                $indexed = array_column($read('PRAGMA index_info(' . $name . ')'), 'name');
                if (array_intersect($indexed, $legacy) !== []) { $pdo->exec('DROP INDEX ' . $name); }
            }
        }
        foreach ($legacy as $column) { $pdo->exec('ALTER TABLE notifications DROP COLUMN ' . $column); }
    }
    if ($sqlite) { $pdo->exec('CREATE INDEX IF NOT EXISTS notifications_subject_index ON notifications(type,subject_id)'); }
    else {
        $indexes = array_column($read('SHOW INDEX FROM notifications'), 'Key_name');
        if (!in_array('notifications_subject_index', $indexes, true)) { $pdo->exec('CREATE INDEX notifications_subject_index ON notifications(type,subject_id)'); }
    }
};
