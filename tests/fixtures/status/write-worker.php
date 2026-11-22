<?php
declare(strict_types=1);

// Child of status/write-concurrency.php, never an independent test.
$state = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
define('TINYCAT', true);
require $state['root'] . '/App/Core/Runtime/autoload.php';
use TinyCat\Database\Connection;
use TinyCat\Social\Posts\{PostCreation, Repository, ImageStorage};
$db = new PDO($state['dsn'], $state['user'], $state['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
Connection::set($db);
(new ReflectionProperty(TinyCat\Support\Config::class, 'booted'))->setValue(null, true);
(new ReflectionProperty(TinyCat\Support\Config::class, 'config'))->setValue(null, []);
try {
    $old = \TinyCat\Database\Transactions::run(static function () use ($state): string {
        // Establish a repeatable-read snapshot before waiting for the parent lock.
        // Current image reads must also work inside a caller-owned transaction.
        Repository::find($state['id']);
        Connection::one('SELECT * FROM social_post_images WHERE content_id = ?', [$state['id']]);
        file_put_contents($state['ready'], (string) Connection::value('SELECT CONNECTION_ID()'));
        if ($state['operation'] === 'delete') { return Repository::delete($state['id'], deleteImageFile: false); }
        $image = $state['operation'] === 'replace' ? $state['image'] : null;
        return Repository::update($state['id'], PostCreation::fromText($state['body']), [], $image, false, ['id' => 1, 'role' => 'user']);
    });
    ImageStorage::delete($old);
    file_put_contents($state['result'], json_encode(['old' => $old], JSON_THROW_ON_ERROR));
} catch (TinyCat\Http\HttpException $error) {
    file_put_contents($state['result'], json_encode(['code' => $error->errorCode], JSON_THROW_ON_ERROR));
}
