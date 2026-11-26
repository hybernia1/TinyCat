<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') { throw new \RuntimeException('MySQL or MariaDB is required.'); }
    $statement = $pdo->query('SHOW TABLES');
    if ($statement === false) { throw new \RuntimeException('Unable to inspect report tables.'); }
    $tables = $statement->fetchAll(\PDO::FETCH_COLUMN);
    $old = in_array('content_reports', $tables, true);
    $current = in_array('social_reports', $tables, true);
    if ($old && $current) { throw new \RuntimeException('Both legacy and current report tables exist; refusing to overwrite report history.'); }
    if ($old) { $pdo->exec('RENAME TABLE content_reports TO social_reports'); return; }
    if (!$current) { throw new \RuntimeException('The report table is missing.'); }
};
