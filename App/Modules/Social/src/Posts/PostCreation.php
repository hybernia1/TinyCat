<?php
declare(strict_types=1);

namespace TinyCat\Social\Posts;

use TinyCat\Social\Posts\Links;
use TinyCat\Social\Posts\Policy;

use InvalidArgumentException;
use TinyCat\Database\Connection;
use TinyCat\Database\Transactions;

/** Shared preparation, validation and persistence of new posts. */
final readonly class PostCreation
{
    public const BODY_MAX_LENGTH = 2000;

    /** @return array<string, string> */
    public static function rules(): array { return ['body' => 'string|max:' . self::BODY_MAX_LENGTH]; }

    /**
     * @param array<mixed> $tags
     * @param list<array<mixed>> $links
     * @param array{code: string, message_key: string, params: array<string, int|string>}|null $rejection
     */
    private function __construct(public string $body, public array $tags, public array $links, public ?array $rejection)
    {
    }

    public static function fromText(string $text): self
    {
        $body = self::normalize(\TinyCat\Core\Text\Text::strip_html_tags_preserving_text($text));
        $body = \TinyCat\Core\Text\Text::plain_text_limit($body, self::BODY_MAX_LENGTH);
        $host = Policy::blockedHost($body);
        $rejection = $host !== ''
            ? ['code' => 'blocked_url', 'message_key' => 'moderation.messages.blocked_url', 'params' => ['host' => $host]]
            : self::tagRejection($body);
        if ($rejection !== null) { return new self($body, [], [], $rejection); }

        $body = Mentions::normalize_mentions_for_storage($body);
        $body = Tags::normalizeTagText($body);
        $body = \TinyCat\Core\Text\Text::plain_text_limit($body, self::BODY_MAX_LENGTH);
        return new self($body, Tags::extractTags($body), Links::status_links_from_text($body), null);
    }

    public static function normalize(string $body): string
    {
        $body = str_replace(["\r\n", "\r", "\u{2028}", "\u{2029}"], "\n", $body);
        $body = preg_replace('/[^\S\n]+/u', ' ', $body) ?? $body;
        $lines = explode("\n", $body);
        foreach ($lines as &$line) {
            $line = trim($line);
            $line = \TinyCat\Core\Text\InlineText::map($line, static function (string $part): string {
                $part = preg_replace('/[\x{200B}\x{FEFF}]/u', '', $part) ?? $part;
                $part = preg_replace('/[^\S\n]+/u', ' ', $part) ?? $part;
                return preg_replace('/\[\s*(?:\.{3}|\x{2026})\s*\]/u', "\u{2026}", $part) ?? $part;
            });
            $line = trim($line);
        }
        unset($line);
        return trim(preg_replace('/\n{3,}/', "\n\n", implode("\n", $lines)) ?? '');
    }

    /** @return array{code: string, message_key: string, params: array<string, int|string>}|null */
    public static function tagRejection(string $body): ?array
    {
        foreach (Tags::tagTokens($body) as $token) {
            if (strlen(\TinyCat\Core\Text\Text::slug((string) $token)) > Tags::MAX_BYTES) {
                return ['code' => 'status_tag_too_long', 'message_key' => 'account.messages.status_tag_too_long', 'params' => ['max' => (string) Tags::MAX_BYTES]];
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
    public static function create(int $authorId, self $draft, array $preparedLinks, ?array $image = null, ?string $publishedAt = null): int
    {
        if ($draft->rejection !== null) {
            throw new InvalidArgumentException('Cannot persist rejected post content.');
        }
        if ($authorId < 1 || (trim($draft->body) === '' && $image === null)) {
            throw new InvalidArgumentException('A post requires an author and text or an image.');
        }

        return Transactions::run(static function () use ($authorId, $draft, $preparedLinks, $image, $publishedAt): int {
            $contentId = (int) Connection::insert('social_posts', [
                'kind' => 'post',
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
