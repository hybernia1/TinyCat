<?php
declare(strict_types=1);

define('TINYCAT', true);
require $argv[1] . '/App/autoload.php';
$result = ['packages' => \TinyCat\Support\Languages::language_packages()];
$result['unknown_locale'] = \TinyCat\Support\Localization::t('common.close', [], 'xx');
$result['regional_plural'] = \TinyCat\Support\Localization::plural('bots.sources_count', 2, [], 'cs_CZ');
foreach (array_keys($result['packages']) as $locale) {
    $result[$locale] = [
        'app' => \TinyCat\Support\Localization::catalog($locale),
        'emails' => \TinyCat\Mail\Templates::email_catalog($locale),
        'missing' => \TinyCat\Support\Localization::t('missing.translation', [], $locale),
        'parameter' => \TinyCat\Support\Localization::plural('public.active_user_posts', 7, [], $locale),
    ];
}
echo json_encode($result, JSON_THROW_ON_ERROR);
