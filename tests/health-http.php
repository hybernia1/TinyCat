<?php
declare(strict_types=1);
require_once __DIR__ . '/cache-isolation.php';
define('TINYCAT', true);
require dirname(__DIR__) . '/App/autoload.php';
use TinyCat\Support\Config;
use TinyCat\Health\HttpCheck;
use TinyCat\Health\Service;
use TinyCat\Http\SiteProbe;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void { $checks++; if (!$condition) { throw new RuntimeException($message); } };
(new ReflectionProperty(Config::class, 'booted'))->setValue(null, true);
$configure = static function (array $config): void { (new ReflectionProperty(Config::class, 'config'))->setValue(null, $config); };
$configure([]);
$limits = HttpCheck::limits();
$sample = ['complete' => true, 'http_status' => 200, 'ttfb_ms' => 800.0, 'total_ms' => 1000.0, 'error' => ''];
$assert(HttpCheck::status($sample, $limits) === 'ok', 'TTFB at the good threshold remains OK.');
foreach ([[800.001, 'warning'], [1800.0, 'warning'], [1800.001, 'critical']] as [$time, $state]) { $assert(HttpCheck::status(array_replace($sample, ['ttfb_ms' => $time, 'total_ms' => $time + 10]), $limits) === $state, 'TTFB boundaries are evaluated precisely.'); }
foreach ([[2000.0, 'ok'], [2000.001, 'warning'], [4000.0, 'warning'], [4000.001, 'critical']] as [$time, $state]) { $assert(HttpCheck::status(array_replace($sample, ['total_ms' => $time]), $limits) === $state, 'Total request duration has separate configurable boundaries.'); }
$assert(HttpCheck::status(array_replace($sample, ['http_status' => 503, 'ttfb_ms' => 1.0]), $limits) === 'critical', 'A fast error page is never healthy.');
$assert(HttpCheck::status(array_replace($sample, ['complete' => false, 'error' => 'timeout']), $limits) === 'critical', 'A partial timed-out transfer is critical even if its first byte arrived quickly.');
$configure(['health' => ['http' => ['ttfb_warning_ms' => -1, 'ttfb_critical_ms' => -1, 'total_warning_ms' => -1, 'total_critical_ms' => -1]]]);
$normalized = HttpCheck::limits();
$assert($normalized['ttfb_warning_ms'] > 0 && $normalized['ttfb_critical_ms'] > $normalized['ttfb_warning_ms'] && $normalized['total_critical_ms'] > $normalized['total_warning_ms'], 'Invalid configuration cannot invert or disable threshold ordering.');

$root = sys_get_temp_dir() . '/tinycat-http-health-' . bin2hex(random_bytes(10));
mkdir($root, 0700);
$router = <<<'PHP'
<?php
file_put_contents(__DIR__ . '/requests.jsonl', json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'auth' => isset($_SERVER['HTTP_AUTHORIZATION']), 'cookie' => isset($_SERVER['HTTP_COOKIE'])]) . "\n", FILE_APPEND);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/slow/') { usleep(220000); }
if ($path === '/timeout/') { usleep(1600000); }
if ($path === '/redirect/') { header('Location: http://127.0.0.1:1/unwanted'); exit; }
if ($path === '/failure/') { http_response_code(503); }
if ($path === '/large/') { echo str_repeat('x', 1100000); exit; }
echo '<!doctype html><title>Health fixture</title>';
PHP;
file_put_contents($root . '/router.php', $router);
$listener = stream_socket_server('tcp://127.0.0.1:0');
$address = stream_socket_get_name($listener, false); fclose($listener);
$process = proc_open([PHP_BINARY, '-S', $address, $root . '/router.php'], [0 => ['pipe', 'r'], 1 => ['file', $root . '/server.log', 'a'], 2 => ['file', $root . '/server.log', 'a']], $pipes);
fclose($pipes[0]);
try {
    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $socket = @stream_socket_client('tcp://' . $address, $errno, $message, 0.05);
        if (is_resource($socket)) { fclose($socket); $ready = true; break; }
        usleep(20000);
    }
    $assert($ready, 'The disposable HTTP fixture starts.');
    $configure(['app' => ['url' => 'http://' . $address]]);
    $_SERVER['HTTP_HOST'] = 'untrusted.invalid';
    $fast = HttpCheck::run();
    $assert($fast['status'] === 'ok' && $fast['metrics']['http_status'] === 200 && $fast['metrics']['complete'], 'A real anonymous GET measures only the configured canonical site.');
    $assert(is_float($fast['metrics']['ttfb_ms']) && $fast['metrics']['ttfb_ms'] > 0 && $fast['metrics']['total_ms'] >= $fast['metrics']['ttfb_ms'], 'Real cURL timings use milliseconds and preserve total versus first-byte timing.');
    $requests = array_map(static fn (string $line): array => json_decode($line, true), file($root . '/requests.jsonl', FILE_IGNORE_NEW_LINES));
    $assert($requests[0] === ['method' => 'GET', 'auth' => false, 'cookie' => false], 'The measurement uses GET without browser credentials or cookies.');
    $report = Service::inspect(['http' => HttpCheck::run(...)]);
    $assert(isset($report['checks'][0]['metrics']['ttfb_ms'], $report['checks'][0]['limits']['ttfb_warning_ms']), 'The resource report preserves both measured values and the limits used for that inspection.');
    $db = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, setting_type TEXT, setting_group TEXT, autoload INTEGER)');
    \TinyCat\Database\Connection::set($db);
    $urlField = ['key' => 'app.url', 'type' => 'site_url'];
    foreach (['http://user:secret@example.com', 'https://example.com/?token=secret', 'https://example.com/#fragment', 'file:///etc/passwd', 'https://example.com/\\evil', "https://example.com/\npath"] as $invalidUrl) {
        $rejected = false;
        try { \TinyCat\Settings\Administration::tc_admin_settings_value_from_post($urlField, ['app.url' => $invalidUrl]); }
        catch (InvalidArgumentException) { $rejected = true; }
        $assert($rejected, 'Admin website URLs reject credential-bearing or ambiguous targets.');
    }
    [$adminUrl] = \TinyCat\Settings\Administration::tc_admin_settings_value_from_post($urlField, ['app.url' => 'http://' . $address . '/']);
    \TinyCat\Settings\Store::setSetting('app.url', $adminUrl, 'string', 'site');
    $configure(['app' => ['url' => 'http://127.0.0.1:1']]);
    $assert(HttpCheck::run()['metrics']['http_status'] === 200, 'A persisted administrator URL overrides file configuration in the actual HTTP probe.');
    $assert(\TinyCat\Settings\Store::config('app.url') === 'http://' . $address, 'The same administrator address is used by canonical link configuration.');
    \TinyCat\Settings\Store::setSetting('app.url', '', 'string', 'site');
    $configure(['app' => ['url' => 'http://' . $address]]);
    $assert(HttpCheck::run()['metrics']['http_status'] === 200, 'Clearing the administrator override falls back to the file configuration.');
    $migration = require dirname(__DIR__) . '/migrations/20261026_003_scheduler_runs.php';
    $migration($db);
    $runId = \TinyCat\Scheduler\History::start('site-health', ['mode' => 'cli']);
    \TinyCat\Scheduler\History::finish($runId, \TinyCat\Scheduler\Tasks::result('completed', details: ['health' => $report]), 5);
    $configure(['health' => ['http' => ['ttfb_warning_ms' => 10, 'ttfb_critical_ms' => 20]]]);
    $saved = Service::snapshot();
    $assert($saved['history'][0]['http']['metrics'] == $report['checks'][0]['metrics'] && $saved['history'][0]['http']['limits']['ttfb_warning_ms'] === 800, 'Durable history keeps real measured timings and the original limits after configuration changes.');
    $configure(['app' => ['url' => 'http://' . $address . '/slow'], 'health' => ['http' => ['ttfb_warning_ms' => 100, 'ttfb_critical_ms' => 1000]]]);
    $slow = HttpCheck::run();
    $assert($slow['status'] === 'warning' && $slow['metrics']['ttfb_ms'] >= 180, 'An actual delayed first byte produces a warning under custom limits.');
    foreach (['redirect' => 'warning', 'failure' => 'critical', 'large' => 'critical'] as $path => $state) {
        $configure(['app' => ['url' => 'http://' . $address . '/' . $path]]);
        $outcome = HttpCheck::run();
        $assert($outcome['status'] === $state, 'Redirects, failed statuses and oversized responses receive explicit outcomes.');
        if ($path === 'redirect') { $assert($outcome['metrics']['http_status'] === 302 && $outcome['metrics']['complete'], 'A redirect is recorded without following its target.'); }
        if ($path === 'large') { $assert(!$outcome['metrics']['complete'] && $outcome['metrics']['error'] === 'response_limit', 'An oversized response is not mistaken for a completed page load.'); }
    }
    $count = count(file($root . '/requests.jsonl'));
    foreach (['', 'file:///etc/passwd', 'http://user:secret@' . $address, 'http://' . $address . '/?token=secret'] as $url) {
        $configure(['app' => ['url' => $url]]);
        $assert(SiteProbe::measure()['error'] === 'configuration', 'Invalid URLs, credentials and query tokens are rejected before transfer.');
    }
    $configure(['app' => ['url' => 'http://' . $address], 'health' => ['http' => ['enabled' => false]]]);
    $assert(HttpCheck::run()['status'] === 'skipped' && count(file($root . '/requests.jsonl')) === $count, 'Disabling the probe makes no request and never fabricates a timing.');
    $configure(['app' => ['url' => 'http://' . $address . '/timeout'], 'health' => ['http' => ['timeout_ms' => 1000]]]);
    $timedOut = HttpCheck::run();
    $assert($timedOut['status'] === 'critical' && $timedOut['metrics']['error'] === 'timeout' && $timedOut['metrics']['ttfb_ms'] === null, 'A real timeout records unavailable TTFB rather than a fake zero.');
} finally {
    proc_terminate($process); proc_close($process);
    foreach (glob($root . '/*') as $path) { unlink($path); }
    rmdir($root);
}
echo "PASS HTTP health measurements and limits ({$checks} checks)\n";
