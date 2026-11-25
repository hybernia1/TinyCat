<?php
declare(strict_types=1);

require_once __DIR__ . '/../../support/mysql.php';
require_once __DIR__ . '/../../support/cache-isolation.php';
define('TINYCAT', true);
require dirname(__DIR__, 3) . '/App/Core/Runtime/autoload.php';
use TinyCat\Support\{Cache, Config};
use TinyCat\Core\Settings\Store;
use TinyCat\Database\{Connection, Transactions};

final class SettingsBootPDO extends PDO
{
    public int $transactionChecks = 0;
    public function __construct() {}
    public function getAttribute(int $attribute): mixed { return $attribute === PDO::ATTR_DRIVER_NAME ? 'mysql' : parent::getAttribute($attribute); }
    public function prepare(string $query, array $options = []): PDOStatement|false { throw new PDOException('injected unavailable database'); }
    public function inTransaction(): bool { $this->transactionChecks++; throw new PDOException('injected operational connection failure'); }
}
$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void { $checks++; if (!$ok) { throw new RuntimeException($message); } };
$configure = static function (array $values): void {
    (new ReflectionProperty(Config::class, 'booted'))->setValue(null, true);
    (new ReflectionProperty(Config::class, 'config'))->setValue(null, $values);
    Store::reset(); Cache::forget('core_autoload_settings');
};
$unavailable = new SettingsBootPDO(); Connection::set($unavailable);
foreach ([[], ['install' => ['complete' => false]], ['install' => ['complete' => true]]] as $values) {
    $configure($values);
    $assert(Store::config('site.name', 'fallback') === 'fallback', 'No-config/incomplete/complete-only boot keeps the pure config fallback');
    $assert(Store::config('email.smtp', ['host' => 'fallback']) === ['host' => 'fallback'], 'Sensitive settings retain the same pre-DB fallback');
    $assert($unavailable->transactionChecks === 0, 'Pre-DB fallback precedes transaction inspection');
    $assert(Cache::read('core_autoload_settings') === null, 'Pre-DB failure never fills shared cache');
}
$configure(['install' => ['complete' => true], 'database' => ['driver' => 'mysql']]);
try { Store::config('site.name'); $assert(false, 'Configured operational failure must be visible'); }
catch (PDOException $error) { $assert(str_contains($error->getMessage(), 'operational'), 'Configured DB failure is propagated'); }
$db = \tinycat_test_database();
Connection::set($db);
$db->exec('CREATE TABLE settings (setting_key VARCHAR(190) PRIMARY KEY, setting_group TEXT, setting_value TEXT, setting_type TEXT, autoload INTEGER)');
Store::setSetting('site.name', 'committed'); Store::setSetting('cron.token', 'committed-secret');
$assert(Store::setting('site.name') === 'committed', 'Warm committed settings snapshot');
Store::setting('cron.token');
Transactions::run(static function () use ($assert): void {
    Store::setSetting('site.name', 'new');
    $assert(Store::setting('site.name') === 'committed', 'An existing request snapshot is stable inside its transaction');
});
$assert(Store::setting('site.name') === 'new', 'Commit resets the request snapshot');
Store::reset(); Cache::forget('core_autoload_settings');
try {
    Transactions::run(static function () use ($assert): void {
        Store::setSetting('site.name', 'uncommitted'); Store::setSetting('cron.token', 'temporary-secret');
        $assert(Store::setting('site.name') === 'uncommitted' && Store::setting('cron.token') === 'temporary-secret', 'Cold transactional reads use their own DB view');
        $assert(Cache::read('core_autoload_settings') === null, 'Cold transactional reads never publish shared settings');
        throw new DomainException('rollback');
    });
} catch (DomainException) {}
$assert(Store::setting('site.name') === 'new' && Store::setting('cron.token') === 'committed-secret', 'Rollback does not retain uncommitted public or sensitive request state');
Store::reset(); Cache::forget('core_autoload_settings'); $db->exec('DROP TABLE settings');
try { Store::setting('site.name'); $assert(false, 'Operational SELECT failure must propagate'); }
catch (PDOException) { $assert(Cache::read('core_autoload_settings') === null, 'Failed SELECT never publishes an empty autoload cache'); }
echo "PASS settings pre-DB and transactional cache boundaries ($checks checks)\n";
