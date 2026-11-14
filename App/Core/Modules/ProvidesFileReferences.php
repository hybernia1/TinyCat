<?php
declare(strict_types=1);
namespace TinyCat\Core\Modules;

/** Owners report their references only when bounded filesystem cleanup asks for them. */
interface ProvidesFileReferences
{
    /** @param list<string> $candidates
     * @return list<string> */
    public static function fileReferences(string $group, array $candidates): array;
}
