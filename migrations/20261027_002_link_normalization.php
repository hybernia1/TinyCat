<?php
declare(strict_types=1);

if (!defined('TINYCAT')) { return null; }
return static function (PDO $database, bool $apply = true): array {
    $owned = $apply && !$database->inTransaction();
    if ($owned) { $database->beginTransaction(); }
    try {
        $lock = $apply && $database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $rows = $database->query('SELECT * FROM links ORDER BY id' . $lock)->fetchAll(PDO::FETCH_ASSOC);
        $groups = [];
        $report = ['links' => count($rows), 'changed' => 0, 'merged' => 0, 'invalid' => 0];
        foreach ($rows as $row) {
            $canonical = \TinyCat\Status\LinkParser::fromRaw((string) $row['normalized_url']);
            if ($canonical === null) { $report['invalid']++; continue; }
            if ($row['normalized_url'] !== $canonical['normalized_url'] || $row['url_hash'] !== $canonical['url_hash']) { $report['changed']++; }
            $groups[$canonical['url_hash']][] = ['row' => $row, 'canonical' => $canonical];
        }
        foreach ($groups as $group) {
            $report['merged'] += count($group) - 1;
            if (!$apply) { continue; }
            $winner = $group[0]['row'];
            $canonical = $group[0]['canonical'];
            if (count($group) === 1 && $winner['normalized_url'] === $canonical['normalized_url'] && $winner['url_hash'] === $canonical['url_hash']) { continue; }
            foreach (array_slice($group, 1) as $entry) {
                $loser = $entry['row'];
                foreach (['title', 'description', 'image_url', 'video_id'] as $field) {
                    if (trim((string) ($winner[$field] ?? '')) === '' && !empty($loser[$field])) { $winner[$field] = $loser[$field]; }
                }
                $relations = $database->prepare('SELECT content_id, created_at FROM content_links WHERE link_id = ?');
                $relations->execute([$loser['id']]);
                $insert = $database->prepare($database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                    ? 'INSERT INTO content_links (content_id, link_id, created_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE link_id = VALUES(link_id)'
                    : 'INSERT INTO content_links (content_id, link_id, created_at) VALUES (?, ?, ?) ON CONFLICT (content_id, link_id) DO NOTHING');
                foreach ($relations->fetchAll(PDO::FETCH_ASSOC) as $relation) { $insert->execute([$relation['content_id'], $winner['id'], $relation['created_at']]); }
                $database->prepare('DELETE FROM content_links WHERE link_id = ?')->execute([$loser['id']]);
                $database->prepare('DELETE FROM links WHERE id = ?')->execute([$loser['id']]);
            }
            $database->prepare('UPDATE links SET normalized_url = ?, url_hash = ?, title = ?, description = ?, image_url = ?, video_id = ? WHERE id = ?')
                ->execute([$canonical['normalized_url'], $canonical['url_hash'], $winner['title'] ?? '', $winner['description'] ?? '', $winner['image_url'] ?? '', $winner['video_id'] ?? '', $winner['id']]);
        }
        if ($owned) { $database->commit(); }
        return $report;
    } catch (Throwable $exception) {
        if ($owned && $database->inTransaction()) { $database->rollBack(); }
        throw $exception;
    }
};
