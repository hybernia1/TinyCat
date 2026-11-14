<?php
declare(strict_types=1);

namespace TinyCat\Social\Content;


use Throwable;

class Tags
{

    public static function status_tag_normalize(string $tag): string { return \TinyCat\Core\Text\Hashtags::normalize($tag, self::status_tag_max_length()); }
    public static function status_tag_max_length(): int { return 32; }
    /** @return list<string> */
    public static function status_tag_tokens_from_text(string $text): array { return \TinyCat\Core\Text\Hashtags::tokens($text); }
    /** @return list<string> */
    public static function status_tags_from_text(string $text): array { return \TinyCat\Core\Text\Hashtags::extract($text, self::status_tag_max_length()); }
    public static function normalize_tags_for_storage(string $text): string { return \TinyCat\Core\Text\Hashtags::normalizeText($text, self::status_tag_max_length()); }

    public static function status_term_id(string $tag): int
    {
        $tag = \TinyCat\Social\Content\Tags::status_tag_normalize($tag);

        if ($tag === '') {
            return 0;
        }

        $term = \TinyCat\Database\Queries::db_select('SELECT id, name FROM social_terms')
            ->where('name = ?', $tag)
            ->limit(1)
            ->one();
        $id = (int) ($term['id'] ?? 0);

        if ($id > 0) {
            if ((string) ($term['name'] ?? '') !== $tag) {
                \TinyCat\Database\Connection::update('social_terms', ['name' => $tag], ['id' => $id]);
            }

            return $id;
        }

        try {
            return (int) \TinyCat\Database\Connection::insert('social_terms', ['name' => $tag]);
        } catch (Throwable $exception) {
            if (!\TinyCat\Database\Connection::uniqueViolation($exception)) {
                throw $exception;
            }

            $term = \TinyCat\Database\Queries::db_select('SELECT id, name FROM social_terms')
                ->where('name = ?', $tag)
                ->limit(1)
                ->one();
            $id = (int) ($term['id'] ?? 0);

            if ($id > 0 && (string) ($term['name'] ?? '') !== $tag) {
                \TinyCat\Database\Connection::update('social_terms', ['name' => $tag], ['id' => $id]);
            }

            return $id;
        }
    }

    public static function status_term_id_exact(string $tag): int
    {
        $tag = \TinyCat\Social\Content\Tags::status_tag_normalize($tag);

        if ($tag === '') {
            return 0;
        }

        foreach (\TinyCat\Database\Queries::db_select('SELECT id, name FROM social_terms')->where('name = ?', $tag)->all() as $term) {
            if ((string) ($term['name'] ?? '') === $tag) {
                return (int) ($term['id'] ?? 0);
            }
        }

        return 0;
    }

    /**
     * @param array<mixed> $tags
     */
    public static function status_sync_tags(int $contentId, array $tags): void
    {
        if ($contentId < 1) {
            return;
        }

        \TinyCat\Database\Transactions::run(static function () use ($contentId, $tags): void {
            $previousTermIds = \TinyCat\Social\Content\Tags::status_term_ids_for_content($contentId);
            \TinyCat\Database\Connection::delete('social_post_tags', ['content_id' => $contentId]);

            foreach ($tags as $tag) {
                $termId = \TinyCat\Social\Content\Tags::status_term_id((string) $tag);

                if ($termId < 1) {
                    continue;
                }

                try {
                    \TinyCat\Database\Connection::insert('social_post_tags', [
                        'content_id' => $contentId,
                        'term_id' => $termId,
                    ]);
                } catch (Throwable $exception) {
                    if (!\TinyCat\Database\Connection::uniqueViolation($exception)) {
                        throw $exception;
                    }
                    // A duplicate pair can happen under a concurrent attach.
                }
            }

            \TinyCat\Social\Content\Tags::status_cleanup_unused_term_ids($previousTermIds);
        });
    }

    /**
     * @return array<mixed>
     */
    public static function status_term_ids_for_content(int $contentId): array
    {
        if ($contentId < 1) {
            return [];
        }

        return \TinyCat\Support\Text::positive_int_ids(array_column(
            \TinyCat\Database\Connection::all('SELECT term_id FROM social_post_tags WHERE content_id = ?', [$contentId]),
            'term_id'
        ));
    }

    /**
     * @param array<mixed> $termIds
     */
    public static function status_cleanup_unused_term_ids(array $termIds): void
    {
        $termIds = \TinyCat\Support\Text::positive_int_ids($termIds);

        if ($termIds === []) {
            return;
        }

        foreach (array_chunk($termIds, 100) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
            \TinyCat\Database\Connection::exec(
                'DELETE FROM social_terms
                    WHERE id IN (' . $placeholders . ')
                        AND NOT EXISTS (
                            SELECT 1
                            FROM social_post_tags ct
                            WHERE ct.term_id = social_terms.id
                        )',
                $chunk
            );
        }
    }
}
