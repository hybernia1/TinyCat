<?php
declare(strict_types=1);

namespace TinyCat\Users;

/** Layered, versioned cat portraits; no storage, database query or external service. */
final class GeneratedAvatar
{
    /** @var array<string, array{default: string, values: list<string>}>|null */
    private static ?array $options = null;

    /** @return array<string, array{default: string, values: list<string>}> */
    public static function options(): array
    {
        return self::$options ??= json_decode((string) file_get_contents(__DIR__ . '/avatar-options.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, int|string> */
    public static function appearance(?string $json): array
    {
        $data = json_decode((string) $json, true);
        $result = ['version' => 1];
        foreach (self::options() as $key => $option) {
            $value = is_array($data) ? ($data[$key] ?? null) : null;
            $result[$key] = is_string($value) && in_array($value, $option['values'], true) ? $value : $option['default'];
        }
        return $result;
    }

    public static function url(int $userId, ?string $appearance = null): string
    {
        if ($userId < 1) { return ''; }
        $data = self::appearance($appearance);
        $query = [];
        foreach (self::options() as $key => $option) {
            if ($data[$key] !== $option['default']) { $query[$key] = $data[$key]; }
        }
        return '/avatar/generated/v1/' . $userId . '.svg' . ($query !== [] ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
    }

    /** @param array<string, mixed> $appearance */
    public static function svg(int $userId, string $gender = 'neutral', array $appearance = []): string
    {
        $appearance = self::appearance(json_encode(array_replace(['gender' => $gender], $appearance), JSON_THROW_ON_ERROR));
        $gender = (string) $appearance['gender'];
        $hash = hash('sha256', 'tinycat-avatar-v1:' . $userId);
        $value = static fn (int $offset): int => (int) hexdec(substr($hash, $offset, 2));
        $hue = $value(0) * 360 / 256;
        $backgrounds = ['ginger' => '#f0d2ad', 'grey' => '#d6e0e7', 'cream' => '#f5edda', 'brown' => '#ddc5b8', 'charcoal' => '#a5afc5', 'lilac' => '#ded1ed', 'sage' => '#d3e5db', 'peach' => '#f3d7ce'];
        $background = $backgrounds[$appearance['background']] ?? 'hsl(' . (int) $hue . ',58%,78%)';
        $outfits = ['ginger' => '#c77c36', 'grey' => '#637989', 'cream' => '#e8d8ad', 'brown' => '#88634b', 'charcoal' => '#424553', 'lilac' => '#8660aa', 'sage' => '#558675', 'peach' => '#d89182'];
        $shirt = $outfits[$appearance['outfit']] ?? 'hsl(' . (($value(2) + 100) % 360) . ',52%,45%)';
        $furs = ['ginger' => '#edb976', 'grey' => '#8f9fa8', 'cream' => '#f4e6ce', 'brown' => '#b8866c', 'charcoal' => '#656579', 'lilac' => '#c7a3cf', 'sage' => '#a8c4ad', 'peach' => '#ecc4ad'];
        $fur = $furs[$appearance['fur']] ?? array_values($furs)[$value(4) % 8];
        $muzzle = ['#fff3df', '#e0e9ec', '#fff9ef', '#f5d9c1'][$value(6) % 4];
        $ink = '#292735';
        $parts = '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200" viewBox="0 0 200 200">';
        $parts .= '<rect width="200" height="200" fill="' . $background . '"/>';
        // The backdrop and outfit remain readable at small navbar sizes.
        $parts .= '<circle cx="' . (60 + $value(8) % 80) . '" cy="70" r="76" fill="#fff" opacity=".16"/>';
        $parts .= '<g stroke="' . $ink . '" stroke-width="4" stroke-linecap="round" stroke-linejoin="round">';
        $parts .= '<path d="M32 204v-15c0-31 25-45 68-45s68 14 68 45v15" fill="' . $shirt . '"/>';
        $parts .= '<path d="m77 151 23 25 23-25" fill="' . $muzzle . '"/>';
        $parts .= '<path d="m50 77-6-48q0-8 8-3l34 22m28 0 34-22q8-5 8 3l-6 48" fill="' . $fur . '"/>';
        $parts .= '<path d="m54 40 4 28 17-15m50 0 17 15 4-28" fill="#e4a1a1" stroke="none"/>';
        $parts .= '<path d="M43 100c0-37 22-55 57-55s57 18 57 55c0 37-20 61-57 61s-57-24-57-61Z" fill="' . $fur . '"/>';
        if ($value(10) % 3 === 0) {
            $parts .= '<path d="m87 48 5 23 8-12 8 12 5-23" fill="' . $ink . '" stroke="none" opacity=".3"/>';
            $parts .= '<path d="m45 95 16 6-16 6m110-12-16 6 16 6" fill="none" opacity=".35"/>';
        }
        $parts .= '<ellipse cx="100" cy="126" rx="28" ry="21" fill="' . $muzzle . '" stroke="none"/>';
        switch (['smile' => 0, 'serious' => 1, 'calm' => 2][$appearance['expression']] ?? $value(12) % 4) {
            case 0:
                $parts .= '<path d="M65 98q9-11 18 0m34 0q9-11 18 0" fill="none"/>';
                break;
            case 1:
                $parts .= '<path d="m64 92 20 3m32 0 20-3" fill="none"/><ellipse cx="75" cy="102" rx="4" ry="6" fill="' . $ink . '"/><ellipse cx="125" cy="102" rx="4" ry="6" fill="' . $ink . '"/>';
                break;
            default:
                $parts .= '<ellipse cx="75" cy="98" rx="5" ry="8" fill="' . $ink . '"/><ellipse cx="125" cy="98" rx="5" ry="8" fill="' . $ink . '"/><path d="M74 94v2m50-2v2" stroke="#fff" stroke-width="2"/>';
        }
        $parts .= '<path d="m94 117 6 5 6-5Z" fill="#c97883" stroke-width="3"/><path d="M100 123v5m-11 0q5 8 11 0 6 8 11 0" fill="none" stroke-width="3"/>';
        $parts .= '<path d="m39 115 19 4m-20 6 19 1m85-7 19-4m-18 11 19-1" fill="none" stroke-width="3"/>';
        switch (['sunglasses' => 0, 'glasses' => 1, 'headphones' => 2, 'cap' => 3, 'none' => 4][$appearance['accessory']] ?? $value(14) % 5) {
            case 0:
                $parts .= '<path d="M58 87h31v16q-15 12-28-1Zm53 0h31l-3 15q-13 13-28 1Zm-22 5h22" fill="#343444"/><path d="m67 91 12 12m41-12 12 12" stroke="#fff" opacity=".4" stroke-width="3"/>';
                break;
            case 1:
                $parts .= '<circle cx="75" cy="98" r="16" fill="none" stroke-width="3"/><circle cx="125" cy="98" r="16" fill="none" stroke-width="3"/><path d="M91 96q9-7 18 0" fill="none" stroke-width="3"/>';
                break;
            case 2:
                $parts .= '<path d="M43 94V81c0-63 114-63 114 0v13" fill="none" stroke="' . $shirt . '" stroke-width="9"/><rect x="35" y="85" width="15" height="30" rx="7" fill="' . $shirt . '"/><rect x="150" y="85" width="15" height="30" rx="7" fill="' . $shirt . '"/>';
                break;
            case 3:
                $parts .= '<path d="M65 59V42q35-23 70 0v17Z" fill="' . $shirt . '"/><path d="M60 60q40-9 80 0" fill="none" stroke-width="8"/>';
                break;
        }
        if ($value(16) % 2 === 0) {
            $parts .= '<path d="M57 180h26m-13-13v26" fill="none" stroke="' . $muzzle . '" stroke-width="5"/>';
        } else {
            $parts .= '<circle cx="70" cy="181" r="9" fill="' . $muzzle . '" stroke="none"/>';
        }
        if ($gender === 'female') {
            $parts .= '<path d="m63 85-4-5m72 5 5-5" fill="none" stroke-width="3"/><path d="m137 58-11-10v20l11-7 11 7V48Z" fill="' . $shirt . '" stroke-width="3"/><circle cx="137" cy="59" r="4" fill="' . $muzzle . '" stroke-width="2"/>';
        } elseif ($gender === 'male') {
            $parts .= '<path d="m100 172-13-8v17l13-6 13 6v-17Z" fill="' . $ink . '" stroke-width="2"/>';
        }
        return $parts . '</g></svg>';
    }

    public static function respond(int $userId): void
    {
        if ($userId < 1) {
            http_response_code(404);
            return;
        }
        $appearance = [];
        foreach (self::options() as $key => $option) {
            $value = \TinyCat\Http\Request::current()->input($key, $option['default']);
            if (!is_string($value) || !in_array($value, $option['values'], true)) { http_response_code(404); return; }
            $appearance[$key] = $value;
        }
        $svg = self::svg($userId, 'neutral', $appearance);
        $etag = '"' . hash('sha256', $svg) . '"';
        header('Content-Type: image/svg+xml; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; style-src 'none'; sandbox");
        header('Cache-Control: public, max-age=31536000, immutable');
        header('ETag: ' . $etag);
        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            return;
        }
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') { echo $svg; }
    }
}
