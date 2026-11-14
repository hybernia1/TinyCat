<?php
declare(strict_types=1);
namespace TinyCat\Core\Modules;
/** Module-owned validation and post-schema initialization for explicit installation. */
interface ProvidesSetup
{
    /** @param array<string, mixed> $input
     * @return array<string, mixed> */
    public static function validateSetup(array $input): array;
    /** @param array<string, mixed> $input */
    public static function completeSetup(array $input): void;
}
