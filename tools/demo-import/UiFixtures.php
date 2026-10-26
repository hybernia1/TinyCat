<?php
declare(strict_types=1);
namespace TinyCat\Tools\DemoImport;

use PDO;
use RuntimeException;

/** Deterministic, offline visual fixtures; safe to rerun after a resumed batch. */
final readonly class UiFixtures
{
    public function __construct(private PDO $database, private string $root, private string $prefix, private string $anchor) {}

    /** @return array<string, int> */
    public function run(): array
    {
        $users = $this->database->query('SELECT id FROM users ORDER BY id LIMIT 12')->fetchAll(PDO::FETCH_COLUMN);
        $avatars = 0;
        foreach ($users as $id) {
            if ((int) $id % 2 === 0) { continue; }
            $this->image('uploads/avatars/' . (int) $id . '.webp', 200, 200, (int) $id);
            $this->execute('UPDATE users SET avatar_exists = 1 WHERE id = ?', [(int) $id]);
            $avatars++;
        }
        $this->execute("UPDATE users SET status = 'waiting' WHERE id = 4 AND role = 'user'", []);
        $this->execute("UPDATE users SET status = 'ban' WHERE id = 6 AND role = 'user'", []);
        for ($i = 1; $i <= 4; $i++) {
            $this->post(1, 'Demo UI ' . $i . ': Příspěvek pro kontrolu vzhledu, komentářů a obrázků. #tinycat #testing');
        }
        $images = $this->database->query('SELECT id FROM content ORDER BY published_at DESC, id DESC LIMIT 18')->fetchAll(PDO::FETCH_COLUMN);
        $imageCount = 0;
        foreach ($images as $offset => $id) {
            if ($offset % 3 !== 0) { continue; }
            $path = gmdate('Y/m', (int) strtotime($this->anchor . ' UTC')) . '/' . md5('tinycat-demo-image-' . $id) . '.webp';
            $bytes = $this->image('uploads/status-images/' . $path, 960, 540, (int) $id);
            $this->execute('INSERT INTO content_images (content_id, path, width, height, bytes) VALUES (?, ?, 960, 540, ?) ON DUPLICATE KEY UPDATE path = VALUES(path), bytes = VALUES(bytes)', [(int) $id, $path, $bytes]);
            $imageCount++;
        }
        for ($i = 1; $i <= 2; $i++) {
            $name = $this->prefix . '_bot_' . $i;
            $this->execute("INSERT INTO users (username, password, role, status, locale, bio, created_at, updated_at) VALUES (?, NULL, 'bot', 'active', 'cs', ?, ?, ?) ON DUPLICATE KEY UPDATE username = VALUES(username)", [$name, 'Lokální ukázkový bot; zdroj je pozastavený.', $this->anchor, $this->anchor]);
            $statement = $this->database->prepare('SELECT id FROM users WHERE username = ? AND role = ?');
            $statement->execute([$name, 'bot']);
            $id = (int) $statement->fetchColumn();
            if ($id < 1) { throw new RuntimeException('Demo bot username belongs to another role.'); }
            $url = 'https://example.invalid/tinycat-demo/' . $i . '.xml';
            $hash = hash('sha256', $url);
            $this->execute('INSERT INTO bot_sources (bot_user_id, name, feed_url, feed_hash, interval_minutes, post_template, enabled, created_at) VALUES (?, ?, ?, ?, 60, ?, 0, ?) ON DUPLICATE KEY UPDATE feed_url = VALUES(feed_url)', [$id, 'Demo zdroj ' . $i, $url, $hash, '{title}' . "\n{url}", $this->anchor]);
            $statement = $this->database->prepare('SELECT id FROM bot_sources WHERE feed_hash = ?');
            $statement->execute([$hash]);
            $source = (int) $statement->fetchColumn();
            $status = $i === 1 ? 'success' : 'error';
            $this->execute('INSERT INTO bot_source_runs (source_id, status, started_at, items_seen, error) SELECT ?, ?, ?, ?, ? WHERE NOT EXISTS (SELECT 1 FROM bot_source_runs WHERE source_id = ? AND started_at = ?)', [$source, $status, $this->anchor, $i === 1 ? 3 : 0, $i === 2 ? 'Ukázková chyba zdroje pro audit UI.' : null, $source, $this->anchor]);
            $this->post($id, 'Demo bot ' . $i . ': Lokální ukázkový příspěvek. #tinycat');
        }
        return ['ui_avatars' => $avatars, 'ui_images' => $imageCount, 'ui_bots' => 2];
    }

    private function post(int $userId, string $body): void
    {
        $this->execute('INSERT INTO content (author_id, body, published_at) SELECT ?, ?, ? WHERE NOT EXISTS (SELECT 1 FROM content WHERE author_id = ? AND body = ?)', [$userId, $body, $this->anchor, $userId, $body]);
        preg_match_all('/#([a-zA-Z0-9_]+)/', $body, $matches);
        foreach (array_unique($matches[1]) as $tag) {
            $tag = strtolower($tag);
            $this->execute('INSERT IGNORE INTO terms (name) VALUES (?)', [$tag]);
            $this->execute('INSERT IGNORE INTO content_tags (content_id, term_id) SELECT c.id, t.id FROM content c INNER JOIN terms t ON t.name = ? WHERE c.author_id = ? AND c.body = ?', [$tag, $userId, $body]);
        }
    }

    /** @param list<mixed> $values */
    private function execute(string $sql, array $values): void
    {
        $this->database->prepare($sql)->execute($values);
    }

    private function image(string $relative, int $width, int $height, int $key): int
    {
        $directory = $this->root . '/' . dirname($relative);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) { throw new RuntimeException('Cannot create demo image directory.'); }
        $image = imagecreatetruecolor($width, $height);
        if ($image === false) { throw new RuntimeException('Cannot allocate demo image.'); }
        $background = imagecolorallocate($image, 30 + ($key * 23 % 100), 40 + ($key * 17 % 100), 65 + ($key * 31 % 100));
        $foreground = imagecolorallocate($image, 225, 230, 240);
        imagefill($image, 0, 0, $background);
        imagefilledellipse($image, (int) ($width / 2), (int) ($height / 2), (int) ($width / 2), (int) ($height / 2), $foreground);
        imagestring($image, 5, 15, 15, 'TinyCat demo ' . $key, $foreground);
        $path = $this->root . '/' . $relative;
        if (!imagewebp($image, $path, 82)) { throw new RuntimeException('Cannot write demo image.'); }
        return (int) filesize($path);
    }
}
