<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/tools/support/workspace.php';
$directory = \TinyCat\Tools\Workspace::directory('tests/debug-' . bin2hex(random_bytes(6)));
$assert = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};
foreach (['on', 'off', 'log', 'true', 'false', 'unknown'] as $mode) {
    foreach (['warning', 'exception', 'fatal', 'user-fatal', 'api', 'http', 'caught', 'unwritable', 'public', 'internal-code', 'internal-argument', 'host-exception'] as $scenario) {
        $path = $directory . '/' . $mode . '-' . $scenario;
        $process = proc_open([PHP_BINARY, '-d', 'html_errors=0', __DIR__ . '/../fixtures/debug-worker.php', $mode, $path, $scenario], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Cannot start debug worker.'); }
        $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $exit = proc_close($process);
        $output = $stdout . $stderr;
        $enabled = in_array($mode, ['on', 'true'], true);
        $fatal = in_array($scenario, ['fatal', 'user-fatal', 'exception', 'host-exception'], true);
        $assert(!$fatal || $exit !== 0, "$mode/$scenario must fail the process");
        $assert(!str_contains($output, 'unreachable'), 'Fatal errors must stop execution');
        $assert(!str_contains($output, 'suppressed'), 'Suppressed errors stay suppressed');
        if ($scenario === 'public') {
            $json = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
            $assert($json['status'] === 404 && $json['code'] === 'not_found' && $json['error']['details']['id'] === 7, 'Explicit public rejection retains its status, code and details');
            $assert($json['message'] === 'Public rejection.' && !isset($json['error']['details']['file']), 'Public rejections never disclose internals');
        } elseif ($scenario === 'host-exception') {
            $assert(str_contains($stderr, 'test assertion diagnostic') && str_contains($stderr, 'debug-worker.php'), 'Test host handler survives production configuration');
        } elseif (in_array($scenario, ['api', 'internal-code', 'internal-argument'], true)) {
            $json = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
            $assert($json['status'] === 500 && $json['ok'] === false, 'API envelope is preserved');
            $assert(($json['message'] === 'api diagnostic') === $enabled, 'API details follow the central mode');
        } elseif (!in_array($scenario, ['caught', 'unwritable'], true)) {
            $assert((str_contains($output, 'diagnostic') || str_contains($output, 'must be compatible')) === $enabled, "$mode/$scenario visibility");
        }
        $file = $path . '/debug.log';
        $assert(is_file($file) === ($mode === 'log' && !in_array($scenario, ['unwritable', 'public', 'host-exception'], true)), "$mode/$scenario log destination");
        if (is_file($file)) {
            $lines = file($file, FILE_IGNORE_NEW_LINES);
            // PHP 8.4 also emits a deprecation before the E_USER_ERROR event.
            $assert(is_array($lines) && count($lines) === ($scenario === 'user-fatal' ? 2 : 1), 'Each event is recorded once on one physical line');
            $record = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
            $assert(array_keys($record) === ['time', 'level', 'message', 'file', 'line', 'exception'], 'Stable log schema');
            $assert(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $record['time']) === 1, 'UTC timestamp');
            $assert($record['file'] !== '' && $record['line'] > 0, 'Source location exists');
        }
    }
}
echo "Runtime debug modes passed (72 isolated scenarios).\n";
