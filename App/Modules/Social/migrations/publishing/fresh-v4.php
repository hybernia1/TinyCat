<?php
declare(strict_types=1);

// Immutable fresh Social publication schema, version four.
return static function (\PDO $pdo): void {
    $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    if (!in_array($driver, ['mysql', 'sqlite'], true)) { throw new \RuntimeException('Unsupported database.'); }
    $statements = $driver === 'sqlite' ? array (
  0 => 'CREATE TABLE IF NOT EXISTS social_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, kind VARCHAR(40) NOT NULL DEFAULT \'post\', body VARCHAR(2000) NOT NULL, author_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, published_at DATETIME NOT NULL, status VARCHAR(20) NOT NULL DEFAULT \'published\')',
  1 => 'CREATE INDEX IF NOT EXISTS content_feed_index ON social_posts(published_at, id)',
  2 => 'CREATE INDEX IF NOT EXISTS content_sidebar_index ON social_posts(published_at, author_id, id)',
  3 => 'CREATE INDEX IF NOT EXISTS content_author_index ON social_posts(author_id, published_at, id)',
  4 => 'CREATE TABLE IF NOT EXISTS social_post_redirects (old_id INTEGER PRIMARY KEY, content_id INTEGER NOT NULL REFERENCES social_posts(id) ON DELETE CASCADE)',
  5 => 'CREATE INDEX IF NOT EXISTS content_redirects_target_index ON social_post_redirects(content_id)',
  6 => 'CREATE TABLE IF NOT EXISTS social_post_images (content_id INTEGER PRIMARY KEY REFERENCES social_posts(id) ON DELETE CASCADE, path VARCHAR(190) NOT NULL UNIQUE, width INTEGER NOT NULL, height INTEGER NOT NULL, bytes INTEGER NOT NULL)',
  7 => 'CREATE TABLE IF NOT EXISTS social_terms (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(120) NOT NULL UNIQUE)',
  8 => 'CREATE TABLE IF NOT EXISTS social_post_tags (content_id INTEGER NOT NULL REFERENCES social_posts(id) ON DELETE CASCADE, term_id INTEGER NOT NULL REFERENCES social_terms(id) ON DELETE CASCADE, PRIMARY KEY(content_id, term_id))',
  9 => 'CREATE INDEX IF NOT EXISTS content_tags_term_index ON social_post_tags(term_id, content_id)',
  10 => 'CREATE TABLE IF NOT EXISTS social_links (id INTEGER PRIMARY KEY AUTOINCREMENT, normalized_url VARCHAR(2048) NOT NULL, url_hash CHAR(64) NOT NULL UNIQUE, provider VARCHAR(40) NOT NULL DEFAULT \'\', link_type VARCHAR(20) NOT NULL DEFAULT \'web\', title VARCHAR(255), description VARCHAR(500), image_url VARCHAR(2048), video_id VARCHAR(80), audio_url VARCHAR(2048), created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)',
  11 => 'CREATE TABLE IF NOT EXISTS social_post_links (content_id INTEGER NOT NULL REFERENCES social_posts(id) ON DELETE CASCADE, link_id INTEGER NOT NULL REFERENCES social_links(id) ON DELETE CASCADE, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(content_id, link_id))',
  12 => 'CREATE INDEX IF NOT EXISTS content_links_link_index ON social_post_links(link_id)',
) : array (
  0 => 'CREATE TABLE IF NOT EXISTS social_posts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                kind VARCHAR(40) NOT NULL DEFAULT \'post\', body VARCHAR(2000) NOT NULL,
                author_id INT UNSIGNED NOT NULL,
                published_at DATETIME NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT \'published\',
                PRIMARY KEY (id),
                KEY content_feed_index (published_at, id),
                KEY content_sidebar_index (published_at, author_id, id),
                KEY content_author_index (author_id, published_at, id),
                FULLTEXT KEY content_body_fulltext (body),
                CONSTRAINT fk_content_author FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  1 => 'CREATE TABLE IF NOT EXISTS social_post_redirects (
                old_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                content_id BIGINT UNSIGNED NOT NULL,
                KEY content_redirects_target_index (content_id),
                CONSTRAINT fk_redirect_target_post FOREIGN KEY (content_id) REFERENCES social_posts (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  2 => 'CREATE TABLE IF NOT EXISTS social_post_images (
                content_id BIGINT UNSIGNED NOT NULL,
                path VARCHAR(190) NOT NULL,
                width INT UNSIGNED NOT NULL,
                height INT UNSIGNED NOT NULL,
                bytes INT UNSIGNED NOT NULL,
                PRIMARY KEY (content_id),
                UNIQUE KEY content_images_path_unique (path),
                CONSTRAINT fk_content_images_content FOREIGN KEY (content_id) REFERENCES social_posts (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  3 => 'CREATE TABLE IF NOT EXISTS social_terms (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(120) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY terms_name_unique (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  4 => 'CREATE TABLE IF NOT EXISTS social_post_tags (
                content_id BIGINT UNSIGNED NOT NULL,
                term_id BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (content_id, term_id),
                KEY content_tags_term_index (term_id, content_id),
                CONSTRAINT fk_content_tags_content FOREIGN KEY (content_id) REFERENCES social_posts (id) ON DELETE CASCADE,
                CONSTRAINT fk_content_tags_term FOREIGN KEY (term_id) REFERENCES social_terms (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  5 => 'CREATE TABLE IF NOT EXISTS social_links (
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
                UNIQUE KEY links_hash_unique (url_hash),
                FULLTEXT KEY links_search_fulltext (normalized_url, title, description)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  6 => 'CREATE TABLE IF NOT EXISTS social_post_links (
                content_id BIGINT UNSIGNED NOT NULL,
                link_id BIGINT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (content_id, link_id),
                KEY content_links_link_index (link_id),
                CONSTRAINT fk_content_links_content FOREIGN KEY (content_id) REFERENCES social_posts (id) ON DELETE CASCADE,
                CONSTRAINT fk_content_links_link FOREIGN KEY (link_id) REFERENCES social_links (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
);
    foreach ($statements as $sql) { $pdo->exec($sql); }
};
