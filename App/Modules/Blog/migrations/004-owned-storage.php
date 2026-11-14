<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    \TinyCat\Blog\LegacyData::migrate($pdo);
};
