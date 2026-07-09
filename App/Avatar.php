<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Avatar
{
    private const SIZE = 240;
    private const VERSION = '15';
    private const PAINT_SIZE = 24;
    private const PAINT_EMPTY = '.';
    private const PAINT_ALPHABET = '.0123456789abcdef';
    private const PAINT_COLORS = [
        '#000000',
        '#ffffff',
        '#f2c6b6',
        '#d9896a',
        '#9a5a3b',
        '#f59e0b',
        '#facc15',
        '#84cc16',
        '#14b8a6',
        '#38bdf8',
        '#2563eb',
        '#7c3aed',
        '#ec4899',
        '#ef4444',
        '#6b7280',
        '#111827',
    ];

    public static function url(string $username, array|string|null $config = null): string
    {
        $username = self::username($username);

        if ($username === '') {
            return '';
        }

        $version = self::VERSION;
        $hash = self::configHash($config);

        if ($hash !== '') {
            $version .= '-' . $hash;
        }

        return '/avatar/' . rawurlencode($username) . '?v=' . rawurlencode($version);
    }

    public static function previewUrl(string $username, array|string|null $config = null): string
    {
        $username = self::username($username);

        if ($username === '') {
            return '';
        }

        $config = self::normalizeConfig($config);

        if ($config === []) {
            $config = [
                'paint' => self::defaultPaint($username),
            ];
        }

        $query = [
            'v' => self::VERSION . '-preview-' . (self::configHash($config) ?: 'auto'),
            'preview' => '1',
        ];

        foreach ($config as $key => $value) {
            $query[$key] = $value;
        }

        return '/avatar/' . rawurlencode($username) . '?' . http_build_query($query);
    }

    public static function paintSize(): int
    {
        return self::PAINT_SIZE;
    }

    public static function paintEmpty(): string
    {
        return self::PAINT_EMPTY;
    }

    public static function paintPalette(): array
    {
        return self::PAINT_COLORS;
    }

    public static function defaultPaint(string $username = 'tinycat'): string
    {
        $username = self::username($username) ?: 'tinycat';

        return self::paintFromSeed($username);
    }

    public static function randomPaint(string $username = 'tinycat'): string
    {
        $username = self::username($username) ?: 'tinycat';

        try {
            $seed = bin2hex(random_bytes(16));
        } catch (Throwable) {
            $seed = uniqid('', true);
        }

        return self::paintFromSeed('random|' . $username . '|' . $seed);
    }

    private static function paintFromSeed(string $seed): string
    {
        $rng = new AvatarPaintRng('tinycat-pixel-avatar|' . self::VERSION . '|' . $seed);
        $grid = array_fill(0, self::PAINT_SIZE, array_fill(0, self::PAINT_SIZE, self::PAINT_EMPTY));
        $outline = $rng->pick(['0', '0', 'f']);
        $fur = $rng->pick(['2', '3', '4', '5', '5', '6', '8', 'a', 'b', 'c', 'e']);
        $fur2 = $rng->pick(['1', '2', '3', '4', '6', '7', '9', 'd', 'e']);
        $eye = $rng->pick(['7', '8', '9', 'a', 'b', 'c']);
        $nose = $rng->pick(['c', 'd', '2', '3']);
        $poseLean = $rng->int(-1, 1);
        $headX = 12 + $poseLean;
        $tailSide = $rng->chance(50) ? -1 : 1;
        $pattern = $rng->pick(['none', 'stripes', 'spots', 'mask', 'bib', 'patch']);

        $set = static function (int $x, int $y, string $value) use (&$grid): void {
            if ($x < 0 || $y < 0 || $x >= self::PAINT_SIZE || $y >= self::PAINT_SIZE) {
                return;
            }

            $grid[$y][$x] = $value;
        };

        $ellipse = static function (int $cx, int $cy, int $rx, int $ry, string $value) use (&$grid, $set): void {
            for ($y = $cy - $ry; $y <= $cy + $ry; $y++) {
                for ($x = $cx - $rx; $x <= $cx + $rx; $x++) {
                    $dx = ($x - $cx) / max(1, $rx);
                    $dy = ($y - $cy) / max(1, $ry);

                    if (($dx * $dx) + ($dy * $dy) <= 1.0) {
                        $set($x, $y, $value);
                    }
                }
            }
        };

        $line = static function (int $x1, int $y1, int $x2, int $y2, string $value, int $thick = 1) use ($set): void {
            $dx = abs($x2 - $x1);
            $dy = -abs($y2 - $y1);
            $sx = $x1 < $x2 ? 1 : -1;
            $sy = $y1 < $y2 ? 1 : -1;
            $error = $dx + $dy;

            while (true) {
                for ($oy = 0; $oy < $thick; $oy++) {
                    for ($ox = 0; $ox < $thick; $ox++) {
                        $set($x1 + $ox, $y1 + $oy, $value);
                    }
                }

                if ($x1 === $x2 && $y1 === $y2) {
                    break;
                }

                $e2 = 2 * $error;

                if ($e2 >= $dy) {
                    $error += $dy;
                    $x1 += $sx;
                }

                if ($e2 <= $dx) {
                    $error += $dx;
                    $y1 += $sy;
                }
            }
        };

        $triangle = static function (array $points, string $value) use ($set): void {
            [$a, $b, $c] = $points;
            $minX = min($a[0], $b[0], $c[0]);
            $maxX = max($a[0], $b[0], $c[0]);
            $minY = min($a[1], $b[1], $c[1]);
            $maxY = max($a[1], $b[1], $c[1]);
            $area = (($b[1] - $c[1]) * ($a[0] - $c[0])) + (($c[0] - $b[0]) * ($a[1] - $c[1]));

            if ($area === 0) {
                return;
            }

            for ($y = $minY; $y <= $maxY; $y++) {
                for ($x = $minX; $x <= $maxX; $x++) {
                    $w1 = ((($b[1] - $c[1]) * ($x - $c[0])) + (($c[0] - $b[0]) * ($y - $c[1]))) / $area;
                    $w2 = ((($c[1] - $a[1]) * ($x - $c[0])) + (($a[0] - $c[0]) * ($y - $c[1]))) / $area;
                    $w3 = 1 - $w1 - $w2;

                    if ($w1 >= 0 && $w2 >= 0 && $w3 >= 0) {
                        $set($x, $y, $value);
                    }
                }
            }
        };

        $tailStartX = $tailSide < 0 ? 8 : 16;
        $tailEndX = $tailSide < 0 ? 2 : 21;
        $line($tailStartX, 17, $tailEndX, 11 + $rng->int(-1, 2), $outline, 2);
        $line($tailStartX, 17, $tailEndX - $tailSide, 12 + $rng->int(-1, 2), $fur, 1);
        $ellipse(12, 17, 6, 5, $outline);
        $ellipse(12, 17, 5, 4, $fur);
        $triangle([[$headX - 6, 9], [$headX - 4, 2], [$headX - 1, 8]], $outline);
        $triangle([[$headX + 6, 9], [$headX + 4, 2], [$headX + 1, 8]], $outline);
        $triangle([[$headX - 5, 8], [$headX - 4, 4], [$headX - 2, 8]], $fur2);
        $triangle([[$headX + 5, 8], [$headX + 4, 4], [$headX + 2, 8]], $fur2);
        $ellipse($headX, 10, 7, 6, $outline);
        $ellipse($headX, 10, 6, 5, $fur);

        if ($pattern === 'stripes') {
            foreach ([-3, 0, 3] as $offset) {
                $line($headX + $offset, 5, $headX + $offset - ($offset <=> 0), 8, $fur2);
            }
            $line(9, 15, 14, 15, $fur2);
            $line(8, 18, 15, 18, $fur2);
        } elseif ($pattern === 'spots') {
            for ($i = 0; $i < 8; $i++) {
                $ellipse($rng->int(7, 17), $rng->int(7, 19), 1, 1, $fur2);
            }
        } elseif ($pattern === 'mask') {
            $ellipse($headX, 11, 4, 3, $fur2);
        } elseif ($pattern === 'bib') {
            $triangle([[9, 13], [15, 13], [12, 20]], '1');
        } elseif ($pattern === 'patch') {
            $ellipse($headX - 3, 9, 2, 3, $fur2);
            $ellipse(15, 17, 2, 3, $fur2);
        }

        $set($headX - 3, 9, $outline);
        $set($headX - 2, 9, $eye);
        $set($headX + 2, 9, $outline);
        $set($headX + 3, 9, $eye);
        $set($headX, 11, $nose);
        $set($headX - 1, 12, $outline);
        $set($headX + 1, 12, $outline);
        $set($headX - 4, 12, $outline);
        $set($headX - 6, 11, $outline);
        $set($headX + 4, 12, $outline);
        $set($headX + 6, 11, $outline);
        $set(8, 21, $outline);
        $set(9, 21, $fur);
        $set(15, 21, $fur);
        $set(16, 21, $outline);

        if ($rng->chance(25)) {
            $set($rng->int(3, 20), $rng->int(3, 20), $rng->pick(['7', '9', 'b', 'c']));
        }

        return self::normalizePaint(implode('', array_map(static fn (array $row): string => implode('', $row), $grid)));
    }

    public static function normalizeConfig(array|string|null $config): array
    {
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            $config = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($config)) {
            return [];
        }

        $paint = self::normalizePaint((string) ($config['paint'] ?? ''));

        if ($paint === '') {
            return [];
        }

        return [
            'paint' => $paint,
        ];
    }

    public static function configJson(array|string|null $config): string
    {
        $config = self::normalizeConfig($config);

        if ($config === []) {
            return '';
        }

        try {
            return json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return '';
        }
    }

    public static function configHash(array|string|null $config): string
    {
        $json = self::configJson($config);

        return $json !== '' ? substr(hash('sha1', $json), 0, 10) : '';
    }

    public static function respond(string $username): never
    {
        $username = self::username($username);

        if ($username === '') {
            http_response_code(404);
            exit;
        }

        $requestConfig = self::requestConfig();
        $config = $requestConfig ?? self::storedConfig($username);
        $configHash = self::configHash($config);
        $etag = '"' . substr(hash('sha256', 'avatar|' . self::VERSION . '|' . $username . '|' . $configHash), 0, 32) . '"';

        header('Content-Type: image/svg+xml; charset=utf-8');
        header($requestConfig === null
            ? 'Cache-Control: public, max-age=31536000, immutable'
            : 'Cache-Control: no-store, max-age=0'
        );
        header('ETag: ' . $etag);
        header('X-Content-Type-Options: nosniff');

        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            exit;
        }

        echo self::svg($username, $config);
        exit;
    }

    public static function svg(string $username, array|string|null $config = null): string
    {
        $username = self::username($username) ?: 'tinycat';
        $config = self::normalizeConfig($config);
        $paint = (string) ($config['paint'] ?? self::defaultPaint($username));

        return self::paintSvg($username, $paint);
    }

    private static function normalizePaint(string $paint): string
    {
        $paint = strtolower(trim($paint));
        $paint = preg_replace('/[^.0-9a-f]/', '', $paint) ?? '';
        $length = self::PAINT_SIZE * self::PAINT_SIZE;

        if ($paint === '') {
            return '';
        }

        if (strlen($paint) !== $length) {
            return '';
        }

        return $paint;
    }

    private static function paintSvg(string $username, string $paint): string
    {
        $paint = self::normalizePaint($paint) ?: self::defaultPaint($username);
        $id = 'tc-paint-' . substr(hash('sha1', self::VERSION . '|' . $username . '|' . $paint), 0, 10);
        $cell = (int) (self::SIZE / self::PAINT_SIZE);
        $offset = 0;
        $paths = [];

        for ($row = 0; $row < self::PAINT_SIZE; $row++) {
            for ($col = 0; $col < self::PAINT_SIZE; $col++) {
                $index = ($row * self::PAINT_SIZE) + $col;
                $token = $paint[$index];

                if ($token === self::PAINT_EMPTY) {
                    continue;
                }

                $run = 1;

                while (
                    $col + $run < self::PAINT_SIZE
                    && $paint[$index + $run] === $token
                ) {
                    $run++;
                }

                $paletteIndex = strpos(self::PAINT_ALPHABET, $token);

                if ($paletteIndex !== false && $paletteIndex > 0) {
                    $color = self::PAINT_COLORS[$paletteIndex - 1] ?? '#111827';
                    $x = $offset + ($col * $cell);
                    $y = $offset + ($row * $cell);
                    $paths[$color][] = 'M' . $x . ' ' . $y . 'h' . ($cell * $run) . 'v' . $cell . 'H' . $x . 'z';
                }

                $col += $run - 1;
            }
        }

        $pixels = '';

        foreach ($paths as $color => $path) {
            $pixels .= '<path fill="' . self::e($color) . '" d="' . implode('', $path) . '"/>';
        }

        return trim(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . self::SIZE . ' ' . self::SIZE . '" width="' . self::SIZE . '" height="' . self::SIZE . '" shape-rendering="crispEdges" role="img" aria-labelledby="' . self::e($id) . '-title ' . self::e($id) . '-desc">'
            . '<title id="' . self::e($id) . '-title">TinyCat 8-bit avatar ' . self::e($username) . '</title>'
            . '<desc id="' . self::e($id) . '-desc">A public 8-bit TinyCat profile avatar.</desc>'
            . '<g>' . $pixels . '</g>'
            . '</svg>'
        );
    }

    private static function requestConfig(): ?array
    {
        if (!array_key_exists('paint', $_GET)) {
            return null;
        }

        return self::normalizeConfig([
            'paint' => (string) ($_GET['paint'] ?? ''),
        ]);
    }

    private static function storedConfig(string $username): array
    {
        if (!class_exists('Core')) {
            return [];
        }

        try {
            $user = Core::find('users', ['username' => $username]);
        } catch (Throwable) {
            return [];
        }

        return self::normalizeConfig($user['avatar_config'] ?? null);
    }

    private static function username(string $username): string
    {
        $username = strtolower(trim($username));

        return preg_match('/^[a-z][a-z0-9_]{2,31}$/', $username) === 1 ? $username : '';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

final class AvatarPaintRng
{
    private string $bytes;
    private int $index = 0;

    public function __construct(string $seed)
    {
        $this->bytes = hash('sha512', $seed, true);
    }

    public function int(int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }

        return $min + ($this->next() % (($max - $min) + 1));
    }

    public function chance(int $percent): bool
    {
        return $this->int(1, 100) <= $percent;
    }

    public function pick(array $items): mixed
    {
        if ($items === []) {
            return null;
        }

        return $items[$this->int(0, count($items) - 1)];
    }

    private function next(): int
    {
        if ($this->index >= strlen($this->bytes)) {
            $this->bytes = hash('sha512', $this->bytes, true);
            $this->index = 0;
        }

        return ord($this->bytes[$this->index++]);
    }
}
