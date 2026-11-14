<?php
declare(strict_types=1);

namespace TinyCat\Social\Presentation;

use TinyCat\Support\Dates;
use TinyCat\Support\Format;
use TinyCat\Support\Localization;
use TinyCat\Support\Text;

/** Prepared dashboard presentation; diagnostics and queries remain in Dashboard. */
final class DashboardViewData
{
    /** @param array<mixed> $data
     * @return array<mixed>
     */
    public static function prepare(array $data): array
    {
        $stats = [];
        foreach ($data['stats'] as $item) {
            $count = $data['counts'][$item['count_key'] ?? $item['table']] ?? null;
            $stats[] = [
                'heading' => 'h2', 'icon' => $item['icon'], 'label' => $item['label'],
                'value' => $count === null ? null : Format::number($count),
                'note' => $count === null ? Localization::t('admin.table_missing') : '',
            ];
        }
        $comments = array_map(static fn (array $comment): array => [
            'body' => trim(Text::strip_html_tags_preserving_text((string) $comment['body'])),
            'username' => '@' . $comment['username'],
            'author_url' => \TinyCat\Social\Profiles\Profiles::author_url((int) $comment['user_id']),
            'content_url' => $comment['content_url'],
            'content_body' => trim(Text::strip_html_tags_preserving_text((string) $comment['content_body'])),
            'created_iso' => Dates::dateIso((string) $comment['created_at']),
            'created_label' => Dates::displayDateTime((string) $comment['created_at']),
        ], $data['recentComments']);

        return ['stats' => $stats, 'comments' => $comments];
    }

}
