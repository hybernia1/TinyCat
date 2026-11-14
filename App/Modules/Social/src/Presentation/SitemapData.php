<?php
declare(strict_types=1);
namespace TinyCat\Social\Presentation;

final class SitemapData
{
    /** @return list<array{url: string, last_modified: null}> */
    public static function pages(): array
    {
        $items = [['url' => '/', 'last_modified' => null], ['url' => '/privacy', 'last_modified' => null]];
        foreach (array_keys(\TinyCat\Support\Languages::language_packages()) as $locale) { $items[] = ['url' => \TinyCat\Http\LocaleRouting::url('/privacy', $locale), 'last_modified' => null]; }
        return $items;
    }
}
