<?php
declare(strict_types=1);

return static function (): array {
    if (!defined('TINYCAT')) { define('TINYCAT', true); }
    require_once dirname(__DIR__) . '/App/autoload.php';
    $validateModals = require __DIR__ . '/modal-contracts.php';
    $validateModals();
    $validateUi = require __DIR__ . '/ui-contracts.php';
    $validateUi();
    $validateTranslations = require __DIR__ . '/translation-catalogs.php';
    $catalogs = $validateTranslations(dirname(__DIR__));
    $validateClients = require __DIR__ . '/client-translations.php';
    $validateClients($catalogs);
    $files = [];
    $server = ['packages' => \TinyCat\Support\TranslationSources::packages(), 'catalogs' => []];
    foreach ($catalogs as $locale => $catalog) {
        // Preserve template order as well as content; all sources were validated above.
        $emails = json_decode((string) file_get_contents(\TinyCat\Support\Paths::basePath('App/Mail/lang/' . $locale . '.json')), true, 512, JSON_THROW_ON_ERROR);
        $source = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export(['app' => $catalog['app'], 'emails' => $emails], true) . ";\n";
        $name = $locale . '.' . substr(hash('sha256', $source), 0, 24) . '.php';
        $files['App/Support/compiled/' . $name] = $source;
        $server['catalogs'][$locale] = $name;
    }
    $files['App/Support/compiled/translations.php'] = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($server, true) . ";\n";
    $manifest = ['assets' => [], 'clients' => []];
    $emit = static function (string $prefix, string $source, string $type = 'js') use (&$files): string {
        $relative = 'assets/build/' . $prefix . '.' . substr(hash('sha256', $source), 0, 24) . '.' . $type;
        $files[$relative] = $source;
        return '/' . $relative;
    };
    $variants = static function (string $prefix, string $source, string $type = 'js') use ($emit): array {
        return ['raw' => $emit($prefix, $source, $type), 'min' => $emit($prefix . '-min', $type === 'js' ? \TinyCat\Support\Minifier::minifyJavaScript($source) : \TinyCat\Support\Minifier::minifyCss($source), $type)];
    };
    $runtime = $variants('runtime', \TinyCat\Support\AssetGraph::javascript(\TinyCat\Support\ClientAssets::runtime()));
    $icon = $emit('icons', \TinyCat\Support\AssetGraph::read('assets/icons.svg'), 'svg');
    $manifest['assets']['icons.svg'] = ['raw' => $icon, 'min' => $icon];
    foreach (\TinyCat\Support\ClientAssets::scopes() as $path) {
        $client = [
            'runtime' => $runtime,
            'app' => $variants('app', \TinyCat\Support\AssetGraph::javascript(\TinyCat\Support\AssetGraph::order(\TinyCat\Support\ClientAssets::entries($path), \TinyCat\Support\ClientAssets::runtime()))),
            'components' => $emit('components', \TinyCat\Support\ClientAssets::componentScript($path)),
            'catalogs' => [],
            'styles' => $variants('styles', \TinyCat\Support\AssetGraph::stylesheet(\TinyCat\Support\ClientAssets::sourceStyles($path)), 'css'),
        ];
        foreach ($catalogs as $locale => $catalog) {
            $client['catalogs'][$locale] = $emit('i18n-' . $locale, 'window.TinyCat.i18n.register(' . \TinyCat\Support\ClientAssets::encode($locale) . ',' . \TinyCat\Support\ClientAssets::encode((object) \TinyCat\Support\ClientAssets::catalog($path, $locale, $catalog['app'])) . ');');
        }
        $manifest['clients'][$path] = $client;
    }
    foreach (\TinyCat\Support\ClientAssets::standalone() as $path) {
        $type = pathinfo($path, PATHINFO_EXTENSION);
        $manifest['assets'][substr($path, strlen('assets/'))] = $variants('standalone-' . pathinfo($path, PATHINFO_FILENAME), \TinyCat\Support\AssetGraph::read($path), $type);
    }
    $files['assets/build/manifest.json'] = \TinyCat\Support\ClientAssets::encode($manifest);
    ksort($files);
    return $files;
};
