<?php
declare(strict_types=1);

namespace TinyCat\Social\Content;

use PDO;
use RuntimeException;

final class Schema
{
    /** @return list<string> */
    public static function tables(): array { return ['social_posts', 'social_post_redirects', 'social_post_images', 'social_terms', 'social_post_tags', 'social_links', 'social_post_links']; }

    public static function schema(): \TinyCat\Core\Modules\ModuleSchema
    {
        return new \TinyCat\Core\Modules\ModuleSchema(4, self::tables(), self::install(...), [
            new \TinyCat\Core\Modules\SchemaMigration(1, 'tinycat.publishing.initial', dirname(__DIR__, 2) . '/migrations/publishing/001-initial.php'),
            new \TinyCat\Core\Modules\SchemaMigration(2, 'tinycat.publishing.kinds', dirname(__DIR__, 2) . '/migrations/publishing/002-kinds.php'),
            new \TinyCat\Core\Modules\SchemaMigration(3, 'tinycat.publishing.interaction-ownership', dirname(__DIR__, 2) . '/migrations/publishing/003-interaction-ownership.php'),
            new \TinyCat\Core\Modules\SchemaMigration(4, 'tinycat.publishing.owned-storage', dirname(__DIR__, 2) . '/migrations/publishing/004-owned-storage.php'),
        ], new \TinyCat\Core\Modules\SchemaMigration(4, 'tinycat.publishing.fresh-v4', dirname(__DIR__, 2) . '/migrations/publishing/fresh-v4.php'), new \TinyCat\Core\Modules\SchemaMigration(4, 'tinycat.publishing.legacy-v4', dirname(__DIR__, 2) . '/migrations/publishing/legacy-v4.php'));
    }

    public static function install(PDO $database): void
    {
        $install = require dirname(__DIR__, 2) . '/migrations/publishing/fresh-v4.php';
        $install($database);
        if ($database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') { self::ensureTagIndex($database); }
    }

    /** Also repairs an interrupted installation with an existing relation table. */
    public static function ensureTagIndex(PDO $database): void
    {
        if ($database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new RuntimeException('The tag archive index requires MySQL or MariaDB.');
        }

        $statement = $database->query('SHOW INDEX FROM social_post_tags');
        if ($statement === false) { throw new RuntimeException('Unable to inspect the tag archive index.'); }
        $columns = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $index) {
            if ($index['Key_name'] === 'content_tags_term_index') {
                $columns[(int) $index['Seq_in_index']] = (string) $index['Column_name'];
            }
        }
        ksort($columns);
        if (array_values($columns) === ['term_id', 'content_id']) { return; }

        $drop = $columns !== [] ? 'DROP INDEX content_tags_term_index, ' : '';
        if ($database->exec('ALTER TABLE social_post_tags ' . $drop . 'ADD INDEX content_tags_term_index (term_id, content_id)') === false) {
            throw new RuntimeException('Unable to create the tag archive index.');
        }
    }
}
