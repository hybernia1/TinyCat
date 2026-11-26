<?php
declare(strict_types=1);

// Standalone Social post schema without a content discriminator.
return static function (\PDO $pdo): void {
    if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') { throw new \RuntimeException('MySQL or MariaDB is required.'); }
    $statements = [
  0 => 'CREATE TABLE IF NOT EXISTS social_posts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                body VARCHAR(2000) NOT NULL,
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
    ];
    foreach ($statements as $sql) { $pdo->exec($sql); }
};
