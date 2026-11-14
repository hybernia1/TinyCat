<?php
declare(strict_types=1);

namespace TinyCat\Core\Modules;

interface ProvidesSchema
{
    /** Metadata and deferred operations only; never execute DDL during discovery. */
    public static function schema(): ModuleSchema;
}
