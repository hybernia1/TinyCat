<?php
declare(strict_types=1);
namespace TinyCat\Social\Content;

use TinyCat\Core\Text\LinkParser;

/** Shared publication rules, independent of optional moderation interfaces. */
final class Policy
{
    /** @return array{items: list<string>, value: string} */
    public static function resource(): array
    {
        $rules = \TinyCat\Core\Security\UrlRules::rules((string) Integration::option('moderation.blocked_urls', ''));
        return ['items' => $rules, 'value' => implode(', ', $rules)];
    }

    public static function save(\TinyCat\Http\Request $request): \TinyCat\Http\Result
    {
        $request->validated(['blocked_urls' => 'string']);
        $rules = \TinyCat\Core\Security\UrlRules::rules((string) $request->input('blocked_urls', ''));
        \TinyCat\Core\Settings\Store::setSetting('moderation.blocked_urls', implode(', ', $rules), 'string', 'security');
        return \TinyCat\Http\Result::from(self::resource() + ['code' => 'url_blocker_saved', 'message' => \TinyCat\Support\Localization::t('moderation.messages.url_blocker_saved')]);
    }

    public static function blockedHost(string $text): string
    {
        return \TinyCat\Core\Security\UrlRules::match($text, \TinyCat\Core\Security\UrlRules::rules((string) Integration::option('moderation.blocked_urls', '')), LinkParser::pattern());
    }

    public static function requireAllowedUrls(string $text): void
    {
        $host = self::blockedHost($text);
        if ($host !== '') { \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('moderation.messages.blocked_url', ['host' => $host]), 422, 'blocked_url', ['host' => $host], ['host' => $host]); }
    }
}
