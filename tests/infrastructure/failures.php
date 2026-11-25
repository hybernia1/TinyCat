<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/mysql.php';

require_once __DIR__ . '/../support/cache-isolation.php';
define('TINYCAT', true);
require dirname(__DIR__, 2) . '/App/Core/Runtime/autoload.php';

use TinyCat\Core\Runtime\{Application, Configuration, Debug, Failure};
use TinyCat\Database\Connection;
use TinyCat\Http\HttpException;

$directory = \TinyCat\Tools\Workspace::directory('tests/failures-' . bin2hex(random_bytes(6)));
Application::start(new Configuration(['app' => ['debug' => 'off']]));
Debug::configure('log', $directory);
Connection::set(\tinycat_test_database());
Connection::get()->exec('CREATE TABLE settings (setting_key VARCHAR(190) PRIMARY KEY, setting_value TEXT, setting_type TEXT, autoload INT)');
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
};
$fails = static function (callable $operation, string $class) use ($assert): Throwable {
    try { $operation(); } catch (Throwable $error) {
        $assert($error instanceof $class, 'Unexpected failure type: ' . $error::class);
        return $error;
    }
    throw new RuntimeException('Failure was silently converted to a successful result.');
};

foreach ([new RuntimeException('private database details', 404), new InvalidArgumentException('bad internal call'), new LogicException('broken definition')] as $error) {
    $assert(Failure::status($error) === 500 && Failure::code($error) === 'server_error', 'Only explicit public failures can set HTTP status');
    $assert(Failure::message($error) === 'Server error.', 'Technical details stay private in log mode');
}
$public = new HttpException('Record not found.', 404, 'not_found');
Failure::report($public);
$assert(!is_file($directory . '/debug.log'), 'Expected public failures do not create technical diagnostics');
$fails(static fn () => new HttpException('bad definition', 200), LogicException::class);

// Deliberately missing tables: these operations must stop, not permit a write.
$fails(static fn () => \TinyCat\Core\Identity\Usernames::taken('someone'), PDOException::class);
$fails(static fn () => \TinyCat\Social\Moderation\Restrictions::moderation_user_post_count(1), PDOException::class);
$fails(static fn () => \TinyCat\Social\Moderation\Restrictions::moderation_action_count(['id' => 1, 'role' => 'user'], 'post'), PDOException::class);
$fails(static fn () => \TinyCat\Social\Posts\Actions::status_json_require_unique_body(['id' => 1], 'Long enough body for duplicate verification.'), PDOException::class);
$authentication = new ReflectionMethod(\TinyCat\Core\Identity\Authentication::class, 'findUserByUsername');
$fails(static fn () => $authentication->invoke(null, 'someone'), PDOException::class);
$unknown = $fails(static fn () => \TinyCat\Core\Ui\Admin\Statistics::resource('unknown.owner'), HttpException::class);
$assert(Failure::status($unknown) === 404, 'Unknown statistics retain public 404');

// An optional display may recover, but must leave a diagnostic record.
$assert(\TinyCat\Social\Site\Dashboard::commentsWidget()['items'] === [], 'Optional dashboard retains its empty fallback');
$records = file($directory . '/debug.log', FILE_IGNORE_NEW_LINES);
$assert(is_array($records) && count($records) === 1, 'Recovered dashboard failure is reported');
$record = json_decode($records[0], true, 512, JSON_THROW_ON_ERROR);
$assert($record['exception'] === PDOException::class, 'Diagnostic preserves the original failure type');

$error = new RuntimeException('one original cause');
Failure::report($error); Failure::report($error);
$assert(count(file($directory . '/debug.log', FILE_IGNORE_NEW_LINES)) === 2, 'Multiple boundaries report the same exception once');

// Per-call flags must not bypass the installation-wide disclosure policy.
foreach ([\TinyCat\Http\Api::class, \TinyCat\Http\Response::class, \TinyCat\Http\Router::class, \TinyCat\Core\Tasks\Runner::class, \TinyCat\Core\Tasks\Execution::class, Failure::class] as $class) {
    foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        foreach ($method->getParameters() as $parameter) {
            $assert($parameter->getName() !== 'debug', 'Shared boundaries must use the central mode, never a per-call debug override');
        }
    }
}

// Prevent the previous unobservable catch style and ad hoc logging from returning.
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/App', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') { continue; }
    $source = file_get_contents($file->getPathname());
    $assert(!str_contains($source, 'error_log('), 'Application diagnostics must use the shared reporter: ' . $file->getPathname());
    if ($file->getFilename() === 'Errors.php' && str_contains($file->getPathname(), 'Runtime')) { continue; }
    $assert(preg_match('/catch\s*\(\\\\?Throwable\s*\)/', $source) !== 1, 'Broad recovery must retain its exception: ' . $file->getPathname());
}
echo "PASS failure classification, fail-closed decisions and observable recovery ({$checks} checks)\n";
