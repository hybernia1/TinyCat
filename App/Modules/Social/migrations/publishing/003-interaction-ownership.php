<?php
declare(strict_types=1);

// Explicit ownership checkpoint. Never rename or replay the published status history.
return static function (\PDO $database): void {
    $query = $database->query("SELECT version, status FROM module_installations WHERE module_id = 'tinycat.status'");
    $state = $query !== false ? $query->fetch(\PDO::FETCH_ASSOC) : false;
    // Fresh and former Blog-only installations install the retained stream separately.
    if ($state === false) { return; }
    if ((int) $state['version'] !== 1 || $state['status'] !== 'ready') { throw new \RuntimeException('Interaction ownership requires a ready historical stream.'); }
    foreach (['content_comments', 'content_likes', 'comment_likes'] as $table) {
        if ($database->query('SELECT 1 FROM ' . $table . ' LIMIT 1') === false) { throw new \RuntimeException('Historical interaction table is missing: ' . $table); }
    }
};
