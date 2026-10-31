<?php
declare(strict_types=1);

final class Igdb
{
    private string $directory;
    public function __construct(private array $config)
    {
        $this->directory = dirname(__DIR__, 2) . '/var/igdb';
    }

    private function read(string $file, int $ttl): ?array
    {
        if (!is_file($file) || filemtime($file) <= time() - $ttl) { return null; }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    private function write(string $file, array $data): void
    {
        if (!is_dir($this->directory)) { mkdir($this->directory, 0700, true); }
        $temporary = tempnam($this->directory, 'igdb-');
        if ($temporary === false) { throw new RuntimeException('Nelze zapisovat IGDB cache.'); }
        chmod($temporary, 0600);
        file_put_contents($temporary, json_encode($data, JSON_THROW_ON_ERROR), LOCK_EX);
        if (!@rename($temporary, $file)) { @unlink($temporary); }
    }

    private function post(string $url, string $body, array $headers): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15]);
        $response = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($response === false) { throw new RuntimeException('IGDB/Twitch není dostupné.'); }
        $data = json_decode($response, true);
        return [$status, is_array($data) ? $data : null];
    }

    private function token(bool $renew = false): string
    {
        $file = $this->directory . '/oauth-' . hash('sha256', $this->config['igdb_client_id'] . $this->config['igdb_client_secret']) . '.json';
        $cached = $this->read($file, PHP_INT_MAX);
        if (!$renew && ($cached['expires_at'] ?? 0) > time() + 60) { return $cached['access_token']; }
        [$status, $data] = $this->post('https://id.twitch.tv/oauth2/token', http_build_query([
            'client_id' => $this->config['igdb_client_id'], 'client_secret' => $this->config['igdb_client_secret'],
            'grant_type' => 'client_credentials',
        ]), ['Content-Type: application/x-www-form-urlencoded']);
        if ($status !== 200 || empty($data['access_token']) || empty($data['expires_in'])) {
            throw new RuntimeException('Twitch přihlášení selhalo (HTTP ' . $status . '). Zkontrolujte IGDB Client ID a Secret.');
        }
        $this->write($file, ['access_token' => $data['access_token'], 'expires_at' => time() + (int)$data['expires_in']]);
        return $data['access_token'];
    }

    public function query(string $endpoint, string $query): array
    {
        if (empty($this->config['igdb_client_id']) || empty($this->config['igdb_client_secret'])) {
            throw new RuntimeException('Doplňte igdb_client_id a igdb_client_secret do config.php.');
        }
        $file = $this->directory . '/' . hash('sha256', $endpoint . $query) . '.json';
        $cached = $this->read($file, (int)($this->config['cache_seconds'] ?? 1800));
        if ($cached !== null) { return $cached; }
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $token = $this->token($attempt === 1);
            [$status, $data] = $this->post('https://api.igdb.com/v4/' . $endpoint, $query, [
                'Client-ID: ' . $this->config['igdb_client_id'], 'Authorization: Bearer ' . $token,
                'Content-Type: text/plain', 'Accept: application/json',
            ]);
            if ($status === 401 && $attempt === 0) { continue; }
            if ($status !== 200 || !is_array($data) || !array_is_list($data)) {
                throw new RuntimeException('IGDB API odmítlo požadavek (HTTP ' . $status . ').');
            }
            $this->write($file, $data);
            return $data;
        }
        throw new RuntimeException('IGDB přihlášení selhalo.');
    }
}
