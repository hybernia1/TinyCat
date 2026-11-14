<?php
declare(strict_types=1);
namespace TinyCat\Core\Modules;

/** Historical streams whose tables are also installed by the legacy global schema. */
interface ProvidesManagedSchemas extends ProvidesSchemas {}
