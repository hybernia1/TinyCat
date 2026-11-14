<?php
declare(strict_types=1);

namespace TinyCat\Http;

/** Network-only fixture: the published updater still verifies and installs every signed byte. */
final class Client
{
    /** @param array<string, mixed> $options */
    public static function download(string $url, string $target, int $maximum, array $options = []): void
    {
        $file = $GLOBALS['signedUpdateFixtures'][$url] ?? null;
        if (!is_string($file) || !is_file($file)) { throw new \RuntimeException('Unexpected update network request: ' . $url); }
        if (isset($options['policy']) && !($options['policy'])($url)) { throw new \RuntimeException('The fixture URL failed the real download policy.'); }
        $bytes = file_get_contents($file);
        if (!is_string($bytes) || strlen($bytes) > $maximum || file_put_contents($target, $bytes) !== strlen($bytes)) { throw new \RuntimeException('Fixture download failed.'); }
    }
}
