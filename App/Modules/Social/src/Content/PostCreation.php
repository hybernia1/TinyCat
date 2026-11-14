<?php
declare(strict_types=1);

namespace TinyCat\Social\Content;

use TinyCat\Core\Text\PostText;

use InvalidArgumentException;
use TinyCat\Database\Connection;
use TinyCat\Database\Transactions;

/** Shared preparation, validation and persistence of new posts. */
readonly class PostCreation
{
    /**
     * @param array<mixed> $tags
     * @param list<array<mixed>> $links
     * @param array{code: string, message_key: string, params: array<string, int|string>}|null $rejection
     */
    final protected function __construct(public string $body, public array $tags, public array $links, public ?array $rejection)
    {
    }

    public static function fromText(string $text, string $kind = 'post'): static
    {
        $body = \TinyCat\Support\Text::plain_text_limit(PostText::normalize(\TinyCat\Support\Text::strip_html_tags_preserving_text($text)), 2000);
        $host = Integration::blockedHost($body);
        $rejection = $host !== ''
            ? ['code' => 'blocked_url', 'message_key' => 'moderation.messages.blocked_url', 'params' => ['host' => $host]]
            : self::tagRejection($body);
        if ($rejection !== null) { return new static($body, [], [], $rejection); }

        $body = Integration::normalizeMentions($body, $kind);
        $body = Tags::normalize_tags_for_storage($body);
        $body = \TinyCat\Support\Text::plain_text_limit($body, 2000);
        return new static($body, Tags::status_tags_from_text($body), Links::status_links_from_text($body), null);
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

    /**
     * Link metadata and image files must be prepared before opening a transaction.
     * A caller may wrap this operation in a larger transaction (e.g. feed history).
     * Account policies, notifications and filesystem cleanup belong to the caller.
     *
     * @param list<array<mixed>> $preparedLinks
     * @param array<mixed>|null $image
     */
    public static function create(int $authorId, self $draft, array $preparedLinks, ?array $image = null, ?string $publishedAt = null, string $kind = 'post'): int
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,39}$/D', $kind) !== 1) { throw new InvalidArgumentException('Invalid publication kind.'); }
        if ($draft->rejection !== null) {
            throw new InvalidArgumentException('Cannot persist rejected post content.');
        }
        if ($authorId < 1 || (trim($draft->body) === '' && $image === null)) {
            throw new InvalidArgumentException('A post requires an author and text or an image.');
        }

        return Transactions::run(static function () use ($authorId, $draft, $preparedLinks, $image, $publishedAt, $kind): int {
            $contentId = (int) Connection::insert('social_posts', [
                'kind' => $kind,
                'body' => $draft->body,
                'author_id' => $authorId,
                'published_at' => $publishedAt ?? \TinyCat\Support\Dates::dateDb(),
            ]);

            if ($image !== null) {
                Connection::insert('social_post_images', [
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
