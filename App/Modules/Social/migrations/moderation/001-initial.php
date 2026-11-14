<?php
declare(strict_types=1);

// Immutable standalone baseline.
return static function (\PDO $pdo): void {
    $statements = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite' ? [
'CREATE TABLE IF NOT EXISTS content_reports (
                id INTEGER NOT NULL,
                content_id INTEGER NOT NULL,
                reporter_id INTEGER NULL,
                reason VARCHAR(40) NOT NULL DEFAULT \'other\',
                note TEXT NULL,
                status VARCHAR(20) NOT NULL DEFAULT \'open\',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reviewed_at DATETIME NULL,
                reviewed_by INTEGER NULL,
                action_note TEXT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_content_reports_content FOREIGN KEY (content_id) REFERENCES content (id) ON DELETE CASCADE,
                CONSTRAINT fk_content_reports_reporter FOREIGN KEY (reporter_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_content_reports_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
            )',
'CREATE INDEX IF NOT EXISTS content_reports_content_reporter_status_index ON content_reports(content_id, reporter_id, status)',
'CREATE INDEX IF NOT EXISTS content_reports_status_index ON content_reports(status, created_at)',
'CREATE INDEX IF NOT EXISTS content_reports_reporter_index ON content_reports(reporter_id)'
    ] : [
'CREATE TABLE IF NOT EXISTS content_reports (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                content_id BIGINT UNSIGNED NOT NULL,
                reporter_id INT UNSIGNED NULL,
                reason VARCHAR(40) NOT NULL DEFAULT \'other\',
                note TEXT NULL,
                status VARCHAR(20) NOT NULL DEFAULT \'open\',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reviewed_at DATETIME NULL,
                reviewed_by INT UNSIGNED NULL,
                action_note TEXT NULL,
                PRIMARY KEY (id),
                KEY content_reports_content_reporter_status_index (content_id, reporter_id, status),
                KEY content_reports_status_index (status, created_at),
                KEY content_reports_reporter_index (reporter_id),
                CONSTRAINT fk_content_reports_content FOREIGN KEY (content_id) REFERENCES content (id) ON DELETE CASCADE,
                CONSTRAINT fk_content_reports_reporter FOREIGN KEY (reporter_id) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_content_reports_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    ];
    foreach ($statements as $sql) { $pdo->exec($sql); }
};
