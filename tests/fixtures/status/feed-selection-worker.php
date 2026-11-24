<?php
declare(strict_types=1);

require_once __DIR__ . '/../../support/cache-isolation.php';
define('TINYCAT', true);
require __DIR__ . '/../../../App/Core/Runtime/autoload.php';
\TinyCat\Core\Runtime\Application::start();
$input = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
final class PausedFeedPdo extends PDO
{
    public string $ready;
    public string $resume;
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_contains($query, 'SELECT c.id,') && str_contains($query, 'c.body,') && str_contains($query, 'social_post_images')) {
            file_put_contents($this->ready, 'ready');
            $deadline = microtime(true) + 15;
            while (!is_file($this->resume) && microtime(true) < $deadline) { usleep(10000); }
            if (!is_file($this->resume)) { throw new RuntimeException('Timed out waiting for the competing commit.'); }
        }
        return parent::prepare($query, $options);
    }
}
$db = new PausedFeedPdo($input['dsn'], $input['user'], $input['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$db->ready = $input['ready']; $db->resume = $input['resume'];
\TinyCat\Database\Connection::set($db);
(new ReflectionProperty(\TinyCat\Core\Settings\Store::class, 'settings'))->setValue(null, []);
\TinyCat\Core\Identity\Authentication::reset(null, true);
$selection = null;
$items = \TinyCat\Social\Posts\Feed::public_status_items_cursor(2, '', 0, '', $selection);
echo json_encode(['items' => array_column($items, 'id'), 'selection' => $selection], JSON_THROW_ON_ERROR);
