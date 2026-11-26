<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') { throw new \RuntimeException('MySQL or MariaDB is required.'); }
    $statement = $pdo->query('SHOW COLUMNS FROM social_posts');
    if ($statement === false) { throw new \RuntimeException('Unable to inspect post columns.'); }
    $columns = $statement->fetchAll(\PDO::FETCH_COLUMN);
    if (!in_array('kind', $columns, true)) { return; }
    $statement = $pdo->query("SELECT COUNT(*) FROM social_posts WHERE kind IS NULL OR BINARY kind <> BINARY 'post'");
    if ($statement === false) { throw new \RuntimeException('Unable to inspect post kinds.'); }
    if ((int) $statement->fetchColumn() !== 0) {
        throw new \RuntimeException('Cannot remove the post discriminator while unexpected content kinds exist.');
    }
    $pdo->exec('ALTER TABLE social_posts DROP COLUMN kind');
};
