<?php
declare(strict_types=1);
namespace TinyCat\Core\Tasks;

use PDO;
use TinyCat\Core\Modules\{ModuleSchema, SchemaMigration};

/** Current execution storage and immutable signed migration history. */
final class Schema
{
    public const OWNER = 'tinycat.support';

    public static function schema(?string $root = null): ModuleSchema
    {
        $directory = ($root ?? \TinyCat\Support\Paths::basePath()) . '/App/Core/Tasks/migrations';
        $fresh = $directory . '/fresh-v6.php';
        return new ModuleSchema(6, ['scheduler_runs'], static function (PDO $pdo) use ($fresh): void { (require $fresh)($pdo); }, [new SchemaMigration(1, 'tinycat.core.tasks.history', null, '87d1d10675413587d26c514e05772b16e376135ead8f92ddfb5a0f73b0527515'), new SchemaMigration(2, 'tinycat.core.migrations.unified', null, '61b3fb71f28932e1b2b0f57ed1fb344cfa470ac5a28eac7953645a509bc96eb5'), new SchemaMigration(3, 'tinycat.core.migrations.writer', null, 'e4db653a2dff095fa582eb9dda272cff69ebc1471b05e88d68b529b834b945ac'), new SchemaMigration(4, 'tinycat.core.migrations.release-labels', null, '8df162c548d0e987cdef30599f1955f540e06a6c8efa8877af791cad8a19fb34'), new SchemaMigration(5, 'tinycat.core.migrations.current', null, 'ac800007cafe715be43a284739fd6f70c94d7a5020c3fb5c662361e07943e715'), new SchemaMigration(6, 'tinycat.core.assets.build', $directory . '/asset-build.php')], new SchemaMigration(6, 'tinycat.core.tasks.fresh-v6', $fresh));
    }

}
