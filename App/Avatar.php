<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Avatar
{
    private const SIZE = 240;
    private const VERSION = '8';

    public static function url(string $username): string
    {
        $username = self::username($username);

        return $username !== '' ? '/avatar/' . rawurlencode($username) . '?v=' . self::VERSION : '';
    }

    public static function respond(string $username): never
    {
        $username = self::username($username);

        if ($username === '') {
            http_response_code(404);
            exit;
        }

        $etag = '"' . substr(hash('sha256', 'avatar|' . self::VERSION . '|' . $username), 0, 32) . '"';

        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Cache-Control: public, max-age=31536000, immutable');
        header('ETag: ' . $etag);
        header('X-Content-Type-Options: nosniff');

        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            exit;
        }

        echo self::svg($username);
        exit;
    }

    public static function svg(string $username): string
    {
        $username = self::username($username);

        if ($username === '') {
            $username = 'tinycat';
        }

        $rng = new AvatarRng($username);
        $rarity = self::rarity($rng);
        $style = self::style($rng, $rarity, $username);
        $breed = self::breed($rng, $rarity, $style, $username);
        $palette = self::palette($rng, $breed, $style, $rarity);
        $shape = self::shape($rng, $breed, $style);
        $pattern = self::pattern($rng, $breed, $style, $rarity);
        $feature = self::feature($rng, $rarity, $style);
        $id = 'tc-' . substr(hash('sha1', self::VERSION . '|' . $username), 0, 10);
        $title = 'TinyCat avatar ' . $username . ' - ' . $style . ', ' . $breed . ', ' . (string) ($shape['pose'] ?? 'sit') . ', ' . $rarity;

        return trim(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . self::SIZE . ' ' . self::SIZE . '" width="' . self::SIZE . '" height="' . self::SIZE . '" role="img" aria-labelledby="' . self::e($id) . '-title ' . self::e($id) . '-desc">'
            . '<title id="' . self::e($id) . '-title">' . self::e($title) . '</title>'
            . '<desc id="' . self::e($id) . '-desc">A deterministic TinyCat profile avatar generated from the username.</desc>'
            . self::defs($id, $palette)
            . '<rect width="240" height="240" rx="' . self::e((string) $shape['frame_rx']) . '" fill="url(#' . self::e($id) . '-bg)"/>'
            . '<rect width="240" height="240" rx="' . self::e((string) $shape['frame_rx']) . '" fill="#fff" opacity=".11" filter="url(#' . self::e($id) . '-paper)"/>'
            . self::background($rng, $id, $palette, $style, $rarity)
            . self::cat($rng, $id, $palette, $shape, $pattern, $feature, $breed, $style, $rarity)
            . self::frameDetails($rng, $palette, $style, $rarity)
            . '</svg>'
        );
    }

    private static function username(string $username): string
    {
        $username = strtolower(trim($username));

        return preg_match('/^[a-z][a-z0-9_]{2,31}$/', $username) === 1 ? $username : '';
    }

    private static function rarity(AvatarRng $rng): string
    {
        return self::weighted($rng, [
            'common' => 560,
            'uncommon' => 260,
            'rare' => 120,
            'epic' => 48,
            'legendary' => 12,
        ]);
    }

    private static function style(AvatarRng $rng, string $rarity, string $username): string
    {
        $hint = self::styleHint($username);

        if ($hint !== '') {
            return $hint;
        }

        $weights = match ($rarity) {
            'legendary' => ['cosmic' => 220, 'royal' => 190, 'barbie' => 180, 'noir' => 170, 'cyber' => 160, 'botanical' => 80],
            'epic' => ['noir' => 190, 'barbie' => 180, 'cyber' => 170, 'royal' => 150, 'cosmic' => 130, 'botanical' => 90, 'street' => 90],
            'rare' => ['noir' => 170, 'barbie' => 160, 'cyber' => 130, 'royal' => 115, 'botanical' => 120, 'street' => 115, 'classic' => 90, 'pastel' => 100],
            'uncommon' => ['classic' => 210, 'pastel' => 170, 'street' => 150, 'botanical' => 140, 'noir' => 105, 'barbie' => 100, 'cyber' => 70, 'royal' => 55],
            default => ['classic' => 320, 'pastel' => 210, 'street' => 160, 'botanical' => 140, 'noir' => 70, 'barbie' => 60, 'cyber' => 25, 'royal' => 15],
        };

        return self::weighted($rng, $weights);
    }

    private static function styleHint(string $username): string
    {
        $hints = [
            'barbie' => ['barbie', 'pink', 'doll'],
            'noir' => ['noir', 'shadow', 'black'],
            'cyber' => ['cyber', 'neon', 'matrix'],
            'royal' => ['royal', 'king', 'queen'],
            'cosmic' => ['cosmic', 'space', 'star'],
            'botanical' => ['botanical', 'plant', 'forest', 'garden'],
            'street' => ['street', 'punk', 'urban'],
            'pastel' => ['pastel', 'soft'],
        ];

        foreach ($hints as $style => $words) {
            foreach ($words as $word) {
                if (str_contains($username, $word)) {
                    return $style;
                }
            }
        }

        return '';
    }

    private static function breed(AvatarRng $rng, string $rarity, string $style, string $username): string
    {
        $hint = self::breedHint($username);

        if ($hint !== '') {
            return $hint;
        }

        $weights = [
            'common' => ['domestic' => 330, 'tabby' => 250, 'british' => 170, 'tuxedo' => 130, 'ginger' => 120],
            'uncommon' => ['tabby' => 220, 'british' => 190, 'tuxedo' => 170, 'siamese' => 150, 'calico' => 140, 'ginger' => 80, 'russianblue' => 50],
            'rare' => ['mainecoon' => 220, 'british' => 160, 'calico' => 160, 'siamese' => 130, 'silver' => 120, 'russianblue' => 110, 'sphynx' => 70, 'bengal' => 30],
            'epic' => ['sphynx' => 200, 'mainecoon' => 190, 'british' => 130, 'silver' => 130, 'midnight' => 120, 'bengal' => 100, 'persian' => 80, 'tuxedo' => 50],
            'legendary' => ['cosmic' => 280, 'sphynx' => 170, 'midnight' => 150, 'mainecoon' => 140, 'british' => 100, 'persian' => 90, 'bengal' => 70],
        ];

        if ($style === 'noir') {
            $weights[$rarity]['tuxedo'] = ($weights[$rarity]['tuxedo'] ?? 0) + 170;
            $weights[$rarity]['midnight'] = ($weights[$rarity]['midnight'] ?? 0) + 140;
        }

        if ($style === 'barbie') {
            $weights[$rarity]['persian'] = ($weights[$rarity]['persian'] ?? 0) + 160;
            $weights[$rarity]['calico'] = ($weights[$rarity]['calico'] ?? 0) + 110;
        }

        if ($style === 'cyber') {
            $weights[$rarity]['silver'] = ($weights[$rarity]['silver'] ?? 0) + 160;
            $weights[$rarity]['russianblue'] = ($weights[$rarity]['russianblue'] ?? 0) + 130;
        }

        return self::weighted($rng, $weights[$rarity] ?? $weights['common']);
    }

    private static function breedHint(string $username): string
    {
        $hints = [
            'sphynx' => ['sphynx', 'sfinx'],
            'mainecoon' => ['mainecoon', 'maine', 'coon'],
            'british' => ['british', 'brit', 'bsh', 'round'],
            'persian' => ['persian'],
            'bengal' => ['bengal'],
            'siamese' => ['siamese'],
            'tuxedo' => ['tuxedo'],
        ];

        foreach ($hints as $breed => $words) {
            foreach ($words as $word) {
                if (str_contains($username, $word)) {
                    return $breed;
                }
            }
        }

        return '';
    }

    private static function palette(AvatarRng $rng, string $breed, string $style, string $rarity): array
    {
        $fur = [
            'domestic' => ['#d58a45', '#c46f3d', '#8f5a3a', '#e8c47f', '#66717f', '#f2f0e8'],
            'tabby' => ['#b97846', '#8f5a37', '#d6aa72', '#6d7378', '#d0d5d8'],
            'tuxedo' => ['#15191f', '#222733', '#34313a', '#f3f0e7'],
            'ginger' => ['#e08a35', '#c7652d', '#f3b15f', '#9c522c'],
            'siamese' => ['#e7d0ad', '#d8b989', '#594238', '#3d2d2c'],
            'calico' => ['#f3ede0', '#c86b36', '#2d2b2b', '#dca456'],
            'mainecoon' => ['#9a6a42', '#6f513a', '#c8a06f', '#4a4542'],
            'sphynx' => ['#d7aa92', '#bf8d78', '#e0bca9', '#9f756b'],
            'silver' => ['#dfe5e9', '#aab5bd', '#76838f', '#f8fafb'],
            'midnight' => ['#101828', '#172033', '#29364f', '#596b92'],
            'cosmic' => ['#16113a', '#2d1f6f', '#5236a8', '#d1c4ff'],
            'russianblue' => ['#7a8792', '#9ba7b2', '#54616d', '#c8d0d7'],
            'bengal' => ['#d6a04f', '#a46c2f', '#4a2c1c', '#f1c87a'],
            'persian' => ['#f5eee2', '#e8d8c5', '#d0b49f', '#fffaf2'],
            'british' => ['#9aa6b2', '#c3ccd5', '#6b7886', '#e7dccb', '#d2bca6'],
        ];
        $stylePalettes = [
            'classic' => ['bg' => ['#f8fafc', '#e0f2fe', '#ccfbf1'], 'accent' => ['#0f766e', '#2563eb', '#047857'], 'eye' => ['#62c370', '#7bdff2', '#ffcf56']],
            'pastel' => ['bg' => ['#fff1f2', '#f5f3ff', '#e0f2fe'], 'accent' => ['#c084fc', '#f472b6', '#38bdf8'], 'eye' => ['#8ecae6', '#c084fc', '#62c370']],
            'street' => ['bg' => ['#e5e7eb', '#f8fafc', '#cbd5e1'], 'accent' => ['#ef4444', '#0f766e', '#111827'], 'eye' => ['#facc15', '#22c55e', '#60a5fa']],
            'botanical' => ['bg' => ['#ecfccb', '#d9f99d', '#f7fee7'], 'accent' => ['#166534', '#65a30d', '#0f766e'], 'eye' => ['#84cc16', '#22c55e', '#facc15']],
            'noir' => ['bg' => ['#06070a', '#111827', '#374151'], 'accent' => ['#f8fafc', '#9ca3af', '#eab308'], 'eye' => ['#facc15', '#e5e7eb', '#ef4444']],
            'barbie' => ['bg' => ['#fce7f3', '#f9a8d4', '#fff1f2'], 'accent' => ['#ec4899', '#f472b6', '#facc15'], 'eye' => ['#38bdf8', '#a78bfa', '#22c55e']],
            'cyber' => ['bg' => ['#020617', '#0f172a', '#164e63'], 'accent' => ['#22d3ee', '#a855f7', '#10b981'], 'eye' => ['#22d3ee', '#a855f7', '#f0abfc']],
            'royal' => ['bg' => ['#312e81', '#581c87', '#fef3c7'], 'accent' => ['#facc15', '#c084fc', '#f59e0b'], 'eye' => ['#facc15', '#38bdf8', '#c084fc']],
            'cosmic' => ['bg' => ['#0f0728', '#312e81', '#4c1d95'], 'accent' => ['#c4b5fd', '#f0abfc', '#67e8f9'], 'eye' => ['#c4b5fd', '#67e8f9', '#f0abfc']],
        ];

        $furSet = $fur[$breed] ?? $fur['domestic'];
        $styleSet = $stylePalettes[$style] ?? $stylePalettes['classic'];
        $furMain = $rng->pick($furSet);
        $furAlt = $rng->pick($furSet);

        if ($style === 'noir') {
            $furMain = $rng->pick(['#111827', '#1f2937', '#27272a', '#e5e7eb']);
            $furAlt = $rng->pick(['#374151', '#6b7280', '#f8fafc']);
        } elseif ($style === 'barbie' && $rng->chance(42)) {
            $furAlt = $rng->pick(['#f9a8d4', '#fbcfe8', '#fb7185', '#fff1f2']);
        } elseif ($style === 'cosmic') {
            $furMain = $rng->pick(['#16113a', '#2d1f6f', '#1e1b4b', '#5236a8']);
            $furAlt = $rng->pick(['#d1c4ff', '#67e8f9', '#f0abfc']);
        }

        $bg = self::readableBackground($styleSet['bg'], $furMain, $furAlt, $style);
        $accent = $styleSet['accent'];
        $eyes = $styleSet['eye'];
        $heterochromia = in_array($rarity, ['epic', 'legendary'], true) || $rng->chance($style === 'cyber' ? 24 : 10);

        return [
            'bg1' => $bg[0],
            'bg2' => $bg[1],
            'bg3' => $bg[2],
            'fur' => $furMain,
            'fur2' => $furAlt,
            'fur3' => self::lighten($furMain, $rng->int(10, 28)),
            'accent' => $rng->pick($accent),
            'accent2' => $rng->pick($accent),
            'eye' => $rng->pick($eyes),
            'eye2' => $heterochromia ? $rng->pick($eyes) : '',
            'white' => $style === 'noir' ? '#f9fafb' : '#fff8ee',
            'line' => $breed === 'sphynx' ? '#8c6259' : ($style === 'noir' ? '#020617' : '#2f241f'),
            'nose' => $style === 'barbie' ? '#ec4899' : $rng->pick(['#e78aa4', '#d96b8a', '#c47a6b', '#f0a6ca']),
            'blush' => $style === 'barbie' ? '#f472b6' : $rng->pick(['#f8b4c5', '#f0a6ca', '#fca5a5']),
            'metal' => $style === 'royal' ? '#facc15' : ($style === 'cyber' ? '#67e8f9' : '#d1d5db'),
        ];
    }

    private static function shape(AvatarRng $rng, string $breed, string $style): array
    {
        $headRx = match ($breed) {
            'british' => 73,
            'persian' => 70,
            'mainecoon' => 66,
            'sphynx' => 52,
            'siamese' => 59,
            default => 62 + $rng->int(-4, 7),
        };
        $headRy = match ($breed) {
            'british' => 68,
            'sphynx' => 62,
            'persian' => 62,
            'mainecoon' => 72,
            default => 64 + $rng->int(-3, 7),
        };
        $tail = match ($breed) {
            'sphynx' => 'slender',
            'british', 'persian' => $rng->pick(['curl', 'wrap', 'puff']),
            'mainecoon' => $rng->pick(['plume', 'curl']),
            'bengal' => $rng->pick(['sweep', 'curl']),
            default => $rng->pick(['curl', 'sweep', 'hook', 'wrap']),
        };
        $pose = self::pose($rng, $breed);
        $poseLean = match ($pose) {
            'side' => $rng->pick([-10, 10]),
            'stretch' => $rng->pick([-6, 6]),
            'wave' => $rng->pick([-4, 4]),
            default => 0,
        };
        $headTilt = match ($pose) {
            'wave' => $rng->pick([-8, 8]),
            'side' => $poseLean > 0 ? 7 : -7,
            'loaf' => $rng->int(-3, 3),
            default => $rng->int(-5, 5),
        };
        $pawUp = $pose === 'wave' ? $rng->pick(['left', 'right']) : '';
        $headTurn = match ($pose) {
            'side' => $poseLean > 0 ? 7 : -7,
            'wave' => $pawUp === 'right' ? 5 : -5,
            'stretch' => $rng->pick([-6, 6]),
            'loaf' => $rng->int(-3, 3),
            default => $rng->int(-5, 5),
        };
        $gazeX = match ($pose) {
            'side' => $headTurn > 0 ? 3 : -3,
            'wave' => $pawUp === 'right' ? 2 : -2,
            'stretch' => $headTurn > 0 ? 2 : -2,
            default => $rng->int(-2, 2),
        };
        $gazeY = match ($pose) {
            'loaf' => 1,
            'stretch' => -1,
            default => $rng->int(-1, 1),
        };

        return [
            'frame_rx' => in_array($style, ['noir', 'cyber'], true) ? 34 : 48,
            'head_rx' => $headRx,
            'head_ry' => $headRy,
            'head_scale' => match ($breed) {
                'sphynx' => 0.82,
                'british', 'persian' => 0.8,
                'mainecoon' => 0.84,
                default => 0.83,
            },
            'head_offset_y' => match ($breed) {
                'sphynx' => -1,
                'mainecoon' => 0,
                'british', 'persian' => 6,
                default => 3,
            },
            'ear' => match ($breed) {
                'sphynx' => 72,
                'british', 'persian' => 34 + $rng->int(-3, 4),
                'mainecoon' => 54 + $rng->int(-4, 8),
                default => 44 + $rng->int(-6, 10),
            },
            'muzzle' => match ($breed) {
                'british', 'persian' => 36,
                'sphynx' => 24,
                'siamese' => 27,
                default => 29 + $rng->int(-3, 6),
            },
            'eye_y' => match ($breed) {
                'sphynx' => 118,
                'british' => 113,
                default => $style === 'noir' ? 110 : 112 + $rng->int(-4, 4),
            },
            'eye_rx' => match ($breed) {
                'british' => 12,
                'sphynx' => 13,
                default => $style === 'barbie' ? 15 : 13,
            },
            'eye_ry' => match ($breed) {
                'british' => 14,
                'sphynx' => 13,
                default => $style === 'noir' ? 13 : 16,
            },
            'cheek' => in_array($breed, ['mainecoon', 'persian', 'british'], true),
            'neck' => in_array($breed, ['sphynx', 'mainecoon'], true) || $style === 'royal' || $rng->chance(30),
            'expression' => match ($breed) {
                'sphynx' => $rng->pick(['closed', 'almond', 'almond']),
                'mainecoon' => 'almond',
                'british' => 'round',
                default => $rng->pick(['round', 'round', 'almond']),
            },
            'body' => match ($breed) {
                'sphynx' => 'slender',
                'british', 'persian' => 'round',
                'mainecoon' => 'elegant',
                default => 'classic',
            },
            'tail' => $tail,
            'pose' => $pose,
            'pose_lean' => $poseLean,
            'head_tilt' => $headTilt,
            'head_turn' => $headTurn,
            'gaze_x' => $gazeX,
            'gaze_y' => $gazeY,
            'ear_lean' => $rng->int(-3, 3) + (int) round($headTurn / 3),
            'paw_up' => $pawUp,
            'whisker_tilt' => match ($breed) {
                'sphynx' => $rng->int(-7, 3),
                'british' => $rng->int(-2, 8),
                'mainecoon' => $rng->int(-8, 2),
                default => $rng->int(-6, 8),
            },
            'whisker_curve' => match ($breed) {
                'sphynx' => $rng->int(-6, 3),
                'british' => $rng->int(2, 9),
                'mainecoon' => $rng->int(-8, 0),
                default => $rng->int(-5, 7),
            },
        ];
    }

    private static function pose(AvatarRng $rng, string $breed): string
    {
        $weights = match ($breed) {
            'sphynx' => ['sit' => 280, 'stretch' => 250, 'wave' => 160, 'side' => 180, 'loaf' => 130],
            'british', 'persian' => ['sit' => 280, 'loaf' => 260, 'wave' => 170, 'side' => 150, 'stretch' => 80],
            'mainecoon' => ['sit' => 240, 'side' => 250, 'stretch' => 170, 'wave' => 170, 'loaf' => 90],
            default => ['sit' => 330, 'wave' => 210, 'side' => 190, 'loaf' => 150, 'stretch' => 120],
        };

        return self::weighted($rng, $weights);
    }

    private static function pattern(AvatarRng $rng, string $breed, string $style, string $rarity): array
    {
        $type = match ($breed) {
            'tabby', 'mainecoon' => 'stripes',
            'bengal' => 'rosettes',
            'calico' => 'patches',
            'tuxedo' => 'bib',
            'siamese' => 'mask',
            'sphynx' => 'wrinkles',
            'cosmic' => 'stars',
            default => self::weighted($rng, ['none' => 230, 'stripes' => 270, 'spots' => 180, 'bib' => 130, 'patches' => 110, 'freckles' => 80]),
        };

        if ($style === 'barbie' && $rng->chance(38)) {
            $type = 'hearts';
        } elseif ($style === 'noir' && $rng->chance(45)) {
            $type = 'shadow';
        } elseif ($style === 'cyber' && $rng->chance(45)) {
            $type = 'circuit';
        } elseif ($style === 'cosmic' && $rng->chance(62)) {
            $type = 'stars';
        }

        if (in_array($rarity, ['epic', 'legendary'], true) && $rng->chance(24)) {
            $type = self::weighted($rng, ['stars' => 150, 'rosettes' => 90, 'hearts' => 70, 'circuit' => 70]);
        }

        return [
            'type' => $type,
            'density' => $rng->int(4, in_array($rarity, ['epic', 'legendary'], true) ? 12 : 8),
            'angle' => $rng->int(-14, 14),
            'asymmetry' => $rng->chance(45),
        ];
    }

    private static function feature(AvatarRng $rng, string $rarity, string $style): string
    {
        $weights = match ($rarity) {
            'legendary' => ['crown' => 160, 'halo' => 120, 'moon' => 110, 'constellation' => 150, 'tiara' => 110, 'detective_hat' => 110, 'visor' => 100, 'pearls' => 80, 'bow' => 60],
            'epic' => ['none' => 210, 'collar' => 140, 'moon' => 80, 'constellation' => 100, 'glasses' => 90, 'bow' => 80, 'tiara' => 65, 'detective_hat' => 65, 'visor' => 70, 'pearls' => 70],
            'rare' => ['none' => 370, 'collar' => 160, 'glasses' => 110, 'star' => 80, 'bow' => 80, 'pearls' => 55, 'detective_hat' => 45, 'visor' => 45],
            'uncommon' => ['none' => 560, 'collar' => 170, 'star' => 85, 'bow' => 70, 'glasses' => 55, 'pearls' => 30],
            default => ['none' => 730, 'collar' => 140, 'star' => 55, 'bow' => 45, 'glasses' => 30],
        };

        if ($style === 'noir') {
            $weights['detective_hat'] = ($weights['detective_hat'] ?? 0) + 180;
            $weights['glasses'] = ($weights['glasses'] ?? 0) + 90;
        } elseif ($style === 'barbie') {
            $weights['bow'] = ($weights['bow'] ?? 0) + 180;
            $weights['tiara'] = ($weights['tiara'] ?? 0) + 90;
            $weights['pearls'] = ($weights['pearls'] ?? 0) + 85;
        } elseif ($style === 'cyber') {
            $weights['visor'] = ($weights['visor'] ?? 0) + 190;
        } elseif ($style === 'royal') {
            $weights['crown'] = ($weights['crown'] ?? 0) + 140;
            $weights['pearls'] = ($weights['pearls'] ?? 0) + 80;
        } elseif ($style === 'cosmic') {
            $weights['constellation'] = ($weights['constellation'] ?? 0) + 140;
            $weights['moon'] = ($weights['moon'] ?? 0) + 90;
        }

        return self::weighted($rng, $weights);
    }

    private static function readableBackground(array $bg, string $fur, string $furAlt, string $style): array
    {
        $bg = array_values($bg);
        $furLum = self::luminance($fur);
        $furAltLum = self::luminance($furAlt);
        $closestLum = 1.0;
        $closestDistance = 999.0;

        foreach ($bg as $color) {
            $closestLum = min($closestLum, abs(self::luminance((string) $color) - $furLum));
            $closestDistance = min($closestDistance, self::colorDistance((string) $color, $fur), self::colorDistance((string) $color, $furAlt));
        }

        $tooClose = $closestLum < 0.18 || $closestDistance < 84;

        if (!$tooClose) {
            return [$bg[0], $bg[1], $bg[2]];
        }

        if ($furLum < 0.42 || ($furLum < 0.5 && $furAltLum < 0.5)) {
            return match ($style) {
                'noir' => ['#f8fafc', '#cbd5e1', '#94a3b8'],
                'cyber' => ['#ecfeff', '#bae6fd', '#64748b'],
                'cosmic' => ['#f5f3ff', '#ddd6fe', '#818cf8'],
                'royal' => ['#fffbeb', '#fde68a', '#c4b5fd'],
                default => ['#f8fafc', '#e2e8f0', '#cbd5e1'],
            };
        }

        if ($furLum > 0.68 || $furAltLum > 0.72) {
            return match ($style) {
                'barbie' => ['#831843', '#be185d', '#f9a8d4'],
                'botanical' => ['#14532d', '#166534', '#86efac'],
                'royal' => ['#312e81', '#4c1d95', '#c4b5fd'],
                default => ['#111827', '#334155', '#64748b'],
            };
        }

        return ['#f8fafc', '#dbeafe', '#cbd5e1'];
    }

    private static function defs(string $id, array $p): string
    {
        return '<defs>'
            . '<linearGradient id="' . self::e($id) . '-bg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="' . self::e($p['bg1']) . '"/><stop offset=".58" stop-color="' . self::e($p['bg2']) . '"/><stop offset="1" stop-color="' . self::e($p['bg3']) . '"/></linearGradient>'
            . '<radialGradient id="' . self::e($id) . '-fur" cx="38%" cy="24%" r="78%"><stop offset="0" stop-color="' . self::e(self::lighten($p['fur'], 20)) . '"/><stop offset=".58" stop-color="' . self::e($p['fur']) . '"/><stop offset="1" stop-color="' . self::e(self::darken($p['fur'], 18)) . '"/></radialGradient>'
            . '<linearGradient id="' . self::e($id) . '-accent" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="' . self::e(self::lighten($p['accent'], 22)) . '"/><stop offset="1" stop-color="' . self::e(self::darken($p['accent2'], 12)) . '"/></linearGradient>'
            . '<clipPath id="' . self::e($id) . '-head"><ellipse cx="120" cy="126" rx="74" ry="72"/></clipPath>'
            . '<filter id="' . self::e($id) . '-soft" x="-24%" y="-24%" width="148%" height="148%"><feDropShadow dx="0" dy="6" stdDeviation="5" flood-color="#000" flood-opacity=".14"/></filter>'
            . '<filter id="' . self::e($id) . '-paper" x="0" y="0" width="100%" height="100%"><feTurbulence type="fractalNoise" baseFrequency=".9" numOctaves="2" seed="7"/><feColorMatrix type="matrix" values="0 0 0 0 .18 0 0 0 0 .16 0 0 0 0 .13 0 0 0 .32 0"/></filter>'
            . '<filter id="' . self::e($id) . '-pencil" x="-8%" y="-8%" width="116%" height="116%"><feTurbulence type="fractalNoise" baseFrequency=".28" numOctaves="2" seed="11" result="noise"/><feDisplacementMap in="SourceGraphic" in2="noise" scale=".55" xChannelSelector="R" yChannelSelector="G"/></filter>'
            . '<filter id="' . self::e($id) . '-glow" x="-40%" y="-40%" width="180%" height="180%"><feGaussianBlur stdDeviation="2.5" result="blur"/><feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge></filter>'
            . '</defs>';
    }

    private static function background(AvatarRng $rng, string $id, array $p, string $style, string $rarity): string
    {
        $html = '<g class="tc-bg">';

        if ($style === 'noir') {
            $html .= '<path d="M-20 170 L260 70 L260 118 L-20 220Z" fill="#000" opacity=".1"/>'
                . '<path d="M-10 40 L260 -5 L260 24 L-10 76Z" fill="#fff" opacity=".05"/>'
                . '<circle cx="188" cy="48" r="24" fill="#f8fafc" opacity=".1"/>';
        } elseif ($style === 'barbie') {
            for ($i = 0; $i < 12; $i++) {
                $html .= self::heart($rng->int(14, 226), $rng->int(12, 226), $rng->int(3, 7), $rng->pick([$p['accent'], $p['accent2'], '#fff1f2']), '.24');
            }
            $html .= '<circle cx="196" cy="46" r="28" fill="#fff" opacity=".12"/>';
        } elseif ($style === 'cyber') {
            for ($i = 0; $i < 7; $i++) {
                $x = 25 + ($i * 32);
                $html .= '<path d="M' . $x . ' 0 L' . ($x + 8) . ' 240" stroke="' . self::e($p['accent']) . '" stroke-width="1" opacity=".09"/>';
            }
            for ($i = 0; $i < 5; $i++) {
                $y = 36 + ($i * 36);
                $html .= '<path d="M0 ' . $y . ' H240" stroke="' . self::e($p['accent2']) . '" stroke-width="1" opacity=".07"/>';
            }
        } elseif ($style === 'royal') {
            for ($i = 0; $i < 18; $i++) {
                $angle = $i * 20;
                $html .= '<path d="M120 120 L' . (120 + (int) (cos(deg2rad($angle)) * 180)) . ' ' . (120 + (int) (sin(deg2rad($angle)) * 180)) . '" stroke="' . self::e($p['metal']) . '" stroke-width="2" opacity=".06"/>';
            }
        } elseif ($style === 'cosmic') {
            for ($i = 0; $i < 28; $i++) {
                $html .= self::sparkle($rng->int(10, 230), $rng->int(10, 230), $rng->int(2, 6), $rng->pick([$p['accent'], $p['accent2'], '#fff']), '.32');
            }
        } elseif ($style === 'botanical') {
            for ($i = 0; $i < 13; $i++) {
                $x = $rng->int(4, 236);
                $y = $rng->int(18, 230);
                $html .= '<ellipse cx="' . $x . '" cy="' . $y . '" rx="' . $rng->int(5, 11) . '" ry="' . $rng->int(2, 5) . '" fill="' . self::e($p['accent']) . '" opacity=".13" transform="rotate(' . $rng->int(-55, 55) . ' ' . $x . ' ' . $y . ')"/>';
            }
        } else {
            for ($i = 0; $i < 16; $i++) {
                $html .= '<circle cx="' . $rng->int(10, 230) . '" cy="' . $rng->int(10, 230) . '" r="' . $rng->int(2, $rarity === 'legendary' ? 8 : 5) . '" fill="' . self::e($p['accent']) . '" opacity=".1"/>';
            }
        }

        return $html . '</g>';
    }

    private static function catBackdrop(array $p, string $style): string
    {
        $furLum = self::luminance($p['fur']);
        $bgLum = (self::luminance($p['bg1']) + self::luminance($p['bg2']) + self::luminance($p['bg3'])) / 3;
        $darkCat = $furLum < 0.45;
        $fill = $darkCat ? '#ffffff' : '#020617';
        $opacity = $darkCat ? ($style === 'noir' ? 0.28 : 0.2) : 0.12;

        if (abs($furLum - $bgLum) < 0.28) {
            $opacity += $darkCat ? 0.08 : 0.04;
        }

        $opacity = number_format(min($darkCat ? 0.34 : 0.18, $opacity), 2, '.', '');
        $line = $darkCat ? '#ffffff' : '#000000';
        $lineOpacity = $darkCat ? '.14' : '.08';

        return '<ellipse cx="120" cy="143" rx="92" ry="96" fill="' . $fill . '" opacity="' . $opacity . '"/>'
            . '<path d="M50 198 C71 121 95 75 137 61 C101 87 77 131 65 206Z" fill="#fff" opacity="' . ($darkCat ? '.1' : '.04') . '"/>'
            . '<ellipse cx="120" cy="148" rx="90" ry="94" fill="none" stroke="' . $line . '" stroke-width="2" opacity="' . $lineOpacity . '"/>';
    }

    private static function cat(AvatarRng $rng, string $id, array $p, array $shape, array $pattern, string $feature, string $breed, string $style, string $rarity): string
    {
        $headRx = (int) $shape['head_rx'];
        $headRy = (int) $shape['head_ry'];
        $ear = (int) $shape['ear'];
        $eyeY = (int) $shape['eye_y'];
        $eye2 = $p['eye2'] !== '' ? $p['eye2'] : $p['eye'];
        $cheek = (bool) $shape['cheek'];
        $neck = (bool) $shape['neck'];
        $headScale = (float) ($shape['head_scale'] ?? 0.85);
        $headX = (int) round(120 - (120 * $headScale));
        $headY = (int) ($shape['head_offset_y'] ?? 2);
        $headTilt = (int) ($shape['head_tilt'] ?? 0);
        $headSkew = number_format(((int) ($shape['head_turn'] ?? 0)) * 0.28, 2, '.', '');
        $bodyLean = (int) ($shape['pose_lean'] ?? 0);
        $bodyTransform = $bodyLean !== 0 ? '<g transform="rotate(' . $bodyLean . ' 120 226)">' : '<g>';

        return '<g filter="url(#' . self::e($id) . '-soft)"><g filter="url(#' . self::e($id) . '-pencil)">'
            . self::catBackdrop($p, $style)
            . '<ellipse cx="120" cy="225" rx="70" ry="10" fill="#000" opacity=".14"/>'
            . self::tail($p, $breed, $style, $shape)
            . $bodyTransform
            . self::body($p, $style, $breed, $shape, $neck)
            . self::bodyPattern($rng, $p, $pattern, $breed, $style, $shape)
            . self::bodyPencilShade($rng, $p, $shape, $breed, $style)
            . self::poseDetails($p, $shape, $breed)
            . self::bodyInk($rng, $p, $shape, $breed)
            . '</g>'
            . '<g transform="translate(' . $headX . ' ' . $headY . ') scale(' . self::e((string) $headScale) . ') rotate(' . $headTilt . ' 120 126) skewX(' . self::e($headSkew) . ')">'
            . self::ears($p, $ear, $breed, $style, $shape)
            . '<ellipse cx="120" cy="126" rx="' . $headRx . '" ry="' . $headRy . '" fill="url(#' . self::e($id) . '-fur)" stroke="' . self::e($p['line']) . '" stroke-width="5"/>'
            . self::headHighlight($p, $style)
            . self::cheekFluff($p, $cheek, $breed)
            . self::patternShapes($rng, $id, $p, $pattern, $breed, $style)
            . self::faceMask($p, $pattern, $breed, $style)
            . self::ruff($p, $breed)
            . self::headPencilShade($rng, $id, $p, $breed, $style)
            . self::earsInnerLines($p, $breed)
            . self::headInk($rng, $p, $breed, $style, $shape)
            . self::eyes($id, $p, $p['eye'], $eye2, $eyeY, (int) $shape['eye_rx'], (int) $shape['eye_ry'], $style, $shape)
            . self::browsAndLashes($p, $style, $eyeY, $shape)
            . self::muzzle($p, (int) $shape['muzzle'], $style, $shape)
            . self::whiskerDots($p, $shape)
            . self::whiskers($p, $style, $shape)
            . self::featureShapes($id, $p, $feature, $rarity, $style)
            . '</g>'
            . '</g></g>';
    }

    private static function body(array $p, string $style, string $breed, array $shape, bool $neck): string
    {
        $type = (string) ($shape['body'] ?? 'classic');
        $pose = (string) ($shape['pose'] ?? 'sit');
        $fill = self::e(self::darken($p['fur'], $breed === 'sphynx' ? 0 : 5));

        if ($pose === 'loaf') {
            $html = '<ellipse cx="120" cy="198" rx="' . ($type === 'round' ? '67' : '58') . '" ry="' . ($type === 'slender' ? '34' : '40') . '" fill="' . $fill . '"/>'
                . '<path d="M76 202 C96 218 144 218 164 202" fill="none" stroke="' . self::e(self::darken($p['fur'], 15)) . '" stroke-width="5" stroke-linecap="round" opacity=".4"/>'
                . '<ellipse cx="95" cy="222" rx="18" ry="8" fill="' . self::e(self::lighten($p['fur'], 8)) . '"/>'
                . '<ellipse cx="145" cy="222" rx="18" ry="8" fill="' . self::e(self::lighten($p['fur'], 8)) . '"/>';

            if ($neck) {
                $html .= '<path d="M87 177 C101 167 139 167 153 177" fill="none" stroke="' . self::e($p['accent']) . '" stroke-width="7" stroke-linecap="round"/>'
                    . '<circle cx="120" cy="179" r="5" fill="' . self::e($p['metal']) . '"/>';
            }

            return $html;
        }

        if ($type === 'slender') {
            $html = '<path d="M77 228 C78 190 90 158 105 139 C112 151 128 151 135 139 C150 158 162 190 163 228Z" fill="' . $fill . '"/>'
                . '<path d="M100 158 C109 168 131 168 140 158" fill="none" stroke="' . self::e(self::darken($p['line'], 4)) . '" stroke-width="2.5" stroke-linecap="round" opacity=".34"/>'
                . ($pose === 'stretch'
                    ? '<path d="M94 228 C97 199 108 177 121 160" fill="none" stroke="' . self::e($p['line']) . '" stroke-width="4" stroke-linecap="round" opacity=".8"/><path d="M146 228 C142 199 131 177 119 160" fill="none" stroke="' . self::e($p['line']) . '" stroke-width="4" stroke-linecap="round" opacity=".8"/>'
                    : '<path d="M96 228 C98 203 103 180 109 165" fill="none" stroke="' . self::e($p['line']) . '" stroke-width="4" stroke-linecap="round" opacity=".8"/><path d="M144 228 C142 203 137 180 131 165" fill="none" stroke="' . self::e($p['line']) . '" stroke-width="4" stroke-linecap="round" opacity=".8"/>')
                . '<ellipse cx="94" cy="226" rx="14" ry="8" fill="' . self::e(self::lighten($p['fur'], 8)) . '"/>'
                . '<ellipse cx="146" cy="226" rx="14" ry="8" fill="' . self::e(self::lighten($p['fur'], 8)) . '"/>';
        } elseif ($type === 'round') {
            $html = '<path d="M54 228 C57 177 84 145 120 145 C156 145 183 177 186 228Z" fill="' . $fill . '"/>'
                . '<ellipse cx="120" cy="194" rx="53" ry="33" fill="' . self::e(self::lighten($p['fur'], 8)) . '" opacity=".2"/>'
                . '<path d="M100 169 C107 190 106 210 98 228" fill="none" stroke="' . self::e(self::darken($p['fur'], 16)) . '" stroke-width="7" stroke-linecap="round" opacity=".62"/>'
                . '<path d="M140 169 C133 190 134 210 142 228" fill="none" stroke="' . self::e(self::darken($p['fur'], 16)) . '" stroke-width="7" stroke-linecap="round" opacity=".62"/>'
                . '<ellipse cx="88" cy="224" rx="21" ry="11" fill="' . self::e(self::lighten($p['fur'], 7)) . '"/>'
                . '<ellipse cx="152" cy="224" rx="21" ry="11" fill="' . self::e(self::lighten($p['fur'], 7)) . '"/>';
        } elseif ($type === 'elegant') {
            $html = '<path d="M65 228 C70 185 94 149 120 143 C146 149 170 185 175 228Z" fill="' . $fill . '"/>'
                . '<path d="M93 226 C98 194 108 171 119 155 C131 171 142 194 147 226" fill="none" stroke="' . self::e(self::lighten($p['fur'], 18)) . '" stroke-width="9" stroke-linecap="round" opacity=".36"/>'
                . '<path d="M98 176 C100 198 99 215 96 228" fill="none" stroke="' . self::e(self::darken($p['fur'], 17)) . '" stroke-width="6" stroke-linecap="round" opacity=".58"/>'
                . '<path d="M142 176 C140 198 141 215 144 228" fill="none" stroke="' . self::e(self::darken($p['fur'], 17)) . '" stroke-width="6" stroke-linecap="round" opacity=".58"/>'
                . '<ellipse cx="91" cy="225" rx="18" ry="9" fill="' . self::e(self::lighten($p['fur'], 9)) . '"/>'
                . '<ellipse cx="149" cy="225" rx="18" ry="9" fill="' . self::e(self::lighten($p['fur'], 9)) . '"/>';
        } else {
            $html = '<path d="M68 228 C73 186 96 153 120 151 C144 153 167 186 172 228Z" fill="' . $fill . '"/>'
                . '<path d="M99 177 C104 198 103 214 99 228" fill="none" stroke="' . self::e(self::darken($p['fur'], 15)) . '" stroke-width="6" stroke-linecap="round" opacity=".56"/>'
                . '<path d="M141 177 C136 198 137 214 141 228" fill="none" stroke="' . self::e(self::darken($p['fur'], 15)) . '" stroke-width="6" stroke-linecap="round" opacity=".56"/>'
                . '<ellipse cx="93" cy="225" rx="18" ry="9" fill="' . self::e(self::lighten($p['fur'], 8)) . '"/>'
                . '<ellipse cx="147" cy="225" rx="18" ry="9" fill="' . self::e(self::lighten($p['fur'], 8)) . '"/>';
        }

        if ($neck) {
            $html .= '<path d="M86 176 C100 164 140 164 154 176" fill="none" stroke="' . self::e($p['accent']) . '" stroke-width="' . ($style === 'royal' ? '10' : '7') . '" stroke-linecap="round"/>'
                . '<circle cx="120" cy="179" r="5" fill="' . self::e($p['metal']) . '"/>';
        }

        return $html;
    }

    private static function poseDetails(array $p, array $shape, string $breed): string
    {
        $pose = (string) ($shape['pose'] ?? 'sit');
        $paw = (string) ($shape['paw_up'] ?? '');

        if ($pose === 'wave') {
            $x = $paw === 'right' ? 151 : 89;
            $control = $paw === 'right' ? 168 : 72;
            $end = $paw === 'right' ? 168 : 72;

            return '<g>'
                . '<path d="M' . $x . ' 177 C' . $control . ' 158 ' . $end . ' 134 ' . $end . ' 116" fill="none" stroke="' . self::e(self::darken($p['fur'], 5)) . '" stroke-width="' . ($breed === 'sphynx' ? '8' : '11') . '" stroke-linecap="round"/>'
                . '<ellipse cx="' . $end . '" cy="113" rx="10" ry="8" fill="' . self::e(self::lighten($p['fur'], 8)) . '" transform="rotate(' . ($paw === 'right' ? '-20' : '20') . ' ' . $end . ' 113)"/>'
                . '</g>';
        }

        if ($pose === 'stretch') {
            return '<g opacity=".78">'
                . '<path d="M88 212 C69 210 58 203 48 190" fill="none" stroke="' . self::e(self::darken($p['fur'], 7)) . '" stroke-width="9" stroke-linecap="round"/>'
                . '<path d="M152 212 C171 210 182 203 192 190" fill="none" stroke="' . self::e(self::darken($p['fur'], 7)) . '" stroke-width="9" stroke-linecap="round"/>'
                . '<ellipse cx="46" cy="190" rx="10" ry="6" fill="' . self::e(self::lighten($p['fur'], 8)) . '"/>'
                . '<ellipse cx="194" cy="190" rx="10" ry="6" fill="' . self::e(self::lighten($p['fur'], 8)) . '"/>'
                . '</g>';
        }

        if ($pose === 'side') {
            return '<ellipse cx="122" cy="224" rx="54" ry="7" fill="' . self::e(self::darken($p['fur'], 12)) . '" opacity=".28"/>';
        }

        return '';
    }

    private static function bodyPattern(AvatarRng $rng, array $p, array $pattern, string $breed, string $style, array $shape): string
    {
        $type = (string) ($pattern['type'] ?? 'none');
        $body = (string) ($shape['body'] ?? 'classic');
        $color = self::darken($p['fur2'], $style === 'noir' ? 8 : 20);
        $html = '<g opacity="' . ($style === 'noir' ? '.32' : '.28') . '">';

        if ($type === 'stripes') {
            $count = $breed === 'mainecoon' ? 7 : 5;

            for ($i = 0; $i < $count; $i++) {
                $y = 164 + ($i * 10);
                $html .= '<path d="M83 ' . $y . ' C105 ' . ($y + 8) . ' 135 ' . ($y + 8) . ' 157 ' . $y . '" fill="none" stroke="' . self::e($color) . '" stroke-width="4" stroke-linecap="round"/>';
            }
        } elseif (in_array($type, ['spots', 'patches', 'rosettes'], true)) {
            for ($i = 0; $i < 7; $i++) {
                $x = $rng->int(80, 160);
                $y = $rng->int(160, 212);
                $rx = $rng->int(6, 16);
                $ry = $rng->int(5, 13);

                if ($type === 'rosettes') {
                    $html .= '<ellipse cx="' . $x . '" cy="' . $y . '" rx="' . $rx . '" ry="' . $ry . '" fill="none" stroke="' . self::e($color) . '" stroke-width="3"/>';
                } else {
                    $html .= '<ellipse cx="' . $x . '" cy="' . $y . '" rx="' . $rx . '" ry="' . $ry . '" fill="' . self::e($i % 2 === 0 ? $color : $p['fur2']) . '" transform="rotate(' . $rng->int(-18, 18) . ' ' . $x . ' ' . $y . ')"/>';
                }
            }
        } elseif ($type === 'wrinkles' || $breed === 'sphynx') {
            for ($i = 0; $i < 5; $i++) {
                $y = 164 + ($i * 11);
                $html .= '<path d="M92 ' . $y . ' C108 ' . ($y - 5) . ' 132 ' . ($y + 5) . ' 148 ' . $y . '" fill="none" stroke="' . self::e($p['line']) . '" stroke-width="2" stroke-linecap="round"/>';
            }
        } elseif ($type === 'hearts') {
            for ($i = 0; $i < 5; $i++) {
                $html .= self::heart($rng->int(86, 154), $rng->int(166, 210), $rng->int(3, 5), $rng->pick([$p['accent'], $p['accent2'], $p['blush']]), '.65');
            }
        } elseif ($type === 'circuit') {
            for ($i = 0; $i < 4; $i++) {
                $y = 166 + ($i * 12);
                $html .= '<path d="M88 ' . $y . ' H112 V' . ($y + 7) . ' H135 V' . ($y - 4) . ' H154" fill="none" stroke="' . self::e($p['accent']) . '" stroke-width="2" stroke-linecap="round"/>';
            }
        }

        if ($body === 'round') {
            $html .= '<ellipse cx="120" cy="196" rx="35" ry="24" fill="#fff" opacity=".12"/>';
        }

        return $html . '</g>';
    }

    private static function bodyPencilShade(AvatarRng $rng, array $p, array $shape, string $breed, string $style): string
    {
        $body = (string) ($shape['body'] ?? 'classic');
        $pose = (string) ($shape['pose'] ?? 'sit');
        $line = self::e(self::darken($p['line'], 4));
        $soft = self::e(self::darken($p['fur'], $style === 'noir' ? 2 : 22));
        $opacity = $style === 'noir' ? '.18' : '.13';
        $bands = $pose === 'loaf'
            ? [180, 187, 194, 201, 208, 215]
            : [158, 166, 174, 183, 192, 201, 210, 219];

        $html = '<g fill="none" stroke-linecap="round" stroke-linejoin="round" opacity="' . $opacity . '">';
        foreach ($bands as $index => $y) {
            $spread = match ($body) {
                'round' => min(58, 30 + ($index * 5)),
                'slender' => min(38, 18 + ($index * 4)),
                'elegant' => min(48, 22 + ($index * 5)),
                default => min(45, 22 + ($index * 4)),
            };
            if ($pose === 'loaf') {
                $spread = 38 + (int) (sin($index / max(1, count($bands) - 1) * pi()) * 22);
            }
            $x1 = 120 - $spread + $rng->int(-3, 2);
            $x2 = 120 + $spread + $rng->int(-2, 3);
            $curve = $rng->int(-4, 5);
            $html .= '<path d="M' . $x1 . ' ' . ($y + $rng->int(-1, 1)) . ' C' . (int) (($x1 + 120) / 2) . ' ' . ($y + $curve) . ' ' . (int) (($x2 + 120) / 2) . ' ' . ($y - $curve) . ' ' . $x2 . ' ' . ($y + $rng->int(-1, 1)) . '" stroke="' . $soft . '" stroke-width="' . $rng->pick(['1', '1.15', '1.35']) . '"/>';
        }

        $edgeTicks = [[76, 172, -7, 9], [70, 190, -8, 8], [81, 211, -6, 7], [164, 172, 7, 9], [170, 190, 8, 8], [159, 211, 6, 7]];
        if ($breed === 'sphynx') {
            $edgeTicks[] = [104, 165, -4, 13];
            $edgeTicks[] = [136, 165, 4, 13];
        }
        foreach ($edgeTicks as $tick) {
            [$x, $y, $dx, $dy] = $tick;
            $html .= '<path d="M' . ($x + $rng->int(-2, 2)) . ' ' . ($y + $rng->int(-2, 2)) . ' l ' . ($dx + $rng->int(-1, 1)) . ' ' . ($dy + $rng->int(-1, 2)) . '" stroke="' . $line . '" stroke-width=".9" opacity=".55"/>';
        }

        return $html . '</g>';
    }

    private static function headPencilShade(AvatarRng $rng, string $id, array $p, string $breed, string $style): string
    {
        $line = self::e(self::darken($p['line'], 4));
        $fur = self::e(self::darken($p['fur'], $style === 'noir' ? 0 : 24));
        $opacity = $style === 'noir' ? '.2' : '.14';
        $html = '<g clip-path="url(#' . self::e($id) . '-head)" fill="none" stroke-linecap="round" stroke-linejoin="round" opacity="' . $opacity . '">';
        foreach ([82, 91, 101, 112, 124, 136, 148, 159] as $index => $y) {
            $ratio = min(1, abs($y - 126) / 62);
            $half = (int) round(sqrt(max(0.08, 1 - ($ratio * $ratio))) * 54);
            $x1 = 120 - $half + $rng->int(-2, 2);
            $x2 = 120 + $half + $rng->int(-2, 2);
            $bend = $rng->int(-5, 5) + ($breed === 'sphynx' ? 2 : 0);
            $html .= '<path d="M' . $x1 . ' ' . ($y + $rng->int(-1, 1)) . ' C' . (int) (($x1 + 120) / 2) . ' ' . ($y + $bend) . ' ' . (int) (($x2 + 120) / 2) . ' ' . ($y - $bend) . ' ' . $x2 . ' ' . ($y + $rng->int(-1, 1)) . '" stroke="' . $fur . '" stroke-width="' . $rng->pick(['1', '1.15', '1.35']) . '"/>';
        }

        foreach ([[92, 84, -4, 16], [108, 78, -2, 18], [121, 77, 0, 20], [134, 78, 2, 18], [148, 84, 4, 16], [82, 134, -10, 6], [158, 134, 10, 6]] as $tick) {
            [$x, $y, $dx, $dy] = $tick;
            $html .= '<path d="M' . ($x + $rng->int(-2, 2)) . ' ' . ($y + $rng->int(-1, 1)) . ' q ' . ($dx + $rng->int(-1, 1)) . ' ' . (int) ($dy / 2) . ' ' . ($dx + $rng->int(-1, 1)) . ' ' . ($dy + $rng->int(-1, 2)) . '" stroke="' . $line . '" stroke-width=".9" opacity=".5"/>';
        }

        return $html . '</g>';
    }

    private static function bodyInk(AvatarRng $rng, array $p, array $shape, string $breed): string
    {
        $body = (string) ($shape['body'] ?? 'classic');
        $pose = (string) ($shape['pose'] ?? 'sit');
        $stroke = self::e($p['line']);
        $thin = $breed === 'sphynx' ? '1.9' : '2.2';
        $outlineWidth = $breed === 'mainecoon' ? '4.5' : '4';

        if ($pose === 'loaf') {
            $outline = '<ellipse cx="120" cy="198" rx="' . ($body === 'round' ? '68' : '59') . '" ry="' . ($body === 'slender' ? '35' : '41') . '" fill="none" stroke="' . $stroke . '" stroke-width="' . $outlineWidth . '" opacity=".78"/>'
                . '<path d="M77 202 C95 217 145 217 163 202" fill="none" stroke="' . $stroke . '" stroke-width="2.6" stroke-linecap="round" opacity=".42"/>';
        } elseif ($body === 'slender') {
            $outline = '<path d="M77 228 C78 190 90 158 105 139 C112 151 128 151 135 139 C150 158 162 190 163 228Z" fill="none" stroke="' . $stroke . '" stroke-width="' . $outlineWidth . '" stroke-linejoin="round" opacity=".78"/>';
        } elseif ($body === 'round') {
            $outline = '<path d="M54 228 C57 177 84 145 120 145 C156 145 183 177 186 228Z" fill="none" stroke="' . $stroke . '" stroke-width="' . $outlineWidth . '" stroke-linejoin="round" opacity=".78"/>';
        } elseif ($body === 'elegant') {
            $outline = '<path d="M65 228 C70 185 94 149 120 143 C146 149 170 185 175 228Z" fill="none" stroke="' . $stroke . '" stroke-width="' . $outlineWidth . '" stroke-linejoin="round" opacity=".78"/>';
        } else {
            $outline = '<path d="M68 228 C73 186 96 153 120 151 C144 153 167 186 172 228Z" fill="none" stroke="' . $stroke . '" stroke-width="' . $outlineWidth . '" stroke-linejoin="round" opacity=".78"/>';
        }

        $html = '<g fill="none" stroke-linecap="round" stroke-linejoin="round">'
            . $outline
            . '<g stroke="' . $stroke . '" stroke-width="' . $thin . '" opacity=".52">'
            . '<path d="M84 224 C88 220 93 220 97 224"/>'
            . '<path d="M92 226 C96 222 101 222 105 226"/>'
            . '<path d="M135 226 C139 222 144 222 148 226"/>'
            . '<path d="M143 224 C147 220 152 220 156 224"/>'
            . '<path d="M102 171 C111 181 129 181 138 171"/>'
            . '</g>';

        if ($pose === 'wave') {
            $paw = (string) ($shape['paw_up'] ?? 'left');
            $x = $paw === 'right' ? 168 : 72;
            $dir = $paw === 'right' ? 1 : -1;
            $html .= '<g stroke="' . $stroke . '" stroke-width="1.8" opacity=".55">'
                . '<path d="M' . ($x - (4 * $dir)) . ' 111 C' . ($x - (1 * $dir)) . ' 107 ' . ($x + (3 * $dir)) . ' 107 ' . ($x + (6 * $dir)) . ' 111"/>'
                . '<path d="M' . ($x - (7 * $dir)) . ' 116 C' . ($x - (3 * $dir)) . ' 112 ' . ($x + (1 * $dir)) . ' 112 ' . ($x + (4 * $dir)) . ' 116"/>'
                . '</g>';
        }

        if ($pose === 'stretch') {
            $html .= '<g stroke="' . $stroke . '" stroke-width="2" opacity=".5">'
                . '<path d="M42 188 C47 185 53 185 58 188"/>'
                . '<path d="M182 188 C187 185 193 185 198 188"/>'
                . '</g>';
        }

        $html .= '<g stroke="' . $stroke . '" stroke-width="1.5" opacity=".26">';
        for ($i = 0; $i < 11; $i++) {
            $x = $rng->int(76, 164);
            $y = $rng->int(157, 219);
            $dx = $rng->int(-5, 5);
            $dy = $rng->int(3, 8);
            $html .= '<path d="M' . $x . ' ' . $y . ' q ' . $dx . ' ' . $dy . ' ' . ($dx + $rng->int(-2, 2)) . ' ' . ($dy + $rng->int(3, 7)) . '"/>';
        }
        $html .= '</g></g>';

        return $html;
    }

    private static function tail(array $p, string $breed, string $style, array $shape): string
    {
        $type = (string) ($shape['tail'] ?? 'curl');
        $pose = (string) ($shape['pose'] ?? 'sit');
        $stroke = self::e(self::darken($p['fur'], $breed === 'sphynx' ? 4 : 8));
        $opacity = $style === 'noir' ? '.56' : '.82';
        if ($pose === 'loaf') {
            $type = 'wrap';
        } elseif ($pose === 'side' && $type === 'curl') {
            $type = 'sweep';
        } elseif ($pose === 'stretch') {
            $type = $breed === 'mainecoon' ? 'plume' : 'sweep';
        }

        $width = match ($type) {
            'plume', 'puff' => 18,
            'slender' => 8,
            default => 13,
        };
        $path = match ($type) {
            'slender' => 'M158 206 C195 194 191 158 160 166 C178 172 177 192 154 197',
            'plume' => 'M164 197 C214 180 207 124 163 137 C195 148 192 181 157 187',
            'puff' => 'M164 197 C207 184 207 145 174 142 C197 163 188 188 158 191',
            'sweep' => 'M164 198 C202 190 218 165 208 136 C197 160 181 175 156 183',
            'hook' => 'M162 198 C202 183 198 143 168 143 C190 151 184 174 160 182',
            'wrap' => 'M163 198 C206 188 204 142 166 146 C196 160 185 195 143 189',
            default => 'M166 191 C205 177 196 134 164 148 C184 152 185 174 161 181',
        };

        return '<path d="' . $path . '" fill="none" stroke="' . self::e($p['line']) . '" stroke-width="' . ($width + 5) . '" stroke-linecap="round" opacity=".45"/>'
            . '<path d="' . $path . '" fill="none" stroke="' . $stroke . '" stroke-width="' . $width . '" stroke-linecap="round" opacity="' . $opacity . '"/>';
    }

    private static function ears(array $p, int $ear, string $breed, string $style, array $shape): string
    {
        $turn = (int) ($shape['head_turn'] ?? 0);
        $lean = (int) ($shape['ear_lean'] ?? 0);
        $leftAngle = -4 + $lean + ($turn < 0 ? -3 : 1);
        $rightAngle = 4 + $lean + ($turn > 0 ? 3 : -1);

        if ($breed === 'sphynx') {
            $left = 'M50 112 C47 71 55 45 75 35 C96 54 111 80 103 116 Z';
            $right = 'M190 112 C193 71 185 45 165 35 C144 54 129 80 137 116 Z';
            $innerLeft = 'M63 100 C60 73 65 58 76 51 C91 68 100 88 94 105 Z';
            $innerRight = 'M177 100 C180 73 175 58 164 51 C149 68 140 88 146 105 Z';
            $foldLeft = '<path d="M72 100 C73 82 76 68 83 58" fill="none" stroke="' . self::e($p['line']) . '" stroke-width="1.8" stroke-linecap="round" opacity=".42"/>';
            $foldRight = '<path d="M168 100 C167 82 164 68 157 58" fill="none" stroke="' . self::e($p['line']) . '" stroke-width="1.8" stroke-linecap="round" opacity=".42"/>';
        } else {
            $left = 'M53 106 L76 ' . (80 - (int) ($ear / 3)) . ' L96 113 Z';
            $right = 'M187 106 L164 ' . (80 - (int) ($ear / 3)) . ' L144 113 Z';
            $innerLeft = 'M68 101 L78 ' . (90 - (int) ($ear / 5)) . ' L87 110 Z';
            $innerRight = 'M172 101 L162 ' . (90 - (int) ($ear / 5)) . ' L153 110 Z';
            $foldLeft = '<path d="M72 101 C76 95 80 91 84 87" fill="none" stroke="' . self::e($p['line']) . '" stroke-width="1.6" stroke-linecap="round" opacity=".38"/>';
            $foldRight = '<path d="M168 101 C164 95 160 91 156 87" fill="none" stroke="' . self::e($p['line']) . '" stroke-width="1.6" stroke-linecap="round" opacity=".38"/>';
        }
        $leftTuft = '';
        $rightTuft = '';

        if (in_array($breed, ['mainecoon', 'cosmic', 'persian'], true)) {
            $leftTuft = '<path d="M75 68 L69 48 L84 66" fill="' . self::e(self::darken($p['fur'], 18)) . '"/>';
            $rightTuft = '<path d="M165 68 L171 48 L156 66" fill="' . self::e(self::darken($p['fur'], 18)) . '"/>';
        }

        if ($style === 'barbie') {
            $leftTuft .= self::heart(80, 70, 5, $p['accent'], '.9');
            $rightTuft .= self::heart(160, 70, 5, $p['accent2'], '.9');
        }

        return '<g transform="rotate(' . $leftAngle . ' 76 96)">'
            . '<path d="' . $left . '" fill="' . self::e(self::darken($p['fur'], 4)) . '" stroke="' . self::e($p['line']) . '" stroke-width="4" stroke-linejoin="round"/>'
            . '<path d="' . $innerLeft . '" fill="' . self::e(self::lighten($p['nose'], 12)) . '" stroke="' . self::e($p['line']) . '" stroke-width="1.8" stroke-linejoin="round" opacity=".78"/>'
            . $foldLeft
            . $leftTuft
            . '</g>'
            . '<g transform="rotate(' . $rightAngle . ' 164 96)">'
            . '<path d="' . $right . '" fill="' . self::e(self::darken($p['fur'], 4)) . '" stroke="' . self::e($p['line']) . '" stroke-width="4" stroke-linejoin="round"/>'
            . '<path d="' . $innerRight . '" fill="' . self::e(self::lighten($p['nose'], 12)) . '" stroke="' . self::e($p['line']) . '" stroke-width="1.8" stroke-linejoin="round" opacity=".78"/>'
            . $foldRight
            . $rightTuft
            . '</g>';
    }

    private static function earsInnerLines(array $p, string $breed): string
    {
        if ($breed !== 'sphynx') {
            return '';
        }

        return '<g fill="none" stroke="' . self::e($p['line']) . '" stroke-width="1.8" opacity=".38"><path d="M73 102 C77 96 80 92 83 86"/><path d="M167 102 C163 96 160 92 157 86"/></g>';
    }

    private static function headInk(AvatarRng $rng, array $p, string $breed, string $style, array $shape): string
    {
        $stroke = self::e($p['line']);
        $soft = self::e(self::darken($p['line'], 5));
        $opacity = $style === 'noir' ? '.56' : '.48';
        $detailWidth = $breed === 'sphynx' ? '2' : '2.2';
        $turn = (int) ($shape['head_turn'] ?? 0);
        $shift = (int) round($turn * .45);

        $html = '<g fill="none" stroke="' . $stroke . '" stroke-linecap="round" stroke-linejoin="round" opacity="' . $opacity . '">'
            . '<path d="M' . (76 + $shift) . ' 132 C' . (81 + $shift) . ' 157 ' . (99 + $shift) . ' 176 ' . (120 + $shift) . ' 180 C' . (141 + $shift) . ' 176 ' . (159 + $shift) . ' 157 ' . (164 + $shift) . ' 132" stroke-width="2.4"/>'
            . '<path d="M' . (103 + $shift) . ' 76 C' . (112 + $shift) . ' 72 ' . (128 + $shift) . ' 72 ' . (137 + $shift) . ' 76" stroke-width="2"/>'
            . '<path d="M' . (90 + $shift) . ' 94 C' . (99 + $shift) . ' 88 ' . (108 + $shift) . ' 86 ' . (116 + $shift) . ' 87" stroke-width="' . $detailWidth . '"/>'
            . '<path d="M' . (150 + $shift) . ' 94 C' . (141 + $shift) . ' 88 ' . (132 + $shift) . ' 86 ' . (124 + $shift) . ' 87" stroke-width="' . $detailWidth . '"/>'
            . '<path d="M' . (101 + $shift) . ' 168 C' . (112 + $shift) . ' 176 ' . (128 + $shift) . ' 176 ' . (139 + $shift) . ' 168" stroke-width="1.8"/>'
            . '<path d="M' . (119 + $shift) . ' 91 C' . (121 + $shift) . ' 111 ' . (121 + $shift) . ' 126 ' . (120 + $shift) . ' 137" stroke-width="1.25" opacity=".35"/>';

        if (in_array($breed, ['british', 'persian'], true)) {
            $html .= '<path d="M' . (72 + $shift) . ' 133 C' . (77 + $shift) . ' 148 ' . (88 + $shift) . ' 156 ' . (101 + $shift) . ' 153" stroke-width="2.2"/>'
                . '<path d="M' . (168 + $shift) . ' 133 C' . (163 + $shift) . ' 148 ' . (152 + $shift) . ' 156 ' . (139 + $shift) . ' 153" stroke-width="2.2"/>'
                . '<path d="M' . (83 + $shift) . ' 145 C' . (90 + $shift) . ' 150 ' . (96 + $shift) . ' 151 ' . (102 + $shift) . ' 149" stroke-width="1.6"/>'
                . '<path d="M' . (157 + $shift) . ' 145 C' . (150 + $shift) . ' 150 ' . (144 + $shift) . ' 151 ' . (138 + $shift) . ' 149" stroke-width="1.6"/>';
        }

        if ($breed === 'sphynx') {
            $html .= '<path d="M' . (96 + $shift) . ' 90 C' . (107 + $shift) . ' 83 ' . (114 + $shift) . ' 84 ' . (120 + $shift) . ' 92" stroke-width="2"/>'
                . '<path d="M' . (120 + $shift) . ' 92 C' . (126 + $shift) . ' 84 ' . (134 + $shift) . ' 83 ' . (144 + $shift) . ' 90" stroke-width="2"/>'
                . '<path d="M' . (88 + $shift) . ' 111 C' . (101 + $shift) . ' 106 ' . (111 + $shift) . ' 106 ' . (119 + $shift) . ' 112" stroke-width="1.8"/>'
                . '<path d="M' . (121 + $shift) . ' 112 C' . (129 + $shift) . ' 106 ' . (139 + $shift) . ' 106 ' . (152 + $shift) . ' 111" stroke-width="1.8"/>'
                . '<path d="M' . (81 + $shift) . ' 127 C' . (91 + $shift) . ' 122 ' . (98 + $shift) . ' 122 ' . (104 + $shift) . ' 128" stroke-width="1.6"/>'
                . '<path d="M' . (159 + $shift) . ' 127 C' . (149 + $shift) . ' 122 ' . (142 + $shift) . ' 122 ' . (136 + $shift) . ' 128" stroke-width="1.6"/>';
        }

        if ($breed === 'mainecoon') {
            $html .= '<path d="M' . (66 + $shift) . ' 142 L' . (51 + $shift) . ' 151 M' . (70 + $shift) . ' 150 L' . (55 + $shift) . ' 161 M' . (174 + $shift) . ' 142 L' . (189 + $shift) . ' 151 M' . (170 + $shift) . ' 150 L' . (185 + $shift) . ' 161" stroke-width="2.4"/>'
                . '<path d="M' . (92 + $shift) . ' 170 L' . (84 + $shift) . ' 187 M' . (110 + $shift) . ' 176 L' . (105 + $shift) . ' 193 M' . (130 + $shift) . ' 176 L' . (135 + $shift) . ' 193 M' . (148 + $shift) . ' 170 L' . (156 + $shift) . ' 187" stroke-width="2" opacity=".7"/>';
        }

        if (in_array($breed, ['tabby', 'bengal', 'calico'], true)) {
            $html .= '<path d="M' . (111 + $shift) . ' 74 C' . (115 + $shift) . ' 85 ' . (115 + $shift) . ' 95 ' . (111 + $shift) . ' 104" stroke-width="1.8"/>'
                . '<path d="M' . (120 + $shift) . ' 72 C' . (120 + $shift) . ' 87 ' . (120 + $shift) . ' 99 ' . (120 + $shift) . ' 109" stroke-width="2"/>'
                . '<path d="M' . (129 + $shift) . ' 74 C' . (125 + $shift) . ' 85 ' . (125 + $shift) . ' 95 ' . (129 + $shift) . ' 104" stroke-width="1.8"/>';
        }

        $html .= '</g><g fill="none" stroke="' . $soft . '" stroke-width="1.35" stroke-linecap="round" opacity=".24">';
        foreach ([[74, 110, -9, -2], [72, 126, -11, 1], [79, 145, -10, 4], [166, 110, 9, -2], [168, 126, 11, 1], [161, 145, 10, 4], [101, 82, -6, -8], [139, 82, 6, -8]] as $tick) {
            [$x, $y, $dx, $dy] = $tick;
            $html .= '<path d="M' . ($x + $shift + $rng->int(-1, 1)) . ' ' . ($y + $rng->int(-1, 1)) . ' q ' . ($dx + $rng->int(-1, 1)) . ' ' . ($dy + $rng->int(-1, 1)) . ' ' . ($dx + $rng->int(-1, 1)) . ' ' . ($dy + $rng->int(-1, 1)) . '"/>';
        }

        return $html . '</g>';
    }

    private static function headHighlight(array $p, string $style): string
    {
        $opacity = $style === 'noir' ? '.1' : '.16';

        return '<path d="M77 108 C85 78 113 63 143 72 C121 76 99 88 86 115Z" fill="#fff" opacity="' . $opacity . '"/>';
    }

    private static function cheekFluff(array $p, bool $enabled, string $breed): string
    {
        if (!$enabled) {
            return '';
        }

        if (in_array($breed, ['british', 'persian'], true)) {
            return '<ellipse cx="72" cy="139" rx="22" ry="26" fill="' . self::e(self::lighten($p['fur'], 8)) . '" opacity=".5"/>'
                . '<ellipse cx="168" cy="139" rx="22" ry="26" fill="' . self::e(self::lighten($p['fur'], 8)) . '" opacity=".5"/>';
        }

        return '<path d="M58 130 L42 139 L61 146 L45 158 L70 157" fill="' . self::e(self::lighten($p['fur'], 8)) . '" opacity=".92"/>'
            . '<path d="M182 130 L198 139 L179 146 L195 158 L170 157" fill="' . self::e(self::lighten($p['fur'], 8)) . '" opacity=".92"/>';
    }

    private static function patternShapes(AvatarRng $rng, string $id, array $p, array $pattern, string $breed, string $style): string
    {
        $type = (string) ($pattern['type'] ?? 'none');
        $density = (int) ($pattern['density'] ?? 5);
        $color = self::darken($p['fur2'], $style === 'noir' ? 8 : 20);
        $html = '<g clip-path="url(#' . self::e($id) . '-head)" opacity="' . ($style === 'noir' ? '.48' : '.4') . '">';

        if ($type === 'stripes') {
            for ($i = 0; $i < $density; $i++) {
                $x = 70 + ($i * 14);
                $html .= '<path d="M' . $x . ' 66 C' . ($x - 9) . ' 91 ' . ($x + 13) . ' 103 ' . ($x - 4) . ' 128" fill="none" stroke="' . self::e($color) . '" stroke-width="' . $rng->int(3, 6) . '" stroke-linecap="round"/>';
            }
            $html .= '<path d="M120 68 L112 96 L120 89 L128 96Z" fill="' . self::e($color) . '" opacity=".58"/>';
        } elseif ($type === 'spots' || $type === 'patches') {
            for ($i = 0; $i < $density; $i++) {
                $html .= '<ellipse cx="' . $rng->int(64, 176) . '" cy="' . $rng->int(80, 151) . '" rx="' . $rng->int(7, 21) . '" ry="' . $rng->int(6, 18) . '" fill="' . self::e($i % 2 === 0 ? $color : $p['fur2']) . '" transform="rotate(' . $rng->int(-25, 25) . ' 120 120)"/>';
            }
        } elseif ($type === 'rosettes') {
            for ($i = 0; $i < $density; $i++) {
                $x = $rng->int(66, 174);
                $y = $rng->int(82, 148);
                $html .= '<ellipse cx="' . $x . '" cy="' . $y . '" rx="' . $rng->int(7, 12) . '" ry="' . $rng->int(5, 10) . '" fill="none" stroke="' . self::e($color) . '" stroke-width="3" opacity=".9"/>';
            }
        } elseif ($type === 'wrinkles') {
            for ($i = 0; $i < 6; $i++) {
                $y = 78 + ($i * 13);
                $html .= '<path d="M78 ' . $y . ' C101 ' . ($y - 7) . ' 139 ' . ($y + 7) . ' 162 ' . $y . '" fill="none" stroke="' . self::e($p['line']) . '" stroke-width="1.8" stroke-linecap="round"/>';
            }
        } elseif ($type === 'stars') {
            for ($i = 0; $i < $density + 2; $i++) {
                $html .= self::sparkle($rng->int(68, 172), $rng->int(70, 150), $rng->int(2, 6), self::lighten($p['accent'], 20), '.9');
            }
        } elseif ($type === 'hearts') {
            for ($i = 0; $i < $density; $i++) {
                $html .= self::heart($rng->int(70, 170), $rng->int(78, 150), $rng->int(3, 6), $rng->pick([$p['accent'], $p['accent2'], $p['blush']]), '.82');
            }
        } elseif ($type === 'shadow') {
            $html .= '<path d="M61 83 C102 61 143 63 180 91 C145 96 95 107 64 150Z" fill="#000" opacity=".28"/>'
                . '<path d="M82 74 L170 140" stroke="#fff" stroke-width="5" opacity=".1"/>';
        } elseif ($type === 'circuit') {
            for ($i = 0; $i < 5; $i++) {
                $y = 82 + ($i * 14);
                $html .= '<path d="M73 ' . $y . ' H106 V' . ($y + 9) . ' H137 V' . ($y - 5) . ' H166" fill="none" stroke="' . self::e($p['accent']) . '" stroke-width="2" stroke-linecap="round"/>';
            }
        } elseif ($type === 'freckles') {
            for ($i = 0; $i < 16; $i++) {
                $html .= '<circle cx="' . $rng->int(80, 160) . '" cy="' . $rng->int(124, 158) . '" r="' . $rng->int(1, 2) . '" fill="' . self::e($color) . '"/>';
            }
        }

        return $html . '</g>';
    }

    private static function faceMask(array $p, array $pattern, string $breed, string $style): string
    {
        $type = (string) ($pattern['type'] ?? '');

        if ($breed === 'siamese' || $type === 'mask') {
            return '<ellipse cx="120" cy="130" rx="43" ry="39" fill="' . self::e(self::darken($p['fur2'], 28)) . '" opacity="' . ($style === 'noir' ? '.38' : '.58') . '"/>';
        }

        if ($breed === 'tuxedo' || $type === 'bib') {
            return '<path d="M84 128 C100 153 140 153 156 128 C151 174 89 174 84 128Z" fill="' . self::e($p['white']) . '" opacity=".93"/>'
                . '<path d="M103 83 L120 112 L137 83 C132 107 127 119 120 130 C113 119 108 107 103 83Z" fill="' . self::e($p['white']) . '" opacity=".9"/>';
        }

        if ($breed === 'calico') {
            return '<path d="M78 82 C97 70 112 79 114 101 C102 110 84 108 72 96Z" fill="' . self::e(self::darken($p['fur2'], 16)) . '" opacity=".76"/>'
                . '<path d="M140 75 C162 78 176 94 170 119 C153 112 141 101 140 75Z" fill="' . self::e($p['fur2']) . '" opacity=".68"/>';
        }

        return '';
    }

    private static function ruff(array $p, string $breed): string
    {
        if (!in_array($breed, ['mainecoon', 'persian'], true)) {
            return '';
        }

        return '<path d="M66 150 L82 190 L100 162 L116 197 L132 162 L152 190 L174 150 C151 181 89 181 66 150Z" fill="' . self::e(self::lighten($p['fur'], 12)) . '" opacity=".9"/>';
    }

    private static function eyes(string $id, array $p, string $left, string $right, int $y, int $rx, int $ry, string $style, array $shape): string
    {
        $expression = (string) ($shape['expression'] ?? 'round');
        $turn = (int) ($shape['head_turn'] ?? 0);
        $gazeX = (int) ($shape['gaze_x'] ?? 0);
        $gazeY = (int) ($shape['gaze_y'] ?? 0);
        $faceShift = (int) round($turn * .45);
        $leftCx = 94 + $faceShift;
        $rightCx = 146 + $faceShift;

        if ($expression === 'closed') {
            return '<g fill="none" stroke="' . self::e($p['line']) . '" stroke-width="5" stroke-linecap="round">'
                . '<path d="M' . ($leftCx - 13) . ' ' . $y . ' C' . ($leftCx - 6) . ' ' . ($y - 10) . ' ' . ($leftCx + 8) . ' ' . ($y - 10) . ' ' . ($leftCx + 15) . ' ' . $y . '"/>'
                . '<path d="M' . ($rightCx - 15) . ' ' . $y . ' C' . ($rightCx - 8) . ' ' . ($y - 10) . ' ' . ($rightCx + 6) . ' ' . ($y - 10) . ' ' . ($rightCx + 13) . ' ' . $y . '"/>'
                . '<path d="M' . ($leftCx - 9) . ' ' . ($y + 7) . ' C' . $leftCx . ' ' . ($y + 12) . ' ' . ($leftCx + 9) . ' ' . ($y + 7) . '" stroke-width="1.8" opacity=".35"/>'
                . '<path d="M' . ($rightCx - 9) . ' ' . ($y + 7) . ' C' . $rightCx . ' ' . ($y + 12) . ' ' . ($rightCx + 9) . ' ' . ($y + 7) . '" stroke-width="1.8" opacity=".35"/>'
                . '</g>';
        }

        if ($expression === 'almond') {
            $ry = max(10, $ry - 3);
        }

        $line = $style === 'cyber' ? $p['accent'] : '#111827';
        $leftRx = max(9, $rx + ($turn < -3 ? 1 : ($turn > 3 ? -1 : 0)));
        $rightRx = max(9, $rx + ($turn > 3 ? 1 : ($turn < -3 ? -1 : 0)));
        $leftRy = max(9, $ry + ($expression === 'round' ? 0 : -1));
        $rightRy = max(9, $ry + ($expression === 'round' ? 0 : -1));
        $pupil = $style === 'barbie' ? 4 : 3;
        $glow = $style === 'cyber' || $style === 'cosmic' ? ' filter="url(#' . self::e($id) . '-glow)"' : '';
        $leftPupilX = $leftCx + $gazeX;
        $rightPupilX = $rightCx + $gazeX;
        $pupilY = $y + $gazeY;

        return '<g>'
            . '<ellipse cx="' . $leftCx . '" cy="' . $y . '" rx="' . ($leftRx + 4) . '" ry="' . ($leftRy + 3) . '" fill="' . self::e($line) . '"/>'
            . '<ellipse cx="' . $rightCx . '" cy="' . $y . '" rx="' . ($rightRx + 4) . '" ry="' . ($rightRy + 3) . '" fill="' . self::e($line) . '"/>'
            . '<ellipse cx="' . $leftCx . '" cy="' . $y . '" rx="' . $leftRx . '" ry="' . $leftRy . '" fill="' . self::e($left) . '"' . $glow . '/>'
            . '<ellipse cx="' . $rightCx . '" cy="' . $y . '" rx="' . $rightRx . '" ry="' . $rightRy . '" fill="' . self::e($right) . '"' . $glow . '/>'
            . '<ellipse cx="' . $leftPupilX . '" cy="' . $pupilY . '" rx="' . $pupil . '" ry="' . ($style === 'noir' ? 8 : 10) . '" fill="#111827"/>'
            . '<ellipse cx="' . $rightPupilX . '" cy="' . $pupilY . '" rx="' . $pupil . '" ry="' . ($style === 'noir' ? 8 : 10) . '" fill="#111827"/>'
            . '<ellipse cx="' . ($leftPupilX + 4) . '" cy="' . ($pupilY - 5) . '" rx="3" ry="5" fill="#fff" opacity=".92"/>'
            . '<ellipse cx="' . ($rightPupilX + 4) . '" cy="' . ($pupilY - 5) . '" rx="3" ry="5" fill="#fff" opacity=".92"/>'
            . '<path d="M' . ($leftCx - $leftRx - 5) . ' ' . ($y - 1) . ' C' . ($leftCx - 6) . ' ' . ($y - $leftRy - 7) . ' ' . ($leftCx + 7) . ' ' . ($y - $leftRy - 7) . ' ' . ($leftCx + $leftRx + 5) . ' ' . ($y - 1) . '" fill="none" stroke="' . self::e($p['line']) . '" stroke-width="2.2" stroke-linecap="round" opacity=".72"/>'
            . '<path d="M' . ($rightCx - $rightRx - 5) . ' ' . ($y - 1) . ' C' . ($rightCx - 7) . ' ' . ($y - $rightRy - 7) . ' ' . ($rightCx + 6) . ' ' . ($y - $rightRy - 7) . ' ' . ($rightCx + $rightRx + 5) . ' ' . ($y - 1) . '" fill="none" stroke="' . self::e($p['line']) . '" stroke-width="2.2" stroke-linecap="round" opacity=".72"/>'
            . '<path d="M' . ($leftCx - $leftRx + 1) . ' ' . ($y + $leftRy + 1) . ' C' . $leftCx . ' ' . ($y + $leftRy + 5) . ' ' . ($leftCx + $leftRx - 1) . ' ' . ($y + $leftRy + 1) . '" fill="none" stroke="' . self::e($p['line']) . '" stroke-width="1.35" stroke-linecap="round" opacity=".35"/>'
            . '<path d="M' . ($rightCx - $rightRx + 1) . ' ' . ($y + $rightRy + 1) . ' C' . $rightCx . ' ' . ($y + $rightRy + 5) . ' ' . ($rightCx + $rightRx - 1) . ' ' . ($y + $rightRy + 1) . '" fill="none" stroke="' . self::e($p['line']) . '" stroke-width="1.35" stroke-linecap="round" opacity=".35"/>'
            . '</g>';
    }

    private static function browsAndLashes(array $p, string $style, int $eyeY, array $shape): string
    {
        $stroke = $style === 'barbie' ? self::darken($p['accent'], 14) : $p['line'];
        $faceShift = (int) round(((int) ($shape['head_turn'] ?? 0)) * .45);
        $html = '<g fill="none" stroke="' . self::e($stroke) . '" stroke-width="' . ($style === 'noir' ? '5' : '3.5') . '" stroke-linecap="round" opacity=".72">'
            . '<path d="M' . (80 + $faceShift) . ' ' . ($eyeY - 21) . ' C' . (90 + $faceShift) . ' ' . ($eyeY - 30) . ' ' . (102 + $faceShift) . ' ' . ($eyeY - 30) . ' ' . (109 + $faceShift) . ' ' . ($eyeY - 20) . '"/>'
            . '<path d="M' . (131 + $faceShift) . ' ' . ($eyeY - 20) . ' C' . (139 + $faceShift) . ' ' . ($eyeY - 30) . ' ' . (151 + $faceShift) . ' ' . ($eyeY - 30) . ' ' . (160 + $faceShift) . ' ' . ($eyeY - 21) . '"/>'
            . '</g>';

        if ($style === 'barbie') {
            $html .= '<g stroke="' . self::e($stroke) . '" stroke-width="2" stroke-linecap="round"><path d="M' . (82 + $faceShift) . ' 104 L' . (75 + $faceShift) . ' 98"/><path d="M' . (91 + $faceShift) . ' 99 L' . (88 + $faceShift) . ' 91"/><path d="M' . (158 + $faceShift) . ' 104 L' . (165 + $faceShift) . ' 98"/><path d="M' . (149 + $faceShift) . ' 99 L' . (152 + $faceShift) . ' 91"/></g>';
        }

        return $html;
    }

    private static function muzzle(array $p, int $size, string $style, array $shape): string
    {
        $whiteOpacity = $style === 'noir' ? '.75' : '.84';
        $turn = (int) ($shape['head_turn'] ?? 0);
        $shift = (int) round($turn * .5);
        $leftSize = max(18, $size + ($turn < -3 ? 1 : ($turn > 3 ? -2 : 0)));
        $rightSize = max(18, $size + ($turn > 3 ? 1 : ($turn < -3 ? -2 : 0)));
        $noseX = 120 + $shift;

        return '<g>'
            . '<ellipse cx="' . (105 + $shift) . '" cy="146" rx="' . $leftSize . '" ry="23" fill="' . self::e($p['white']) . '" stroke="' . self::e($p['line']) . '" stroke-width="1.4" opacity="' . $whiteOpacity . '"/>'
            . '<ellipse cx="' . (135 + $shift) . '" cy="146" rx="' . $rightSize . '" ry="23" fill="' . self::e($p['white']) . '" stroke="' . self::e($p['line']) . '" stroke-width="1.4" opacity="' . $whiteOpacity . '"/>'
            . '<path d="M' . ($noseX - 8) . ' 137 Q' . $noseX . ' 130 ' . ($noseX + 8) . ' 137 Q' . ($noseX + 6) . ' 146 ' . $noseX . ' 150 Q' . ($noseX - 6) . ' 146 ' . ($noseX - 8) . ' 137Z" fill="' . self::e($p['nose']) . '" stroke="' . self::e($p['line']) . '" stroke-width="1.5" stroke-linejoin="round"/>'
            . '<ellipse cx="' . ($noseX + 3) . '" cy="136" rx="3" ry="2" fill="#fff" opacity=".45"/>'
            . '<circle cx="' . ($noseX - 3) . '" cy="141" r="1.1" fill="' . self::e(self::darken($p['nose'], 28)) . '" opacity=".65"/>'
            . '<circle cx="' . ($noseX + 3) . '" cy="141" r="1.1" fill="' . self::e(self::darken($p['nose'], 28)) . '" opacity=".65"/>'
            . '<path d="M' . $noseX . ' 150 L' . $noseX . ' 160" stroke="' . self::e($p['line']) . '" stroke-width="3" stroke-linecap="round"/>'
            . '<path d="M' . $noseX . ' 160 C' . ($noseX - 8) . ' 170 ' . ($noseX - 19) . ' 163 ' . ($noseX - 21) . ' 155" fill="none" stroke="' . self::e($p['line']) . '" stroke-width="3" stroke-linecap="round"/>'
            . '<path d="M' . $noseX . ' 160 C' . ($noseX + 8) . ' 170 ' . ($noseX + 19) . ' 163 ' . ($noseX + 21) . ' 155" fill="none" stroke="' . self::e($p['line']) . '" stroke-width="3" stroke-linecap="round"/>'
            . '<path d="M' . ($noseX - 8) . ' 171 C' . ($noseX - 3) . ' 175 ' . ($noseX + 3) . ' 175 ' . ($noseX + 8) . ' 171" fill="none" stroke="' . self::e($p['nose']) . '" stroke-width="2" stroke-linecap="round" opacity=".62"/>'
            . '<circle cx="' . (91 + $shift) . '" cy="140" r="8" fill="' . self::e($p['blush']) . '" opacity=".18"/>'
            . '<circle cx="' . (149 + $shift) . '" cy="140" r="8" fill="' . self::e($p['blush']) . '" opacity=".18"/>'
            . '</g>';
    }

    private static function whiskerDots(array $p, array $shape): string
    {
        $shift = (int) round(((int) ($shape['head_turn'] ?? 0)) * .5);

        return '<g fill="' . self::e(self::darken($p['line'], 5)) . '" opacity=".55">'
            . '<circle cx="' . (99 + $shift) . '" cy="144" r="1.7"/><circle cx="' . (104 + $shift) . '" cy="151" r="1.5"/><circle cx="' . (96 + $shift) . '" cy="154" r="1.4"/>'
            . '<circle cx="' . (141 + $shift) . '" cy="144" r="1.7"/><circle cx="' . (136 + $shift) . '" cy="151" r="1.5"/><circle cx="' . (144 + $shift) . '" cy="154" r="1.4"/>'
            . '</g>';
    }

    private static function whiskers(array $p, string $style, array $shape): string
    {
        $opacity = $style === 'noir' ? '.82' : '.68';
        $tilt = (int) ($shape['whisker_tilt'] ?? 0);
        $curve = (int) ($shape['whisker_curve'] ?? 0);
        $shift = (int) round(((int) ($shape['head_turn'] ?? 0)) * .5);
        $leftReach = (int) ($shape['head_turn'] ?? 0) < -2 ? -6 : 0;
        $rightReach = (int) ($shape['head_turn'] ?? 0) > 2 ? 6 : 0;
        $left = [
            [92 + $shift, 145, 43 + $shift + $leftReach, 136 + $tilt, 64 + $shift, 132 + $tilt + $curve],
            [92 + $shift, 154, 42 + $shift + $leftReach, 156 + (int) ($tilt / 2), 62 + $shift, 154 + $curve],
            [94 + $shift, 163, 51 + $shift + $leftReach, 178 + $tilt, 67 + $shift, 172 + $tilt + $curve],
        ];
        $right = [
            [148 + $shift, 145, 197 + $shift + $rightReach, 136 + $tilt, 176 + $shift, 132 + $tilt + $curve],
            [148 + $shift, 154, 198 + $shift + $rightReach, 156 + (int) ($tilt / 2), 178 + $shift, 154 + $curve],
            [146 + $shift, 163, 189 + $shift + $rightReach, 178 + $tilt, 173 + $shift, 172 + $tilt + $curve],
        ];
        $html = '<g stroke="' . self::e($p['line']) . '" stroke-width="2.4" stroke-linecap="round" opacity="' . $opacity . '">';

        foreach ($left as $w) {
            [$x1, $y1, $x2, $y2, $cx, $cy] = $w;
            $html .= '<path d="M' . $x1 . ' ' . $y1 . ' Q' . $cx . ' ' . $cy . ' ' . $x2 . ' ' . $y2 . '"/>';
        }

        foreach ($right as $w) {
            [$x1, $y1, $x2, $y2, $cx, $cy] = $w;
            $html .= '<path d="M' . $x1 . ' ' . $y1 . ' Q' . $cx . ' ' . $cy . ' ' . $x2 . ' ' . $y2 . '"/>';
        }

        return $html . '</g>';
    }

    private static function featureShapes(string $id, array $p, string $feature, string $rarity, string $style): string
    {
        return match ($feature) {
            'collar' => '<path d="M78 181 C99 198 141 198 162 181" fill="none" stroke="url(#' . self::e($id) . '-accent)" stroke-width="9" stroke-linecap="round"/><circle cx="120" cy="191" r="6" fill="' . self::e($p['metal']) . '"/>',
            'glasses' => '<g fill="none" stroke="' . self::e($style === 'noir' ? '#f8fafc' : '#111827') . '" stroke-width="4"><circle cx="94" cy="114" r="20"/><circle cx="146" cy="114" r="20"/><path d="M114 114 L126 114"/></g>',
            'star' => self::sparkle(120, 80, 10, self::lighten($p['accent'], 25), '.95'),
            'moon' => '<path d="M154 72 C140 78 139 99 154 106 C132 108 121 84 137 68 C142 64 149 64 154 72Z" fill="' . self::e(self::lighten($p['accent'], 28)) . '"/>',
            'halo' => '<ellipse cx="120" cy="61" rx="44" ry="12" fill="none" stroke="' . self::e(self::lighten($p['accent'], 30)) . '" stroke-width="6" opacity=".9"/>',
            'crown' => '<path d="M89 73 L101 48 L119 70 L138 48 L151 73 Z" fill="' . self::e($p['metal']) . '" stroke="' . self::e(self::darken($p['metal'], 22)) . '" stroke-width="3" stroke-linejoin="round"/><circle cx="119" cy="64" r="4" fill="' . self::e($p['accent']) . '"/>',
            'constellation' => '<g stroke="' . self::e(self::lighten($p['accent'], 25)) . '" fill="' . self::e(self::lighten($p['accent2'], 30)) . '" stroke-width="2"><path d="M76 78 L96 66 L113 82 L139 64 L163 81" fill="none"/><circle cx="76" cy="78" r="3"/><circle cx="96" cy="66" r="3"/><circle cx="113" cy="82" r="3"/><circle cx="139" cy="64" r="3"/><circle cx="163" cy="81" r="3"/></g>',
            'bow' => '<g><path d="M113 77 C92 61 83 70 84 88 C92 92 102 89 113 82Z" fill="' . self::e($p['accent']) . '"/><path d="M127 77 C148 61 157 70 156 88 C148 92 138 89 127 82Z" fill="' . self::e($p['accent2']) . '"/><circle cx="120" cy="81" r="7" fill="' . self::e($p['metal']) . '"/></g>',
            'tiara' => '<g><path d="M91 78 L104 62 L118 77 L133 62 L149 78" fill="none" stroke="' . self::e($p['metal']) . '" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/><circle cx="104" cy="62" r="4" fill="' . self::e($p['accent']) . '"/><circle cx="133" cy="62" r="4" fill="' . self::e($p['accent2']) . '"/></g>',
            'pearls' => self::pearls($p),
            'detective_hat' => '<g><path d="M72 82 C91 61 147 58 169 82 C139 91 102 91 72 82Z" fill="#111827"/><path d="M61 88 C92 98 151 98 181 88" fill="none" stroke="#030712" stroke-width="10" stroke-linecap="round"/><path d="M92 76 C111 69 136 69 151 77" fill="none" stroke="' . self::e($p['accent']) . '" stroke-width="4" opacity=".8"/></g>',
            'visor' => '<g><path d="M76 111 C94 100 146 100 164 111 L155 127 C135 120 105 120 85 127Z" fill="' . self::e(self::darken($p['accent'], 8)) . '" opacity=".82"/><path d="M84 115 H156" stroke="' . self::e(self::lighten($p['accent2'], 20)) . '" stroke-width="3" opacity=".9"/></g>',
            default => '',
        };
    }

    private static function pearls(array $p): string
    {
        $html = '<g fill="#fff" stroke="' . self::e($p['metal']) . '" stroke-width="1.2">';

        for ($i = 0; $i < 9; $i++) {
            $x = 86 + ($i * 8);
            $y = 184 + (int) abs($i - 4);
            $html .= '<circle cx="' . $x . '" cy="' . $y . '" r="4"/>';
        }

        return $html . '</g>';
    }

    private static function frameDetails(AvatarRng $rng, array $p, string $style, string $rarity): string
    {
        $html = '<g pointer-events="none">';

        if (in_array($rarity, ['epic', 'legendary'], true)) {
            $html .= '<rect x="5" y="5" width="230" height="230" rx="42" fill="none" stroke="' . self::e($p['accent']) . '" stroke-width="2" opacity=".5"/>';
        }

        if ($style === 'noir') {
            $html .= '<rect x="7" y="7" width="226" height="226" rx="30" fill="none" stroke="#fff" stroke-width="1" opacity=".16"/>';
        } elseif ($style === 'barbie') {
            $html .= self::sparkle(34, 35, 8, '#fff', '.82') . self::heart(207, 204, 6, $p['accent'], '.65');
        } elseif ($style === 'cyber') {
            $html .= '<path d="M18 52 V18 H54" fill="none" stroke="' . self::e($p['accent']) . '" stroke-width="3" opacity=".55"/><path d="M222 188 V222 H186" fill="none" stroke="' . self::e($p['accent2']) . '" stroke-width="3" opacity=".55"/>';
        }

        return $html . '</g>';
    }

    private static function sparkle(int $x, int $y, int $r, string $color, string $opacity = '1'): string
    {
        return '<path d="M' . $x . ' ' . ($y - $r) . ' L' . ($x + (int) ($r * .28)) . ' ' . ($y - (int) ($r * .28)) . ' L' . ($x + $r) . ' ' . $y . ' L' . ($x + (int) ($r * .28)) . ' ' . ($y + (int) ($r * .28)) . ' L' . $x . ' ' . ($y + $r) . ' L' . ($x - (int) ($r * .28)) . ' ' . ($y + (int) ($r * .28)) . ' L' . ($x - $r) . ' ' . $y . ' L' . ($x - (int) ($r * .28)) . ' ' . ($y - (int) ($r * .28)) . ' Z" fill="' . self::e($color) . '" opacity="' . self::e($opacity) . '"/>';
    }

    private static function heart(int $x, int $y, int $r, string $color, string $opacity = '1'): string
    {
        return '<path d="M' . $x . ' ' . ($y + $r) . ' C' . ($x - $r * 2) . ' ' . ($y - (int) ($r * .5)) . ' ' . ($x - $r) . ' ' . ($y - $r * 2) . ' ' . $x . ' ' . ($y - (int) ($r * .7)) . ' C' . ($x + $r) . ' ' . ($y - $r * 2) . ' ' . ($x + $r * 2) . ' ' . ($y - (int) ($r * .5)) . ' ' . $x . ' ' . ($y + $r) . 'Z" fill="' . self::e($color) . '" opacity="' . self::e($opacity) . '"/>';
    }

    private static function weighted(AvatarRng $rng, array $weights): string
    {
        $total = max(1, array_sum(array_map('intval', $weights)));
        $roll = $rng->int(1, $total);

        foreach ($weights as $value => $weight) {
            $roll -= (int) $weight;

            if ($roll <= 0) {
                return (string) $value;
            }
        }

        return (string) array_key_first($weights);
    }

    private static function lighten(string $hex, int $amount): string
    {
        return self::shiftColor($hex, abs($amount));
    }

    private static function darken(string $hex, int $amount): string
    {
        return self::shiftColor($hex, -abs($amount));
    }

    private static function shiftColor(string $hex, int $amount): string
    {
        $hex = ltrim($hex, '#');

        if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            return '#000000';
        }

        $parts = [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];

        foreach ($parts as $index => $value) {
            $parts[$index] = max(0, min(255, $value + $amount));
        }

        return sprintf('#%02x%02x%02x', $parts[0], $parts[1], $parts[2]);
    }

    private static function luminance(string $hex): float
    {
        [$r, $g, $b] = self::rgb($hex);
        $r /= 255;
        $g /= 255;
        $b /= 255;

        $r = $r <= 0.03928 ? $r / 12.92 : (($r + 0.055) / 1.055) ** 2.4;
        $g = $g <= 0.03928 ? $g / 12.92 : (($g + 0.055) / 1.055) ** 2.4;
        $b = $b <= 0.03928 ? $b / 12.92 : (($b + 0.055) / 1.055) ** 2.4;

        return (0.2126 * $r) + (0.7152 * $g) + (0.0722 * $b);
    }

    private static function colorDistance(string $a, string $b): float
    {
        [$ar, $ag, $ab] = self::rgb($a);
        [$br, $bg, $bb] = self::rgb($b);

        return sqrt((($ar - $br) ** 2) + (($ag - $bg) ** 2) + (($ab - $bb) ** 2));
    }

    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            return [0, 0, 0];
        }

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

final class AvatarRng
{
    private string $bytes;
    private int $offset = 0;

    public function __construct(string $seed)
    {
        $this->bytes = hash('sha512', 'tinycat-avatar-v2|' . strtolower($seed), true);
    }

    public function int(int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }

        $value = $this->nextInt();

        return $min + ($value % ($max - $min + 1));
    }

    public function chance(int $percent): bool
    {
        return $this->int(1, 100) <= max(0, min(100, $percent));
    }

    public function pick(array $items): mixed
    {
        if ($items === []) {
            return null;
        }

        return $items[$this->int(0, count($items) - 1)];
    }

    private function nextInt(): int
    {
        if ($this->offset + 4 > strlen($this->bytes)) {
            $this->bytes .= hash('sha512', $this->bytes, true);
        }

        $chunk = substr($this->bytes, $this->offset, 4);
        $this->offset += 4;
        $data = unpack('Nvalue', $chunk);

        return (int) ($data['value'] ?? 0);
    }
}
