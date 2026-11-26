<?php
declare(strict_types=1);

define('TINYCAT', true);
require $argv[1] . '/App/Core/Runtime/autoload.php';

use TinyCat\Support\{CompiledTranslations, PreparedAssets};
use TinyCat\Core\Modules\DeploymentIdentity;

// A fresh reader must reject the signed build selection and use local metadata.
$signed = require $argv[1] . '/App/Core/Support/compiled/translations.php';
if (DeploymentIdentity::matchesSelection($signed)) { throw new RuntimeException('Fixture requires a mismatched signed inventory.'); }
$prepared = PreparedAssets::read();
if ($prepared === [] || $prepared['identity'] !== DeploymentIdentity::key()) { throw new RuntimeException('Completed lifecycle lost its prepared generation.'); }
if (CompiledTranslations::packages() !== $prepared['packages'] || CompiledTranslations::application('en') !== $prepared['catalogs']['en']['app']) { throw new RuntimeException('Compiled reader did not select the prepared catalogs.'); }
echo "PASS fresh prepared lifecycle reader\n";
