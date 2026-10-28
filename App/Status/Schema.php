<?php
declare(strict_types=1);

namespace TinyCat\Status;

use PDO;
use RuntimeException;

final class Schema
{
    /** Also repairs an interrupted installation with an existing relation table. */
    public static function ensureTagIndex(PDO $database): void
    {
        if ($database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new RuntimeException('The tag archive index requires MySQL or MariaDB.');
        }

        $statement = $database->query('SHOW INDEX FROM content_tags');
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
        if ($database->exec('ALTER TABLE content_tags ' . $drop . 'ADD INDEX content_tags_term_index (term_id, content_id)') === false) {
            throw new RuntimeException('Unable to create the tag archive index.');
        }
    }
}
