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
        $fresh = $directory . '/fresh-v5.php';
        return new ModuleSchema(5, ['scheduler_runs'], static function (PDO $pdo) use ($fresh): void { (require $fresh)($pdo); }, [new SchemaMigration(1, 'tinycat.core.tasks.history', $directory . '/001-history.php'), new SchemaMigration(2, 'tinycat.core.migrations.unified', $directory . '/002-journal.php'), new SchemaMigration(3, 'tinycat.core.migrations.writer', $directory . '/003-writer.php'), new SchemaMigration(4, 'tinycat.core.migrations.release-labels', $directory . '/004-release-labels.php'), new SchemaMigration(5, 'tinycat.core.migrations.current', $directory . '/005-current-journal.php')], new SchemaMigration(5, 'tinycat.core.tasks.fresh-v5', $fresh));
    }

}
