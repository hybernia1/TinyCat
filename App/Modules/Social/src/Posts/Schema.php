<?php
declare(strict_types=1);

namespace TinyCat\Social\Posts;

use PDO;
use RuntimeException;

final class Schema
{
    /** @return list<string> */
    public static function tables(): array { return ['social_posts', 'social_post_redirects', 'social_post_images', 'social_terms', 'social_post_tags', 'social_links', 'social_post_links']; }

    public static function schema(): \TinyCat\Core\Modules\ModuleSchema
    {
        return new \TinyCat\Core\Modules\ModuleSchema(4, self::tables(), self::install(...), [
            new \TinyCat\Core\Modules\SchemaMigration(1, 'tinycat.publishing.initial', null, '49b77a46cc768f5e5d9cae3db8bb7bd249c1eaeeda824abf7d92d03a28d0f43c'),
            new \TinyCat\Core\Modules\SchemaMigration(2, 'tinycat.publishing.kinds', null, 'dae1d419a620d0979bcf560e2351895a839c86fbfcba7c1602e7e56af2966c0d'),
            new \TinyCat\Core\Modules\SchemaMigration(3, 'tinycat.publishing.interaction-ownership', null, 'f8fb56260fda59a2a551ec708474635700c23a1936d9a91efa29fd7f12343e3a'),
            new \TinyCat\Core\Modules\SchemaMigration(4, 'tinycat.publishing.owned-storage', null, '4d758c21e734f197136a0d02b41db6de93dd97239fdfd2c45b5b19be9a18c4ae'),
        ], new \TinyCat\Core\Modules\SchemaMigration(4, 'tinycat.publishing.fresh-v4', dirname(__DIR__, 2) . '/migrations/publishing/fresh-v4.php'), repairOnInstall: true, gateRoutes: false);
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
