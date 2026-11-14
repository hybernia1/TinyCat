<?php
declare(strict_types=1);

namespace TinyCat\Core\Security;

final class UrlRules
{
    /**
     * @return list<string>
     */
    public static function rules(?string $value = null): array
    {
        $value ??= '';
        $items = preg_split('/[,;\r\n]+/', $value) ?: [];
        $rules = [];

        foreach ($items as $item) {
            $rule = self::rule((string) $item);

            if ($rule !== '') {
                $rules[$rule] = true;
            }
        }

        return array_map('strval', array_keys($rules));
    }

    public static function rule(string $value): string
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

        return self::host($value);
    }

    public static function host(string $value): string
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

    /** @param list<string> $blockedRules */
    public static function match(string $text, array $blockedRules, string $pattern): string
    {

        if ($text === '' || $blockedRules === []) {
            return '';
        }

        if (preg_match_all($pattern, $text, $matches)) {
            foreach ((array) ($matches[0] ?? []) as $match) {
                $url = (string) $match;
                $host = self::host($url);

                if ($host !== '') {
                    $blockedBy = self::blockedBy($host, $blockedRules);

                    if ($blockedBy !== '') {
                        return $blockedBy;
                    }
                }
            }
        }

        foreach ($blockedRules as $blockedRule) {
            if (self::textContains($text, (string) $blockedRule)) {
                return (string) $blockedRule;
            }
        }

        return '';
    }

    public static function textContains(string $text, string $blockedRule): bool
    {
        $blockedRule = self::rule($blockedRule);

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
    public static function blockedBy(string $host, array $blockedRules): string
    {
        $host = self::host($host);

        if ($host === '') {
            return '';
        }

        foreach ($blockedRules as $blockedRule) {
            $blockedRule = self::rule((string) $blockedRule);

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

}
