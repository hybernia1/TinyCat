<?php
declare(strict_types=1);

namespace TinyCat\Moderation;

final class Blocking
{
    /**
     * @return array<mixed>
     */
    public static function moderation_blocked_url_rules(?string $value = null): array
    {
        $value = $value ?? (string) \TinyCat\Settings\Store::config('moderation.blocked_urls', '');
        $items = preg_split('/[,;\r\n]+/', $value) ?: [];
        $rules = [];

        foreach ($items as $item) {
            $rule = \TinyCat\Moderation\Blocking::moderation_blocked_url_rule((string) $item);

            if ($rule !== '') {
                $rules[$rule] = true;
            }
        }

        return array_keys($rules);
    }

    public static function moderation_blocked_url_rule(string $value): string
    {
        $value = strtolower(trim((string) preg_replace('/[\x00-\x1F\x7F]+/', '', $value)));

        if ($value === '') {
            return '';
        }

        if (str_starts_with($value, 'domain:')) {
            $domain = trim(substr($value, 7), " \t\n\r\0\x0B./");

            if (preg_match('/^[a-z0-9-]{2,63}(?:\.[a-z0-9-]{2,63})*$/', $domain) !== 1) {
                return '';
            }

            return 'domain:' . $domain;
        }

        return \TinyCat\Moderation\Blocking::moderation_url_host($value);
    }

    public static function moderation_url_host(string $value): string
    {
        $value = strtolower(trim((string) preg_replace('/[\x00-\x1F\x7F]+/', '', $value)));
        $value = trim($value, " \t\n\r\0\x0B/");

        if ($value === '') {
            return '';
        }

        $value = (string) preg_replace('/^\*\./', '', $value);
        $candidate = preg_match('~^[a-z][a-z0-9+.-]*://~i', $value) === 1
            ? $value
            : 'https://' . ltrim($value, '/');
        $parts = parse_url($candidate);

        if (!is_array($parts)) {
            return '';
        }

        $host = strtolower(rtrim(trim((string) ($parts['host'] ?? ''), '[]'), '.'));

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        if ($host === '' || !str_contains($host, '.') || preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
            return '';
        }

        return $host;
    }

    public static function moderation_blocked_url_match(string $text): string
    {
        $blockedRules = \TinyCat\Moderation\Blocking::moderation_blocked_url_rules();

        if ($text === '' || $blockedRules === []) {
            return '';
        }

        if (preg_match_all(\TinyCat\Status\LinkParser::pattern(), $text, $matches)) {
            foreach ((array) ($matches[0] ?? []) as $match) {
                [$url] = \TinyCat\Status\LinkParser::splitTail((string) $match);
                $host = \TinyCat\Moderation\Blocking::moderation_url_host($url);

                if ($host !== '') {
                    $blockedBy = \TinyCat\Moderation\Blocking::moderation_host_blocked_by($host, $blockedRules);

                    if ($blockedBy !== '') {
                        return $blockedBy;
                    }
                }
            }
        }

        foreach ($blockedRules as $blockedRule) {
            if (\TinyCat\Moderation\Blocking::moderation_text_contains_blocked_host($text, (string) $blockedRule)) {
                return (string) $blockedRule;
            }
        }

        return '';
    }

    public static function moderation_require_allowed_urls(string $text): void
    {
        $blockedHost = \TinyCat\Moderation\Blocking::moderation_blocked_url_match($text);

        if ($blockedHost !== '') {
            \TinyCat\Http\Response::error(\TinyCat\Support\Localization::t('moderation.messages.blocked_url', ['host' => $blockedHost]), 422, 'blocked_url', ['host' => $blockedHost], ['host' => $blockedHost]);
        }
    }

    public static function moderation_text_contains_blocked_host(string $text, string $blockedRule): bool
    {
        $blockedRule = \TinyCat\Moderation\Blocking::moderation_blocked_url_rule($blockedRule);

        if ($text === '' || $blockedRule === '') {
            return false;
        }

        if (str_starts_with($blockedRule, 'domain:')) {
            $domain = substr($blockedRule, 7);
            $pattern = '/(?<![@A-Za-z0-9_.-])(?:[A-Za-z0-9-]+\.)+' . preg_quote($domain, '/')
                . '(?=$|[\/?#:,\s!?\)\]\}]|\.(?:\s|$))/i';

            return preg_match($pattern, $text) === 1;
        }

        $pattern = '/(?<![@A-Za-z0-9_.-])(?:[A-Za-z0-9-]+\.)*'
            . preg_quote($blockedRule, '/')
            . '(?=$|[\/?#:,\s!?\)\]\}]|\.(?:\s|$))/i';

        return preg_match($pattern, $text) === 1;
    }

    /**
     * @param array<mixed> $blockedRules
     */
    public static function moderation_host_blocked_by(string $host, array $blockedRules): string
    {
        $host = \TinyCat\Moderation\Blocking::moderation_url_host($host);

        if ($host === '') {
            return '';
        }

        foreach ($blockedRules as $blockedRule) {
            $blockedRule = \TinyCat\Moderation\Blocking::moderation_blocked_url_rule((string) $blockedRule);

            if ($blockedRule === '') {
                continue;
            }

            if (str_starts_with($blockedRule, 'domain:')) {
                $domain = substr($blockedRule, 7);

                if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                    return $blockedRule;
                }

                continue;
            }

            if ($host === $blockedRule || str_ends_with($host, '.' . $blockedRule)) {
                return $blockedRule;
            }
        }

        return '';
    }

    /**
     * @return array<mixed>
     */
    public static function tc_admin_moderation_blocking_payload(): array
    {
        $rules = \TinyCat\Moderation\Blocking::moderation_blocked_url_rules();

        $payload = [
            'items' => $rules,
            'value' => implode(', ', $rules),
        ];

        return $payload;
    }
}
