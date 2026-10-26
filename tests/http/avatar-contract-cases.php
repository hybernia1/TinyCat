<?php
declare(strict_types=1);

// Exercise PHP multipart parsing, the real avatar writer, JSON resources and
// delivery of the resulting image. Never reuse a workspace avatar ID.
$avatarId = random_int(90_000_000, 99_999_999);
$avatarFile = $root . '/uploads/avatars/' . $avatarId . '.webp';
$assert(!file_exists($avatarFile), 'The avatar fixture must not overwrite an existing file.');
$seed = new PDO('sqlite:' . $databasePath, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$insertAvatarUser = $seed->prepare('INSERT INTO users (id, username, role, status, avatar_exists) VALUES (?, ?, ?, ?, 0)');
$insertAvatarUser->execute([$avatarId, 'Avatar.Fixture.' . $avatarId, 'user', 'active']);
$fixtureOwnsAvatar = false;
try {
    $canvas = imagecreatetruecolor(8, 8);
    ob_start(); imagepng($canvas); $png = (string) ob_get_clean(); imagedestroy($canvas);
    $boundary = 'tinycat-avatar-' . bin2hex(random_bytes(12));
    $multipart = '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"_method\"\r\n\r\nPUT\r\n"
        . '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"avatar\"; filename=\"avatar.png\"\r\nContent-Type: image/png\r\n\r\n" . $png . "\r\n--" . $boundary . "--\r\n";
    $fixtureOwnsAvatar = true;
    [$status, $payload] = $request('POST', '/api/users/' . $avatarId . '/avatar', $multipart, 'admin', $csrf + ['Content-Type' => 'multipart/form-data; boundary=' . $boundary]);
    $assert($status === 200 && is_file($avatarFile), 'Multipart avatar upload writes its WebP image.');
    $avatarUrl = $payload['data']['user']['avatar_url'];
    $assert(str_starts_with($avatarUrl, '/uploads/avatars/' . $avatarId . '.webp?v='), 'Upload returns the new cache-versioned avatar resource.');
    $assert((int) $seed->query('SELECT avatar_exists FROM users WHERE id = ' . $avatarId)->fetchColumn() === 1, 'Successful upload persists avatar availability.');
    [$status, $payload] = $request('GET', '/api/users/' . $avatarId);
    $assert($status === 200 && $payload['data']['user']['avatar_url'] === $avatarUrl, 'Subsequent public resources preserve the uploaded avatar URL.');
    $imageBytes = file_get_contents('http://' . $address . $avatarUrl);
    $assert(is_string($imageBytes) && getimagesizefromstring($imageBytes)['mime'] === 'image/webp', 'The returned avatar URL actually serves a WebP image.');
    [$status] = $request('DELETE', '/api/users/' . $avatarId . '/avatar', [], 'admin', $csrf);
    clearstatcache(true, $avatarFile);
    $assert($status === 200 && !is_file($avatarFile), 'Removing the avatar clears its file.');
    [$status, $payload] = $request('GET', '/api/users/' . $avatarId);
    $assert($status === 200 && $payload['data']['user']['avatar_url'] === '', 'Removed avatars return an empty URL for the local fallback icon.');
} finally {
    clearstatcache(true, $avatarFile);
    if ($fixtureOwnsAvatar && is_file($avatarFile)) { unlink($avatarFile); }
    unset($insertAvatarUser);
    $seed = null;
}
