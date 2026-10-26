<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$options = getopt('', ['config:', 'directory:']);
$configPath = realpath((string) ($options['config'] ?? dirname(__DIR__) . '/config.php'));
$directory = (string) ($options['directory'] ?? '');
if (!$configPath || $directory === '' || file_exists($directory)) {
    fwrite(STDERR, "Usage: php tools/prepare-performance.php --config=config.php --directory=<new private directory>\n");
    exit(2);
}
$config = require $configPath;
$db = $config['database'];
$server = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], $db['port'] ?? 3306),
    $db['user'], $db['password'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
);
$source = str_replace('`', '``', $db['name']);
$tables = $server->query("SHOW FULL TABLES FROM `$source` WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
if ($tables === []) { throw new RuntimeException('Source schema is empty.'); }
if (!mkdir($directory, 0700, true)) { throw new RuntimeException('Unable to create private performance directory.'); }
$name = 'tinycat_perf_' . bin2hex(random_bytes(6));
$server->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$server->exec("USE `$name`");
$server->exec('SET FOREIGN_KEY_CHECKS=0');
try {
    foreach ($tables as $table) {
        $table = str_replace('`', '``', $table);
        $ddl = $server->query("SHOW CREATE TABLE `$source`.`$table`")->fetch(PDO::FETCH_NUM)[1];
        $server->exec($ddl);
    }
} finally {
    $server->exec('SET FOREIGN_KEY_CHECKS=1');
}
$config['database']['name'] = $name;
$config['cache'] = ['driver' => 'filesystem', 'directory' => realpath($directory) . '/cache'];
$path = $directory . '/config.php';
if (file_put_contents($path, "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n", LOCK_EX) === false) {
    throw new RuntimeException('Unable to save performance configuration; isolated database: ' . $name);
}
echo json_encode(['database' => $name, 'schema_tables' => count($tables), 'config' => $path, 'source_data_copied' => false], JSON_THROW_ON_ERROR) . PHP_EOL;
