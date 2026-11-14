<?php
declare(strict_types=1);
namespace TinyCat\Social\Content;

/** Social supplies its own attachment records to the common data formatter. */
final class Resource
{
    /** @return array<string, mixed> */
    public static function content(int $id, string $body, string $kind = 'post'): array { return \TinyCat\Core\Ui\RichTextResource::content($id, $body, $kind, Links::status_links_for_content($id)); }
    /** @param array<mixed> $link
     * @return array<string, mixed> */
    public static function link(array $link, int $contentId = 0, string $kind = 'post'): array { return \TinyCat\Core\Ui\RichTextResource::link($link, $contentId, $kind); }
}
