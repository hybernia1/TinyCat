<?php
declare(strict_types=1);

// Immutable classification of articles previously written as generic publications.
return static function (\PDO $pdo): void {
    $pdo->exec("UPDATE content SET kind = 'article' WHERE id IN (SELECT content_id FROM blog_articles)");
};
