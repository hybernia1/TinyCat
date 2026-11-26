<?php
declare(strict_types=1);

define('TINYCAT', true);
require $argv[1] . '/App/Core/Runtime/autoload.php';

use TinyCat\Support\Languages;
use TinyCat\Support\TranslationSources;
use TinyCat\Core\Modules\{Catalog, Resources};

// No signed or prepared inventory: preserve the ordinary source fallback.
\TinyCat\Support\Config::configure(new \TinyCat\Core\Runtime\Configuration(['cache' => ['directory' => $argv[1] . '/cache']]));
Resources::configure(new Catalog([]));

$file = $argv[1] . '/App/Core/Support/lang/en.json';
$write = static fn (string $name): int|false => file_put_contents($file, json_encode(['_meta' => ['name' => $name], 'common' => ['close' => 'Close']], JSON_THROW_ON_ERROR));
try {
    $write('First');
    if (Languages::language_packages() !== ['en' => 'First']) { throw new RuntimeException('Initial fallback language discovery failed.'); }
    $write('Second');
    if (Languages::language_packages() !== ['en' => 'First'] || TranslationSources::packages() !== ['en' => 'Second']) { throw new RuntimeException('Runtime snapshot must not cache direct source validation.'); }
    Resources::withCatalog(new Catalog([]), static function () use ($write): void {
        if (Languages::language_packages() !== ['en' => 'Second']) { throw new RuntimeException('Selection change did not refresh language metadata.'); }
        $write('Third');
        if (Languages::language_packages() !== ['en' => 'Second'] || TranslationSources::packages() !== ['en' => 'Third']) { throw new RuntimeException('Scoped metadata snapshot or fresh validation failed.'); }
    });
    if (Languages::language_packages() !== ['en' => 'Third']) { throw new RuntimeException('Leaving a selection scope retained stale language metadata.'); }
} finally {
    if (is_file($file)) { unlink($file); }
    $lock = $argv[1] . '/cache/coordination/' . hash('sha256', 'runtime-assets') . '.lock';
    if (is_file($lock)) { unlink($lock); }
    foreach (['cache/coordination', 'cache'] as $relative) { if (is_dir($argv[1] . '/' . $relative)) { rmdir($argv[1] . '/' . $relative); } }
}
echo "PASS fallback language snapshots and fresh source validation\n";
