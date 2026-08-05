<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

if (PHP_VERSION_ID < 80400) {
    http_response_code(500);
    exit('TinyCat requires PHP 8.4 or newer.');
}

require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/Core.php';
require_once __DIR__ . '/Cache.php';
require_once __DIR__ . '/Minifier.php';
require_once __DIR__ . '/Avatar.php';
require_once __DIR__ . '/SiteIdentity.php';
require_once __DIR__ . '/StatusLinks.php';
require_once __DIR__ . '/LinkMetadata.php';
require_once __DIR__ . '/Notifications.php';
require_once __DIR__ . '/UserRoles.php';
require_once __DIR__ . '/functions.php';

// TinyCat 2.0 bridge for already published 1.x extension packages.
// Remove after all official packages use TinyCat\Extension\Registry directly.
if (!class_exists('ExtensionRegistry', false)) {
    class_alias(TinyCat\Extension\Registry::class, 'ExtensionRegistry');
}

$extensionStateOverrides = Core::setting('extensions.states', []);
TinyCat\Extension\Loader::boot(
    dirname(__DIR__) . '/Extensions',
    is_array($extensionStateOverrides) ? $extensionStateOverrides : []
);
unset($extensionStateOverrides);
