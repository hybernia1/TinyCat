<?php
declare(strict_types=1);

// A tiny independently authored package, consumed by the existing native Blog fixture.
return static function (string $workspace, string $version, string $message): string {
    $class = <<<'PHP'
<?php
declare(strict_types=1);
namespace TinyCat\UploadFixture;
file_put_contents(\TinyCat\Support\Paths::basePath('storage/upload-fixture-loaded'), 'loaded');
final class Module implements \TinyCat\Core\Modules\Module
{
    public static function register(\TinyCat\Http\Api $api): void
    {
        \TinyCat\Http\Router::route('GET', '/fixture-module', static fn () => \TinyCat\Http\Document::respond('@@MESSAGE@@', 'text/plain', 0));
    }
}
PHP;
    $manifest = ['format' => 1, 'coreApi' => 1, 'id' => 'fixture.upload', 'namespace' => 'TinyCat\\UploadFixture\\', 'requires' => [], 'providers' => ['routes' => 'TinyCat\\UploadFixture\\Module'], 'resources' => (object) []];
    $files = ['module.json' => json_encode($manifest, JSON_THROW_ON_ERROR), 'src/Module.php' => str_replace('@@MESSAGE@@', $message, $class)];
    $package = ['format' => 1, 'kind' => 'tinycat-module', 'id' => 'fixture.upload', 'directory' => 'UploadFixture', 'version' => $version,
        'minimum_core' => \TinyCat\Support\Version::CURRENT, 'minimum_php' => '8.4.0', 'manifest' => $manifest, 'schemas' => [], 'files' => array_map(static fn (string $body): string => hash('sha256', $body), $files)];
    $files['package.json'] = json_encode($package, JSON_THROW_ON_ERROR);
    $path = $workspace . '/upload-module-' . $version . '.zip';
    $zip = new ZipArchive(); $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($files as $name => $body) { $zip->addFromString($name, $body); }
    $zip->close();
    return $path;
};
