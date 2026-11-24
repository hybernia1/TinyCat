<?php
declare(strict_types=1);
$root = $argv[1]; $phase = $argv[2];
$state = isset($argv[3]) ? json_decode((string) file_get_contents($argv[3]), true, 512, JSON_THROW_ON_ERROR) : [];
define('TINYCAT', true);
require $root . '/App/Core/Runtime/autoload.php';
$pdo = new class ($state['dsn'] ?? ('sqlite:' . $root . '/avatars.sqlite'), $state['user'] ?? null, $state['password'] ?? null) extends PDO {
    public string $pause = '';
    public string $ready = '';
    public function beginTransaction(): bool {
        if ($this->pause === 'concurrent') { file_put_contents($this->ready, json_encode(['connection' => (int) TinyCat\Database\Connection::value('SELECT CONNECTION_ID()'), 'in_transaction' => $this->inTransaction()], JSON_THROW_ON_ERROR)); }
        return parent::beginTransaction();
    }
    public function commit(): bool {
        $ok = parent::commit();
        if ($this->pause === 'after-commit') { file_put_contents($this->ready, 'committed'); while (true) { usleep(10000); } }
        return $ok;
    }
};
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->pause = $phase; $pdo->ready = $root . '/ready';
TinyCat\Database\Connection::set($pdo);
$updated = TinyCat\Core\Identity\Avatar::replace(1, $root . '/uploads/avatars/' . ($state['prepared'] ?? '.avatar-crash'), false, static function (array $user) use ($root, $phase): array {
    if ($phase === 'before-commit') { file_put_contents($root . '/ready', 'prepared'); while (true) { usleep(10000); } }
    return $user;
});
if ($phase === 'concurrent') { file_put_contents($root . '/concurrent-result', json_encode($updated, JSON_THROW_ON_ERROR)); }
