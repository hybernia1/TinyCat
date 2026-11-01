<?php
declare(strict_types=1);

if (!is_file(dirname(__DIR__) . '/config.php') || !in_array('mysql', PDO::getAvailableDrivers(), true)) {
    echo "SKIP numeric tag migration: MySQL configuration unavailable.\n";
    exit(0);
}
define('TINYCAT', true);
$server = null;
$created = false;
$name = 'tinycat_numeric_tags_' . bin2hex(random_bytes(6));
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
};
try {
    $config = require (getenv('TINYCAT_TEST_MYSQL_CONFIG') ?: dirname(__DIR__) . '/config.php');
    $settings = $config['database'];
    $dsn = 'mysql:host=' . $settings['host'] . ';port=' . ($settings['port'] ?? 3306) . ';charset=utf8mb4';
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
    $server = new PDO($dsn, $settings['user'], $settings['password'], $options);
    $server->exec('CREATE DATABASE ' . $name . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;
    $database = new PDO($dsn . ';dbname=' . $name, $settings['user'], $settings['password'], $options);
    $database->exec('CREATE TABLE terms (id INT PRIMARY KEY, name VARCHAR(80)) ENGINE=InnoDB');
    $database->exec('CREATE TABLE content (id INT PRIMARY KEY, body TEXT) ENGINE=InnoDB');
    $database->exec('CREATE TABLE content_tags (content_id INT, term_id INT, PRIMARY KEY (content_id, term_id), FOREIGN KEY (content_id) REFERENCES content(id), FOREIGN KEY (term_id) REFERENCES terms(id)) ENGINE=InnoDB');
    $body = 'Feature Update #1 #1980s #2026 #3d';
    $database->prepare('INSERT INTO content VALUES (10, ?)')->execute([$body]);
    for ($id = 1; $id <= 502; $id++) {
        $tag = [1 => '1', 500 => '1980s', 501 => '2026', 502 => '3d'][$id] ?? 'topic-' . $id;
        $database->prepare('INSERT INTO terms VALUES (?, ?)')->execute([$id, $tag]);
        $database->prepare('INSERT INTO content_tags VALUES (10, ?)')->execute([$id]);
    }
    $migration = require dirname(__DIR__) . '/migrations/20261101_002_numeric_tags.php';
    $migration($database);
    $migration($database);
    $assert((int) $database->query('SELECT COUNT(*) FROM terms')->fetchColumn() === 500, 'Migration removes numeric terms across page boundaries and is repeatable');
    $assert((int) $database->query('SELECT COUNT(*) FROM content_tags')->fetchColumn() === 500, 'Relationships are removed before their referenced terms');
    $assert($database->query('SELECT body FROM content WHERE id = 10')->fetchColumn() === $body, 'Original post text is unchanged');
    $assert($database->query('SELECT name FROM terms WHERE id IN (500, 502) ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) === ['1980s', '3d'], 'Mixed alphanumeric tags are retained');
    $database->exec("INSERT INTO terms VALUES (700, '0007'); INSERT INTO content_tags VALUES (10, 700)");
    $database->beginTransaction();
    $migration($database);
    $assert($database->inTransaction(), 'Migration does not commit a caller-owned transaction');
    $database->rollBack();
    $assert((int) $database->query('SELECT COUNT(*) FROM terms WHERE id = 700')->fetchColumn() === 1, 'Caller rollback restores the deleted term');
    $database->exec('CREATE TABLE protected_term (term_id INT, FOREIGN KEY (term_id) REFERENCES terms(id)) ENGINE=InnoDB');
    $database->exec('INSERT INTO protected_term VALUES (700)');
    $failed = false;
    try { $migration($database); } catch (PDOException) { $failed = true; }
    $assert($failed && !$database->inTransaction(), 'An unexpected dependent reference rolls back the migration');
    $assert((int) $database->query('SELECT COUNT(*) FROM content_tags WHERE term_id = 700')->fetchColumn() === 1, 'Failed cleanup restores already deleted relationships');
    echo "PASS numeric tag migration (MySQL, {$checks} checks)\n";
} finally {
    if ($created && $server instanceof PDO) { $server->exec('DROP DATABASE ' . $name); }
}
