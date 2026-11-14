<?php
declare(strict_types=1);

namespace TinyCat\Core\Identity;
use TinyCat\Core\Modules\Distribution;
use TinyCat\Core\Modules\Deployment;
use TinyCat\Core\Modules\Loader;
use TinyCat\Core\Modules\Resources;
use TinyCat\Core\Modules\ModuleAutoload;


use TinyCat\Core\Identity\Participant;
use TinyCat\Support\Paths;

/** Product integration, kept outside the kernel and the Accounts domain service. */
final class Participants
{
    private static bool $configured = false;
    private static ?Loader $modules = null;

    public static function configure(?Loader $modules): void
    {
        self::$configured = true;
        self::$modules = $modules;
    }

    /** @return array<string, Participant> */
    public static function current(bool $deleting = false): array
    {
        if ($deleting) {
            $root = Paths::basePath(); $plan = Distribution::read($root); $state = Deployment::read($root);
            if ($plan !== null && $state !== null) {
                // Inactive owners still own retained account data; no tasks or routes are activated.
                $installed = Loader::fromSelection($root, Distribution::selection($plan, $state['installed']));
                $participants = [];
                foreach (self::fromModules($installed) as $id => $participant) {
                    $catalog = $installed->catalog;
                    $participants[$id] = new Participant(prepareDelete: static function (array $user) use ($catalog, $participant): \TinyCat\Core\Identity\Deletion {
                        $deletion = ModuleAutoload::withCatalog($catalog, static fn () => ($participant->prepareDelete)($user));
                        return new \TinyCat\Core\Identity\Deletion(
                            afterDelete: static fn () => ModuleAutoload::withCatalog($catalog, $deletion->afterDelete),
                            afterCommit: static fn () => ModuleAutoload::withCatalog($catalog, $deletion->afterCommit),
                        );
                    });
                }
                return $participants;
            }
        }
        $modules = self::$configured ? self::$modules : Loader::load(Paths::basePath());
        return $modules !== null ? self::fromModules($modules, Resources::catalog()) : [];
    }

    /** @return array<string, Participant> */
    public static function fromModules(Loader $modules, ?\TinyCat\Core\Modules\Catalog $catalog = null): array
    {
        return ModuleAutoload::withCatalog($catalog ?? $modules->catalog, static fn (): array => self::createParticipants($modules, $catalog));
    }

    /** @return array<string, Participant> */
    private static function createParticipants(Loader $modules, ?\TinyCat\Core\Modules\Catalog $catalog): array
    {
        $result = [];
        foreach ($modules->providers(ProvidesLifecycle::class, $catalog) as $id => $class) { $result[$id] = $class::accountLifecycle(); }
        return $result;
    }
}
