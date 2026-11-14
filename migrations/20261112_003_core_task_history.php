<?php
declare(strict_types=1);

// Immutable transfer of execution history to the core owner; no row is deleted.
return static function (\PDO $pdo): void {
    $file = dirname(__DIR__) . '/App/Core/Tasks/migrations/001-history.php';
    $checksum = hash('sha256', str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($file)));
    (require $file)($pdo);
    $pdo->exec('CREATE TABLE IF NOT EXISTS module_installations (module_id VARCHAR(190) PRIMARY KEY, version INTEGER NOT NULL, status VARCHAR(20) NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS module_migrations (module_id VARCHAR(190) NOT NULL, migration VARCHAR(190) NOT NULL, version INTEGER NOT NULL, checksum CHAR(64) NOT NULL, PRIMARY KEY (module_id, migration))');
    $query = $pdo->query("SELECT version, status FROM module_installations WHERE module_id = 'tinycat.support'");
    $state = $query === false ? false : $query->fetch(\PDO::FETCH_ASSOC);
    if ($state !== false) {
        $query = $pdo->query("SELECT migration, version, checksum FROM module_migrations WHERE module_id = 'tinycat.support' ORDER BY version");
        $rows = $query === false ? [] : $query->fetchAll(\PDO::FETCH_ASSOC);
        if ((int) $state['version'] !== 1 || $state['status'] !== 'ready' || count($rows) !== 1 || $rows[0]['migration'] !== 'tinycat.core.tasks.history' || (int) $rows[0]['version'] !== 1 || $rows[0]['checksum'] !== $checksum) { throw new \RuntimeException('Core history integrity mismatch.'); }
        return;
    }
    $query = $pdo->prepare('INSERT INTO module_migrations(module_id, migration, version, checksum) VALUES (?, ?, ?, ?)');
    $query->execute(['tinycat.support', 'tinycat.core.tasks.history', 1, $checksum]);
    $pdo->exec("INSERT INTO module_installations(module_id, version, status) VALUES ('tinycat.support', 1, 'ready')");
};
