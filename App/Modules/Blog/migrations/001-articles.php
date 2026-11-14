<?php
declare(strict_types=1);

// Immutable version-one DDL; never delegate historical migrations to a mutable fresh schema.
return static function (\PDO $pdo): void {
    $mysql = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
    $id = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
    $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $pdo->exec('CREATE TABLE blog_articles (content_id ' . $id . ' PRIMARY KEY, title VARCHAR(200) NOT NULL, body TEXT NOT NULL, FOREIGN KEY (content_id) REFERENCES content(id) ON DELETE CASCADE)' . $suffix);
};
