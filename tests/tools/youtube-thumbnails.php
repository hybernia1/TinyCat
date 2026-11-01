<?php
declare(strict_types=1);

require __DIR__ . '/../../tools/youtube-thumbnail-cleanup.php';
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) { echo "SKIP YouTube cleanup: SQLite unavailable\n"; exit; }
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE links (id INTEGER PRIMARY KEY, normalized_url TEXT, image_url TEXT, title TEXT)');
$path = '/uploads/links/2026/11/' . str_repeat('a', 40) . '.webp';
$insert = $pdo->prepare('INSERT INTO links VALUES (?, ?, ?, ?)');
$insert->execute([1, 'https://youtu.be/abc123', $path, 'YouTube title']);
$insert->execute([2, 'https://example.com/article', $path, 'Shared image']);
$insert->execute([3, 'https://youtube.com.evil.test/watch?v=abc123', $path, 'Other host']);
$insert->execute([4, 'https://www.youtube.com/watch?v=def456', 'https://i.ytimg.com/old.jpg', 'Remote']);
$before = $pdo->query('SELECT * FROM links ORDER BY id')->fetchAll();
$plan = youtubeThumbnailCleanup($pdo);
if ($plan['changed'] !== 2 || $plan['paths'] !== [$path] || $pdo->query('SELECT * FROM links ORDER BY id')->fetchAll() !== $before) { throw new RuntimeException('Invalid read-only plan'); }
$backup = sys_get_temp_dir() . '/tinycat-youtube-backup-' . bin2hex(random_bytes(8)) . '.json';
try {
    $report = youtubeThumbnailCleanup($pdo, true, $backup);
    $after = $pdo->query('SELECT * FROM links ORDER BY id')->fetchAll();
    if (!$report['applied'] || $after[0]['image_url'] !== '' || $after[3]['image_url'] !== '' || $after[1] !== $before[1] || $after[2] !== $before[2] || $after[0]['title'] !== $before[0]['title']) { throw new RuntimeException('Cleanup scope changed'); }
    if (count(json_decode(file_get_contents($backup), true, 512, JSON_THROW_ON_ERROR)['rows']) !== 2 || youtubeThumbnailCleanup($pdo)['changed'] !== 0) { throw new RuntimeException('Backup or idempotency failed'); }
} finally { if (is_file($backup)) { unlink($backup); } }
echo "PASS YouTube thumbnail cleanup: scoped, backed up, shared images preserved, idempotent\n";
