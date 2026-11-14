<?php
declare(strict_types=1);

// Only Social interprets its historical notification references.
return static function (\PDO $pdo): void {
    if (!\TinyCat\Database\SchemaInspection::exists($pdo, 'notifications')) { return; }
    $types = ['content_like' => 'content_id', 'content_mention' => 'content_id', 'report_resolved' => 'content_id', 'report_dismissed' => 'content_id', 'content_comment' => 'comment_id', 'comment_like' => 'comment_id', 'comment_mention' => 'comment_id', 'follow' => null];
    $last = 0;
    $select = $pdo->prepare('SELECT id,type,payload_json,updated_at FROM notifications WHERE id>? AND type IN (' . implode(',', array_fill(0, count($types), '?')) . ') ORDER BY id LIMIT 500');
    $update = $pdo->prepare('UPDATE notifications SET subject_id=?,payload_json=?,updated_at=? WHERE id=?');
    do {
        $select->execute([$last, ...array_keys($types)]); $rows = $select->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $last = (int) $row['id'];
            if ($row['payload_json'] === null) { continue; }
            $payload = json_decode($row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload) || !isset($payload['_tinycat_legacy_subject'])) { continue; }
            $references = $payload['_tinycat_legacy_subject'];
            if (!array_key_exists('_tinycat_legacy_payload', $payload)) { throw new \RuntimeException('Missing preserved notification payload.'); }
            $field = $types[$row['type']]; $subject = $field === null ? null : ((int) ($references[$field] ?? 0) ?: null);
            $update->execute([$subject, $payload['_tinycat_legacy_payload'], $row['updated_at'], $row['id']]);
        }
    } while (count($rows) === 500);
};
