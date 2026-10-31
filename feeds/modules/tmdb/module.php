<?php
declare(strict_types=1);
require_once __DIR__ . '/Tmdb.php';

$handle = static function (string $resource, array $config): array {
    $language = parameter('language', ($config['language'] ?? 'cs-CZ'));
    $region = parameter('region', ($config['region'] ?? 'CZ'));
    $genre = parameter('genre', parameter('id', ''));
    $page = parameter('page', '1');
    $days = parameter('days', '90');
    if (!preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $language)
        || !preg_match('/^[A-Z]{2}$/', $region)
        || ($genre !== '' && !preg_match('/^[1-9][0-9]{0,5}(?:[,|][1-9][0-9]{0,5})*$/', $genre))
        || !ctype_digit($page) || (int)$page < 1 || (int)$page > 500
        || !ctype_digit($days) || (int)$days < 1 || (int)$days > 365) {
        throw new InvalidArgumentException('Neplatné parametry language, region, genre/id, page nebo days.');
    }
    $api = new Tmdb($config);
    $genres = $api->get('genre/movie/list', ['language' => $language])['genres'] ?? [];
    if ($resource === 'genres') { return ['type' => 'json', 'data' => $genres]; }
    $names = array_column($genres, 'name', 'id');
    $translations = [
        'cs' => ['release' => 'Premiéra', 'genres' => 'Žánry', 'upcoming' => 'Chystané filmy'],
        'en' => ['release' => 'Release date', 'genres' => 'Genres', 'upcoming' => 'Upcoming movies'],
    ];
    $strings = $translations[substr($language, 0, 2)] ?? $translations['en'];
    $today = new DateTimeImmutable('today', new DateTimeZone('Europe/Prague'));
    $query = ['language' => $language, 'region' => $region, 'page' => (int)$page,
        'include_adult' => 'false', 'include_video' => 'false', 'sort_by' => 'popularity.desc',
        'with_release_type' => '2|3', 'release_date.gte' => $today->format('Y-m-d'),
        'release_date.lte' => $today->modify('+' . (int)$days . ' days')->format('Y-m-d')];
    if ($genre !== '') { $query['with_genres'] = $genre; }
    $movies = $api->get('discover/movie', $query)['results'] ?? [];
    $items = [];
    foreach ($movies as $movie) {
        $id = (int)$movie['id'];
        $url = 'https://www.themoviedb.org/movie/' . $id;
        $trailer = $api->trailer($id, $language);
        $labels = array_filter(array_map(static fn($id) => $names[$id] ?? '', $movie['genre_ids'] ?? []));
        $text = ($movie['overview'] ?? '') . "\n\n" . $strings['release'] . ': ' . ($movie['release_date'] ?? '')
            . "\n" . $strings['genres'] . ': ' . implode(', ', $labels);
        $image = '';
        if (!empty($movie['poster_path']) && preg_match('~^/[A-Za-z0-9._-]+$~', $movie['poster_path'])) {
            $image = 'https://image.tmdb.org/t/p/w500' . $movie['poster_path'];
        }
        // Stable GUID across polls. A release date is not a publication timestamp.
        $items[] = ['id' => 'tmdb:movie:' . $id, 'title' => $movie['title'] ?? '',
            'url' => $trailer ?? $url, 'description' => $text, 'image_url' => $image,
            'categories' => $labels];
    }
    return ['type' => 'rss', 'data' => [
        'title' => 'TMDB – ' . $strings['upcoming'] . ' (' . $language . ' / ' . $region . ')',
        'url' => 'https://www.themoviedb.org/', 'language' => $language,
        'description' => 'Upcoming theatrical releases. This product uses the TMDB API but is not endorsed or certified by TMDB.',
        'items' => $items,
    ]];
};
return [
    'upcoming' => static fn(array $config): array => $handle('upcoming', $config),
    'genres' => static fn(array $config): array => $handle('genres', $config),
];
