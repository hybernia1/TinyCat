<?php
declare(strict_types=1);

// Exercise the source-owned transaction with explicitly prepared publication data.
return static function (int $sourceId, int $authorId, string $feedHash, string $itemHash, \TinyCat\Social\Posts\PostCreation $draft, array $links, int $interval = 30, ?array $image = null): int {
    $source = ['id' => $sourceId, 'bot_user_id' => $authorId, 'publication_interval_minutes' => $interval];
    return (new \TinyCat\Social\Automation\FeedStore())->commitIdentity($source, $feedHash, $itemHash, static fn (string $at): int => \TinyCat\Social\Posts\PostCreation::create($authorId, $draft, $links, $image, publishedAt: $at));
};
