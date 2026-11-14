<?php
declare(strict_types=1);

// Immutable checkpoint for legacy global installations. Publishing now owns these
// existing tables; do not invent standalone history or recreate any user data.
return static function (\PDO $database): void {
    foreach ([
        'content_comments' => 'id, content_id, parent_id, user_id, body, comments_diff, created_at',
        'content_likes' => 'content_id, user_id',
        'comment_likes' => 'comment_id, user_id',
    ] as $table => $columns) {
        if ($database->query('SELECT ' . $columns . ' FROM ' . $table . ' WHERE 1 = 0') === false) {
            throw new \RuntimeException('Shared interaction schema is incomplete: ' . $table);
        }
    }
};
