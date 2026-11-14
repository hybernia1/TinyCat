<?php
declare(strict_types=1);

namespace TinyCat\Core\Runtime;
use TinyCat\Core\Modules\Loader;
use TinyCat\Core\Modules\Resources;
use TinyCat\Core\Modules\Registrations;
use TinyCat\Core\Modules\Lifecycle;


use TinyCat\Core\Modules\Catalog;
use TinyCat\Core\Modules\Registries;
use TinyCat\Core\Runtime\Context;
use TinyCat\Database\Schema;
use TinyCat\Http\Api;
use TinyCat\Http\Router;
use TinyCat\Support\Localization;
use TinyCat\Support\Config;

/** One startup per request/process. Domain policies are inputs, never inferred. */
final class Application
{
    private static bool $booted = false;
    private static bool $initialized = false;

    public static function start(?Configuration $configuration = null, ?Loader $modules = null): Api
    {
        if ($configuration !== null) { Config::configure($configuration); }
        else { Config::load(); }
        $modules ??= Loader::load(\TinyCat\Support\Paths::basePath());
        Resources::configure($modules->catalog);
        if ($modules->catalog->has('tinycat.ui')) { \TinyCat\Core\Ui\Platform::initialize(); }
        $modules->initialize(); self::$initialized = true;
        Startup::$readiness[] = static fn (): bool => Lifecycle::ready($modules, Startup::$legacySchemas);
        \TinyCat\Support\ClientAssets::configure(static fn (string $key): bool => (bool) Startup::option($key, false));
        return self::boot(Startup::context(Config::snapshot()), $modules);
    }

    public static function boot(Context $context, ?Loader $modules = null): Api
    {
        if (self::$booted) { throw new \LogicException('The application is already booted.'); }
        self::$booted = true;
        $catalog = $modules->catalog ?? new Catalog([]);
        $registries = $modules?->registries() ?? new Registries();
        Config::configure($context->configuration);
        \TinyCat\Support\Dates::configure($context->dateOption(...));
        Router::debug((bool) $context->configuration->get('app.debug', false));
        Resources::configure($catalog);
        if ($catalog->has('tinycat.ui')) { \TinyCat\Core\Ui\Platform::initialize(); }
        if ($modules !== null && !self::$initialized) { $modules->initialize(); self::$initialized = true; }
        Registrations::bind($modules);
        Registrations::configure($registries);
        Localization::configure($context->locale(...));
        $api = new Api($context->identity(...));
        $tables = $modules?->register($api) ?? [];
        if ($modules !== null) { EntryPages::register(); Router::registerModule(static fn () => \TinyCat\Core\Ui\Platform::register($api)); Router::registerModule(static fn () => \TinyCat\Core\Ui\ModulesPage::register($api)); Router::registerModule(static fn () => \TinyCat\Core\Discovery\Routes::register($api)); }
        Schema::configure($tables, $context->ready(...));
        return $api;
    }
}
