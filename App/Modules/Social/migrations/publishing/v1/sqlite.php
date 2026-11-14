<?php
declare(strict_types=1);
return [
    "CREATE TABLE IF NOT EXISTS content (id INTEGER PRIMARY KEY AUTOINCREMENT, body VARCHAR(2000) NOT NULL, author_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, published_at DATETIME NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'published')",
    'CREATE INDEX IF NOT EXISTS content_feed_index ON content(published_at, id)',
    'CREATE INDEX IF NOT EXISTS content_sidebar_index ON content(published_at, author_id, id)',
    'CREATE INDEX IF NOT EXISTS content_author_index ON content(author_id, published_at, id)',
    'CREATE TABLE IF NOT EXISTS content_redirects (old_id INTEGER PRIMARY KEY, content_id INTEGER NOT NULL REFERENCES content(id) ON DELETE CASCADE)',
    'CREATE INDEX IF NOT EXISTS content_redirects_target_index ON content_redirects(content_id)',
    'CREATE TABLE IF NOT EXISTS content_images (content_id INTEGER PRIMARY KEY REFERENCES content(id) ON DELETE CASCADE, path VARCHAR(190) NOT NULL UNIQUE, width INTEGER NOT NULL, height INTEGER NOT NULL, bytes INTEGER NOT NULL)',
    'CREATE TABLE IF NOT EXISTS terms (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(120) NOT NULL UNIQUE)',
    'CREATE TABLE IF NOT EXISTS content_tags (content_id INTEGER NOT NULL REFERENCES content(id) ON DELETE CASCADE, term_id INTEGER NOT NULL REFERENCES terms(id) ON DELETE CASCADE, PRIMARY KEY(content_id, term_id))',
    'CREATE INDEX IF NOT EXISTS content_tags_term_index ON content_tags(term_id, content_id)',
    "CREATE TABLE IF NOT EXISTS links (id INTEGER PRIMARY KEY AUTOINCREMENT, normalized_url VARCHAR(2048) NOT NULL, url_hash CHAR(64) NOT NULL UNIQUE, provider VARCHAR(40) NOT NULL DEFAULT '', link_type VARCHAR(20) NOT NULL DEFAULT 'web', title VARCHAR(255), description VARCHAR(500), image_url VARCHAR(2048), video_id VARCHAR(80), audio_url VARCHAR(2048), created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)",
    'CREATE TABLE IF NOT EXISTS content_links (content_id INTEGER NOT NULL REFERENCES content(id) ON DELETE CASCADE, link_id INTEGER NOT NULL REFERENCES links(id) ON DELETE CASCADE, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(content_id, link_id))',
    'CREATE INDEX IF NOT EXISTS content_links_link_index ON content_links(link_id)',
];
