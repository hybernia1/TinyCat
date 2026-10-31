<?php
declare(strict_types=1);
require_once __DIR__ . '/Igdb.php';

return [
    'themes' => static fn(array $config): array => ['type' => 'json', 'data' =>
        (new Igdb($config))->query('themes', 'fields name,slug; sort name asc; limit 500;')],
    'genres' => static fn(array $config): array => ['type' => 'json', 'data' =>
        (new Igdb($config))->query('genres', 'fields name,slug; sort name asc; limit 500;')],
    'platforms' => static fn(array $config): array => ['type' => 'json', 'data' =>
        (new Igdb($config))->query('platforms', 'fields name,abbreviation; sort name asc; limit 500;')],
    'upcoming' => static function (array $config): array {
        $language = parameter('language', 'en-US');
        // IGDB summaries are English; do not imply translated content.
        if (!in_array($language, ['en', 'en-US', 'en-GB'], true)) {
            throw new InvalidArgumentException('IGDB feed podporuje language=en-US (anglická data).');
        }
        $genre = parameter('genre', parameter('id', ''));
        $theme = parameter('theme', '');
        $platform = parameter('platform', '');
        $days = parameter('days', '90');
        $page = parameter('page', '1');
        foreach ([$genre, $platform, $theme] as $filter) {
            if ($filter !== '' && !preg_match('/^[1-9][0-9]{0,8}(?:,[1-9][0-9]{0,8})*$/D', $filter)) {
                throw new InvalidArgumentException('Neplatný genre/id, theme nebo platform (číselná ID oddělená čárkou).');
            }
        }
        if (!ctype_digit($days) || (int)$days < 1 || (int)$days > 365
            || !ctype_digit($page) || (int)$page < 1 || (int)$page > 500) {
            throw new InvalidArgumentException('Neplatný days (1–365) nebo page (1–500).');
        }
        $start = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $end = $start->modify('+' . (int)$days . ' days');
        $where = 'first_release_date >= ' . $start->getTimestamp() . ' & first_release_date < ' . $end->getTimestamp();
        if ($genre !== '') { $where .= ' & genres = (' . $genre . ')'; }
        if ($platform !== '') { $where .= ' & platforms = (' . $platform . ')'; }
        if ($theme !== '') { $where .= ' & themes = (' . $theme . ')'; }
        $query = 'fields name,summary,url,first_release_date,cover.image_id,genres.name,platforms.name,videos.name,videos.video_id; '
            . 'where ' . $where . '; sort first_release_date asc; limit 20; offset ' . (((int)$page - 1) * 20) . ';';
        $games = (new Igdb($config))->query('games', $query);
        $items = [];
        foreach ($games as $game) {
            $genres = array_column($game['genres'] ?? [], 'name');
            $platforms = array_column($game['platforms'] ?? [], 'name');
            $videos = array_values(array_filter($game['videos'] ?? [], static fn(array $video): bool =>
                preg_match('/^[A-Za-z0-9_-]{11}$/D', $video['video_id'] ?? '') === 1));
            usort($videos, static fn(array $a, array $b): int =>
                (int)str_contains(strtolower($b['name'] ?? ''), 'trailer') <=> (int)str_contains(strtolower($a['name'] ?? ''), 'trailer'));
            $url = $game['url'] ?? '';
            if (!preg_match('~^https://www\.igdb\.com/games/[a-zA-Z0-9_-]+$~D', $url)) { $url = 'https://www.igdb.com/'; }
            if ($videos !== []) { $url = 'https://www.youtube.com/watch?v=' . $videos[0]['video_id']; }
            $cover = $game['cover']['image_id'] ?? '';
            $items[] = ['id' => 'igdb:game:' . (int)$game['id'], 'title' => $game['name'] ?? '',
                'description' => ($game['summary'] ?? '') . "\nRelease date: " . gmdate('Y-m-d', (int)$game['first_release_date'])
                    . "\nGenres: " . implode(', ', $genres) . "\nPlatforms: " . implode(', ', $platforms),
                'url' => $url, 'categories' => $genres,
                'image_url' => preg_match('/^[A-Za-z0-9_-]+$/D', $cover) ? 'https://images.igdb.com/igdb/image/upload/t_cover_big/' . $cover . '.jpg' : '',
            ];
        }
        return ['type' => 'rss', 'data' => ['title' => 'IGDB – Upcoming games', 'url' => 'https://www.igdb.com/',
            'description' => 'Upcoming game releases. Data provided by IGDB.', 'language' => $language, 'items' => $items]];
    },
];
