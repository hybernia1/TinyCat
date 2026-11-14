<?php
declare(strict_types=1);
namespace TinyCat\Core\Modules;

/** One module can retain several immutable, independently published migration streams. */
interface ProvidesSchemas
{
    /** @return array<string, ModuleSchema> Stable history ID => schema. */
    public static function schemas(): array;
}
