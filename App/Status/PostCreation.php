<?php
declare(strict_types=1);

namespace TinyCat\Status;

use InvalidArgumentException;
use TinyCat\Database\Connection;
use TinyCat\Database\Transactions;

/** Shared preparation, validation and persistence of new posts. */
final readonly class PostCreation
{
    /**
     * @param array<mixed> $tags
     * @param array<mixed> $links
     * @param array{code: string, message_key: string, params: array<string, int|string>}|null $rejection
     */
    private function __construct(public string $body, public array $tags, public array $links, public ?array $rejection)
    {
    }

    public static function fromText(string $text): self
    {
        $body = \TinyCat\Support\Text::plain_text_limit($text, 2000);
        $host = \TinyCat\Moderation\Blocking::moderation_blocked_url_match($body);
        $rejection = $host !== ''
            ? ['code' => 'blocked_url', 'message_key' => 'moderation.messages.blocked_url', 'params' => ['host' => $host]]
            : self::tagRejection($body);
        if ($rejection !== null) { return new self($body, [], [], $rejection); }

        $body = Mentions::normalize_mentions_for_storage($body);
        $body = Tags::normalize_tags_for_storage($body);
        $body = \TinyCat\Support\Text::plain_text_limit($body, 2000);
        return new self($body, Tags::status_tags_from_text($body), Links::status_links_from_text($body), null);
    }

    /** @return array{code: string, message_key: string, params: array<string, int|string>}|null */
    public static function tagRejection(string $body): ?array
    {
        foreach (Tags::status_tag_tokens_from_text($body) as $token) {
            if (strlen(\TinyCat\Support\Text::slug((string) $token)) > Tags::status_tag_max_length()) {
                return ['code' => 'status_tag_too_long', 'message_key' => 'account.messages.status_tag_too_long', 'params' => ['max' => (string) Tags::status_tag_max_length()]];
            }
        }
        return null;
    }

    /** @return array{body: string, tags: array<mixed>, links: array<mixed>} */
    public function payload(): array
    {
        return ['body' => $this->body, 'tags' => $this->tags, 'links' => $this->links];
    }

    public static function sendsMentionNotifications(string $role): bool
    {
        return in_array($role, ['user', 'admin'], true);
    }

    /**
     * Link metadata and image files must be prepared before opening a transaction.
     * A caller may wrap this operation in a larger transaction (e.g. feed history).
     * Account policies, notifications and filesystem cleanup belong to the caller.
     *
     * @param list<array<mixed>> $preparedLinks
     * @param array<mixed>|null $image
     */
    public static function create(int $authorId, self $draft, array $preparedLinks, ?array $image = null, ?string $publishedAt = null): int
    {
        if ($draft->rejection !== null) {
            throw new InvalidArgumentException('Cannot persist rejected post content.');
        }
        if ($authorId < 1 || (trim($draft->body) === '' && $image === null)) {
            throw new InvalidArgumentException('A post requires an author and text or an image.');
        }

        return Transactions::run(static function () use ($authorId, $draft, $preparedLinks, $image, $publishedAt): int {
            $contentId = (int) Connection::insert('content', [
                'body' => $draft->body,
                'author_id' => $authorId,
                'published_at' => $publishedAt ?? \TinyCat\Support\Dates::dateDb(),
            ]);

            if ($image !== null) {
                Connection::insert('content_images', [
                    'content_id' => $contentId,
                    'path' => (string) $image['path'],
                    'width' => (int) $image['width'],
                    'height' => (int) $image['height'],
                    'bytes' => (int) $image['bytes'],
                ]);
            }

            Tags::status_sync_tags($contentId, $draft->tags);
            Links::persistLinks($contentId, $preparedLinks);

            return $contentId;
        });
    }
}
