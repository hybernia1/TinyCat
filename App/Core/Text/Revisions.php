<?php
declare(strict_types=1);
namespace TinyCat\Core\Text;

use JsonException;

/** Immutable text revisions as data, independent of their storage owner. */
final class Revisions
{
    /** @return array<mixed> */
    public static function read(string $json): array
    {
        $json = trim($json);

        if ($json === '') {
            return [];
        }

        try {
            $history = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (!is_array($history)) {
            return [];
        }

        return array_values(array_filter($history, static function (mixed $entry): bool {
            return is_array($entry)
                && is_array($entry['changes'] ?? null)
                && is_array($entry['changes']['body'] ?? null);
        }));
    }

    /** @param array<mixed> $actor */
    public static function append(string $before, string $body, array $actor, string $json = ''): ?string
    {

        if ($body === $before) {
            return null;
        }

        $history = self::read($json);
        $history[] = [
            'at' => \TinyCat\Support\Dates::dateDb(),
            'actor' => [
                'id' => (int) ($actor['id'] ?? 0),
                'username' => \TinyCat\Support\Text::plain_text_limit((string) ($actor['username'] ?? ''), 32),
                'role' => (string) ($actor['role'] ?? ''),
            ],
            'changes' => [
                'body' => [
                    'from' => $before,
                    'to' => $body,
                ],
            ],
        ];

        return json_encode($history, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
