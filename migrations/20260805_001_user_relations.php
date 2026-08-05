<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    return null;
}

return static function (PDO $database): void {
    if ((string) $database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('The user relations migration requires MySQL or MariaDB.');
    }

    $schema = (string) $database->query('SELECT DATABASE()')->fetchColumn();

    if ($schema === '') {
        throw new RuntimeException('Unable to resolve the active database schema.');
    }

    $execute = static function (string $sql) use ($database): int {
        $result = $database->exec($sql);

        if ($result === false) {
            throw new RuntimeException('Database migration statement failed.');
        }

        return $result;
    };

    $columnNullable = static function (string $table, string $column) use ($database, $schema): bool {
        $statement = $database->prepare(
            'SELECT IS_NULLABLE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
             LIMIT 1'
        );
        $statement->execute([$schema, $table, $column]);
        $value = $statement->fetchColumn();

        if ($value === false) {
            throw new RuntimeException('Missing migration column: ' . $table . '.' . $column);
        }

        return strtoupper((string) $value) === 'YES';
    };

    $foreignKey = static function (
        string $table,
        string $column,
        string $referencedTable,
        string $referencedColumn,
        string $constraint,
        string $deleteRule
    ) use ($database, $schema, $execute): void {
        foreach ([$table, $column, $referencedTable, $referencedColumn, $constraint] as $identifier) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
                throw new RuntimeException('Unsafe migration identifier.');
            }
        }

        $deleteRule = strtoupper($deleteRule);

        if (!in_array($deleteRule, ['CASCADE', 'SET NULL'], true)) {
            throw new RuntimeException('Unsupported foreign-key delete rule.');
        }

        $statement = $database->prepare(
            'SELECT kcu.CONSTRAINT_NAME, rc.DELETE_RULE
             FROM information_schema.KEY_COLUMN_USAGE kcu
             INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
                ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
               AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
               AND rc.TABLE_NAME = kcu.TABLE_NAME
             WHERE kcu.TABLE_SCHEMA = ?
               AND kcu.TABLE_NAME = ?
               AND kcu.COLUMN_NAME = ?
               AND kcu.REFERENCED_TABLE_NAME = ?
               AND kcu.REFERENCED_COLUMN_NAME = ?
             LIMIT 1'
        );
        $statement->execute([$schema, $table, $column, $referencedTable, $referencedColumn]);
        $existing = $statement->fetch(PDO::FETCH_ASSOC);

        if (is_array($existing)) {
            if (strtoupper((string) ($existing['DELETE_RULE'] ?? '')) !== $deleteRule) {
                throw new RuntimeException(
                    'Foreign key ' . $table . '.' . $column . ' already exists with a different delete rule.'
                );
            }

            return;
        }

        $quote = static fn (string $identifier): string => '`' . $identifier . '`';
        $execute(
            'ALTER TABLE ' . $quote($table)
            . ' ADD CONSTRAINT ' . $quote($constraint)
            . ' FOREIGN KEY (' . $quote($column) . ')'
            . ' REFERENCES ' . $quote($referencedTable) . ' (' . $quote($referencedColumn) . ')'
            . ' ON DELETE ' . $deleteRule
        );
    };

    // Audit references must be nullable before orphan values can be preserved.
    if (!$columnNullable('notifications', 'actor_id')) {
        $execute('ALTER TABLE notifications MODIFY actor_id INT UNSIGNED NULL');
    }

    if (!$columnNullable('content_reports', 'reporter_id')) {
        $execute('ALTER TABLE content_reports MODIFY reporter_id INT UNSIGNED NULL');
    }

    // Remove data that cannot have a valid owner under the new CASCADE policy.
    $execute(
        'DELETE c FROM content c
         LEFT JOIN users u ON u.id = c.author_id
         WHERE c.author_id IS NULL OR u.id IS NULL'
    );

    $execute('DELETE ct FROM content_tags ct LEFT JOIN content c ON c.id = ct.content_id LEFT JOIN terms t ON t.id = ct.term_id WHERE c.id IS NULL OR t.id IS NULL');
    $execute('DELETE cl FROM content_links cl LEFT JOIN content c ON c.id = cl.content_id LEFT JOIN links l ON l.id = cl.link_id WHERE c.id IS NULL OR l.id IS NULL');
    $execute('DELETE cl FROM content_likes cl LEFT JOIN content c ON c.id = cl.content_id LEFT JOIN users u ON u.id = cl.user_id WHERE c.id IS NULL OR u.id IS NULL');
    $execute('DELETE cc FROM content_comments cc LEFT JOIN content c ON c.id = cc.content_id LEFT JOIN users u ON u.id = cc.user_id WHERE c.id IS NULL OR u.id IS NULL');

    do {
        $removedComments = $execute(
            'DELETE cc FROM content_comments cc
             LEFT JOIN content_comments parent ON parent.id = cc.parent_id
             WHERE cc.parent_id IS NOT NULL AND parent.id IS NULL'
        );
    } while ($removedComments > 0);

    $execute(
        'UPDATE content_comments cc
         INNER JOIN content_comments parent ON parent.id = cc.parent_id
         SET cc.parent_id = NULL
         WHERE cc.content_id <> parent.content_id'
    );
    $execute('DELETE cl FROM comment_likes cl LEFT JOIN content_comments cc ON cc.id = cl.comment_id LEFT JOIN users u ON u.id = cl.user_id WHERE cc.id IS NULL OR u.id IS NULL');
    $execute('DELETE uf FROM user_followers uf LEFT JOIN users followed ON followed.id = uf.user_id LEFT JOIN users follower ON follower.id = uf.follower_id WHERE followed.id IS NULL OR follower.id IS NULL');
    $execute('DELETE upl FROM user_profile_links upl LEFT JOIN users u ON u.id = upl.user_id WHERE u.id IS NULL');
    $execute('DELETE n FROM notifications n LEFT JOIN users u ON u.id = n.user_id LEFT JOIN content c ON c.id = n.content_id LEFT JOIN content_comments cc ON cc.id = n.comment_id WHERE u.id IS NULL OR (n.content_id IS NOT NULL AND c.id IS NULL) OR (n.comment_id IS NOT NULL AND cc.id IS NULL)');
    $execute('UPDATE notifications n LEFT JOIN users u ON u.id = n.actor_id SET n.actor_id = NULL WHERE n.actor_id IS NOT NULL AND u.id IS NULL');
    $execute('DELETE cr FROM content_reports cr LEFT JOIN content c ON c.id = cr.content_id WHERE c.id IS NULL');
    $execute('UPDATE content_reports cr LEFT JOIN users u ON u.id = cr.reporter_id SET cr.reporter_id = NULL WHERE cr.reporter_id IS NOT NULL AND u.id IS NULL');
    $execute('UPDATE content_reports cr LEFT JOIN users u ON u.id = cr.reviewed_by SET cr.reviewed_by = NULL WHERE cr.reviewed_by IS NOT NULL AND u.id IS NULL');
    $execute('DELETE prt FROM password_reset_tokens prt LEFT JOIN users u ON u.id = prt.user_id WHERE u.id IS NULL');
    $execute('UPDATE users target LEFT JOIN users moderator ON moderator.id = target.muted_by SET target.muted_by = NULL WHERE target.muted_by IS NOT NULL AND moderator.id IS NULL');
    $execute('UPDATE content c LEFT JOIN users moderator ON moderator.id = c.edit_locked_by SET c.edit_locked_by = NULL WHERE c.edit_locked_by IS NOT NULL AND moderator.id IS NULL');

    $execute('DELETE bs FROM bot_sources bs LEFT JOIN users u ON u.id = bs.bot_user_id WHERE u.id IS NULL');
    $execute('DELETE bfi FROM bot_feed_items bfi LEFT JOIN bot_sources bs ON bs.id = bfi.source_id WHERE bs.id IS NULL');
    $execute('UPDATE bot_feed_items bfi LEFT JOIN content c ON c.id = bfi.content_id SET bfi.content_id = NULL WHERE bfi.content_id IS NOT NULL AND c.id IS NULL');
    $execute('DELETE bfh FROM bot_feed_history bfh LEFT JOIN users u ON u.id = bfh.bot_user_id WHERE u.id IS NULL');
    $execute('UPDATE bot_feed_history bfh LEFT JOIN content c ON c.id = bfh.content_id SET bfh.content_id = NULL WHERE bfh.content_id IS NOT NULL AND c.id IS NULL');
    $execute('DELETE bsr FROM bot_source_runs bsr LEFT JOIN bot_sources bs ON bs.id = bsr.source_id LEFT JOIN users u ON u.id = bsr.bot_user_id WHERE bs.id IS NULL OR u.id IS NULL');
    $execute('UPDATE bot_source_runs bsr LEFT JOIN content c ON c.id = bsr.content_id SET bsr.content_id = NULL WHERE bsr.content_id IS NOT NULL AND c.id IS NULL');

    $execute('DELETE t FROM terms t LEFT JOIN content_tags ct ON ct.term_id = t.id WHERE ct.term_id IS NULL');
    $execute('DELETE l FROM links l LEFT JOIN content_links cl ON cl.link_id = l.id WHERE cl.link_id IS NULL');

    if ($columnNullable('content', 'author_id')) {
        $execute('ALTER TABLE content MODIFY author_id INT UNSIGNED NOT NULL');
    }

    $foreignKey('users', 'muted_by', 'users', 'id', 'fk_users_muted_by', 'SET NULL');
    $foreignKey('content', 'author_id', 'users', 'id', 'fk_content_author', 'CASCADE');
    $foreignKey('content', 'edit_locked_by', 'users', 'id', 'fk_content_edit_locked_by', 'SET NULL');
    $foreignKey('content_tags', 'content_id', 'content', 'id', 'fk_content_tags_content', 'CASCADE');
    $foreignKey('content_tags', 'term_id', 'terms', 'id', 'fk_content_tags_term', 'CASCADE');
    $foreignKey('content_links', 'content_id', 'content', 'id', 'fk_content_links_content', 'CASCADE');
    $foreignKey('content_links', 'link_id', 'links', 'id', 'fk_content_links_link', 'CASCADE');
    $foreignKey('content_likes', 'content_id', 'content', 'id', 'fk_content_likes_content', 'CASCADE');
    $foreignKey('content_likes', 'user_id', 'users', 'id', 'fk_content_likes_user', 'CASCADE');
    $foreignKey('content_comments', 'content_id', 'content', 'id', 'fk_content_comments_content', 'CASCADE');
    $foreignKey('content_comments', 'parent_id', 'content_comments', 'id', 'fk_content_comments_parent', 'CASCADE');
    $foreignKey('content_comments', 'user_id', 'users', 'id', 'fk_content_comments_user', 'CASCADE');
    $foreignKey('comment_likes', 'comment_id', 'content_comments', 'id', 'fk_comment_likes_comment', 'CASCADE');
    $foreignKey('comment_likes', 'user_id', 'users', 'id', 'fk_comment_likes_user', 'CASCADE');
    $foreignKey('user_followers', 'user_id', 'users', 'id', 'fk_user_followers_user', 'CASCADE');
    $foreignKey('user_followers', 'follower_id', 'users', 'id', 'fk_user_followers_follower', 'CASCADE');
    $foreignKey('user_profile_links', 'user_id', 'users', 'id', 'fk_user_profile_links_user', 'CASCADE');
    $foreignKey('notifications', 'user_id', 'users', 'id', 'fk_notifications_user', 'CASCADE');
    $foreignKey('notifications', 'actor_id', 'users', 'id', 'fk_notifications_actor', 'SET NULL');
    $foreignKey('notifications', 'content_id', 'content', 'id', 'fk_notifications_content', 'CASCADE');
    $foreignKey('notifications', 'comment_id', 'content_comments', 'id', 'fk_notifications_comment', 'CASCADE');
    $foreignKey('content_reports', 'content_id', 'content', 'id', 'fk_content_reports_content', 'CASCADE');
    $foreignKey('content_reports', 'reporter_id', 'users', 'id', 'fk_content_reports_reporter', 'SET NULL');
    $foreignKey('content_reports', 'reviewed_by', 'users', 'id', 'fk_content_reports_reviewer', 'SET NULL');
    $foreignKey('password_reset_tokens', 'user_id', 'users', 'id', 'fk_password_reset_tokens_user', 'CASCADE');
    $foreignKey('bot_sources', 'bot_user_id', 'users', 'id', 'fk_bot_sources_user', 'CASCADE');
    $foreignKey('bot_feed_items', 'source_id', 'bot_sources', 'id', 'fk_bot_feed_items_source', 'CASCADE');
    $foreignKey('bot_feed_items', 'content_id', 'content', 'id', 'fk_bot_feed_items_content', 'SET NULL');
    $foreignKey('bot_feed_history', 'bot_user_id', 'users', 'id', 'fk_bot_feed_history_user', 'CASCADE');
    $foreignKey('bot_feed_history', 'content_id', 'content', 'id', 'fk_bot_feed_history_content', 'SET NULL');
    $foreignKey('bot_source_runs', 'source_id', 'bot_sources', 'id', 'fk_bot_source_runs_source', 'CASCADE');
    $foreignKey('bot_source_runs', 'bot_user_id', 'users', 'id', 'fk_bot_source_runs_user', 'CASCADE');
    $foreignKey('bot_source_runs', 'content_id', 'content', 'id', 'fk_bot_source_runs_content', 'SET NULL');
};
