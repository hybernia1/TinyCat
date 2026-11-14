<?php
declare(strict_types=1);

// Immutable current Blog schema: no shared publication table is required.
return static function (\PDO $pdo): void {
    $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    if (!in_array($driver, ['mysql', 'sqlite'], true)) { throw new \RuntimeException('Unsupported database.'); }
    $statements = $driver === 'sqlite' ? array (
  0 => 'CREATE TABLE IF NOT EXISTS blog_articles (id INTEGER PRIMARY KEY AUTOINCREMENT, author_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, title VARCHAR(200) NOT NULL, body TEXT NOT NULL, excerpt VARCHAR(2000) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT \'published\', published_at DATETIME NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)',
  1 => 'CREATE INDEX IF NOT EXISTS blog_articles_feed_index ON blog_articles(published_at, id)',
  2 => 'CREATE INDEX IF NOT EXISTS blog_articles_sidebar_index ON blog_articles(published_at, author_id, id)',
  3 => 'CREATE INDEX IF NOT EXISTS blog_articles_author_index ON blog_articles(author_id, published_at, id)',
  4 => 'CREATE TABLE IF NOT EXISTS blog_article_redirects (old_id INTEGER PRIMARY KEY, article_id INTEGER NOT NULL REFERENCES blog_articles(id) ON DELETE CASCADE)',
  5 => 'CREATE INDEX IF NOT EXISTS blog_article_redirects_target_index ON blog_article_redirects(article_id)',
  6 => 'CREATE TABLE IF NOT EXISTS blog_article_images (article_id INTEGER PRIMARY KEY REFERENCES blog_articles(id) ON DELETE CASCADE, path VARCHAR(190) NOT NULL UNIQUE, width INTEGER NOT NULL, height INTEGER NOT NULL, bytes INTEGER NOT NULL)',
  7 => 'CREATE TABLE IF NOT EXISTS blog_terms (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(120) NOT NULL UNIQUE)',
  8 => 'CREATE TABLE IF NOT EXISTS blog_article_tags (article_id INTEGER NOT NULL REFERENCES blog_articles(id) ON DELETE CASCADE, term_id INTEGER NOT NULL REFERENCES blog_terms(id) ON DELETE CASCADE, PRIMARY KEY(article_id, term_id))',
  9 => 'CREATE INDEX IF NOT EXISTS blog_article_tags_term_index ON blog_article_tags(term_id, article_id)',
  10 => 'CREATE TABLE IF NOT EXISTS blog_links (id INTEGER PRIMARY KEY AUTOINCREMENT, normalized_url VARCHAR(2048) NOT NULL, url_hash CHAR(64) NOT NULL UNIQUE, provider VARCHAR(40) NOT NULL DEFAULT \'\', link_type VARCHAR(20) NOT NULL DEFAULT \'web\', title VARCHAR(255), description VARCHAR(500), image_url VARCHAR(2048), video_id VARCHAR(80), audio_url VARCHAR(2048), created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)',
  11 => 'CREATE TABLE IF NOT EXISTS blog_article_links (article_id INTEGER NOT NULL REFERENCES blog_articles(id) ON DELETE CASCADE, link_id INTEGER NOT NULL REFERENCES blog_links(id) ON DELETE CASCADE, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(article_id, link_id))',
  12 => 'CREATE INDEX IF NOT EXISTS blog_article_links_link_index ON blog_article_links(link_id)',
) : array (
  0 => 'CREATE TABLE IF NOT EXISTS blog_articles (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, author_id INT UNSIGNED NOT NULL, title VARCHAR(200) NOT NULL, body TEXT NOT NULL, excerpt VARCHAR(2000) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT \'published\', published_at DATETIME NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY blog_articles_feed_index (published_at, id), KEY blog_articles_author_index (author_id, published_at, id), FULLTEXT KEY blog_articles_search_fulltext (title, body), CONSTRAINT fk_blog_articles_author FOREIGN KEY(author_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  1 => 'CREATE TABLE IF NOT EXISTS blog_article_redirects (
                old_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                article_id BIGINT UNSIGNED NOT NULL,
                KEY blog_article_redirects_target_index (article_id),
                CONSTRAINT fk_blog_redirect_target_article FOREIGN KEY (article_id) REFERENCES blog_articles (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  2 => 'CREATE TABLE IF NOT EXISTS blog_article_images (
                article_id BIGINT UNSIGNED NOT NULL,
                path VARCHAR(190) NOT NULL,
                width INT UNSIGNED NOT NULL,
                height INT UNSIGNED NOT NULL,
                bytes INT UNSIGNED NOT NULL,
                PRIMARY KEY (article_id),
                UNIQUE KEY blog_article_images_path_unique (path),
                CONSTRAINT fk_blog_article_images_content FOREIGN KEY (article_id) REFERENCES blog_articles (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  3 => 'CREATE TABLE IF NOT EXISTS blog_terms (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(120) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY blog_terms_name_unique (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  4 => 'CREATE TABLE IF NOT EXISTS blog_article_tags (
                article_id BIGINT UNSIGNED NOT NULL,
                term_id BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (article_id, term_id),
                KEY blog_article_tags_term_index (term_id, article_id),
                CONSTRAINT fk_blog_article_tags_content FOREIGN KEY (article_id) REFERENCES blog_articles (id) ON DELETE CASCADE,
                CONSTRAINT fk_blog_article_tags_term FOREIGN KEY (term_id) REFERENCES blog_terms (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  5 => 'CREATE TABLE IF NOT EXISTS blog_links (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                normalized_url VARCHAR(2048) NOT NULL,
                url_hash CHAR(64) NOT NULL,
                provider VARCHAR(40) NOT NULL DEFAULT \'\',
                link_type VARCHAR(20) NOT NULL DEFAULT \'web\',
                title VARCHAR(255) NULL,
                description VARCHAR(500) NULL,
                image_url VARCHAR(2048) NULL,
                video_id VARCHAR(80) NULL,
                audio_url VARCHAR(2048) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY blog_links_hash_unique (url_hash),
                FULLTEXT KEY blog_links_search_fulltext (normalized_url, title, description)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  6 => 'CREATE TABLE IF NOT EXISTS blog_article_links (
                article_id BIGINT UNSIGNED NOT NULL,
                link_id BIGINT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (article_id, link_id),
                KEY blog_article_links_link_index (link_id),
                CONSTRAINT fk_blog_article_links_content FOREIGN KEY (article_id) REFERENCES blog_articles (id) ON DELETE CASCADE,
                CONSTRAINT fk_blog_article_links_link FOREIGN KEY (link_id) REFERENCES blog_links (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
);
    foreach ($statements as $sql) { $pdo->exec($sql); }

    $mysql = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
    $id = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
    $userId = $mysql ? 'INT UNSIGNED' : 'INTEGER';
    $auto = $mysql ? 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $pdo->exec('CREATE TABLE IF NOT EXISTS blog_sources (id ' . $auto . ', author_id ' . $userId . ' NOT NULL, name VARCHAR(120) NOT NULL, feed_url VARCHAR(2048) NOT NULL, feed_hash CHAR(64) NOT NULL UNIQUE, interval_minutes INTEGER NOT NULL DEFAULT 60, publication_status VARCHAR(16) NOT NULL DEFAULT \'hidden\', import_images INTEGER NOT NULL DEFAULT 0, enabled INTEGER NOT NULL DEFAULT 1, revision INTEGER NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, next_run_at DATETIME NULL, last_checked_at DATETIME NULL, last_imported_at DATETIME NULL, last_error VARCHAR(500) NULL, FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE' . ($mysql ? ', INDEX blog_sources_due (enabled, next_run_at, id)' : '') . ')' . $suffix);
    if (!$mysql) { $pdo->exec('CREATE INDEX IF NOT EXISTS blog_sources_due ON blog_sources(enabled, next_run_at, id)'); }
    $pdo->exec('CREATE TABLE IF NOT EXISTS blog_feed_history (source_id ' . $id . ' NOT NULL, item_hash CHAR(64) NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (source_id, item_hash), FOREIGN KEY (source_id) REFERENCES blog_sources(id) ON DELETE CASCADE)' . $suffix);
    $pdo->exec('CREATE TABLE IF NOT EXISTS blog_source_articles (source_id ' . $id . ' NOT NULL, article_id ' . $id . ' NOT NULL UNIQUE, PRIMARY KEY (source_id, article_id), FOREIGN KEY (source_id) REFERENCES blog_sources(id) ON DELETE CASCADE, FOREIGN KEY (article_id) REFERENCES blog_articles(id) ON DELETE CASCADE)' . $suffix);


        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $pdo->exec('CREATE TABLE IF NOT EXISTS blog_article_likes (article_id INTEGER NOT NULL REFERENCES blog_articles(id) ON DELETE CASCADE, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, PRIMARY KEY(article_id, user_id))');
            $pdo->exec('CREATE INDEX IF NOT EXISTS blog_article_likes_user_index ON blog_article_likes(user_id, article_id)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS blog_comments (id INTEGER PRIMARY KEY AUTOINCREMENT, article_id INTEGER NOT NULL REFERENCES blog_articles(id) ON DELETE CASCADE, parent_id INTEGER REFERENCES blog_comments(id) ON DELETE CASCADE, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, body VARCHAR(2000) NOT NULL, comments_diff TEXT, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS blog_comments_content_index ON blog_comments(article_id, parent_id, created_at)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS blog_comments_user_index ON blog_comments(user_id, created_at)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS blog_comments_parent_index ON blog_comments(parent_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS blog_comments_recent_index ON blog_comments(created_at, id)');
            $pdo->exec('CREATE TABLE IF NOT EXISTS blog_comment_likes (comment_id INTEGER NOT NULL REFERENCES blog_comments(id) ON DELETE CASCADE, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, PRIMARY KEY(comment_id, user_id))');
            $pdo->exec('CREATE INDEX IF NOT EXISTS blog_comment_likes_user_index ON blog_comment_likes(user_id, comment_id)');
            return;
        }
        foreach ([
    "CREATE TABLE IF NOT EXISTS blog_article_likes (
                article_id BIGINT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (article_id, user_id),
                KEY blog_article_likes_user_index (user_id, article_id),
                CONSTRAINT fk_blog_article_likes_content FOREIGN KEY (article_id) REFERENCES blog_articles (id) ON DELETE CASCADE,
                CONSTRAINT fk_blog_article_likes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS blog_comments (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                article_id BIGINT UNSIGNED NOT NULL,
                parent_id BIGINT UNSIGNED NULL,
                user_id INT UNSIGNED NOT NULL,
                body VARCHAR(2000) NOT NULL,
                comments_diff JSON NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY blog_comments_content_index (article_id, parent_id, created_at),
                KEY blog_comments_user_index (user_id, created_at),
                KEY blog_comments_parent_index (parent_id),
                KEY blog_comments_recent_index (created_at, id),
                CONSTRAINT fk_blog_comments_content FOREIGN KEY (article_id) REFERENCES blog_articles (id) ON DELETE CASCADE,
                CONSTRAINT fk_blog_comments_parent FOREIGN KEY (parent_id) REFERENCES blog_comments (id) ON DELETE CASCADE,
                CONSTRAINT fk_blog_comments_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS blog_comment_likes (
                comment_id BIGINT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (comment_id, user_id),
                KEY blog_comment_likes_user_index (user_id, comment_id),
                CONSTRAINT fk_blog_comment_likes_comment FOREIGN KEY (comment_id) REFERENCES blog_comments (id) ON DELETE CASCADE,
                CONSTRAINT fk_blog_comment_likes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
] as $sql) { $pdo->exec($sql); }


};
