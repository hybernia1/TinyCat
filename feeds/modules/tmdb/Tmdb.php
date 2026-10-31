<?php
declare(strict_types=1);

final class Tmdb
{
    public function __construct(private array $config) {}

    public function get(string $path, array $query): array
    {
        $token = trim($this->config['tmdb_token'] ?? '');
        $key = trim($this->config['tmdb_api_key'] ?? '');
        if ($token === '' && $key === '') {
            throw new RuntimeException('Doplňte tmdb_token nebo tmdb_api_key do config.php.');
        }
        $cache = dirname(__DIR__, 2) . '/var/tmdb/' . hash('sha256', $path . json_encode($query)) . '.json';
        if (is_file($cache) && filemtime($cache) > time() - ($this->config['cache_seconds'] ?? 1800)) {
            $data = json_decode((string) file_get_contents($cache), true);
            if (is_array($data)) { return $data; }
        }
        if ($token === '') { $query['api_key'] = $key; }
        $url = 'https://api.themoviedb.org/3/' . $path . '?' . http_build_query($query);
        $headers = ['Accept: application/json'];
        if ($token !== '') { $headers[] = 'Authorization: Bearer ' . $token; }
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15]);
        $body = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($body === false || $status !== 200) {
            throw new RuntimeException('TMDB API není dostupné nebo odmítlo požadavek (HTTP ' . $status . ').');
        }
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) { throw new RuntimeException('Neplatná odpověď TMDB.'); }
        if (!is_dir(dirname($cache))) { mkdir(dirname($cache), 0700, true); }
        // Atomic replacement prevents concurrent readers from seeing partial JSON.
        $temporary = tempnam(dirname($cache), 'tmdb-');
        if ($temporary !== false) {
            file_put_contents($temporary, $body, LOCK_EX);
            if (!@rename($temporary, $cache)) { @unlink($temporary); }
        }
        return $data;
    }

    public function trailer(int $id, string $language): ?string
    {
        foreach (array_unique([$language, 'en-US']) as $lang) {
            $videos = $this->get("movie/$id/videos", ['language' => $lang])['results'] ?? [];
            $videos = array_values(array_filter($videos, static fn(array $v): bool =>
                ($v['site'] ?? '') === 'YouTube' && in_array($v['type'] ?? '', ['Trailer', 'Teaser'], true)
                && preg_match('/^[A-Za-z0-9_-]{11}$/', $v['key'] ?? '') === 1));
            usort($videos, static fn(array $a, array $b): int =>
                [($b['type'] === 'Trailer'), ($b['official'] ?? false)] <=> [($a['type'] === 'Trailer'), ($a['official'] ?? false)]);
            if ($videos !== []) { return 'https://www.youtube.com/watch?v=' . $videos[0]['key']; }
        }
        return null;
    }
}
