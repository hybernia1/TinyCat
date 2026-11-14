<?php
declare(strict_types=1);

return static function (\PDO $pdo): void {
    \TinyCat\Social\Content\LegacyData::publications($pdo);
};
