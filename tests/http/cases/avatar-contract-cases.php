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
    [$status, $generatedUser] = $request('GET', '/api/users/' . $avatarId);
    $generatedUrl = '/avatar/generated/v1/' . $avatarId . '.svg';
    $assert($status === 200 && $generatedUser['data']['user']['avatar_url'] === $generatedUrl && !$generatedUser['data']['user']['avatar_custom'], 'A new user receives a generated avatar before uploading.');
    [$modalStatus, $avatarModal] = $request('GET', '/api/users/' . $avatarId . '/avatar', null, 'admin');
    $assert($modalStatus === 200 && count($avatarModal['data']['appearance_options']) === 6 && in_array('female', $avatarModal['data']['appearance_options']['gender']['values'], true), 'Avatar modal resources expose allowed parameter values without forbidden presentation fields.');
    $generatedImage = file_get_contents('http://' . $address . $generatedUrl);
    $assert(is_string($generatedImage) && str_contains($generatedImage, '<svg') && str_contains($generatedImage, '<rect'), 'The generated avatar route serves its SVG image.');
    $generatedHeaders = $http_response_header;
    $assert(count(array_filter($generatedHeaders, static fn (string $header): bool => stripos($header, 'Content-Type: image/svg+xml') === 0)) === 1, 'Generated avatars use the SVG image content type.');
    [$status] = $request('PATCH', '/api/users/' . $avatarId . '/avatar', ['appearance' => ['version' => 1, 'gender' => 'female']], 'user', $csrf);
    $assert($status === 403, 'Members cannot change another user generated avatar.');
    [$status] = $request('PATCH', '/api/users/' . $avatarId . '/avatar', ['appearance' => ['gender' => '<script>']], 'admin', $csrf);
    $assert($status === 422, 'Unsupported appearance values are rejected.');
    [$status, $appearanceResult] = $request('PATCH', '/api/users/' . $avatarId . '/avatar', ['appearance' => ['version' => 1, 'gender' => 'female']], 'admin', $csrf);
    $assert($status === 200 && $appearanceResult['data']['user']['avatar_appearance']['gender'] === 'female' && $appearanceResult['data']['user']['avatar_url'] === $generatedUrl . '?gender=female', 'Generated appearance is saved and versions the image URL.');
    $savedAppearance = json_decode((string) $seed->query('SELECT avatar_appearance FROM users WHERE id = ' . $avatarId)->fetchColumn(), true);
    $assert($savedAppearance === ['version' => 1, 'gender' => 'female', 'fur' => 'auto', 'expression' => 'auto', 'accessory' => 'auto', 'outfit' => 'auto', 'background' => 'auto'], 'Appearance is stored as a normalized JSON object.');
    $femaleImage = file_get_contents('http://' . $address . $generatedUrl . '?gender=female');
    $assert($femaleImage !== $generatedImage && str_contains((string) $femaleImage, '<svg'), 'Gender changes the generated SVG without changing the identity seed.');
    [$creatorStatus, $creatorResult] = $request('PATCH', '/api/users/' . $avatarId . '/avatar', ['appearance' => ['fur' => 'lilac', 'expression' => 'smile', 'accessory' => 'glasses']], 'admin', $csrf);
    $assert($creatorStatus === 200 && $creatorResult['data']['user']['avatar_appearance']['gender'] === 'female' && $creatorResult['data']['user']['avatar_appearance']['fur'] === 'lilac', 'Creator saves its parameters and preserves omitted gender settings.');
    $creatorImage = file_get_contents('http://' . $address . $creatorResult['data']['user']['avatar_url']);
    $assert(is_string($creatorImage) && str_contains($creatorImage, 'fill="#c7a3cf"'), 'Creator image URLs render the selected fur.');
    [$colourStatus, $colourResult] = $request('PATCH', '/api/users/' . $avatarId . '/avatar', ['appearance' => ['outfit' => 'sage', 'background' => 'peach']], 'admin', $csrf);
    $assert($colourStatus === 200 && $colourResult['data']['user']['avatar_appearance']['outfit'] === 'sage' && $colourResult['data']['user']['avatar_appearance']['background'] === 'peach', 'Outfit and background colours save independently of other creator options.');
    [$colourStatus, $savedColours] = $request('GET', '/api/users/' . $avatarId . '/avatar', null, 'admin');
    $colourImage = file_get_contents('http://' . $address . $savedColours['data']['user']['avatar_url']);
    $assert($colourStatus === 200 && is_string($colourImage) && str_contains($colourImage, 'fill="#558675"') && str_contains($colourImage, 'fill="#f3d7ce"'), 'Reopening the editor preserves colours and its SVG renders them.');
    [$creatorStatus] = $request('PATCH', '/api/users/' . $avatarId . '/avatar', ['appearance' => ['accessory' => 'script']], 'admin', $csrf);
    $assert($creatorStatus === 422, 'Creator rejects unknown layer values.');
    [$creatorStatus] = $request('PATCH', '/api/users/' . $avatarId . '/avatar', ['appearance' => ['fur' => 'auto', 'expression' => 'auto', 'accessory' => 'auto', 'outfit' => 'auto', 'background' => 'auto']], 'admin', $csrf);
    $assert($creatorStatus === 200, 'Creator can restore the original deterministic layers.');
    $boundary = 'tinycat-avatar-' . bin2hex(random_bytes(12));
    $multipart = '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"_method\"\r\n\r\nPUT\r\n"
        . '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"avatar\"; filename=\"avatar.png\"\r\nContent-Type: image/png\r\n\r\n" . $png . "\r\n--" . $boundary . "--\r\n";
    $fixtureOwnsAvatar = true;
    [$status, $payload] = $request('POST', '/api/users/' . $avatarId . '/avatar', $multipart, 'admin', $csrf + ['Content-Type' => 'multipart/form-data; boundary=' . $boundary]);
    $assert($status === 200 && is_file($avatarFile), 'Multipart avatar upload writes its WebP image.');
    $dimensions = getimagesize($avatarFile);
    $assert($dimensions[0] === 200 && $dimensions[1] === 200, 'Real avatar uploads retain their 200 by 200 crop.');
    [$appearanceStatus] = $request('PATCH', '/api/users/' . $avatarId . '/avatar', ['appearance' => ['gender' => 'male']], 'admin', $csrf);
    $assert($appearanceStatus === 422, 'Uploaded avatars cannot be customized as generated cats.');
    $avatarUrl = $payload['data']['user']['avatar_url'];
    $assert(str_starts_with($avatarUrl, '/uploads/avatars/' . $avatarId . '.webp?v='), 'Upload returns the new cache-versioned avatar resource.');
    $assert((int) $seed->query('SELECT avatar_exists FROM users WHERE id = ' . $avatarId)->fetchColumn() === 1, 'Successful upload persists avatar availability.');
    [$status, $payload] = $request('GET', '/api/users/' . $avatarId);
    $assert($status === 200 && $payload['data']['user']['avatar_url'] === $avatarUrl, 'Subsequent public resources preserve the uploaded avatar URL.');
    $imageBytes = file_get_contents('http://' . $address . $avatarUrl);
    $assert(is_string($imageBytes) && getimagesizefromstring($imageBytes)['mime'] === 'image/webp', 'The returned avatar URL actually serves a WebP image.');
    $originalHash = hash_file('sha256', $avatarFile);
    $originalUpdatedAt = $seed->query('SELECT updated_at FROM users WHERE id = ' . $avatarId)->fetchColumn();
    $stagedBefore = glob($root . '/uploads/avatars/.avatar-*') ?: [];
    $canvas = imagecreatetruecolor(12, 8);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 0, 0));
    ob_start(); imagepng($canvas); $redPng = (string) ob_get_clean(); imagedestroy($canvas);
    $multipartBody = static function (array $fields, string $bytes, string $uploadName = 'avatar') use ($boundary): string {
        $body = '';
        foreach ($fields as $name => $value) {
            $body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . $name . "\"\r\n\r\n" . $value . "\r\n";
        }
        return $body . '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . $uploadName . "\"; filename=\"replacement.png\"\r\nContent-Type: image/png\r\n\r\n" . $bytes . "\r\n--" . $boundary . "--\r\n";
    };
    $multipartHeaders = $csrf + ['Content-Type' => 'multipart/form-data; boundary=' . $boundary];
    [$status] = $request('POST', '/api/users/' . $avatarId, $multipartBody(['_method' => 'PATCH', 'scope' => 'administration', 'role' => 'user', 'status' => 'active', 'email' => 'invalid-address'], $redPng), 'admin', $multipartHeaders);
    $assert($status === 422 && hash_file('sha256', $avatarFile) === $originalHash, 'Invalid profile fields cannot replace an existing avatar.');
    $seed->exec('CREATE TRIGGER fail_avatar_update BEFORE UPDATE ON users WHEN NEW.id = ' . $avatarId . " BEGIN SELECT RAISE(ABORT, 'forced avatar persistence failure'); END");
    [$status] = $request('POST', '/api/users/' . $avatarId . '/avatar', $multipartBody(['_method' => 'PUT'], $redPng), 'admin', $multipartHeaders);
    $assert($status === 500 && hash_file('sha256', $avatarFile) === $originalHash, 'A database failure restores the original avatar after a direct upload.');
    [$status] = $request('POST', '/api/users/' . $avatarId, $multipartBody(['_method' => 'PATCH', 'scope' => 'administration', 'role' => 'user', 'status' => 'active'], $redPng), 'admin', $multipartHeaders);
    $assert($status === 500 && hash_file('sha256', $avatarFile) === $originalHash, 'An administrator upload restores the original avatar when persistence fails.');
    [$status] = $request('DELETE', '/api/users/' . $avatarId . '/avatar', [], 'admin', $csrf);
    $assert($status === 500 && hash_file('sha256', $avatarFile) === $originalHash, 'A failed removal preserves the avatar file.');
    $assert($seed->query('SELECT updated_at FROM users WHERE id = ' . $avatarId)->fetchColumn() === $originalUpdatedAt, 'Failed avatar changes preserve the database cache version.');
    $seed->exec('DROP TRIGGER fail_avatar_update');
    $seed->exec("UPDATE users SET role = 'bot' WHERE id = " . $avatarId);
    $seed->prepare('INSERT INTO bot_sources (bot_user_id, name, feed_url, feed_hash, post_template, enabled) VALUES (?, ?, ?, ?, ?, 1)')->execute([$avatarId, 'Avatar rollback source', 'http://127.0.0.1/blocked', hash('sha256', 'avatar-' . $avatarId), '{{title}}']);
    $seed->exec('CREATE TRIGGER fail_bot_avatar_sources BEFORE UPDATE ON bot_sources WHEN NEW.bot_user_id = ' . $avatarId . " BEGIN SELECT RAISE(ABORT, 'forced bot side effect failure'); END");
    [$status] = $request('POST', '/api/bots/accounts/' . $avatarId, $multipartBody(['_method' => 'PATCH', 'status' => 'ban'], $redPng), 'admin', $multipartHeaders);
    $assert($status === 500 && hash_file('sha256', $avatarFile) === $originalHash, 'Failure disabling bot sources rolls back the avatar replacement.');
    $assert($seed->query('SELECT status FROM users WHERE id = ' . $avatarId)->fetchColumn() === 'active', 'A bot side effect failure rolls back the account status.');
    $seed->exec('DROP TRIGGER fail_bot_avatar_sources');
    [$status, $payload] = $request('POST', '/api/bots/accounts/' . $avatarId, $multipartBody(['_method' => 'PATCH', 'status' => 'active'], $redPng), 'admin', $multipartHeaders);
    $assert($status === 200 && hash_file('sha256', $avatarFile) !== $originalHash, 'A successful bot update commits the new avatar through the shared writer.');
    $assert(array_diff(glob($root . '/uploads/avatars/.avatar-*') ?: [], $stagedBefore) === [], 'Successful and failed changes clean up their staged files and backups.');
    $seed->exec("UPDATE users SET role = 'user' WHERE id = " . $avatarId);
    [$status] = $request('DELETE', '/api/users/' . $avatarId . '/avatar', [], 'admin', $csrf);
    clearstatcache(true, $avatarFile);
    $assert($status === 200 && !is_file($avatarFile), 'Removing the avatar clears its file.');
    [$status, $payload] = $request('GET', '/api/users/' . $avatarId);
    $assert($status === 200 && $payload['data']['user']['avatar_url'] === '/avatar/generated/v1/' . $avatarId . '.svg?gender=female' && !$payload['data']['user']['avatar_custom'], 'Removed avatars return the stable generated fallback.');
    $seed->exec('CREATE TRIGGER fail_new_avatar BEFORE UPDATE ON users WHEN NEW.id = ' . $avatarId . " BEGIN SELECT RAISE(ABORT, 'forced first avatar failure'); END");
    [$status] = $request('POST', '/api/users/' . $avatarId . '/avatar', $multipartBody(['_method' => 'PUT'], $redPng), 'admin', $multipartHeaders);
    clearstatcache(true, $avatarFile);
    $assert($status === 500 && !is_file($avatarFile), 'A failed first upload removes its new file when there is no previous avatar.');
    $assert((int) $seed->query('SELECT avatar_exists FROM users WHERE id = ' . $avatarId)->fetchColumn() === 0, 'A failed first upload preserves the absent-avatar database state.');
    $seed->exec('DROP TRIGGER fail_new_avatar');
    [$status, $postUpload] = $request('POST', '/api/statuses', $multipartBody(['body' => 'Raster upload fixture ' . $avatarId], $redPng, 'image'), 'admin', $multipartHeaders);
    $assert($status === 201, 'Post images upload through the shared raster processing helpers.');
    $postId = (int) $postUpload['data']['status_id'];
    $path = $seed->query('SELECT path FROM content_images WHERE content_id = ' . $postId)->fetchColumn();
    $assert(is_string($path) && preg_match('~^\d{4}/\d{2}/[a-f0-9]{32}\.webp$~D', $path) === 1, 'The post image retains its established storage path.');
    $postImageFile = $root . '/uploads/status-images/' . $path;
    try {
        $dimensions = getimagesize($postImageFile);
        $assert($dimensions[0] === 12 && $dimensions[1] === 8, 'Post images preserve aspect ratio without the avatar crop or upscaling.');
        [$status] = $request('DELETE', '/api/statuses/' . $postId, [], 'admin', $csrf);
        clearstatcache(true, $postImageFile);
        $assert($status === 200 && !is_file($postImageFile), 'Deleting the post removes its encoded image.');
    } finally {
        if (is_file($postImageFile)) { unlink($postImageFile); }
    }
} finally {
    clearstatcache(true, $avatarFile);
    if ($fixtureOwnsAvatar && is_file($avatarFile)) { unlink($avatarFile); }
    unset($insertAvatarUser);
    $seed = null;
}
