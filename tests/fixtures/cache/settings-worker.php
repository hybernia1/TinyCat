<?php
declare(strict_types=1);

require_once __DIR__ . '/../../support/mysql.php';
$state = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
define('TINYCAT', true); require $state['source'] . '/App/Core/Runtime/autoload.php';
TinyCat\Support\Config::configure(new \TinyCat\Core\Runtime\Configuration($state['config']));
final class PausedSettingsStatement extends PDOStatement {
    protected function __construct(private string $directory) {}
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
        $rows = parent::fetchAll($mode, ...$args);
        if (str_contains($this->queryString, 'WHERE autoload = 1')) {
            file_put_contents($this->directory . '/read-ready', 'old rows read');
            $deadline = microtime(true) + 15;
            while (!is_file($this->directory . '/committed') && microtime(true) < $deadline) { usleep(10000); }
            if (!is_file($this->directory . '/committed')) { throw new RuntimeException('Writer never committed'); }
        }
        return $rows;
    }
}
$pdo = \tinycat_test_database($state['directory'] . '/settings.database.json');
$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PausedSettingsStatement::class, [$state['directory']]]);
TinyCat\Database\Connection::set($pdo);
$value = TinyCat\Core\Settings\Store::setting('site.public_enabled');
file_put_contents($state['directory'] . '/read-result', json_encode($value, JSON_THROW_ON_ERROR));
