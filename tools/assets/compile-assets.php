<?php
declare(strict_types=1);

return static function (): array {
    if (!defined('TINYCAT')) { define('TINYCAT', true); }
    require_once __DIR__ . '/../../App/Core/Runtime/autoload.php';
    return \TinyCat\Support\AssetGraph::withSnapshot(static fn (): array => \TinyCat\Support\ClientAssets::withBuildCache(static function (): array {
        $validateModals = require __DIR__ . '/../quality/modal-contracts.php';
        $validateModals();
        $validateUi = require __DIR__ . '/../quality/ui-contracts.php';
        $validateUi();
        $validateTranslations = require __DIR__ . '/../quality/translation-catalogs.php';
        $catalogs = $validateTranslations(dirname(__DIR__, 2));
        $validateClients = require __DIR__ . '/../quality/client-translations.php';
        $validateClients($catalogs);
        return \TinyCat\Support\AssetCompiler::compile($catalogs)['files'];
    }));
};
