<?php
declare(strict_types=1);
namespace TinyCat\Core\Runtime;
use TinyCat\Core\Modules\Distribution;
use TinyCat\Core\Modules\Deployment;
use TinyCat\Core\Modules\Lifecycle;
use TinyCat\Core\Modules\Loader;
use TinyCat\Core\Modules\Resources;
use TinyCat\Core\Modules\Registrations;


/** Production CLI entry: no dev tools, Composer or public setup endpoint required. */
final class Console
{
    /** @param list<string> $arguments */
    public static function run(array $arguments): int
    {
        try {
            $command = $arguments[1] ?? 'help';
            $root = \TinyCat\Support\Paths::basePath();
            \TinyCat\Support\Config::load();
            if (!in_array($command, ['help', 'rescue', 'rollback', 'finish-deployment'], true) && \TinyCat\Core\Updates\Manager::installationPending()) { throw new \RuntimeException('Installation recovery is required.'); }
            if ($command === 'help') {
                echo "php App/Console.php status|install|upgrade|rescue|activate ID...|deactivate ID...|task ID [name=integer ...]|module-install ZIP|update MANIFEST SIGNATURE ZIP|rollback BACKUP_ID|finish-deployment BACKUP_ID\n";
                echo "Install reads the selected setup providers' JSON input from stdin. Configure config.php first.\n";
                echo "Installation recovery: rollback BACKUP_ID --installation-stdin reads a database JSON object from private stdin.\n";
                return 0;
            }
            if ($command === 'rescue') {
                $file = $root . '/storage/updates/console.php';
                $bootstrap = "<?php\ndeclare(strict_types=1);\nif (PHP_SAPI !== 'cli') { http_response_code(403); exit; }\ndefine('TINYCAT', true);\nrequire " . var_export($root . '/App/Core/Runtime/autoload.php', true) . ";\nexit(\\TinyCat\\Core\\Runtime\\Console::run(\$argv));\n";
                if (!\TinyCat\Support\Storage::write($file, $bootstrap)) { throw new \RuntimeException('Cannot prepare the private rescue launcher.'); }
                $result = ['launcher' => 'storage/updates/console.php'];
            }
            elseif ($command === 'rollback') {
                $installation = ($arguments[3] ?? '') === '--installation-stdin';
                if ($installation) {
                    if (count($arguments) !== 4 || stream_isatty(STDIN)) { throw new \RuntimeException('Supply installation database JSON through private stdin.'); }
                    $source = stream_get_contents(STDIN, 65537);
                    if (!is_string($source) || strlen($source) > 65536) { throw new \RuntimeException('Invalid installation recovery input.'); }
                    $input = json_decode($source, true, 16, JSON_THROW_ON_ERROR);
                    $database = is_array($input) ? ($input['database'] ?? null) : null;
                    if (!is_array($database) || array_diff(array_keys($database), ['host', 'port', 'name', 'user', 'password', 'charset']) !== []) { throw new \RuntimeException('Invalid installation recovery database.'); }
                    foreach ($database as $key => $value) { if (!is_string($value) && !($key === 'port' && is_int($value))) { throw new \RuntimeException('Invalid installation recovery database field.'); } }
                    \TinyCat\Database\Connection::set(\TinyCat\Core\Installation\Installer::pdo($database));
                } elseif (count($arguments) !== 3) { throw new \RuntimeException('Rollback requires a backup ID.'); }
                $result = \TinyCat\Core\Updates\Manager::rollback($arguments[2], installationCli: $installation);
            }
            elseif ($command === 'finish-deployment') {
                if (count($arguments) !== 3) { throw new \TinyCat\Http\HttpException('Finishing deployment requires a backup ID.'); }
                \TinyCat\Core\Updates\Manager::finishCommittedOperation($arguments[2]);
                $result = ['finished' => true, 'backup' => $arguments[2]];
            }
            elseif ($command === 'module-install') {
                if (count($arguments) !== 3) { throw new \TinyCat\Http\HttpException('Module installation requires a ZIP path.'); }
                $result = \TinyCat\Core\Modules\ModulePackage::install($arguments[2]);
            }
            elseif ($command === 'update') {
                if (count($arguments) !== 5) { throw new \TinyCat\Http\HttpException('Update requires manifest, signature and package paths.'); }
                $result = \TinyCat\Core\Updates\Manager::installLocalPackage($arguments[2], $arguments[3], $arguments[4]);
            } else {
                $plan = Distribution::read($root);
                if ($plan === null) { throw new \RuntimeException('This operation requires a built product descriptor. Build and extract the selected distribution first.'); }
                if ($command === 'status') {
                    $state = Deployment::read($root);
                    $available = Loader::fromSelection($root, \TinyCat\Core\Modules\PackageRegistry::featureSelection($root, $plan['selection'], $state['active'] ?? $plan['active']))->registries()->entries;
                    $features = [];
                    foreach ($available->features as $feature) { $features[$feature->id] = $feature->modules; }
                    $result = ['product' => $plan['product'], 'version' => \TinyCat\Support\Version::CURRENT, 'available' => array_keys($plan['selection']), 'features' => $features, 'deployment' => $state, 'maintenance' => \TinyCat\Core\Updates\Manager::maintenanceActive()];
                } elseif ($command === 'task') {
                    $name = $arguments[2] ?? '';
                    $options = [];
                    foreach (array_slice($arguments, 3) as $argument) {
                        $parts = explode('=', $argument, 2);
                        if (count($parts) !== 2) { throw new \TinyCat\Http\HttpException('Task options use name=integer.'); }
                        $value = filter_var($parts[1], FILTER_VALIDATE_INT);
                        if ($value === false) { throw new \TinyCat\Http\HttpException('Task options must be integers.'); }
                        $options[$parts[0]] = $value;
                    }
                    Application::start();
                    $result = \TinyCat\Core\Tasks\Execution::run($name, $options, 'cli');
                } elseif ($command === 'install') {
                    Resources::configure(Loader::fromSelection($root, Distribution::selection($plan, $plan['active']))->catalog);
                    $setup = [];
                    $providers = Loader::fromSelection($root, Distribution::selection($plan, $plan['active']))->providers(\TinyCat\Core\Modules\ProvidesSetup::class);
                    if ($providers !== []) {
                        if (stream_isatty(STDIN)) { throw new \TinyCat\Http\HttpException('Supply module setup JSON through stdin; never put passwords in command arguments.'); }
                        $source = stream_get_contents(STDIN, 65537);
                        if (!is_string($source) || strlen($source) > 65536) { throw new \TinyCat\Http\HttpException('Invalid setup input.'); }
                        $input = json_decode($source, true, 32, JSON_THROW_ON_ERROR);
                        if (!is_array($input)) { throw new \TinyCat\Http\HttpException('Setup input must be an object.'); }
                        foreach ($providers as $id => $provider) { $setup[$id] = $provider::validateSetup($input); }
                    }
                    $result = Lifecycle::install($plan, $setup);
                } elseif (in_array($command, ['activate', 'deactivate', 'upgrade'], true)) {
                    $state = Deployment::read($root);
                    $active = $state['active'] ?? $plan['active'];
                    $plan = Distribution::withDiscovered($root, $plan, array_slice($arguments, 2));
                    $ids = \TinyCat\Core\Modules\FeatureSelection::expand($plan, array_slice($arguments, 2), $command === 'activate');
                    foreach ($ids as $id) { if (!isset($plan['selection'][$id])) { throw new \TinyCat\Http\HttpException('Module is not available in this distribution.'); } }
                    if ($command !== 'upgrade' && $ids === []) { throw new \TinyCat\Http\HttpException('Specify at least one module ID.'); }
                    if ($command === 'activate') { $active = array_values(array_unique([...$active, ...$ids])); }
                    if ($command === 'deactivate') { $active = array_values(array_diff($active, $ids)); }
                    $result = Lifecycle::activate($active, $state);
                } else { throw new \TinyCat\Http\HttpException('Unknown command; use help.'); }
            }
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            return $command === 'task' && empty($result['ok']) ? 1 : 0;
        } catch (\Throwable $error) { Failure::report($error); fwrite(STDERR, Failure::message($error, 'Command failed.') . "\n"); return 1; }
    }
}
