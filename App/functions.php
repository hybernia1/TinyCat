<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/Core.php';

if (!function_exists('guard')) {
    function guard(): void
    {
        if (!defined('TINYCAT')) {
            http_response_code(403);
            exit('Forbidden');
        }
    }
}

if (!function_exists('config')) {
    function config(?string $key = null, mixed $default = null): mixed
    {
        return Core::config($key, $default);
    }
}

if (!function_exists('setting')) {
    function setting(?string $key = null, mixed $default = null): mixed
    {
        return Core::setting($key, $default);
    }
}

if (!function_exists('setting_set')) {
    function setting_set(string $key, mixed $value, string $type = 'string', string $group = 'general'): void
    {
        Core::setSetting($key, $value, $type, $group);
    }
}

if (!function_exists('public_path')) {
    function public_path(string $path = ''): string
    {
        return Core::publicPath($path);
    }
}

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return Core::basePath($path);
    }
}

if (!function_exists('db')) {
    function db(): PDO
    {
        return Core::db();
    }
}

if (!function_exists('app_required_tables')) {
    function app_required_tables(): array
    {
        return ['users', 'content', 'terms', 'content_tags', 'content_likes', 'content_comments', 'comment_likes', 'user_followers', 'notifications', 'content_reports', 'user_action_limits', 'settings'];
    }
}

if (!function_exists('site_name')) {
    function site_name(): string
    {
        return (string) config('site.name', config('app.name', 'TinyCat'));
    }
}

if (!function_exists('site_logo_url')) {
    function site_logo_url(): string
    {
        return trim((string) config('site.logo_url', ''));
    }
}

if (!function_exists('site_favicon_url')) {
    function site_favicon_url(): string
    {
        return trim((string) config('site.favicon_url', ''));
    }
}

if (!function_exists('site_footer_html')) {
    function site_footer_html(): string
    {
        return trim((string) config('site.footer_html', ''));
    }
}

if (!function_exists('site_meta_image_url')) {
    function site_meta_image_url(): string
    {
        return site_logo_url() ?: site_favicon_url();
    }
}

if (!function_exists('absolute_url')) {
    function absolute_url(string $url = ''): string
    {
        $url = trim($url);

        if ($url === '') {
            $url = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        }

        if (preg_match('~^https?://~i', $url)) {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            $scheme = app_request_scheme();

            return $scheme . ':' . $url;
        }

        $path = '/' . ltrim($url, '/');
        $base = rtrim((string) config('app.url', ''), '/');

        if ($base !== '') {
            return $base . $path;
        }

        $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');

        return app_request_scheme() . '://' . $host . $path;
    }
}

if (!function_exists('app_request_scheme')) {
    function app_request_scheme(): string
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));

        return in_array($https, ['on', '1'], true)
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
            ? 'https'
            : 'http';
    }
}

if (!function_exists('meta_text')) {
    function meta_text(string $text, int $limit = 180): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '') {
            return '';
        }

        if ((function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text)) <= $limit) {
            return $text;
        }

        $slice = function_exists('mb_substr') ? mb_substr($text, 0, max(0, $limit - 1), 'UTF-8') : substr($text, 0, max(0, $limit - 1));

        return rtrim((string) $slice) . '...';
    }
}

if (!function_exists('status_meta_description')) {
    function status_meta_description(array $item): string
    {
        $body = status_strip_external_urls((string) ($item['body'] ?? ''));
        $body = meta_text($body, 180);

        if ($body !== '') {
            return $body;
        }

        return t('public.status_meta_description', [
            'author' => (string) ($item['author_name'] ?? site_name()),
        ]);
    }
}

if (!function_exists('status_meta_image')) {
    function status_meta_image(array $item): string
    {
        return (string) ($item['avatar_url'] ?? '') ?: site_meta_image_url();
    }
}

if (!function_exists('user_avatar_url')) {
    function user_avatar_url(?array $user): string
    {
        return trim((string) ($user['avatar_url'] ?? ''));
    }
}

if (!function_exists('user_display_name')) {
    function user_display_name(?array $user): string
    {
        if ($user === null) {
            return '';
        }

        return trim((string) ($user['username'] ?? ''));
    }
}

if (!function_exists('avatar_delete')) {
    function avatar_delete(?string $path, ?string $url = null): void
    {
        $relative = trim(str_replace('\\', '/', (string) $path), '/');

        if ($relative === '' && trim((string) $url) !== '') {
            $baseUrl = rtrim((string) config('avatar.url', '/uploads/avatars'), '/') . '/';
            $urlPath = (string) (parse_url((string) $url, PHP_URL_PATH) ?: '');

            if (str_starts_with($urlPath, $baseUrl)) {
                $relative = trim(substr($urlPath, strlen($baseUrl)), '/');
            }
        }

        if ($relative === '' || str_contains($relative, '..')) {
            return;
        }

        $baseDirectory = rtrim((string) config('avatar.directory', base_path('uploads/avatars')), "/\\");
        $baseReal = realpath($baseDirectory);

        if ($baseReal === false || !is_dir($baseReal)) {
            return;
        }

        $target = $baseReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $targetReal = realpath($target);

        if ($targetReal === false || !is_file($targetReal)) {
            return;
        }

        $basePrefix = rtrim($baseReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (!str_starts_with(strtolower($targetReal), strtolower($basePrefix))) {
            return;
        }

        @unlink($targetReal);
    }
}

if (!function_exists('avatar_upload')) {
    function avatar_upload(array $file, string $name): array
    {
        if (!extension_loaded('gd') || !function_exists('imagewebp')) {
            throw new RuntimeException('WebP avatar conversion is not available.');
        }

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Uploaded avatar is not valid.');
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');

        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new RuntimeException('Uploaded avatar is not valid.');
        }

        $maxSize = (int) config('avatar.max_size', 64 * 1024 * 1024);
        $size = (int) ($file['size'] ?? 0);

        if ($maxSize > 0 && $size > $maxSize) {
            throw new RuntimeException('Uploaded avatar is too large.');
        }

        $info = @getimagesize($tmpName);

        if ($info === false || empty($info['mime'])) {
            throw new RuntimeException('Uploaded avatar is not an image.');
        }

        $mime = strtolower((string) $info['mime']);
        $source = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($tmpName),
            'image/png' => imagecreatefrompng($tmpName),
            'image/gif' => imagecreatefromgif($tmpName),
            'image/webp' => imagecreatefromwebp($tmpName),
            default => false,
        };

        if (!$source instanceof GdImage) {
            throw new RuntimeException('Only JPEG, PNG, GIF, and WebP avatars can be uploaded.');
        }

        if ($mime === 'image/jpeg') {
            $source = avatar_apply_orientation($source, $tmpName);
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);

        if ($sourceWidth < 1 || $sourceHeight < 1) {
            imagedestroy($source);
            throw new RuntimeException('Uploaded avatar is empty.');
        }

        $targetSize = max(1, (int) config('avatar.size', 200));
        $canvas = imagecreatetruecolor($targetSize, $targetSize);
        $sourceRatio = $sourceWidth / $sourceHeight;

        if ($sourceRatio > 1) {
            $cropHeight = $sourceHeight;
            $cropWidth = $sourceHeight;
            $sourceX = (int) floor(($sourceWidth - $cropWidth) / 2);
            $sourceY = 0;
        } else {
            $cropWidth = $sourceWidth;
            $cropHeight = $sourceWidth;
            $sourceX = 0;
            $sourceY = (int) floor(($sourceHeight - $cropHeight) / 2);
        }

        imagecopyresampled(
            $canvas,
            $source,
            0,
            0,
            $sourceX,
            $sourceY,
            $targetSize,
            $targetSize,
            $cropWidth,
            $cropHeight
        );

        imagedestroy($source);

        $baseDirectory = rtrim((string) config('avatar.directory', base_path('uploads/avatars')), "/\\");
        $baseUrl = rtrim((string) config('avatar.url', '/uploads/avatars'), '/');
        $subfolder = trim((string) date((string) config('avatar.subfolder', 'Y/m')), '/');
        $directory = $baseDirectory . ($subfolder !== '' ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $subfolder) : '');

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            imagedestroy($canvas);
            throw new RuntimeException('Could not create avatar directory.');
        }

        $base = slug($name);
        $base = $base !== '' ? $base : 'avatar';
        $filename = $base . '.webp';
        $target = $directory . DIRECTORY_SEPARATOR . $filename;
        $counter = 2;

        while (is_file($target)) {
            $filename = $base . '-' . $counter . '.webp';
            $target = $directory . DIRECTORY_SEPARATOR . $filename;
            $counter++;
        }

        if (!imagewebp($canvas, $target, max(1, min(100, (int) config('avatar.quality', 86))))) {
            imagedestroy($canvas);
            throw new RuntimeException('Could not write WebP avatar.');
        }

        imagedestroy($canvas);

        return [
            'path' => trim(($subfolder !== '' ? $subfolder . '/' : '') . $filename, '/'),
            'url' => $baseUrl . '/' . ($subfolder !== '' ? $subfolder . '/' : '') . $filename,
            'size' => (int) filesize($target),
        ];
    }
}

if (!function_exists('site_image_upload')) {
    function site_image_upload(array $file, string $name, string $variant): array
    {
        if (!extension_loaded('gd') || !function_exists('imagewebp')) {
            throw new RuntimeException('WebP image conversion is not available.');
        }

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Uploaded image is not valid.');
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');

        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new RuntimeException('Uploaded image is not valid.');
        }

        $maxSize = (int) config('site.image_max_size', 64 * 1024 * 1024);
        $size = (int) ($file['size'] ?? 0);

        if ($maxSize > 0 && $size > $maxSize) {
            throw new RuntimeException('Uploaded image is too large.');
        }

        $info = @getimagesize($tmpName);

        if ($info === false || empty($info['mime'])) {
            throw new RuntimeException('Uploaded image is not valid.');
        }

        $mime = strtolower((string) $info['mime']);
        $source = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($tmpName),
            'image/png' => imagecreatefrompng($tmpName),
            'image/gif' => imagecreatefromgif($tmpName),
            'image/webp' => imagecreatefromwebp($tmpName),
            default => false,
        };

        if (!$source instanceof GdImage) {
            throw new RuntimeException('Only JPEG, PNG, GIF, and WebP images can be uploaded.');
        }

        if ($mime === 'image/jpeg') {
            $source = avatar_apply_orientation($source, $tmpName);
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);

        if ($sourceWidth < 1 || $sourceHeight < 1) {
            imagedestroy($source);
            throw new RuntimeException('Uploaded image is empty.');
        }

        if ($variant === 'favicon') {
            $targetWidth = 64;
            $targetHeight = 64;
            $cropSize = min($sourceWidth, $sourceHeight);
            $sourceX = (int) floor(($sourceWidth - $cropSize) / 2);
            $sourceY = (int) floor(($sourceHeight - $cropSize) / 2);
            $cropWidth = $cropSize;
            $cropHeight = $cropSize;
        } else {
            $maxWidth = 640;
            $scale = $sourceWidth > $maxWidth ? $maxWidth / $sourceWidth : 1.0;
            $targetWidth = max(1, (int) round($sourceWidth * $scale));
            $targetHeight = max(1, (int) round($sourceHeight * $scale));
            $sourceX = 0;
            $sourceY = 0;
            $cropWidth = $sourceWidth;
            $cropHeight = $sourceHeight;
        }

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, imagecolorallocatealpha($canvas, 0, 0, 0, 127));

        imagecopyresampled(
            $canvas,
            $source,
            0,
            0,
            $sourceX,
            $sourceY,
            $targetWidth,
            $targetHeight,
            $cropWidth,
            $cropHeight
        );

        imagedestroy($source);

        $baseDirectory = rtrim((string) config('site.image_directory', base_path('uploads/site')), "/\\");
        $baseUrl = rtrim((string) config('site.image_url', '/uploads/site'), '/');
        $subfolder = trim((string) date((string) config('site.image_subfolder', 'Y/m')), '/');
        $directory = $baseDirectory . ($subfolder !== '' ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $subfolder) : '');

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            imagedestroy($canvas);
            throw new RuntimeException('Could not create image directory.');
        }

        $base = slug($name);
        $base = $base !== '' ? $base : $variant;
        $filename = $base . '.webp';
        $target = $directory . DIRECTORY_SEPARATOR . $filename;
        $counter = 2;

        while (is_file($target)) {
            $filename = $base . '-' . $counter . '.webp';
            $target = $directory . DIRECTORY_SEPARATOR . $filename;
            $counter++;
        }

        if (!imagewebp($canvas, $target, max(1, min(100, (int) config('site.image_quality', 86))))) {
            imagedestroy($canvas);
            throw new RuntimeException('Could not write WebP image.');
        }

        imagedestroy($canvas);

        return [
            'path' => trim(($subfolder !== '' ? $subfolder . '/' : '') . $filename, '/'),
            'url' => $baseUrl . '/' . ($subfolder !== '' ? $subfolder . '/' : '') . $filename,
            'size' => (int) filesize($target),
        ];
    }
}

if (!function_exists('avatar_apply_orientation')) {
    function avatar_apply_orientation(GdImage $image, string $path): GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $oriented = match ($orientation) {
            2 => avatar_flip($image, IMG_FLIP_HORIZONTAL),
            3 => avatar_rotate($image, 180),
            4 => avatar_flip($image, IMG_FLIP_VERTICAL),
            5 => avatar_flip(avatar_rotate($image, -90), IMG_FLIP_HORIZONTAL),
            6 => avatar_rotate($image, -90),
            7 => avatar_flip(avatar_rotate($image, 90), IMG_FLIP_HORIZONTAL),
            8 => avatar_rotate($image, 90),
            default => $image,
        };

        return $oriented instanceof GdImage ? $oriented : $image;
    }
}

if (!function_exists('avatar_rotate')) {
    function avatar_rotate(GdImage $image, int $angle): GdImage
    {
        $rotated = imagerotate($image, $angle, 0);

        return $rotated instanceof GdImage ? $rotated : $image;
    }
}

if (!function_exists('avatar_flip')) {
    function avatar_flip(GdImage $image, int $mode): GdImage
    {
        if (function_exists('imageflip')) {
            imageflip($image, $mode);
        }

        return $image;
    }
}

if (!function_exists('auth_account_url')) {
    function auth_account_url(): string
    {
        return (string) config('auth.account_url', '/account');
    }
}

if (!function_exists('auth_landing_url')) {
    function auth_landing_url(?array $user = null): string
    {
        $user ??= auth();

        if ($user !== null && (string) ($user['role'] ?? '') === 'admin') {
            return (string) config('auth.home_url', '/admin');
        }

        $id = (int) ($user['id'] ?? 0);

        return $id > 0 ? author_url($id) : auth_account_url();
    }
}

if (!function_exists('auth_next_path')) {
    function auth_next_path(string $next): string
    {
        return route_path((string) (parse_url($next, PHP_URL_PATH) ?: '/'));
    }
}

if (!function_exists('auth_safe_next_url')) {
    function auth_safe_next_url(string $next): string
    {
        $next = trim($next);

        if ($next === '' || !str_starts_with($next, '/') || str_starts_with($next, '//')) {
            return '';
        }

        $path = auth_next_path($next);

        if (
            in_array($path, ['/login', '/register', '/install'], true)
            || $path === '/api'
            || str_starts_with($path, '/api/')
            || str_starts_with($path, '/install/')
        ) {
            return '';
        }

        return $next;
    }
}

if (!function_exists('auth_referer_next_url')) {
    function auth_referer_next_url(): string
    {
        $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));

        if ($referer === '') {
            return '';
        }

        $refererHost = strtolower((string) (parse_url($referer, PHP_URL_HOST) ?: ''));
        $currentHost = strtolower((string) (parse_url('http://' . (string) ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST) ?: ''));

        if ($refererHost === '' || $currentHost === '' || $refererHost !== $currentHost) {
            return '';
        }

        $path = (string) (parse_url($referer, PHP_URL_PATH) ?: '/');
        $query = (string) (parse_url($referer, PHP_URL_QUERY) ?: '');

        return auth_safe_next_url($path . ($query !== '' ? '?' . $query : ''));
    }
}

if (!function_exists('registration_enabled')) {
    function registration_enabled(): bool
    {
        return (bool) config('auth.registration.enabled', false);
    }
}

if (!function_exists('registration_auto_approve')) {
    function registration_auto_approve(): bool
    {
        return (bool) config('auth.registration.auto_approve', false);
    }
}

if (!function_exists('username_normalize')) {
    function username_normalize(string $username): string
    {
        return strtolower(trim($username));
    }
}

if (!function_exists('username_valid')) {
    function username_valid(string $username): bool
    {
        return preg_match('/^[a-z][a-z0-9_]{2,31}$/', username_normalize($username)) === 1;
    }
}

if (!function_exists('username_hint')) {
    function username_hint(): string
    {
        return t('account.username_hint');
    }
}

if (!function_exists('user_username_taken')) {
    function user_username_taken(string $username, ?int $ignoreId = null): bool
    {
        $username = username_normalize($username);

        if ($username === '') {
            return false;
        }

        $params = ['username' => $username];
        $sql = 'SELECT COUNT(*) FROM users WHERE username = :username';

        if ($ignoreId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $ignoreId;
        }

        try {
            return (int) val($sql, $params) > 0;
        } catch (Throwable) {
            return false;
        }
    }
}

if (!function_exists('user_recovery_hash_generate')) {
    function user_recovery_hash_generate(): string
    {
        return bin2hex(random_bytes(32));
    }
}

if (!function_exists('user_recovery_hash_ensure')) {
    function user_recovery_hash_ensure(array $user): string
    {
        $id = (int) ($user['id'] ?? 0);
        $hash = trim((string) ($user['recovery_hash'] ?? ''));

        if ($hash !== '' || $id < 1) {
            return $hash;
        }

        $hash = user_recovery_hash_generate();
        update('users', ['recovery_hash' => $hash], ['id' => $id]);

        return $hash;
    }
}

if (!function_exists('user_recovery_hash_rotate')) {
    function user_recovery_hash_rotate(int $id): string
    {
        if ($id < 1) {
            return '';
        }

        $hash = user_recovery_hash_generate();
        update('users', ['recovery_hash' => $hash], ['id' => $id]);

        return $hash;
    }
}

if (!function_exists('user_recovery_hash_normalize')) {
    function user_recovery_hash_normalize(string $hash): string
    {
        $hash = strtolower(trim($hash));

        return preg_match('/^[a-f0-9]{64,128}$/', $hash) === 1 ? $hash : '';
    }
}

if (!function_exists('user_find_by_recovery_hash')) {
    function user_find_by_recovery_hash(string $hash): ?array
    {
        $hash = user_recovery_hash_normalize($hash);

        if ($hash === '') {
            return null;
        }

        return one(
            'SELECT *
            FROM users
            WHERE recovery_hash = ? AND status = ?
            LIMIT 1',
            [$hash, 'active']
        );
    }
}

if (!function_exists('moderation_user_post_count')) {
    function moderation_user_post_count(int $userId): int
    {
        if ($userId < 1) {
            return 0;
        }

        try {
            return (int) val('SELECT COUNT(*) FROM content WHERE author_id = ?', [$userId]);
        } catch (Throwable) {
            return 0;
        }
    }
}

if (!function_exists('moderation_user_reputation')) {
    function moderation_user_reputation(array $user): string
    {
        $userId = (int) ($user['id'] ?? 0);

        if ((string) ($user['role'] ?? '') === 'admin') {
            return 'trusted';
        }

        $createdAt = strtotime((string) ($user['created_at'] ?? '')) ?: time();
        $age = max(0, time() - $createdAt);
        $posts = moderation_user_post_count($userId);

        if ($age >= 7 * 86400 && $posts >= 10) {
            return 'trusted';
        }

        if ($age >= 86400 || $posts >= 3) {
            return 'normal';
        }

        return 'new';
    }
}

if (!function_exists('moderation_action_rules')) {
    function moderation_action_rules(): array
    {
        return [
            'new' => [
                'post' => [3600, 5],
                'comment' => [3600, 20],
                'like' => [3600, 60],
                'report' => [3600, 10],
                'search' => [3600, 300],
            ],
            'normal' => [
                'post' => [3600, 20],
                'comment' => [3600, 80],
                'like' => [3600, 180],
                'report' => [3600, 30],
                'search' => [3600, 900],
            ],
            'trusted' => [
                'post' => [3600, 60],
                'comment' => [3600, 240],
                'like' => [3600, 600],
                'report' => [3600, 80],
                'search' => [3600, 3000],
            ],
        ];
    }
}

if (!function_exists('moderation_action_rule')) {
    function moderation_action_rule(array $user, string $action): array
    {
        $rules = moderation_action_rules();
        $level = moderation_user_reputation($user);

        return $rules[$level][$action] ?? $rules['normal'][$action] ?? [3600, 60];
    }
}

if (!function_exists('moderation_bucket_start')) {
    function moderation_bucket_start(int $window): string
    {
        $window = max(60, $window);
        $bucket = intdiv(time(), $window) * $window;

        return date_db($bucket);
    }
}

if (!function_exists('moderation_action_count')) {
    function moderation_action_count(array $user, string $action): int
    {
        [$window] = moderation_action_rule($user, $action);

        try {
            return (int) val(
                'SELECT action_count FROM user_action_limits WHERE user_id = ? AND action_name = ? AND bucket_start = ? LIMIT 1',
                [(int) ($user['id'] ?? 0), $action, moderation_bucket_start((int) $window)]
            );
        } catch (Throwable) {
            return 0;
        }
    }
}

if (!function_exists('moderation_require_action')) {
    function moderation_require_action(array $user, string $action, string $redirect): void
    {
        if ((string) ($user['role'] ?? '') === 'admin') {
            return;
        }

        [, $limit] = moderation_action_rule($user, $action);

        if (moderation_action_count($user, $action) < (int) $limit) {
            return;
        }

        flash('error', t('moderation.messages.action_limited'));
        redirect($redirect);
    }
}

if (!function_exists('user_muted_until')) {
    function user_muted_until(array $user): string
    {
        $mutedUntil = trim((string) ($user['muted_until'] ?? ''));

        if ($mutedUntil === '') {
            return '';
        }

        $timestamp = strtotime($mutedUntil);

        return $timestamp !== false && $timestamp > time() ? $mutedUntil : '';
    }
}

if (!function_exists('user_is_muted')) {
    function user_is_muted(array $user): bool
    {
        return user_muted_until($user) !== '';
    }
}

if (!function_exists('user_mute')) {
    function user_mute(int $userId, array $actor, string $until, string $reason = ''): void
    {
        if ($userId < 1) {
            return;
        }

        $target = one('SELECT id, role, muted_until FROM users WHERE id = ? LIMIT 1', [$userId]);

        if ($target === null || (string) ($target['role'] ?? '') === 'admin') {
            return;
        }

        $untilValue = date_db($until);
        $currentMutedUntil = trim((string) ($target['muted_until'] ?? ''));
        $currentTimestamp = $currentMutedUntil !== '' ? (strtotime($currentMutedUntil) ?: 0) : 0;
        $newTimestamp = strtotime($untilValue) ?: 0;

        if ($currentTimestamp > $newTimestamp) {
            $untilValue = $currentMutedUntil;
        }

        $data = [
            'muted_until' => $untilValue,
            'muted_by' => ((int) ($actor['id'] ?? 0)) > 0 ? (int) ($actor['id'] ?? 0) : null,
            'muted_reason' => plain_text_limit($reason, 80),
        ];

        update('users', $data, ['id' => $userId]);
    }
}

if (!function_exists('moderation_require_not_muted')) {
    function moderation_require_not_muted(array $user, string $redirect): void
    {
        $mutedUntil = user_muted_until($user);

        if ($mutedUntil === '') {
            return;
        }

        flash('error', t('moderation.messages.account_muted', ['until' => datetime($mutedUntil)]));
        redirect($redirect);
    }
}

if (!function_exists('moderation_record_action')) {
    function moderation_record_action(array $user, string $action): void
    {
        $userId = (int) ($user['id'] ?? 0);

        if ($userId < 1) {
            return;
        }

        [$window] = moderation_action_rule($user, $action);
        $bucket = moderation_bucket_start((int) $window);

        try {
            run(
                'INSERT INTO user_action_limits (user_id, action_name, bucket_start, action_count, updated_at)
                VALUES (?, ?, ?, 1, ?)
                ON DUPLICATE KEY UPDATE action_count = action_count + 1, updated_at = VALUES(updated_at)',
                [$userId, $action, $bucket, date_db()]
            );
        } catch (Throwable) {
            // Moderation limits must never break the primary action.
        }
    }
}

if (!function_exists('moderation_body_fingerprint')) {
    function moderation_body_fingerprint(string $body): string
    {
        $body = strtolower(trim((string) preg_replace('/\s+/', ' ', $body)));

        return $body !== '' ? hash('sha256', $body) : '';
    }
}

if (!function_exists('moderation_require_unique_body')) {
    function moderation_require_unique_body(array $user, string $body, string $redirect, int $ignoreId = 0): void
    {
        $userId = (int) ($user['id'] ?? 0);
        $fingerprint = moderation_body_fingerprint($body);

        if ($userId < 1 || strlen(trim($body)) < 12 || $fingerprint === '') {
            return;
        }

        try {
            $rows = all(
                'SELECT id, body FROM content WHERE author_id = ? AND created_at >= ? ORDER BY id DESC LIMIT 30',
                [$userId, date_db('-1 day')]
            );
        } catch (Throwable) {
            return;
        }

        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) === $ignoreId) {
                continue;
            }

            if (moderation_body_fingerprint((string) ($row['body'] ?? '')) === $fingerprint) {
                flash('error', t('moderation.messages.duplicate_body'));
                redirect($redirect);
            }
        }
    }
}

if (!function_exists('user_profile_normalize_website')) {
    function user_profile_normalize_website(string $website): string
    {
        $website = trim($website);

        if ($website === '') {
            return '';
        }

        if (!preg_match('~^https?://~i', $website)) {
            $website = 'https://' . $website;
        }

        return function_exists('mb_substr') ? mb_substr($website, 0, 255) : substr($website, 0, 255);
    }
}

if (!function_exists('user_profile_valid_url')) {
    function user_profile_valid_url(string $url): bool
    {
        $parts = parse_url($url);

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true);
    }
}

if (!function_exists('plain_text_limit')) {
    function plain_text_limit(string $value, int $limit): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = trim(strip_tags($value));

        if ($limit < 1) {
            return '';
        }

        if (function_exists('mb_substr')) {
            $value = mb_substr($value, 0, $limit);
        } else {
            $value = substr($value, 0, $limit);
        }

        return trim($value);
    }
}

if (!function_exists('user_profile_store_avatar')) {
    function user_profile_store_avatar(array $file, string $name): array
    {
        $title = trim($name) !== '' ? trim($name) : t('account.avatar');

        return avatar_upload($file, $title . ' avatar');
    }
}

if (!function_exists('user_profile_update')) {
    function user_profile_update(array $user, string $redirect): void
    {
        $id = (int) ($user['id'] ?? 0);
        $website = user_profile_normalize_website((string) post('website', ''));
        $bio = plain_text_limit((string) post('bio', ''), 500);
        $locale = language_code((string) post('locale', ''));
        $errors = [];

        if ($website !== '' && !user_profile_valid_url($website)) {
            $errors[] = t('account.messages.website_invalid');
        }

        if ($locale === '' || !array_key_exists($locale, language_packages())) {
            $errors[] = t('settings.messages.invalid_language');
        }

        if ($errors !== []) {
            flash('error', implode(' ', $errors));
            redirect($redirect);
        }

        $data = [
            'website' => $website,
            'bio' => $bio,
            'locale' => $locale,
        ];

        update('users', $data, ['id' => $id]);

        locale($locale);

        flash('success', t('account.messages.profile_saved'));
        redirect($redirect);
    }
}

if (!function_exists('user_avatar_update')) {
    function user_avatar_update(array $user, string $redirect): void
    {
        $id = (int) ($user['id'] ?? 0);
        $name = user_display_name($user);
        $oldAvatarPath = (string) ($user['avatar_path'] ?? '');
        $oldAvatarUrl = (string) ($user['avatar_url'] ?? '');
        $avatar = $_FILES['avatar'] ?? null;

        if ($id < 1 || !is_array($avatar) || (int) ($avatar['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            redirect($redirect);
        }

        try {
            $avatarData = user_profile_store_avatar($avatar, $name);
        } catch (RuntimeException $exception) {
            flash('error', $exception->getMessage());
            redirect($redirect);
        }

        update('users', [
            'avatar_path' => (string) ($avatarData['path'] ?? ''),
            'avatar_url' => (string) ($avatarData['url'] ?? ''),
        ], ['id' => $id]);

        $newAvatarPath = (string) ($avatarData['path'] ?? '');
        $newAvatarUrl = (string) ($avatarData['url'] ?? '');

        if ($oldAvatarPath !== $newAvatarPath || $oldAvatarUrl !== $newAvatarUrl) {
            avatar_delete($oldAvatarPath, $oldAvatarUrl);
        }

        flash('success', t('account.messages.avatar_saved'));
        redirect($redirect);
    }
}

if (!function_exists('author_url')) {
    function author_url(int $id): string
    {
        return $id > 0 ? '/author/' . $id : '/';
    }
}

if (!function_exists('public_author_find')) {
    function public_author_find(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        return db_select(
            'SELECT id,
                username,
                username AS name,
                role,
                status,
                locale,
                website,
                bio,
                avatar_path,
                avatar_url,
                muted_until,
                muted_by,
                muted_reason,
                last_login_at,
                last_seen_at,
                created_at,
                updated_at
            FROM users'
        )
            ->where('id = ?', $id)
            ->where('status = ?', 'active')
            ->limit(1)
            ->one();
    }
}

if (!function_exists('tag_url')) {
    function tag_url(string $tag): string
    {
        $tag = status_tag_normalize($tag);

        return $tag !== '' ? '/tag/' . rawurlencode($tag) : '/';
    }
}

if (!function_exists('author_is_followed')) {
    function author_is_followed(int $followerId, int $authorId): bool
    {
        if ($followerId < 1 || $authorId < 1 || $followerId === $authorId) {
            return false;
        }

        return (int) val(
            'SELECT COUNT(*)
            FROM user_followers
            WHERE user_id = ?
                AND follower_id = ?',
            [$authorId, $followerId]
        ) > 0;
    }
}

if (!function_exists('author_follow')) {
    function author_follow(int $followerId, int $authorId): void
    {
        if ($followerId < 1 || $authorId < 1 || $followerId === $authorId) {
            return;
        }

        if (author_is_followed($followerId, $authorId)) {
            return;
        }

        insert('user_followers', [
            'user_id' => $authorId,
            'follower_id' => $followerId,
        ]);
    }
}

if (!function_exists('author_unfollow')) {
    function author_unfollow(int $followerId, int $authorId): void
    {
        if ($followerId < 1 || $authorId < 1 || $followerId === $authorId) {
            return;
        }

        delete('user_followers', [
            'user_id' => $authorId,
            'follower_id' => $followerId,
        ]);
    }
}

if (!function_exists('author_follow_counts')) {
    function author_follow_counts(int $authorId): array
    {
        if ($authorId < 1) {
            return ['followers' => 0, 'following' => 0];
        }

        return [
            'followers' => (int) val(
                'SELECT COUNT(*)
                FROM user_followers
                WHERE user_id = ?',
                [$authorId]
            ),
            'following' => (int) val(
                'SELECT COUNT(*)
                FROM user_followers
                WHERE follower_id = ?',
                [$authorId]
            ),
        ];
    }
}

if (!function_exists('author_follow_button_html')) {
    function author_follow_button_html(int $authorId, bool $isFollowing): string
    {
        ob_start();
        ?>
        <form method="post" action="<?= e(author_url($authorId)) ?>" data-follow-form data-author-id="<?= e($authorId) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $isFollowing ? 'unfollow' : 'follow' ?>">
            <button class="btn <?= $isFollowing ? 'btn-secondary' : 'btn-primary' ?> btn-sm" type="submit">
                <?= icon($isFollowing ? 'check' : 'plus') ?> <span><?= et($isFollowing ? 'public.unfollow' : 'public.follow') ?></span>
            </button>
        </form>
        <?php

        return trim((string) ob_get_clean());
    }
}

if (!function_exists('author_following_profiles')) {
    function author_following_profiles(int $authorId, int $limit = 12): array
    {
        if ($authorId < 1) {
            return [];
        }

        $limit = max(1, min(50, $limit));
        return all(
            'SELECT u.id,
                u.username,
                u.username AS name,
                u.avatar_url AS avatar_url,
                uf.created_at AS followed_at,
                (
                    SELECT COUNT(*)
                    FROM content c
                    WHERE c.author_id = u.id
                ) AS posts_count
            FROM user_followers uf
            INNER JOIN users u ON u.id = uf.user_id
            WHERE uf.follower_id = ?
                AND u.status = ?
            ORDER BY uf.created_at DESC, u.username ASC
            LIMIT ' . $limit,
            [$authorId, 'active']
        );
    }
}

if (!function_exists('author_activity_stats')) {
    function author_activity_stats(int $authorId): array
    {
        $empty = [
            'posts' => 0,
            'likes_given' => 0,
            'likes_received' => 0,
            'comments' => 0,
        ];

        if ($authorId < 1) {
            return $empty;
        }

        try {
            return [
                'posts' => (int) val(
                    'SELECT COUNT(*)
                    FROM content
                    WHERE author_id = ?',
                    [$authorId]
                ),
                'likes_given' => (int) val(
                    'SELECT
                        (
                            SELECT COUNT(*)
                            FROM content_likes
                            WHERE user_id = ?
                        ) + (
                            SELECT COUNT(*)
                            FROM comment_likes
                            WHERE user_id = ?
                        )',
                    [$authorId, $authorId]
                ),
                'likes_received' => (int) val(
                    'SELECT
                        (
                            SELECT COUNT(*)
                            FROM content_likes cl
                            INNER JOIN content c ON c.id = cl.content_id
                            WHERE c.author_id = ?
                        ) + (
                            SELECT COUNT(*)
                            FROM comment_likes cl
                            INNER JOIN content_comments cc ON cc.id = cl.comment_id
                            WHERE cc.user_id = ?
                        )',
                    [$authorId, $authorId]
                ),
                'comments' => (int) val(
                    'SELECT COUNT(*)
                    FROM content_comments
                    WHERE user_id = ?',
                    [$authorId]
                ),
            ];
        } catch (Throwable) {
            return $empty;
        }
    }
}

if (!function_exists('author_is_online')) {
    function author_is_online(string $lastSeen): bool
    {
        $lastSeen = trim($lastSeen);

        if ($lastSeen === '') {
            return false;
        }

        try {
            $seen = relative_datetime_value($lastSeen);
            $now = relative_datetime_value();
        } catch (Throwable) {
            return false;
        }

        return ($now->getTimestamp() - $seen->getTimestamp()) <= max(60, (int) config('auth.online_window', 300));
    }
}

if (!function_exists('author_presence')) {
    function author_presence(array $author): array
    {
        $lastSeen = trim((string) ($author['last_seen_at'] ?? ''));
        $lastLogin = trim((string) ($author['last_login_at'] ?? ''));
        $value = $lastSeen !== '' ? $lastSeen : $lastLogin;
        $online = author_is_online($value);

        if ($online) {
            return [
                'online' => true,
                'label' => t('public.online_now'),
                'datetime' => $value !== '' ? date_iso($value) : '',
            ];
        }

        if ($value !== '') {
            return [
                'online' => false,
                'label' => t('public.last_seen', ['time' => relative_time($value)]),
                'datetime' => date_iso($value),
            ];
        }

        return [
            'online' => false,
            'label' => t('public.offline'),
            'datetime' => '',
        ];
    }
}

if (!function_exists('status_anchor')) {
    function status_anchor(int $id): string
    {
        return $id > 0 ? 'status-' . $id : '';
    }
}

if (!function_exists('status_url')) {
    function status_url(int $id): string
    {
        return $id > 0 ? '/status/' . $id : '/';
    }
}

if (!function_exists('status_url_host_key')) {
    function status_url_host_key(string $url): string
    {
        $parts = parse_url($url);

        if (!is_array($parts)) {
            return '';
        }

        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if ($host === '') {
            return '';
        }

        return $host . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }
}

if (!function_exists('status_internal_url')) {
    function status_internal_url(string $url): bool
    {
        $url = trim($url);

        if ($url === '' || str_starts_with($url, '//')) {
            return false;
        }

        if (str_starts_with($url, '/')) {
            return !str_starts_with($url, '//');
        }

        if (!preg_match('~^https?://~i', $url)) {
            return false;
        }

        $currentHost = status_url_host_key(absolute_url('/'));
        $urlHost = status_url_host_key($url);

        return $currentHost !== '' && $urlHost !== '' && $currentHost === $urlHost;
    }
}

if (!function_exists('status_external_url_pattern')) {
    function status_external_url_pattern(): string
    {
        return '~(?<![@\p{L}\p{N}_])(?:https?://|www\.)[^\s<>"\']+|(?<![@\p{L}\p{N}_])(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}(?:/[^\s<>"\']*)?~iu';
    }
}

if (!function_exists('status_strip_external_urls')) {
    function status_strip_external_urls(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $text = (string) preg_replace_callback(status_external_url_pattern(), static function (array $match): string {
            [, $tail] = social_split_url_tail((string) ($match[0] ?? ''));

            return $tail;
        }, $text);

        $text = (string) preg_replace('/[ \t]{2,}/', ' ', $text);
        $text = (string) preg_replace('/\s+([.,;:!?])/u', '$1', $text);
        $text = (string) preg_replace('/[ \t]+\R/u', "\n", $text);
        $text = (string) preg_replace('/\R[ \t]+/u', "\n", $text);
        $text = (string) preg_replace('/\R{3,}/u', "\n\n", $text);

        return trim($text);
    }
}

if (!function_exists('author_mention_map')) {
    function author_mention_map(): array
    {
        static $map = null;

        if ($map !== null) {
            return $map;
        }

        $map = [];

        foreach (all('SELECT id, username FROM users WHERE status = ? ORDER BY id ASC', ['active']) as $user) {
            $handle = username_normalize((string) ($user['username'] ?? ''));
            $id = (int) ($user['id'] ?? 0);

            if ($handle !== '' && $id > 0 && !isset($map[$handle])) {
                $map[$handle] = $id;
            }
        }

        return $map;
    }
}

if (!function_exists('author_mention_users')) {
    function author_mention_users(): array
    {
        static $users = null;

        if ($users !== null) {
            return $users;
        }

        $users = [];

        foreach (all('SELECT id, username FROM users WHERE status = ? ORDER BY id ASC', ['active']) as $user) {
            $id = (int) ($user['id'] ?? 0);
            $name = (string) ($user['username'] ?? '');
            $handle = username_normalize($name);

            if ($id > 0) {
                $users[$id] = [
                    'id' => $id,
                    'name' => $name,
                    'handle' => $handle,
                ];
            }
        }

        return $users;
    }
}

if (!function_exists('normalize_mentions_for_storage')) {
    function normalize_mentions_for_storage(string $text): string
    {
        $map = author_mention_map();
        $users = author_mention_users();
        $pattern = '/(?<![A-Za-z0-9_])@([a-z0-9](?:[a-z0-9-]{0,78}[a-z0-9])?)/i';

        return (string) preg_replace_callback($pattern, static function (array $match) use ($map, $users): string {
            $token = strtolower((string) ($match[1] ?? ''));

            if (ctype_digit($token) && isset($users[(int) $token])) {
                return '@' . (int) $token;
            }

            if (isset($map[$token])) {
                return '@' . (int) $map[$token];
            }

            return (string) ($match[0] ?? '');
        }, $text);
    }
}

if (!function_exists('render_mentions')) {
    function render_mentions(string $text, bool $embeds = true, array $hiddenUrls = []): string
    {
        return render_mentions_segment(status_strip_external_urls($text));
    }
}

if (!function_exists('render_status_body')) {
    function render_status_body(array $item): string
    {
        return trim(render_mentions((string) ($item['body'] ?? ''), false));
    }
}

if (!function_exists('render_mentions_segment')) {
    function render_mentions_segment(string $text): string
    {
        $map = author_mention_map();
        $users = author_mention_users();
        $pattern = '/(?<![\\p{L}\\p{N}_])([@#])([\\p{L}\\p{N}](?:[\\p{L}\\p{N}_-]{0,78}[\\p{L}\\p{N}])?)/u';
        $offset = 0;
        $html = '';

        if (!preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
            return nl2br(e($text), false);
        }

        foreach ($matches[0] as $index => $match) {
            $token = (string) $match[0];
            $position = (int) $match[1];
            $symbol = (string) ($matches[1][$index][0] ?? '');
            $handleRaw = (string) ($matches[2][$index][0] ?? '');
            $handle = strtolower($handleRaw);
            $authorId = 0;

            $html .= e(substr($text, $offset, $position - $offset));

            if ($symbol === '@') {
                if (ctype_digit($handle) && isset($users[(int) $handle])) {
                    $authorId = (int) $handle;
                } elseif (isset($map[$handle])) {
                    $authorId = (int) $map[$handle];
                }

                if ($authorId > 0 && isset($users[$authorId])) {
                    $displayHandle = (string) ($users[$authorId]['handle'] ?? '');
                    $display = $displayHandle !== '' ? '@' . $displayHandle : '@' . $authorId;
                    $html .= '<a class="mention-link" href="' . e(author_url($authorId)) . '">' . e($display) . '</a>';
                } else {
                    $html .= e($token);
                }
            } elseif ($symbol === '#') {
                $tag = status_tag_normalize($handleRaw);
                $html .= $tag !== ''
                    ? '<a class="hashtag" href="' . e(tag_url($tag)) . '">' . e($token) . '</a>'
                    : e($token);
            } else {
                $html .= e($token);
            }

            $offset = $position + strlen($token);
        }

        $html .= e(substr($text, $offset));

        return nl2br($html, false);
    }
}

if (!function_exists('social_split_url_tail')) {
    function social_split_url_tail(string $url): array
    {
        $tail = '';

        while ($url !== '' && preg_match('/[\\.,;:!\\?\\)\\]\\}]+$/', $url, $match) === 1) {
            $chunk = (string) ($match[0] ?? '');
            $tail = $chunk . $tail;
            $url = substr($url, 0, -strlen($chunk));
        }

        return [$url, $tail];
    }
}

if (!function_exists('public_status_select_sql')) {
    function public_status_select_sql(): string
    {
        return "SELECT c.id,
                c.body,
                c.author_id AS user_id,
                c.author_id,
                c.published_at,
                c.created_at,
                c.edit_locked_at,
                c.edit_locked_by,
                c.edit_lock_reason,
                u.username AS author_username,
                u.username AS author_name,
                u.website AS author_website,
                u.bio AS author_bio,
                u.avatar_url AS avatar_url,
                (
                    SELECT COUNT(*)
                    FROM content_likes cl
                    WHERE cl.content_id = c.id
                ) AS likes_count,
                (
                    SELECT COUNT(*)
                    FROM content_comments cc
                    WHERE cc.content_id = c.id
                ) AS comments_count
            FROM content c
            INNER JOIN users u ON u.id = c.author_id";
    }
}

if (!function_exists('public_status_id_query')) {
    function public_status_id_query(): CoreQuery
    {
        $feedIndex = (string) config('database.driver', 'mysql') === 'mysql'
            ? ' FORCE INDEX (content_feed_index)'
            : '';

        return db_select(
            'SELECT c.id
            FROM content c' . $feedIndex . '
            INNER JOIN users u ON u.id = c.author_id'
        )
            ->where('u.status = ?', 'active');
    }
}

if (!function_exists('public_status_author_id_query')) {
    function public_status_author_id_query(): CoreQuery
    {
        $authorIndex = (string) config('database.driver', 'mysql') === 'mysql'
            ? ' FORCE INDEX (content_author_index)'
            : '';

        return db_select(
            'SELECT c.id
            FROM content c' . $authorIndex . '
            INNER JOIN users u ON u.id = c.author_id'
        )
            ->where('u.status = ?', 'active');
    }
}

if (!function_exists('public_status_tag_id_query')) {
    function public_status_tag_id_query(int $termId): CoreQuery
    {
        $tagIndex = (string) config('database.driver', 'mysql') === 'mysql'
            ? ' FORCE INDEX (content_tags_term_index)'
            : '';

        return db_select(
            'SELECT c.id
            FROM content_tags ct' . $tagIndex . '
            INNER JOIN content c ON c.id = ct.content_id
            INNER JOIN users u ON u.id = c.author_id'
        )
            ->where('ct.term_id = ?', $termId)
            ->where('u.status = ?', 'active');
    }
}

if (!function_exists('public_status_query')) {
    function public_status_query(): CoreQuery
    {
        return db_select(public_status_select_sql())
            ->where('u.status = ?', 'active');
    }
}

if (!function_exists('public_status_page')) {
    function public_status_page(CoreQuery $query, int $limit = 24, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $ids = array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            $query
                ->order('c.published_at DESC, c.id DESC')
                ->limit($limit, $offset)
                ->all()
        );

        return public_status_items_by_ids($ids);
    }
}

if (!function_exists('public_status_hydrate_page')) {
    function public_status_hydrate_page(CoreQuery $query, int $limit = 24, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $items = $query
            ->order('c.published_at DESC, c.id DESC')
            ->limit($limit, $offset)
            ->all();

        status_preload_feed($items);

        return $items;
    }
}

if (!function_exists('public_status_items')) {
    function public_status_items(int $limit = 24, int $offset = 0): array
    {
        return public_status_page(public_status_id_query(), $limit, $offset);
    }
}

if (!function_exists('public_status_items_for_user')) {
    function public_status_items_for_user(int $userId, int $limit = 24, int $offset = 0): array
    {
        if ($userId < 1) {
            return public_status_items($limit, $offset);
        }

        $authorIds = public_following_author_ids($userId);

        if (count($authorIds) <= 1000) {
            return public_status_page(
                public_status_author_id_query()->whereIn('c.author_id', $authorIds),
                $limit,
                $offset
            );
        }

        return public_status_page(
            public_status_id_query()->where(
                '(
                    c.author_id = ?
                    OR EXISTS (
                        SELECT 1
                        FROM user_followers uf
                        WHERE uf.follower_id = ?
                            AND uf.user_id = c.author_id
                    )
                )',
                $userId,
                $userId
            ),
            $limit,
            $offset
        );
    }
}

if (!function_exists('public_following_author_ids')) {
    function public_following_author_ids(int $userId): array
    {
        static $cache = [];

        if ($userId < 1) {
            return [];
        }

        if (array_key_exists($userId, $cache)) {
            return $cache[$userId];
        }

        $ids = array_map(
            static fn (array $row): int => (int) ($row['user_id'] ?? 0),
            all('SELECT user_id FROM user_followers WHERE follower_id = ?', [$userId])
        );
        $ids[] = $userId;
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        return $cache[$userId] = $ids;
    }
}

if (!function_exists('public_status_items_by_author')) {
    function public_status_items_by_author(int $authorId, int $limit = 24, int $offset = 0): array
    {
        if ($authorId < 1) {
            return [];
        }

        return public_status_page(
            public_status_author_id_query()->where('c.author_id = ?', $authorId),
            $limit,
            $offset
        );
    }
}

if (!function_exists('public_status_items_by_tag')) {
    function public_status_items_by_tag(string $tag, int $limit = 24, int $offset = 0): array
    {
        $tag = status_tag_normalize($tag);

        if ($tag === '') {
            return [];
        }

        $termId = status_term_id_exact($tag);

        if ($termId < 1) {
            return [];
        }

        if ($offset >= 8000) {
            return public_status_page(
                public_status_tag_id_query($termId),
                $limit,
                $offset
            );
        }

        return public_status_page(
            public_status_id_query()
                ->join('INNER JOIN content_tags ct ON ct.content_id = c.id')
                ->where('ct.term_id = ?', $termId),
            $limit,
            $offset
        );
    }
}

if (!function_exists('public_status_item')) {
    function public_status_item(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        return public_status_query()
            ->where('c.id = ?', $id)
            ->limit(1)
            ->one();
    }
}

if (!function_exists('public_status_items_by_ids')) {
    function public_status_items_by_ids(array $ids, bool $preload = true): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $ids = array_slice($ids, 0, 100);
        $rows = public_status_query()
            ->whereIn('c.id', $ids)
            ->all();
        $byId = [];

        foreach ($rows as $row) {
            $byId[(int) ($row['id'] ?? 0)] = $row;
        }

        $ordered = [];

        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        if ($preload) {
            status_preload_feed($ordered);
        }

        return $ordered;
    }
}

if (!function_exists('status_preload_feed')) {
    function status_preload_feed(array $items): void
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn (array $item): int => (int) ($item['id'] ?? 0),
            $items
        ), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return;
        }

        status_preload_latest_parent_comments($ids);
    }
}

if (!function_exists('public_trending_tags')) {
    function public_trending_tags(int $limit = 8, int $days = 7, bool $compute = true): array
    {
        $limit = max(1, min(30, $limit));
        $days = max(1, min(365, $days));
        $since = date_db('-' . $days . ' days');
        $cacheKey = 'public_trending_tags_' . $limit . '_' . $days;

        $cached = public_stats_cache_get($cacheKey, 3600);

        if ($cached !== null) {
            return $cached;
        }

        if (!$compute) {
            return public_stats_cache_read($cacheKey) ?? [];
        }

        $feedIndex = (string) config('database.driver', 'mysql') === 'mysql'
            ? ' FORCE INDEX (content_sidebar_index)'
            : '';
        $rows = db_select(
            'SELECT t.id,
                t.name,
                COUNT(*) AS posts_count,
                MAX(c.published_at) AS latest_at
            FROM content c' . $feedIndex . '
            INNER JOIN users u ON u.id = c.author_id
            INNER JOIN content_tags ct ON ct.content_id = c.id
            INNER JOIN terms t ON t.id = ct.term_id'
        )
            ->where('c.published_at >= ?', $since)
            ->where('u.status = ?', 'active')
            ->group('t.id, t.name')
            ->order('posts_count DESC, latest_at DESC, t.name ASC')
            ->limit($limit)
            ->all();

        $tags = [];

        foreach ($rows as $row) {
            $name = status_tag_normalize((string) ($row['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $tags[] = [
                'id' => (int) ($row['id'] ?? 0),
                'name' => $name,
                'url' => tag_url($name),
                'posts_count' => (int) ($row['posts_count'] ?? 0),
            ];
        }

        public_stats_cache_set($cacheKey, $tags);

        return $tags;
    }
}

if (!function_exists('public_top_authors')) {
    function public_top_authors(int $limit = 5, int $days = 7, bool $compute = true): array
    {
        $limit = max(1, min(20, $limit));
        $days = max(1, min(365, $days));
        $cacheKey = 'public_top_authors_' . $limit . '_' . $days;

        $cached = public_stats_cache_get($cacheKey, 3600);

        if ($cached !== null) {
            return $cached;
        }

        if (!$compute) {
            return public_stats_cache_read($cacheKey) ?? [];
        }

        $feedIndex = (string) config('database.driver', 'mysql') === 'mysql'
            ? ' FORCE INDEX (content_feed_index)'
            : '';
        $authors = db_select(
            'SELECT u.id,
                u.username,
                u.username AS name,
                u.bio,
                u.avatar_url AS avatar_url,
                COUNT(*) AS posts_count,
                MAX(c.published_at) AS latest_at
            FROM content c' . $feedIndex . '
            INNER JOIN users u ON u.id = c.author_id'
        )
            ->where('c.published_at >= ?', date_db('-' . $days . ' days'))
            ->where('u.status = ?', 'active')
            ->group('u.id, u.username, u.bio, u.avatar_url')
            ->order('posts_count DESC, latest_at DESC, u.username ASC')
            ->limit($limit)
            ->all();

        public_stats_cache_set($cacheKey, $authors);

        return $authors;
    }
}

if (!function_exists('public_stats_cache_get')) {
    function public_stats_cache_get(string $key, int $ttl = 300): ?array
    {
        $file = public_stats_cache_file($key);

        if (!public_stats_cache_fresh($key, $ttl)) {
            return null;
        }

        $json = file_get_contents($file);

        if (!is_string($json) || $json === '') {
            return null;
        }

        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }
}

if (!function_exists('public_stats_cache_fresh')) {
    function public_stats_cache_fresh(string $key, int $ttl = 300): bool
    {
        $file = public_stats_cache_file($key);

        return is_file($file) && filemtime($file) >= time() - max(1, $ttl);
    }
}

if (!function_exists('public_stats_cache_read')) {
    function public_stats_cache_read(string $key): ?array
    {
        $file = public_stats_cache_file($key);

        if (!is_file($file)) {
            return null;
        }

        $json = file_get_contents($file);

        if (!is_string($json) || $json === '') {
            return null;
        }

        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }
}

if (!function_exists('public_stats_cache_set')) {
    function public_stats_cache_set(string $key, array $data): void
    {
        $file = public_stats_cache_file($key);
        $directory = dirname($file);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($json)) {
            return;
        }

        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            return;
        }

        @rename($tmp, $file);
    }
}

if (!function_exists('public_stats_cache_file')) {
    function public_stats_cache_file(string $key): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $key) ?: 'public_stats';

        return base_path('storage/cache/' . $safe . '.json');
    }
}

if (!function_exists('public_sidebar')) {
    function public_sidebar(?string $activeTag = null, bool $compute = false): string
    {
        $activeTag = status_tag_normalize((string) $activeTag);
        $tags = public_trending_tags(8, 7, $compute);
        $authors = public_top_authors(5, 7, $compute);
        $needsRefresh = !$compute && (
            !public_stats_cache_fresh('public_trending_tags_8_7', 3600)
            || !public_stats_cache_fresh('public_top_authors_5_7', 3600)
        );
        $sidebarUrl = '/api/sidebar' . ($activeTag !== '' ? '?tag=' . rawurlencode($activeTag) : '');

        ob_start();
        ?>
        <aside class="public-sidebar" aria-label="<?= et('public.sidebar_title') ?>"<?= $needsRefresh ? ' data-public-sidebar data-sidebar-url="' . e($sidebarUrl) . '"' : '' ?>>
            <article class="card public-sidebar-card">
                <div class="card-header">
                    <h2 class="text-base m-0 cluster gap-2"><?= icon('hash') ?> <?= et('public.favorite_topics') ?></h2>
                </div>
                <div class="card-body">
                    <?php if ($tags === []): ?>
                        <p class="text-muted m-0"><?= et('public.favorite_topics_empty') ?></p>
                    <?php else: ?>
                        <nav class="topic-list" aria-label="<?= et('public.favorite_topics') ?>">
                            <?php foreach ($tags as $tag): ?>
                                <?php
                                $name = (string) ($tag['name'] ?? '');
                                $isActive = $activeTag !== '' && $activeTag === $name;
                                ?>
                                <a class="topic-link<?= $isActive ? ' is-active' : '' ?>" href="<?= e((string) ($tag['url'] ?? tag_url($name))) ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
                                    <span class="topic-name">#<?= e($name) ?></span>
                                    <span class="badge"><?= e((int) ($tag['posts_count'] ?? 0)) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </nav>
                    <?php endif; ?>
                </div>
            </article>
            <article class="card public-sidebar-card">
                <div class="card-header">
                    <h2 class="text-base m-0 cluster gap-2"><?= icon('users') ?> <?= et('public.active_users') ?></h2>
                </div>
                <div class="card-body">
                    <?php if ($authors === []): ?>
                        <p class="text-muted m-0"><?= et('public.active_users_empty') ?></p>
                    <?php else: ?>
                        <nav class="sidebar-user-list" aria-label="<?= et('public.active_users') ?>">
                            <?php foreach ($authors as $author): ?>
                                <?php
                                $id = (int) ($author['id'] ?? 0);
                                $name = trim((string) ($author['name'] ?? ''));
                                $avatarUrl = (string) ($author['avatar_url'] ?? '');
                                ?>
                                <?php if ($id > 0 && $name !== ''): ?>
                                    <a class="sidebar-user-link" href="<?= e(author_url($id)) ?>">
                                        <span class="avatar avatar-sm">
                                            <?php if ($avatarUrl !== ''): ?>
                                                <img src="<?= e($avatarUrl) ?>" alt="<?= e($name) ?>" loading="lazy">
                                            <?php else: ?>
                                                <?= icon('user') ?>
                                            <?php endif; ?>
                                        </span>
                                        <span class="sidebar-user-main">
                                            <strong><?= e($name) ?></strong>
                                            <small><?= et('public.active_user_posts', ['count' => (int) ($author['posts_count'] ?? 0)]) ?></small>
                                        </span>
                                    </a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </nav>
                    <?php endif; ?>
                </div>
            </article>
        </aside>
        <?php

        return trim((string) ob_get_clean());
    }
}

if (!function_exists('public_search_excerpt')) {
    function public_search_excerpt(string $text, string $query, int $limit = 120): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $query = trim($query);

        if ($text === '') {
            return '';
        }

        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);

        if ($length <= $limit) {
            return $text;
        }

        $position = $query !== ''
            ? (function_exists('mb_stripos') ? mb_stripos($text, $query, 0, 'UTF-8') : stripos($text, $query))
            : false;
        $start = $position === false ? 0 : max(0, (int) $position - 40);
        $excerpt = function_exists('mb_substr')
            ? mb_substr($text, $start, $limit, 'UTF-8')
            : substr($text, $start, $limit);

        return ($start > 0 ? '...' : '') . trim($excerpt) . ($start + $limit < $length ? '...' : '');
    }
}

if (!function_exists('public_search_empty_result')) {
    function public_search_empty_result(string $query): array
    {
        return [
            'query' => $query,
            'tags' => [],
            'users' => [],
            'content' => [],
        ];
    }
}

if (!function_exists('public_search_normalize_query')) {
    function public_search_normalize_query(string $query): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $query));
    }
}

if (!function_exists('public_search_query_too_short')) {
    function public_search_query_too_short(string $query): bool
    {
        return (function_exists('mb_strlen') ? mb_strlen($query, 'UTF-8') : strlen($query)) < 2;
    }
}

if (!function_exists('public_search_guest_limits')) {
    function public_search_guest_limits(): array
    {
        return [
            'max' => 10,
            'window' => 300,
            'unlock' => 600,
        ];
    }
}

if (!function_exists('public_search_guest_state')) {
    function public_search_guest_state(bool $increment = false): array
    {
        Core::session();

        $limits = public_search_guest_limits();
        $now = time();
        $state = $_SESSION['_tinycat_search_guard'] ?? [];

        if (!is_array($state)) {
            $state = [];
        }

        $started = (int) ($state['started_at'] ?? 0);

        if ($started < 1 || $started <= $now - (int) $limits['window']) {
            $state = [
                'started_at' => $now,
                'count' => 0,
                'unlocked_until' => (int) ($state['unlocked_until'] ?? 0),
            ];
        }

        if ($increment && (int) ($state['unlocked_until'] ?? 0) < $now) {
            $state['count'] = (int) ($state['count'] ?? 0) + 1;
        }

        $_SESSION['_tinycat_search_guard'] = $state;

        return $state + [
            'started_at' => $now,
            'count' => 0,
            'unlocked_until' => 0,
        ];
    }
}

if (!function_exists('public_search_guest_unlock')) {
    function public_search_guest_unlock(): void
    {
        Core::session();

        $limits = public_search_guest_limits();
        $_SESSION['_tinycat_search_guard'] = [
            'started_at' => time(),
            'count' => 0,
            'unlocked_until' => time() + (int) $limits['unlock'],
        ];
    }
}

if (!function_exists('public_search_guard')) {
    function public_search_guard(string $query, bool $increment = true): ?array
    {
        $query = public_search_normalize_query($query);

        if (public_search_query_too_short($query)) {
            return null;
        }

        $user = auth();

        if ($user !== null) {
            if ((string) ($user['role'] ?? '') === 'admin') {
                return null;
            }

            [, $limit] = moderation_action_rule($user, 'search');

            if (moderation_action_count($user, 'search') >= (int) $limit) {
                return [
                    'code' => 'action_limited',
                    'message' => t('moderation.messages.action_limited'),
                    'login_url' => '',
                ];
            }

            if ($increment) {
                moderation_record_action($user, 'search');
            }

            return null;
        }

        if (!(bool) config('security.captcha.enabled', true)) {
            return null;
        }

        $limits = public_search_guest_limits();
        $state = public_search_guest_state($increment);
        $now = time();

        if ((int) ($state['unlocked_until'] ?? 0) >= $now || (int) ($state['count'] ?? 0) <= (int) $limits['max']) {
            return null;
        }

        captcha_refresh('search');

        return [
            'code' => 'captcha_required',
            'message' => t('public.search_captcha_required'),
            'captcha_html' => captcha_field('search'),
            'verify_url' => '/api/search-captcha',
            'login_url' => '/login',
            'retry_after' => max(1, (int) ($state['started_at'] ?? $now) + (int) $limits['window'] - $now),
        ];
    }
}

if (!function_exists('public_search_api_guard')) {
    function public_search_api_guard(string $query): void
    {
        $blocked = public_search_guard($query);

        if ($blocked !== null) {
            api_error(
                (string) ($blocked['message'] ?? t('public.search_captcha_required')),
                429,
                (string) ($blocked['code'] ?? 'captcha_required'),
                $blocked
            );
        }
    }
}

if (!function_exists('public_search_captcha_verify')) {
    function public_search_captcha_verify(): bool
    {
        if (!captcha_check('search')) {
            captcha_refresh('search');
            return false;
        }

        public_search_guest_unlock();

        return true;
    }
}

if (!function_exists('public_search_fulltext_query')) {
    function public_search_fulltext_query(string $query): string
    {
        $tokens = preg_split('/[^\p{L}\p{N}_]+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $terms = [];

        foreach ($tokens as $token) {
            $token = trim($token);
            $length = function_exists('mb_strlen') ? mb_strlen($token, 'UTF-8') : strlen($token);

            if ($length < 4) {
                continue;
            }

            $terms[] = '+' . $token . '*';

            if (count($terms) >= 6) {
                break;
            }
        }

        return implode(' ', $terms);
    }
}

if (!function_exists('public_search_fulltext_ready')) {
    function public_search_fulltext_ready(string $table, string $index): bool
    {
        static $cache = [];

        $key = $table . ':' . $index;

        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        if ((string) config('database.driver', 'mysql') !== 'mysql') {
            $cache[$key] = false;
            return false;
        }

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $index)) {
            $cache[$key] = false;
            return false;
        }

        try {
            $cache[$key] = (int) val(
                'SELECT COUNT(*)
                FROM INFORMATION_SCHEMA.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?
                    AND INDEX_NAME = ?
                    AND INDEX_TYPE = ?',
                [$table, $index, 'FULLTEXT']
            ) > 0;
        } catch (Throwable) {
            $cache[$key] = false;
        }

        return $cache[$key];
    }
}

if (!function_exists('public_search_text_contains')) {
    function public_search_text_contains(string $text, string $query): bool
    {
        if ($text === '' || $query === '') {
            return false;
        }

        return (function_exists('mb_stripos') ? mb_stripos($text, $query, 0, 'UTF-8') : stripos($text, $query)) !== false;
    }
}

if (!function_exists('public_search_content_result')) {
    function public_search_content_result(array $item, string $query, ?string $excerptText = null): array
    {
        $id = (int) ($item['id'] ?? 0);
        $authorId = (int) ($item['author_id'] ?? 0);
        $createdAt = (string) ($item['created_at'] ?? '');
        $excerptText ??= (string) ($item['body'] ?? '');

        return [
            'id' => $id,
            'type' => 'content',
            'title' => (string) ($item['author_name'] ?? ''),
            'excerpt' => public_search_excerpt($excerptText, $query, 120),
            'url' => status_url($id),
            'author_url' => author_url($authorId),
            'created_at' => $createdAt,
            'created_label' => datetime($createdAt),
            'avatar_url' => (string) ($item['avatar_url'] ?? ''),
        ];
    }
}

if (!function_exists('public_search_recent_content_scan')) {
    function public_search_recent_content_scan(string $query, int $limit): array
    {
        $feedIndex = (string) config('database.driver', 'mysql') === 'mysql'
            ? ' FORCE INDEX (content_feed_index)'
            : '';
        $scanLimit = max(300, min(5000, $limit * 250));
        $matches = [];

        foreach (db_select(
            'SELECT c.id,
                c.body,
                c.author_id,
                c.created_at,
                u.username AS author_name,
                u.avatar_url AS avatar_url
            FROM content c' . $feedIndex . '
            INNER JOIN users u ON u.id = c.author_id'
        )
            ->where('u.status = ?', 'active')
            ->order('c.published_at DESC, c.id DESC')
            ->limit($scanLimit)
            ->all() as $item) {
            if (!public_search_text_contains((string) ($item['body'] ?? ''), $query)) {
                continue;
            }

            $matches[] = $item;

            if (count($matches) >= $limit) {
                break;
            }
        }

        return $matches;
    }
}

if (!function_exists('public_search_content_rows')) {
    function public_search_content_rows(string $query, int $limit): array
    {
        $recent = public_search_recent_content_scan($query, $limit);

        if (count($recent) >= $limit) {
            return $recent;
        }

        $fulltext = public_search_fulltext_query($query);

        if ($fulltext !== '' && public_search_fulltext_ready('content', 'content_body_fulltext')) {
            try {
                $fulltextQuery = db_select(
                    'SELECT c.id,
                        c.body,
                        c.author_id,
                        c.created_at,
                        u.username AS author_name,
                        u.avatar_url AS avatar_url
                    FROM content c
                    INNER JOIN users u ON u.id = c.author_id'
                )
                    ->where('MATCH(c.body) AGAINST (? IN BOOLEAN MODE)', $fulltext)
                    ->where('u.status = ?', 'active');

                $existingIds = array_values(array_filter(array_map(
                    static fn (array $item): int => (int) ($item['id'] ?? 0),
                    $recent
                ), static fn (int $id): bool => $id > 0));

                if ($existingIds !== []) {
                    $fulltextQuery->where(
                        'c.id NOT IN (' . implode(', ', array_fill(0, count($existingIds), '?')) . ')',
                        $existingIds
                    );
                }

                foreach ($fulltextQuery
                    ->order('c.published_at DESC, c.id DESC')
                    ->limit(max($limit, min(100, $limit * 4)))
                    ->all() as $item) {
                    $recent[] = $item;

                    if (count($recent) >= $limit) {
                        break;
                    }
                }
            } catch (Throwable) {
                return $recent;
            }
        }

        return $recent;
    }
}

if (!function_exists('public_search_suggestion_tags')) {
    function public_search_suggestion_tags(string $query, int $limit): array
    {
        $tagQuery = status_tag_normalize($query);

        if ($tagQuery === '') {
            return [];
        }

        $tags = [];

        foreach (db_select('SELECT t.id, t.name FROM terms t')
            ->where('t.name LIKE ?', $tagQuery . '%')
            ->order('t.name ASC')
            ->limit($limit)
            ->all() as $tag) {
            $name = status_tag_normalize((string) ($tag['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $tags[] = [
                'id' => (int) ($tag['id'] ?? 0),
                'type' => 'tag',
                'title' => '#' . $name,
                'excerpt' => '',
                'url' => tag_url($name),
                'posts_count' => 0,
            ];
        }

        return $tags;
    }
}

if (!function_exists('public_search_suggestion_users')) {
    function public_search_suggestion_users(string $query, int $limit): array
    {
        $usernameQuery = username_normalize(ltrim($query, '@'));

        if ($usernameQuery === '') {
            return [];
        }

        $users = [];

        foreach (db_select(
            'SELECT u.id, u.username, u.username AS name, u.bio, u.avatar_url AS avatar_url
            FROM users u'
        )
            ->where('u.status = ?', 'active')
            ->where('u.username LIKE ?', $usernameQuery . '%')
            ->order('u.username ASC, u.id ASC')
            ->limit($limit)
            ->all() as $user) {
            $id = (int) ($user['id'] ?? 0);

            if ($id < 1) {
                continue;
            }

            $users[] = [
                'id' => $id,
                'type' => 'user',
                'title' => (string) ($user['name'] ?? ''),
                'excerpt' => public_search_excerpt((string) ($user['bio'] ?? ''), $query, 90),
                'url' => author_url($id),
                'avatar_url' => (string) ($user['avatar_url'] ?? ''),
            ];
        }

        return $users;
    }
}

if (!function_exists('public_search_suggestions')) {
    function public_search_suggestions(string $query, int $limit = 6): array
    {
        $query = public_search_normalize_query($query);
        $limit = max(1, min(8, $limit));

        if ($query === '' || public_search_query_too_short($query)) {
            return public_search_empty_result($query);
        }

        return [
            'query' => $query,
            'tags' => public_search_suggestion_tags($query, $limit),
            'users' => public_search_suggestion_users($query, $limit),
            'content' => [],
        ];
    }
}

if (!function_exists('public_search_results')) {
    function public_search_results(string $query, int $limit = 6): array
    {
        $query = public_search_normalize_query($query);
        $limit = max(1, min(12, $limit));

        if ($query === '' || public_search_query_too_short($query)) {
            return public_search_empty_result($query);
        }

        $like = '%' . $query . '%';
        $queryLength = function_exists('mb_strlen') ? mb_strlen($query, 'UTF-8') : strlen($query);
        $shortSearch = $queryLength < 4;
        $tagQuery = status_tag_normalize($query);
        $tagLike = $tagQuery !== ''
            ? ($shortSearch ? $tagQuery . '%' : '%' . $tagQuery . '%')
            : '%' . $query . '%';
        $tags = [];
        $users = [];
        $content = [];
        $contentIds = [];

        foreach (db_select(
            'SELECT t.id, t.name, COUNT(ct.content_id) AS posts_count
            FROM terms t
            LEFT JOIN content_tags ct ON ct.term_id = t.id'
        )
            ->where('t.name LIKE ?', $tagLike)
            ->group('t.id, t.name')
            ->order('posts_count DESC, t.name ASC')
            ->limit($limit)
            ->all() as $tag) {
            $name = status_tag_normalize((string) ($tag['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $tags[] = [
                'id' => (int) ($tag['id'] ?? 0),
                'type' => 'tag',
                'title' => '#' . $name,
                'excerpt' => t('public.search_tag_posts', ['count' => (int) ($tag['posts_count'] ?? 0)]),
                'url' => tag_url($name),
                'posts_count' => (int) ($tag['posts_count'] ?? 0),
            ];
        }

        $userQuery = db_select(
            'SELECT u.id, u.username, u.username AS name, u.bio, u.avatar_url AS avatar_url
            FROM users u'
        )->where('u.status = ?', 'active');
        $usernameQuery = username_normalize(ltrim($query, '@'));

        if ($shortSearch) {
            if ($usernameQuery !== '') {
                $userQuery->where('u.username LIKE ?', $usernameQuery . '%');
            } else {
                $userQuery->where('1 = 0');
            }
        } else {
            $userQuery->where(
                '(
                    u.username LIKE ?
                    OR u.bio LIKE ?
                    OR u.website LIKE ?
                )',
                $like,
                $like,
                $like
            );
        }

        foreach ($userQuery
            ->order('u.username ASC, u.id ASC')
            ->limit($limit)
            ->all() as $user) {
            $id = (int) ($user['id'] ?? 0);

            if ($id < 1) {
                continue;
            }

            $users[] = [
                'id' => $id,
                'type' => 'user',
                'title' => (string) ($user['name'] ?? ''),
                'excerpt' => public_search_excerpt((string) ($user['bio'] ?? ''), $query, 90),
                'url' => author_url($id),
                'avatar_url' => (string) ($user['avatar_url'] ?? ''),
            ];
        }

        foreach (public_search_content_rows($query, $limit) as $item) {
            $id = (int) ($item['id'] ?? 0);
            $authorId = (int) ($item['author_id'] ?? 0);

            if ($id < 1 || $authorId < 1) {
                continue;
            }

            $content[] = public_search_content_result($item, $query);
            $contentIds[$id] = true;
        }

        return [
            'query' => $query,
            'tags' => $tags,
            'users' => $users,
            'content' => $content,
        ];
    }
}

if (!function_exists('status_find')) {
    function status_find(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        return db_select('SELECT * FROM content')
            ->where('id = ?', $id)
            ->limit(1)
            ->one();
    }
}

if (!function_exists('status_edit_locked')) {
    function status_edit_locked(?array $item): bool
    {
        return $item !== null && trim((string) ($item['edit_locked_at'] ?? '')) !== '';
    }
}

if (!function_exists('status_edit_lock')) {
    function status_edit_lock(int $contentId, array $actor, string $reason = ''): void
    {
        if ($contentId < 1) {
            return;
        }

        $actorId = (int) ($actor['id'] ?? 0);
        $data = [
            'edit_locked_at' => date_db(),
            'edit_locked_by' => $actorId > 0 ? $actorId : null,
            'edit_lock_reason' => plain_text_limit($reason, 80),
        ];

        update('content', $data, ['id' => $contentId]);
    }
}

if (!function_exists('status_can_edit')) {
    function status_can_edit(?array $item, ?array $user): bool
    {
        return $item !== null
            && $user !== null
            && !status_edit_locked($item)
            && (int) ($item['author_id'] ?? 0) === (int) ($user['id'] ?? 0);
    }
}

if (!function_exists('status_can_delete')) {
    function status_can_delete(?array $item, ?array $user): bool
    {
        if ($item === null || $user === null) {
            return false;
        }

        return (int) ($item['author_id'] ?? 0) === (int) ($user['id'] ?? 0)
            || (string) ($user['role'] ?? '') === 'admin';
    }
}

if (!function_exists('status_user_liked')) {
    function status_user_liked(int $contentId, int $userId): bool
    {
        if ($contentId < 1 || $userId < 1) {
            return false;
        }

        return db_select('SELECT content_id FROM content_likes')
            ->where('content_id = ?', $contentId)
            ->where('user_id = ?', $userId)
            ->limit(1)
            ->value() !== null;
    }
}

if (!function_exists('status_comments_cache')) {
    function &status_comments_cache(): array
    {
        static $cache = [];

        return $cache;
    }
}

if (!function_exists('status_comment_rows_to_tree')) {
    function status_comment_rows_to_tree(array $rows): array
    {
        $parents = [];
        $children = [];

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $parentId = (int) ($row['parent_id'] ?? 0);

            if ($id < 1) {
                continue;
            }

            $row['replies'] = [];

            if ($parentId > 0) {
                $children[$parentId][] = $row;
                continue;
            }

            $parents[$id] = $row;
        }

        foreach ($children as $parentId => $items) {
            if (!isset($parents[$parentId])) {
                continue;
            }

            $parents[$parentId]['replies'] = $items;
        }

        return array_values($parents);
    }
}

if (!function_exists('status_comments_query')) {
    function status_comments_query(): CoreQuery
    {
        return db_select(
            'SELECT cc.id,
                cc.content_id,
                cc.parent_id,
                cc.user_id,
                cc.body,
                cc.created_at,
                u.username AS author_name,
                u.avatar_url AS avatar_url,
                (
                    SELECT COUNT(*)
                    FROM comment_likes cl
                    WHERE cl.comment_id = cc.id
                ) AS likes_count
            FROM content_comments cc
            INNER JOIN users u ON u.id = cc.user_id'
        )
            ->where('u.status = ?', 'active');
    }
}

if (!function_exists('status_comments')) {
    function status_comments(int $contentId): array
    {
        if ($contentId < 1) {
            return [];
        }

        $cache =& status_comments_cache();

        if (!array_key_exists($contentId, $cache)) {
            $cache[$contentId] = status_comment_rows_to_tree(
                status_comments_query()
                    ->where('cc.content_id = ?', $contentId)
                    ->order('cc.created_at ASC, cc.id ASC')
                    ->all()
            );
        }

        return $cache[$contentId];
    }
}

if (!function_exists('status_preload_comments')) {
    function status_preload_comments(array $contentIds): void
    {
        $contentIds = array_values(array_unique(array_filter(array_map('intval', $contentIds), static fn (int $id): bool => $id > 0)));

        if ($contentIds === []) {
            return;
        }

        $cache =& status_comments_cache();
        $missing = [];

        foreach ($contentIds as $contentId) {
            if (!array_key_exists($contentId, $cache)) {
                $cache[$contentId] = [];
                $missing[] = $contentId;
            }
        }

        if ($missing === []) {
            return;
        }

        $rowsByContent = [];

        foreach (status_comments_query()
            ->whereIn('cc.content_id', $missing)
            ->order('cc.content_id ASC, cc.created_at ASC, cc.id ASC')
            ->all() as $row) {
            $contentId = (int) ($row['content_id'] ?? 0);

            if ($contentId > 0) {
                $rowsByContent[$contentId][] = $row;
            }
        }

        foreach ($missing as $contentId) {
            $cache[$contentId] = status_comment_rows_to_tree($rowsByContent[$contentId] ?? []);
        }
    }
}

if (!function_exists('status_comment_find')) {
    function status_comment_find(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        return db_select('SELECT * FROM content_comments')
            ->where('id = ?', $id)
            ->limit(1)
            ->one();
    }
}

if (!function_exists('status_comment_count')) {
    function status_comment_count(int $contentId): int
    {
        if ($contentId < 1) {
            return 0;
        }

        return db_select('SELECT id FROM content_comments')
            ->where('content_id = ?', $contentId)
            ->count();
    }
}

if (!function_exists('status_latest_parent_comment_cache')) {
    function &status_latest_parent_comment_cache(): array
    {
        static $cache = [];

        return $cache;
    }
}

if (!function_exists('status_latest_parent_comment')) {
    function status_latest_parent_comment(int $contentId): ?array
    {
        if ($contentId < 1) {
            return null;
        }

        $cache =& status_latest_parent_comment_cache();

        if (!array_key_exists($contentId, $cache)) {
            $comment = status_comments_query()
                ->where('cc.content_id = ?', $contentId)
                ->where('cc.parent_id IS NULL')
                ->order('cc.created_at DESC, cc.id DESC')
                ->limit(1)
                ->one();

            if ($comment !== null) {
                $comment['replies'] = [];
            }

            $cache[$contentId] = $comment;
        }

        return $cache[$contentId];
    }
}

if (!function_exists('status_preload_latest_parent_comments')) {
    function status_preload_latest_parent_comments(array $contentIds): void
    {
        $contentIds = array_values(array_unique(array_filter(array_map('intval', $contentIds), static fn (int $id): bool => $id > 0)));

        if ($contentIds === []) {
            return;
        }

        $cache =& status_latest_parent_comment_cache();
        $missing = [];

        foreach ($contentIds as $contentId) {
            if (!array_key_exists($contentId, $cache)) {
                $cache[$contentId] = null;
                $missing[] = $contentId;
            }
        }

        if ($missing === []) {
            return;
        }

        foreach (status_comments_query()
            ->whereIn('cc.content_id', $missing)
            ->where('cc.parent_id IS NULL')
            ->order('cc.content_id ASC, cc.created_at DESC, cc.id DESC')
            ->all() as $comment) {
            $contentId = (int) ($comment['content_id'] ?? 0);

            if ($contentId < 1 || $cache[$contentId] !== null) {
                continue;
            }

            $comment['replies'] = [];
            $cache[$contentId] = $comment;
        }
    }
}

if (!function_exists('status_comment_can_delete')) {
    function status_comment_can_delete(?array $comment, ?array $user): bool
    {
        if ($comment === null || $user === null) {
            return false;
        }

        $userId = (int) ($user['id'] ?? 0);

        if ($userId < 1) {
            return false;
        }

        if ((string) ($user['role'] ?? '') === 'admin' || (int) ($comment['user_id'] ?? 0) === $userId) {
            return true;
        }

        $status = status_find((int) ($comment['content_id'] ?? 0));

        return $status !== null && (int) ($status['author_id'] ?? 0) === $userId;
    }
}

if (!function_exists('status_comment_user_liked')) {
    function status_comment_user_liked(int $commentId, int $userId): bool
    {
        if ($commentId < 1 || $userId < 1) {
            return false;
        }

        return db_select('SELECT comment_id FROM comment_likes')
            ->where('comment_id = ?', $commentId)
            ->where('user_id = ?', $userId)
            ->exists();
    }
}

if (!function_exists('status_comment_like_count')) {
    function status_comment_like_count(int $commentId): int
    {
        if ($commentId < 1) {
            return 0;
        }

        return db_select('SELECT comment_id FROM comment_likes')
            ->where('comment_id = ?', $commentId)
            ->count();
    }
}

if (!function_exists('status_tag_normalize')) {
    function status_tag_normalize(string $tag): string
    {
        $tag = trim($tag);
        $tag = ltrim($tag, "# \t\n\r\0\x0B");
        $tag = slug($tag);

        return substr($tag, 0, 80);
    }
}

if (!function_exists('status_tags_from_text')) {
    function status_tags_from_text(string $text): array
    {
        if (!preg_match_all('/(?<![\\p{L}\\p{N}_])#([\\p{L}\\p{N}][\\p{L}\\p{N}_-]{0,79})/u', $text, $matches)) {
            return [];
        }

        $tags = [];

        foreach ((array) ($matches[1] ?? []) as $item) {
            $tag = status_tag_normalize((string) $item);

            if ($tag !== '') {
                $tags[$tag] = $tag;
            }
        }

        return array_values($tags);
    }
}

if (!function_exists('normalize_tags_for_storage')) {
    function normalize_tags_for_storage(string $text): string
    {
        return (string) preg_replace_callback(
            '/(?<![\\p{L}\\p{N}_])#([\\p{L}\\p{N}][\\p{L}\\p{N}_-]{0,79})/u',
            static function (array $match): string {
                $tag = status_tag_normalize((string) ($match[1] ?? ''));

                return $tag !== '' ? '#' . $tag : (string) ($match[0] ?? '');
            },
            $text
        );
    }
}

if (!function_exists('status_tag_suggestions')) {
    function status_tag_suggestions(): array
    {
        static $tags = null;

        if ($tags !== null) {
            return $tags;
        }

        $tags = [];

        try {
            foreach (db_select('SELECT name FROM terms')->order('name ASC')->limit(300)->all() as $row) {
                $tag = status_tag_normalize((string) ($row['name'] ?? ''));

                if ($tag !== '') {
                    $tags[$tag] = $tag;
                }
            }
        } catch (Throwable) {
            $tags = [];
        }

        return array_values($tags);
    }
}

if (!function_exists('status_term_id')) {
    function status_term_id(string $tag): int
    {
        $tag = status_tag_normalize($tag);

        if ($tag === '') {
            return 0;
        }

        $term = db_select('SELECT id, name FROM terms')
            ->where('name = ?', $tag)
            ->limit(1)
            ->one();
        $id = (int) ($term['id'] ?? 0);

        if ($id > 0) {
            if ((string) ($term['name'] ?? '') !== $tag) {
                update('terms', ['name' => $tag], ['id' => $id]);
            }

            return $id;
        }

        try {
            return (int) insert('terms', ['name' => $tag]);
        } catch (Throwable) {
            $term = db_select('SELECT id, name FROM terms')
                ->where('name = ?', $tag)
                ->limit(1)
                ->one();
            $id = (int) ($term['id'] ?? 0);

            if ($id > 0 && (string) ($term['name'] ?? '') !== $tag) {
                update('terms', ['name' => $tag], ['id' => $id]);
            }

            return $id;
        }
    }
}

if (!function_exists('status_term_id_exact')) {
    function status_term_id_exact(string $tag): int
    {
        $tag = status_tag_normalize($tag);

        if ($tag === '') {
            return 0;
        }

        foreach (db_select('SELECT id, name FROM terms')->where('name = ?', $tag)->all() as $term) {
            if ((string) ($term['name'] ?? '') === $tag) {
                return (int) ($term['id'] ?? 0);
            }
        }

        return 0;
    }
}

if (!function_exists('status_sync_tags')) {
    function status_sync_tags(int $contentId, array $tags): void
    {
        if ($contentId < 1) {
            return;
        }

        delete('content_tags', ['content_id' => $contentId]);

        foreach ($tags as $tag) {
            $termId = status_term_id((string) $tag);

            if ($termId < 1) {
                continue;
            }

            try {
                insert('content_tags', [
                    'content_id' => $contentId,
                    'term_id' => $termId,
                ]);
            } catch (Throwable) {
                // Duplicate pairs can happen only under a race or manual data edit.
            }
        }

        status_cleanup_unused_terms();
    }
}

if (!function_exists('status_cleanup_unused_terms')) {
    function status_cleanup_unused_terms(): void
    {
        run(
            'DELETE FROM terms
            WHERE id NOT IN (
                SELECT term_id FROM content_tags
            )'
        );
    }
}

if (!function_exists('status_post_modal_id')) {
    function status_post_modal_id(int $contentId): string
    {
        return 'status-post-modal-' . max(0, $contentId);
    }
}

if (!function_exists('status_edit_modal_id')) {
    function status_edit_modal_id(int $contentId): string
    {
        return 'status-edit-modal-' . max(0, $contentId);
    }
}

if (!function_exists('status_post_modal_url')) {
    function status_post_modal_url(int $contentId, string $action = ''): string
    {
        $query = ['id' => max(0, $contentId)];

        if ($action !== '') {
            $query['action'] = $action;
        }

        return '/api/status-modal?' . http_build_query($query);
    }
}

if (!function_exists('status_action_modal_url')) {
    function status_action_modal_url(string $type, int $contentId, string $action = ''): string
    {
        $type = in_array($type, ['report', 'edit'], true) ? $type : 'edit';
        $query = ['id' => max(0, $contentId)];

        if ($action !== '') {
            $query['action'] = $action;
        }

        return '/api/status-' . $type . '-modal?' . http_build_query($query);
    }
}

if (!function_exists('status_report_modal_id')) {
    function status_report_modal_id(int $contentId): string
    {
        return 'status-report-modal-' . max(0, $contentId);
    }
}

if (!function_exists('author_profile_edit_modal_id')) {
    function author_profile_edit_modal_id(int $authorId): string
    {
        return 'profile-edit-modal-' . max(0, $authorId);
    }
}

if (!function_exists('author_profile_edit_modal_url')) {
    function author_profile_edit_modal_url(int $authorId, string $focus = ''): string
    {
        $focus = in_array($focus, ['website', 'locale', 'bio'], true) ? $focus : '';
        $query = ['author_id' => max(0, $authorId)];

        if ($focus !== '') {
            $query['focus'] = $focus;
        }

        return '/api/profile-edit-modal?' . http_build_query($query);
    }
}

if (!function_exists('status_time_button')) {
    function status_time_button(string $createdAt, int $contentId, bool $openModal = true, string $action = ''): string
    {
        if ($createdAt === '') {
            return '';
        }

        ob_start();
        ?>
        <?php if ($openModal): ?>
            <button class="link-button public-content-meta status-time-button" type="button" data-modal-open="<?= e(status_post_modal_id($contentId)) ?>" data-modal-url="<?= e(status_post_modal_url($contentId, $action)) ?>">
                <time datetime="<?= e(date_iso($createdAt)) ?>"><?= e(datetime($createdAt)) ?></time>
            </button>
        <?php else: ?>
            <a class="link-button public-content-meta status-time-button" href="<?= e(status_url($contentId)) ?>">
                <time datetime="<?= e(date_iso($createdAt)) ?>"><?= e(datetime($createdAt)) ?></time>
            </a>
        <?php endif; ?>
        <?php

        return trim((string) ob_get_clean());
    }
}

if (!function_exists('status_field')) {
    function status_field(?array $item = null): string
    {
        $tags = json_encode(
            status_tag_suggestions(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        ob_start();
        ?>
        <div class="field status-field" data-status-editor>
            <textarea class="textarea status-textarea" name="body" rows="4" maxlength="2000" placeholder="<?= et('account.status_body') ?>" aria-label="<?= et('account.status_body') ?>" data-status-editor-source data-status-tags="<?= e((string) $tags) ?>" data-status-placeholder="<?= et('account.status_body') ?>" data-status-counter="<?= et('account.status_counter') ?>"><?= e($item['body'] ?? '') ?></textarea>
        </div>
        <?php

        return trim((string) ob_get_clean());
    }
}

if (!function_exists('status_composer')) {
    function status_composer(string $action, array $user): string
    {
        $avatarUrl = user_avatar_url($user);

        ob_start();
        ?>
        <section class="card status-composer">
            <div class="card-body">
                <form method="post" action="<?= e($action) ?>" data-status-form data-status-scope="feed">
                    <?= csrf_field() ?>
                    <div class="status-compose-row">
                        <div class="avatar">
                            <?php if ($avatarUrl !== ''): ?>
                                <img src="<?= e($avatarUrl) ?>" alt="<?= e(user_display_name($user)) ?>" loading="lazy">
                            <?php else: ?>
                                <?= icon('user') ?>
                            <?php endif; ?>
                        </div>
                        <div class="status-compose-main">
                            <?= status_field(null) ?>
                            <div class="status-compose-footer">
                                <div class="status-compose-counter" data-status-editor-meta-slot></div>
                                <div class="status-compose-actions">
                                    <button class="btn btn-primary" type="submit"><?= icon('plus') ?> <span><?= et('account.status_create') ?></span></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </section>
        <?php

        return trim((string) ob_get_clean());
    }
}

if (!function_exists('notification_icon')) {
    function notification_icon(string $type): string
    {
        return match ($type) {
            'content_like' => 'thumb-up',
            'comment_like' => 'thumb-up',
            'content_comment' => 'message-circle',
            'report_resolved' => 'check',
            'report_dismissed' => 'flag',
            default => 'bell',
        };
    }
}

if (!function_exists('notification_message')) {
    function notification_message(array $notification): string
    {
        $type = (string) ($notification['type'] ?? '');
        $actor = trim((string) ($notification['actor_name'] ?? ''));
        $actor = $actor !== '' ? $actor : t('notifications.someone');

        return match ($type) {
            'content_like' => t('notifications.messages.content_like', ['actor' => $actor]),
            'comment_like' => t('notifications.messages.comment_like', ['actor' => $actor]),
            'content_comment' => t('notifications.messages.content_comment', ['actor' => $actor]),
            'report_resolved' => t('notifications.messages.report_resolved', ['actor' => $actor]),
            'report_dismissed' => t('notifications.messages.report_dismissed', ['actor' => $actor]),
            default => t('notifications.messages.generic', ['actor' => $actor]),
        };
    }
}

if (!function_exists('notification_url')) {
    function notification_url(array $notification): string
    {
        $contentId = (int) ($notification['content_id'] ?? 0);

        return $contentId > 0 ? status_url($contentId) : '/notifications';
    }
}

if (!function_exists('notification_create')) {
    function notification_create(int $userId, string $type, int $actorId, int $contentId = 0, int $commentId = 0, string $key = ''): void
    {
        if ($userId < 1 || $actorId < 1 || $userId === $actorId) {
            return;
        }

        $type = plain_text_limit($type, 40);

        if ($type === '') {
            return;
        }

        $key = plain_text_limit($key !== '' ? $key : $type . ':' . $contentId . ':' . $commentId . ':' . $actorId, 190);
        $now = date_db();
        $data = [
            'user_id' => $userId,
            'actor_id' => $actorId,
            'content_id' => $contentId > 0 ? $contentId : null,
            'comment_id' => $commentId > 0 ? $commentId : null,
            'type' => $type,
            'notification_key' => $key,
            'read_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        try {
            insert('notifications', $data);
        } catch (Throwable) {
            update('notifications', [
                'actor_id' => $actorId,
                'content_id' => $contentId > 0 ? $contentId : null,
                'comment_id' => $commentId > 0 ? $commentId : null,
                'read_at' => null,
                'updated_at' => $now,
            ], ['user_id' => $userId, 'notification_key' => $key]);
        }
    }
}

if (!function_exists('notification_create_for_content_owner')) {
    function notification_create_for_content_owner(string $type, int $contentId, array $actor, int $commentId = 0, int $sourceContentId = 0): void
    {
        if ($contentId < 1) {
            return;
        }

        $status = status_find($contentId);
        $ownerId = (int) ($status['author_id'] ?? 0);
        $actorId = (int) ($actor['id'] ?? 0);

        if ($ownerId < 1 || $actorId < 1 || $ownerId === $actorId) {
            return;
        }

        $key = match ($type) {
            'content_like' => 'content_like:' . $contentId . ':' . $actorId,
            'content_comment' => 'content_comment:' . $contentId . ':' . $commentId,
            default => $type . ':' . $contentId . ':' . $commentId . ':' . $actorId,
        };

        notification_create($ownerId, $type, $actorId, $contentId, $commentId, $key);
    }
}

if (!function_exists('notification_create_for_comment_owner')) {
    function notification_create_for_comment_owner(int $commentId, array $actor): void
    {
        if ($commentId < 1) {
            return;
        }

        $comment = status_comment_find($commentId);
        $ownerId = (int) ($comment['user_id'] ?? 0);
        $actorId = (int) ($actor['id'] ?? 0);
        $contentId = (int) ($comment['content_id'] ?? 0);

        if ($ownerId < 1 || $actorId < 1 || $ownerId === $actorId || $contentId < 1) {
            return;
        }

        notification_create(
            $ownerId,
            'comment_like',
            $actorId,
            $contentId,
            $commentId,
            'comment_like:' . $commentId . ':' . $actorId
        );
    }
}

if (!function_exists('notification_create_for_reporters')) {
    function notification_create_for_reporters(int $contentId, string $type, array $actor, string $reportStatus = ''): void
    {
        if ($contentId < 1) {
            return;
        }

        $actorId = (int) ($actor['id'] ?? 0);

        if ($actorId < 1) {
            return;
        }

        $query = db_select('SELECT DISTINCT reporter_id FROM content_reports')
            ->where('content_id = ?', $contentId);

        if ($reportStatus !== '') {
            $query->where('status = ?', $reportStatus);
        }

        foreach ($query->all() as $row) {
            $reporterId = (int) ($row['reporter_id'] ?? 0);

            if ($reporterId < 1 || $reporterId === $actorId) {
                continue;
            }

            notification_create(
                $reporterId,
                $type,
                $actorId,
                $contentId,
                0,
                $type . ':' . $contentId . ':' . $reporterId
            );
        }
    }
}

if (!function_exists('notification_unread_count')) {
    function notification_unread_count(int $userId): int
    {
        if ($userId < 1) {
            return 0;
        }

        return db_select('SELECT id FROM notifications')
            ->where('user_id = ?', $userId)
            ->where('read_at IS NULL')
            ->count();
    }
}

if (!function_exists('notification_latest_id')) {
    function notification_latest_id(int $userId): int
    {
        if ($userId < 1) {
            return 0;
        }

        return (int) db_select('SELECT COALESCE(MAX(id), 0) FROM notifications')
            ->where('user_id = ?', $userId)
            ->value();
    }
}

if (!function_exists('notifications_for_user')) {
    function notifications_for_user(int $userId, int $limit = 80): array
    {
        if ($userId < 1) {
            return [];
        }

        $limit = max(1, min(200, $limit));

        return db_select(
            'SELECT n.*,
                u.username AS actor_name,
                u.avatar_url AS actor_avatar_url,
                c.body AS content_body
            FROM notifications n
            LEFT JOIN users u ON u.id = n.actor_id
            LEFT JOIN content c ON c.id = n.content_id'
        )
            ->where('n.user_id = ?', $userId)
            ->order('n.created_at DESC, n.id DESC')
            ->limit($limit)
            ->all();
    }
}

if (!function_exists('notification_preview_html')) {
    function notification_preview_html(int $userId, int $limit = 6): string
    {
        $items = notifications_for_user($userId, $limit);

        if ($items === []) {
            return '<div class="notification-popover-empty">' . icon('bell') . ' <span>' . e(t('notifications.empty')) . '</span></div>';
        }

        ob_start();
        ?>
        <?php foreach ($items as $notification): ?>
            <?php
            $isUnread = trim((string) ($notification['read_at'] ?? '')) === '';
            $actorName = trim((string) ($notification['actor_name'] ?? ''));
            $avatarUrl = trim((string) ($notification['actor_avatar_url'] ?? ''));
            $createdAt = (string) ($notification['created_at'] ?? '');
            $contentText = meta_text((string) ($notification['content_body'] ?? ''), 90);
            ?>
            <a class="notification-popover-item<?= $isUnread ? ' is-unread' : '' ?>" href="<?= e(notification_url($notification)) ?>">
                <span class="notification-popover-avatar">
                    <?php if ($avatarUrl !== ''): ?>
                        <img src="<?= e($avatarUrl) ?>" alt="<?= e($actorName) ?>" loading="lazy">
                    <?php else: ?>
                        <?= icon(notification_icon((string) ($notification['type'] ?? ''))) ?>
                    <?php endif; ?>
                </span>
                <span class="notification-popover-copy">
                    <strong><?= e(notification_message($notification)) ?></strong>
                    <?php if ($contentText !== ''): ?>
                        <span><?= e($contentText) ?></span>
                    <?php endif; ?>
                    <?php if ($createdAt !== ''): ?>
                        <time datetime="<?= e(date_iso($createdAt)) ?>"><?= e(datetime($createdAt)) ?></time>
                    <?php endif; ?>
                </span>
            </a>
        <?php endforeach; ?>
        <?php

        return trim((string) ob_get_clean());
    }
}

if (!function_exists('notification_mark_read')) {
    function notification_mark_read(int $id, int $userId): void
    {
        if ($id < 1 || $userId < 1) {
            return;
        }

        update('notifications', [
            'read_at' => date_db(),
            'updated_at' => date_db(),
        ], ['id' => $id, 'user_id' => $userId]);
    }
}

if (!function_exists('notification_mark_all_read')) {
    function notification_mark_all_read(int $userId): void
    {
        if ($userId < 1) {
            return;
        }

        run('UPDATE notifications SET read_at = ?, updated_at = ? WHERE user_id = ? AND read_at IS NULL', [date_db(), date_db(), $userId]);
    }
}

if (!function_exists('notification_delete')) {
    function notification_delete(int $id, int $userId): void
    {
        if ($id < 1 || $userId < 1) {
            return;
        }

        delete('notifications', ['id' => $id, 'user_id' => $userId]);
    }
}

if (!function_exists('notification_delete_for_content')) {
    function notification_delete_for_content(int $contentId): void
    {
        if ($contentId < 1) {
            return;
        }

        delete('notifications', ['content_id' => $contentId]);
    }
}

if (!function_exists('notification_delete_for_comment')) {
    function notification_delete_for_comment(int $commentId): void
    {
        if ($commentId < 1) {
            return;
        }

        delete('notifications', ['comment_id' => $commentId]);
    }
}

if (!function_exists('notification_state')) {
    function notification_state(int $userId): array
    {
        $unread = notification_unread_count($userId);
        $message = '';

        if ($unread === 1) {
            $message = t('notifications.new');
        } elseif ($unread > 1) {
            $message = t('notifications.new_count', ['count' => $unread]);
        }

        return [
            'unread' => $unread,
            'latest_id' => notification_latest_id($userId),
            'message' => $message,
            'html' => notification_preview_html($userId),
        ];
    }
}

if (!function_exists('notification_badge_text')) {
    function notification_badge_text(int $count): string
    {
        return $count > 99 ? '99+' : (string) max(0, $count);
    }
}

if (!function_exists('status_handle_post')) {
    function status_handle_post(array $user, string $redirect = '/'): void
    {
        $action = (string) post('action', 'create');
        $id = max(0, (int) post('id', 0));

        if (wants_json() && in_array($action, ['create', 'react', 'comment', 'comment_like', 'comment_delete'], true)) {
            status_handle_post_json($action, $id, $user, $redirect);
        }

        if ($action === 'react') {
            status_react_for_user($id, $user, $redirect);
        }

        if ($action === 'comment') {
            status_comment_for_user($id, max(0, (int) post('parent_id', 0)), $user, $redirect);
        }

        if ($action === 'comment_like') {
            status_comment_like_for_user(max(0, (int) post('comment_id', 0)), $user, $redirect);
        }

        if ($action === 'report') {
            status_report_for_user($id, $user, $redirect);
        }

        if ($action === 'comment_delete') {
            status_comment_delete_for_user(max(0, (int) post('comment_id', 0)), $user, $redirect);
        }

        if ($action === 'update') {
            status_update_for_user($id, $user, $redirect);
        }

        if ($action === 'delete') {
            status_delete_for_user($id, $user, $redirect);
        }

        status_create_for_user($user, $redirect);
    }
}

if (!function_exists('status_json_require_not_muted')) {
    function status_json_require_not_muted(array $user): void
    {
        $mutedUntil = user_muted_until($user);

        if ($mutedUntil !== '') {
            api_error(t('moderation.messages.account_muted', ['until' => datetime($mutedUntil)]), 403, 'muted');
        }
    }
}

if (!function_exists('status_json_require_action')) {
    function status_json_require_action(array $user, string $action): void
    {
        if ((string) ($user['role'] ?? '') === 'admin') {
            return;
        }

        [, $limit] = moderation_action_rule($user, $action);

        if (moderation_action_count($user, $action) >= (int) $limit) {
            api_error(t('moderation.messages.action_limited'), 429, 'action_limited');
        }
    }
}

if (!function_exists('status_json_require_unique_body')) {
    function status_json_require_unique_body(array $user, string $body, int $ignoreId = 0): void
    {
        $userId = (int) ($user['id'] ?? 0);
        $fingerprint = moderation_body_fingerprint($body);

        if ($userId < 1 || strlen(trim($body)) < 12 || $fingerprint === '') {
            return;
        }

        try {
            $rows = all(
                'SELECT id, body FROM content WHERE author_id = ? AND created_at >= ? ORDER BY id DESC LIMIT 30',
                [$userId, date_db('-1 day')]
            );
        } catch (Throwable) {
            return;
        }

        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) === $ignoreId) {
                continue;
            }

            if (moderation_body_fingerprint((string) ($row['body'] ?? '')) === $fingerprint) {
                api_error(t('moderation.messages.duplicate_body'), 422, 'duplicate_body');
            }
        }
    }
}

if (!function_exists('status_like_count')) {
    function status_like_count(int $contentId): int
    {
        if ($contentId < 1) {
            return 0;
        }

        return (int) val(
            'SELECT COUNT(*) FROM content_likes WHERE content_id = ?',
            [$contentId]
        );
    }
}

if (!function_exists('status_json_summary')) {
    function status_json_summary(int $contentId, array $user): array
    {
        $userId = (int) ($user['id'] ?? 0);
        $liked = $userId > 0 && status_user_liked($contentId, $userId);
        $commentsCount = status_comment_count($contentId);

        return [
            'id' => $contentId,
            'likes_count' => status_like_count($contentId),
            'comments_count' => $commentsCount,
            'comments_label' => t('account.status_view_comments', ['count' => $commentsCount]),
            'liked' => $liked,
        ];
    }
}

if (!function_exists('status_comment_item_find')) {
    function status_comment_item_find(int $commentId): ?array
    {
        if ($commentId < 1) {
            return null;
        }

        $comment = status_comments_query()
            ->where('cc.id = ?', $commentId)
            ->limit(1)
            ->one();

        if ($comment !== null) {
            $comment['replies'] = [];
        }

        return $comment;
    }
}

if (!function_exists('status_json_comment_payload')) {
    function status_json_comment_payload(int $commentId, array $user, string $action, string $context = ''): array
    {
        $comment = status_comment_item_find($commentId);

        if ($comment === null) {
            api_error(t('account.messages.comment_not_found'), 404, 'comment_not_found');
        }

        $depth = (int) ($comment['parent_id'] ?? 0) > 0 ? 1 : 0;

        return [
            'id' => $commentId,
            'content_id' => (int) ($comment['content_id'] ?? 0),
            'parent_id' => (int) ($comment['parent_id'] ?? 0),
            'html' => status_comment_item($comment, $user, $action, $depth, $context, true, true),
        ];
    }
}

if (!function_exists('status_handle_post_json')) {
    function status_handle_post_json(string $action, int $id, array $user, string $redirect = '/'): never
    {
        if ($action === 'create') {
            api(status_json_create($user, $redirect));
        }

        if ($action === 'react') {
            api(status_json_react($id, $user));
        }

        if ($action === 'comment') {
            api(status_json_comment($id, max(0, (int) post('parent_id', 0)), $user, $redirect, (string) post('context', '')));
        }

        if ($action === 'comment_like') {
            api(status_json_comment_like(max(0, (int) post('comment_id', 0)), $user));
        }

        if ($action === 'comment_delete') {
            api(status_json_comment_delete(max(0, (int) post('comment_id', 0)), $user));
        }

        api_error('Unsupported status action.', 400, 'unsupported_action');
    }
}

if (!function_exists('status_json_create')) {
    function status_json_create(array $user, string $redirect = '/'): array
    {
        $payload = status_payload();
        $userId = (int) ($user['id'] ?? 0);
        $body = (string) ($payload['body'] ?? '');

        if (trim($body) === '') {
            api_error(t('account.messages.status_required'), 422, 'status_required');
        }

        status_json_require_not_muted($user);
        status_json_require_action($user, 'post');
        status_json_require_unique_body($user, $body);

        $now = date_db();
        $contentId = (int) insert('content', [
            'body' => $body,
            'author_id' => $userId,
            'published_at' => $now,
            'created_at' => $now,
        ]);
        status_sync_tags($contentId, (array) ($payload['tags'] ?? []));
        moderation_record_action($user, 'post');

        $item = public_status_item($contentId);

        return [
            'action' => 'create',
            'status' => status_json_summary($contentId, $user),
            'status_id' => $contentId,
            'card_html' => $item !== null ? status_card($item, $redirect, $user) : '',
            'message' => t('account.messages.status_created'),
        ];
    }
}

if (!function_exists('status_json_react')) {
    function status_json_react(int $contentId, array $user): array
    {
        $userId = (int) ($user['id'] ?? 0);

        if ($contentId < 1 || $userId < 1 || status_find($contentId) === null) {
            api_error(t('account.messages.status_not_found'), 404, 'status_not_found');
        }

        $liked = status_user_liked($contentId, $userId);

        if (!$liked) {
            status_json_require_not_muted($user);
            status_json_require_action($user, 'like');
        }

        if ($liked) {
            delete('content_likes', ['content_id' => $contentId, 'user_id' => $userId]);
        } else {
            insert('content_likes', [
                'content_id' => $contentId,
                'user_id' => $userId,
                'created_at' => date_db(),
            ]);
            moderation_record_action($user, 'like');
            notification_create_for_content_owner('content_like', $contentId, $user);
        }

        return [
            'action' => 'react',
            'status' => status_json_summary($contentId, $user),
        ];
    }
}

if (!function_exists('status_json_comment')) {
    function status_json_comment(int $contentId, int $parentId, array $user, string $redirect = '/', string $context = ''): array
    {
        $userId = (int) ($user['id'] ?? 0);
        $status = status_find($contentId);
        $body = plain_text_limit((string) post('comment', ''), 2000);
        $body = status_strip_external_urls($body);
        $body = normalize_mentions_for_storage($body);
        $body = plain_text_limit($body, 2000);

        if ($contentId < 1 || $userId < 1 || $status === null) {
            api_error(t('account.messages.status_not_found'), 404, 'status_not_found');
        }

        if ($body === '') {
            api_error(t('account.messages.comment_required'), 422, 'comment_required');
        }

        status_json_require_not_muted($user);
        status_json_require_action($user, 'comment');

        if ($parentId > 0) {
            $parent = status_comment_find($parentId);

            if ($parent === null || (int) ($parent['content_id'] ?? 0) !== $contentId) {
                api_error(t('account.messages.comment_not_found'), 404, 'comment_not_found');
            }

            if ((int) ($parent['parent_id'] ?? 0) > 0) {
                $parentId = (int) $parent['parent_id'];
            }
        }

        $commentId = (int) insert('content_comments', [
            'content_id' => $contentId,
            'parent_id' => $parentId > 0 ? $parentId : null,
            'user_id' => $userId,
            'body' => $body,
            'created_at' => date_db(),
        ]);
        moderation_record_action($user, 'comment');
        notification_create_for_content_owner('content_comment', $contentId, $user, $commentId);

        return [
            'action' => 'comment',
            'status' => status_json_summary($contentId, $user),
            'comment' => status_json_comment_payload($commentId, $user, $redirect, $context),
            'message' => t('account.messages.comment_created'),
        ];
    }
}

if (!function_exists('status_json_comment_like')) {
    function status_json_comment_like(int $commentId, array $user): array
    {
        $userId = (int) ($user['id'] ?? 0);
        $comment = status_comment_find($commentId);

        if ($comment === null || $userId < 1) {
            api_error(t('account.messages.comment_not_found'), 404, 'comment_not_found');
        }

        $contentId = (int) ($comment['content_id'] ?? 0);
        $liked = status_comment_user_liked($commentId, $userId);

        if (!$liked) {
            status_json_require_not_muted($user);
            status_json_require_action($user, 'like');
        }

        if ($liked) {
            delete('comment_likes', ['comment_id' => $commentId, 'user_id' => $userId]);
        } else {
            insert('comment_likes', [
                'comment_id' => $commentId,
                'user_id' => $userId,
                'created_at' => date_db(),
            ]);
            moderation_record_action($user, 'like');
            notification_create_for_comment_owner($commentId, $user);
        }

        return [
            'action' => 'comment_like',
            'status' => status_json_summary($contentId, $user),
            'comment_like' => [
                'comment_id' => $commentId,
                'likes_count' => status_comment_like_count($commentId),
                'liked' => !$liked,
            ],
        ];
    }
}

if (!function_exists('status_json_comment_delete')) {
    function status_json_comment_delete(int $commentId, array $user): array
    {
        $comment = status_comment_find($commentId);

        if ($comment === null) {
            api_error(t('account.messages.comment_not_found'), 404, 'comment_not_found');
        }

        $contentId = (int) ($comment['content_id'] ?? 0);

        if (!status_comment_can_delete($comment, $user)) {
            api_error(t('account.messages.comment_forbidden'), 403, 'comment_forbidden');
        }

        foreach (db_select('SELECT id FROM content_comments')->where('parent_id = ?', $commentId)->all() as $child) {
            $childId = (int) ($child['id'] ?? 0);
            delete('comment_likes', ['comment_id' => $childId]);
            notification_delete_for_comment($childId);
        }

        delete('comment_likes', ['comment_id' => $commentId]);
        notification_delete_for_comment($commentId);
        delete('content_comments', ['parent_id' => $commentId]);
        delete('content_comments', ['id' => $commentId]);

        return [
            'action' => 'comment_delete',
            'status' => status_json_summary($contentId, $user),
            'deleted_comment_id' => $commentId,
            'message' => t('account.messages.comment_deleted'),
        ];
    }
}

if (!function_exists('status_payload')) {
    function status_payload(): array
    {
        $body = plain_text_limit((string) post('body', ''), 2000);
        $body = status_strip_external_urls($body);
        $body = normalize_mentions_for_storage($body);
        $body = normalize_tags_for_storage($body);
        $body = plain_text_limit($body, 2000);

        return [
            'body' => $body,
            'tags' => status_tags_from_text($body),
        ];
    }
}

if (!function_exists('status_react_for_user')) {
    function status_react_for_user(int $contentId, array $user, string $redirect = '/'): void
    {
        $userId = (int) ($user['id'] ?? 0);

        if ($contentId < 1 || $userId < 1 || status_find($contentId) === null) {
            flash('error', t('account.messages.status_not_found'));
            redirect($redirect);
        }

        $liked = status_user_liked($contentId, $userId);

        if (!$liked) {
            moderation_require_not_muted($user, $redirect);
            moderation_require_action($user, 'like', $redirect);
        }

        if ($liked) {
            delete('content_likes', ['content_id' => $contentId, 'user_id' => $userId]);
        } else {
            insert('content_likes', [
                'content_id' => $contentId,
                'user_id' => $userId,
                'created_at' => date_db(),
            ]);
            moderation_record_action($user, 'like');
            notification_create_for_content_owner('content_like', $contentId, $user);
        }

        redirect($redirect . '#' . status_anchor($contentId));
    }
}

if (!function_exists('status_comment_for_user')) {
    function status_comment_for_user(int $contentId, int $parentId, array $user, string $redirect = '/'): void
    {
        $userId = (int) ($user['id'] ?? 0);
        $status = status_find($contentId);
        $body = plain_text_limit((string) post('comment', ''), 2000);
        $body = status_strip_external_urls($body);
        $body = normalize_mentions_for_storage($body);
        $body = plain_text_limit($body, 2000);

        if ($contentId < 1 || $userId < 1 || $status === null) {
            flash('error', t('account.messages.status_not_found'));
            redirect($redirect);
        }

        if ($body === '') {
            flash('error', t('account.messages.comment_required'));
            redirect($redirect . '#' . status_anchor($contentId));
        }

        moderation_require_not_muted($user, $redirect . '#' . status_anchor($contentId));
        moderation_require_action($user, 'comment', $redirect . '#' . status_anchor($contentId));

        if ($parentId > 0) {
            $parent = status_comment_find($parentId);

            if ($parent === null || (int) ($parent['content_id'] ?? 0) !== $contentId) {
                flash('error', t('account.messages.comment_not_found'));
                redirect($redirect . '#' . status_anchor($contentId));
            }

            if ((int) ($parent['parent_id'] ?? 0) > 0) {
                $parentId = (int) $parent['parent_id'];
            }
        }

        $commentId = (int) insert('content_comments', [
            'content_id' => $contentId,
            'parent_id' => $parentId > 0 ? $parentId : null,
            'user_id' => $userId,
            'body' => $body,
            'created_at' => date_db(),
        ]);
        moderation_record_action($user, 'comment');
        notification_create_for_content_owner('content_comment', $contentId, $user, $commentId);

        flash('success', t('account.messages.comment_created'));
        redirect($redirect . '#' . status_anchor($contentId));
    }
}

if (!function_exists('status_comment_like_for_user')) {
    function status_comment_like_for_user(int $commentId, array $user, string $redirect = '/'): void
    {
        $userId = (int) ($user['id'] ?? 0);
        $comment = status_comment_find($commentId);

        if ($comment === null || $userId < 1) {
            flash('error', t('account.messages.comment_not_found'));
            redirect($redirect);
        }

        $contentId = (int) ($comment['content_id'] ?? 0);

        $liked = status_comment_user_liked($commentId, $userId);

        if (!$liked) {
            moderation_require_not_muted($user, $redirect . '#' . status_anchor($contentId));
            moderation_require_action($user, 'like', $redirect . '#' . status_anchor($contentId));
        }

        if ($liked) {
            delete('comment_likes', ['comment_id' => $commentId, 'user_id' => $userId]);
        } else {
            insert('comment_likes', [
                'comment_id' => $commentId,
                'user_id' => $userId,
                'created_at' => date_db(),
            ]);
            moderation_record_action($user, 'like');
            notification_create_for_comment_owner($commentId, $user);
        }

        redirect($redirect . '#' . status_anchor($contentId));
    }
}

if (!function_exists('status_report_reasons')) {
    function status_report_reasons(): array
    {
        return [
            'spam' => t('moderation.report_reasons.spam'),
            'illegal' => t('moderation.report_reasons.illegal'),
            'malware' => t('moderation.report_reasons.malware'),
            'abuse' => t('moderation.report_reasons.abuse'),
            'other' => t('moderation.report_reasons.other'),
        ];
    }
}

if (!function_exists('status_report_dismissal_lock')) {
    function status_report_dismissal_lock(int $contentId): ?array
    {
        if ($contentId < 1) {
            return null;
        }

        $openCount = (int) val(
            'SELECT COUNT(*) FROM content_reports WHERE content_id = ? AND status = ?',
            [$contentId, 'open']
        );

        if ($openCount > 0) {
            return null;
        }

        return one(
            'SELECT *
            FROM content_reports
            WHERE content_id = ? AND status = ? AND reviewed_at IS NOT NULL
            ORDER BY reviewed_at DESC, id DESC
            LIMIT 1',
            [$contentId, 'dismissed']
        );
    }
}

if (!function_exists('status_report_for_user')) {
    function status_report_for_user(int $contentId, array $user, string $redirect = '/'): void
    {
        $userId = (int) ($user['id'] ?? 0);
        $item = status_find($contentId);

        if ($contentId < 1 || $userId < 1 || $item === null) {
            flash('error', t('account.messages.status_not_found'));
            redirect($redirect);
        }

        if ((int) ($item['author_id'] ?? 0) === $userId) {
            flash('error', t('moderation.messages.report_own_content'));
            redirect($redirect . '#' . status_anchor($contentId));
        }

        $reasons = array_keys(status_report_reasons());
        $reason = (string) post('reason', 'other');
        $reason = in_array($reason, $reasons, true) ? $reason : 'other';
        $note = plain_text_limit((string) post('note', ''), 1000);
        $now = date_db();
        $existing = one(
            'SELECT *
            FROM content_reports
            WHERE content_id = ? AND reporter_id = ?
            LIMIT 1',
            [$contentId, $userId]
        );

        if ($existing !== null && (string) ($existing['status'] ?? '') !== 'open') {
            flash('info', t('moderation.messages.report_already_reviewed'));
            redirect($redirect . '#' . status_anchor($contentId));
        }

        $dismissed = status_report_dismissal_lock($contentId);

        if ($dismissed !== null && $existing === null) {
            try {
                insert('content_reports', [
                    'content_id' => $contentId,
                    'reporter_id' => $userId,
                    'reason' => $reason,
                    'note' => $note,
                    'status' => 'dismissed',
                    'created_at' => $now,
                    'reviewed_at' => $now,
                    'reviewed_by' => (int) ($dismissed['reviewed_by'] ?? 0) ?: null,
                    'action_note' => 'already_dismissed',
                ]);
            } catch (Throwable) {
                // A race on the unique report key should behave like an already reviewed report.
            }

            flash('info', t('moderation.messages.report_already_reviewed'));
            redirect($redirect . '#' . status_anchor($contentId));
        }

        moderation_require_action($user, 'report', $redirect . '#' . status_anchor($contentId));

        if ($existing !== null) {
            update('content_reports', [
                'reason' => $reason,
                'note' => $note,
                'created_at' => $now,
            ], ['content_id' => $contentId, 'reporter_id' => $userId]);
        } else {
            insert('content_reports', [
                'content_id' => $contentId,
                'reporter_id' => $userId,
                'reason' => $reason,
                'note' => $note,
                'status' => 'open',
                'created_at' => $now,
            ]);
        }

        moderation_record_action($user, 'report');
        flash('success', t('moderation.messages.report_created'));
        redirect($redirect . '#' . status_anchor($contentId));
    }
}

if (!function_exists('status_comment_delete_for_user')) {
    function status_comment_delete_for_user(int $commentId, array $user, string $redirect = '/'): void
    {
        $comment = status_comment_find($commentId);

        if ($comment === null) {
            flash('error', t('account.messages.comment_not_found'));
            redirect($redirect);
        }

        $contentId = (int) ($comment['content_id'] ?? 0);

        if (!status_comment_can_delete($comment, $user)) {
            flash('error', t('account.messages.comment_forbidden'));
            redirect($redirect . '#' . status_anchor($contentId));
        }

        foreach (db_select('SELECT id FROM content_comments')->where('parent_id = ?', $commentId)->all() as $child) {
            $childId = (int) ($child['id'] ?? 0);
            delete('comment_likes', ['comment_id' => $childId]);
            notification_delete_for_comment($childId);
        }

        delete('comment_likes', ['comment_id' => $commentId]);
        notification_delete_for_comment($commentId);
        delete('content_comments', ['parent_id' => $commentId]);
        delete('content_comments', ['id' => $commentId]);

        flash('success', t('account.messages.comment_deleted'));
        redirect($redirect . '#' . status_anchor($contentId));
    }
}

if (!function_exists('status_update_for_user')) {
    function status_update_for_user(int $contentId, array $user, string $redirect = '/'): void
    {
        $item = status_find($contentId);

        if (status_edit_locked($item)) {
            flash('error', t('account.messages.status_edit_locked'));
            redirect($redirect . '#' . status_anchor($contentId));
        }

        if (!status_can_edit($item, $user)) {
            flash('error', t('account.messages.status_forbidden'));
            redirect($redirect);
        }

        moderation_require_not_muted($user, $redirect . '#' . status_anchor($contentId));

        $payload = status_payload();
        $body = (string) ($payload['body'] ?? '');

        if (trim($body) === '') {
            flash('error', t('account.messages.status_required'));
            redirect($redirect . '#' . status_anchor($contentId));
        }

        moderation_require_unique_body($user, $body, $redirect . '#' . status_anchor($contentId), $contentId);

        update('content', [
            'body' => $body,
        ], ['id' => $contentId]);
        status_sync_tags($contentId, (array) ($payload['tags'] ?? []));

        flash('success', t('account.messages.status_saved'));
        redirect($redirect . '#' . status_anchor($contentId));
    }
}

if (!function_exists('status_delete_content')) {
    function status_delete_content(int $contentId, bool $deleteReports = true, bool $deleteNotifications = true): void
    {
        if ($contentId < 1) {
            return;
        }

        delete('content_likes', ['content_id' => $contentId]);
        foreach (db_select('SELECT id FROM content_comments')->where('content_id = ?', $contentId)->all() as $comment) {
            delete('comment_likes', ['comment_id' => (int) ($comment['id'] ?? 0)]);
        }

        if ($deleteNotifications) {
            notification_delete_for_content($contentId);
        }

        delete('content_comments', ['content_id' => $contentId]);
        delete('content_tags', ['content_id' => $contentId]);

        if ($deleteReports) {
            delete('content_reports', ['content_id' => $contentId]);
        }

        status_cleanup_unused_terms();
        delete('content', ['id' => $contentId]);
    }
}

if (!function_exists('status_delete_for_user')) {
    function status_delete_for_user(int $contentId, array $user, string $redirect = '/'): void
    {
        $item = status_find($contentId);

        if (!status_can_delete($item, $user)) {
            flash('error', t('account.messages.status_forbidden'));
            redirect($redirect);
        }

        status_delete_content($contentId);

        flash('success', t('account.messages.status_deleted'));
        redirect($redirect);
    }
}

if (!function_exists('status_actions')) {
    function status_actions(array $item, ?array $user, string $action, bool $openCommentsModal = true): string
    {
        $contentId = (int) ($item['id'] ?? 0);
        $counts = [
            'like' => (int) ($item['likes_count'] ?? 0),
        ];
        $commentsCount = array_key_exists('comments_count', $item)
            ? (int) ($item['comments_count'] ?? 0)
            : status_comment_count($contentId);
        $userId = (int) ($user['id'] ?? 0);
        $liked = $userId > 0 && status_user_liked($contentId, $userId);
        $loginUrl = status_login_url($contentId > 0 ? '#' . status_anchor($contentId) : '', $action);

        ob_start();
        ?>
        <div class="status-reactions">
            <?php if ($user !== null): ?>
                <form method="post" action="<?= e($action) ?>" data-status-form data-status-id="<?= e($contentId) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="react">
                    <input type="hidden" name="id" value="<?= e($contentId) ?>">
                    <button class="btn btn-ghost btn-sm status-reaction<?= $liked ? ' is-active' : '' ?>" type="submit" title="<?= et('account.status_like') ?>" data-status-like-button data-status-id="<?= e($contentId) ?>">
                        <?= icon('thumb-up', 'icon status-like-icon status-like-icon-outline') ?><?= icon('thumb-up-filled', 'icon status-like-icon status-like-icon-filled') ?> <span data-status-count="likes" data-status-id="<?= e($contentId) ?>"><?= e($counts['like']) ?></span>
                    </button>
                </form>
            <?php else: ?>
                <a class="btn btn-ghost btn-sm status-reaction" href="<?= e($loginUrl) ?>" aria-label="<?= et('account.status_like') ?>" title="<?= et('account.status_like') ?>">
                    <?= icon('thumb-up', 'icon status-like-icon status-like-icon-outline') ?> <span data-status-count="likes" data-status-id="<?= e($contentId) ?>"><?= e($counts['like']) ?></span>
                </a>
            <?php endif; ?>
            <?php if ($openCommentsModal): ?>
                <button class="btn btn-ghost btn-sm status-reaction" type="button" data-modal-open="<?= e(status_post_modal_id($contentId)) ?>" data-modal-url="<?= e(status_post_modal_url($contentId, $action)) ?>" aria-label="<?= et('account.status_comments') ?>">
                    <?= icon('message-circle') ?> <span data-status-count="comments" data-status-id="<?= e($contentId) ?>"><?= e($commentsCount) ?></span>
                </button>
            <?php else: ?>
                <a class="btn btn-ghost btn-sm status-reaction" href="#status-comments-thread-<?= e($contentId) ?>" aria-label="<?= et('account.status_comments') ?>">
                    <?= icon('message-circle') ?> <span data-status-count="comments" data-status-id="<?= e($contentId) ?>"><?= e($commentsCount) ?></span>
                </a>
            <?php endif; ?>
        </div>
        <?php

        return trim((string) ob_get_clean());
    }
}

if (!function_exists('status_manage_actions')) {
    function status_manage_actions(array $item, ?array $user, string $action): string
    {
        $contentId = (int) ($item['id'] ?? 0);
        $isLocked = status_edit_locked($item);
        $canEdit = status_can_edit($item, $user);
        $canDelete = status_can_delete($item, $user);
        $canReport = $user !== null
            && (int) ($item['author_id'] ?? $item['user_id'] ?? 0) !== (int) ($user['id'] ?? 0);

        if ($contentId < 1) {
            return '';
        }

        ob_start();
        ?>
        <div class="status-manage status-manage-top">
            <a class="btn btn-ghost btn-icon btn-sm status-manage-icon" href="<?= e(status_url($contentId)) ?>" title="<?= et('account.status_permalink') ?>" aria-label="<?= et('account.status_permalink') ?>">
                <?= icon('link') ?>
            </a>
            <?php if ($isLocked): ?>
                <span class="btn btn-ghost btn-icon btn-sm status-manage-icon" title="<?= et('account.status_edit_locked') ?>" aria-label="<?= et('account.status_edit_locked') ?>">
                    <?= icon('lock') ?>
                </span>
            <?php endif; ?>
            <?php if ($canReport): ?>
                <button class="btn btn-ghost btn-icon btn-sm status-manage-icon" type="button" data-modal-open="<?= e(status_report_modal_id($contentId)) ?>" data-modal-url="<?= e(status_action_modal_url('report', $contentId, $action)) ?>" title="<?= et('moderation.report_status') ?>" aria-label="<?= et('moderation.report_status') ?>">
                    <?= icon('flag') ?>
                </button>
            <?php endif; ?>
            <?php if ($canEdit): ?>
                <button class="btn btn-ghost btn-icon btn-sm status-manage-icon" type="button" data-modal-open="<?= e(status_edit_modal_id($contentId)) ?>" data-modal-url="<?= e(status_action_modal_url('edit', $contentId, $action)) ?>" title="<?= et('account.status_edit') ?>" aria-label="<?= et('account.status_edit') ?>">
                    <?= icon('edit') ?>
                </button>
            <?php endif; ?>
            <?php if ($canDelete): ?>
                <form method="post" action="<?= e($action) ?>" data-status-form data-status-id="<?= e($contentId) ?>" data-confirm="<?= et('account.status_delete_confirm') ?>" data-confirm-title="<?= et('account.status_delete_title') ?>" data-confirm-ok="<?= et('common.delete') ?>" data-confirm-cancel="<?= et('common.cancel') ?>" data-confirm-variant="danger">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= e($contentId) ?>">
                    <button class="btn btn-ghost btn-icon btn-sm status-manage-icon text-danger" type="submit" title="<?= et('account.status_delete') ?>" aria-label="<?= et('account.status_delete') ?>">
                        <?= icon('trash') ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>
        <?php

        return trim((string) ob_get_clean());
    }
}

if (!function_exists('status_card')) {
    function status_card(array $item, string $action = '/', ?array $user = null): string
    {
        $user ??= auth();
        $contentId = (int) ($item['id'] ?? 0);
        $authorId = (int) ($item['author_id'] ?? $item['user_id'] ?? 0);
        $authorName = trim((string) ($item['author_name'] ?? ''));
        $avatarUrl = (string) ($item['avatar_url'] ?? '');
        $createdAt = (string) ($item['created_at'] ?? '');
        $url = $authorId > 0 ? author_url($authorId) . '#' . status_anchor($contentId) : '#';

        if ($contentId < 1) {
            return '';
        }

        ob_start();
        ?>
        <article class="card status-card" id="<?= e(status_anchor($contentId)) ?>">
            <div class="card-body status-card-body">
                <div class="status-header">
                    <a class="avatar" href="<?= e($url) ?>" aria-label="<?= e($authorName) ?>">
                        <?php if ($avatarUrl !== ''): ?>
                            <img src="<?= e($avatarUrl) ?>" alt="<?= e($authorName) ?>" loading="lazy">
                        <?php else: ?>
                            <?= icon('user') ?>
                        <?php endif; ?>
                    </a>
                    <div class="status-author">
                        <?php if ($authorId > 0 && $authorName !== ''): ?>
                            <a href="<?= e(author_url($authorId)) ?>"><?= e($authorName) ?></a>
                        <?php endif; ?>
                        <?php if ($createdAt !== ''): ?>
                            <?= status_time_button($createdAt, $contentId, true, $action) ?>
                        <?php endif; ?>
                    </div>
                    <?= status_manage_actions($item, $user, $action) ?>
                </div>
                <?php $bodyHtml = render_status_body($item); ?>
                <?php if ($bodyHtml !== ''): ?>
                    <div class="status-body"><?= $bodyHtml ?></div>
                <?php endif; ?>
                <?= status_actions($item, $user, $action) ?>
                <?= status_comments_section($item, $user, $action) ?>
            </div>
        </article>
        <?php

        return trim((string) ob_get_clean());
    }
}

if (!function_exists('public_status_page_limit')) {
    function public_status_page_limit(): int
    {
        return max(1, min(50, (int) config('public.status_limit', 20)));
    }
}

if (!function_exists('status_feed_context_items')) {
    function status_feed_context_items(string $context, int $limit, int $offset, array $params = [], ?array $user = null): array
    {
        $limit = max(1, min(50, $limit));
        $offset = max(0, $offset);
        $context = in_array($context, ['home', 'author', 'tag'], true) ? $context : 'home';
        $user ??= auth();

        if ($context === 'author') {
            $authorId = max(0, (int) ($params['author_id'] ?? 0));

            return [
                'items' => public_status_items_by_author($authorId, $limit, $offset),
                'action' => author_url($authorId),
            ];
        }

        if ($context === 'tag') {
            $tag = status_tag_normalize((string) ($params['tag'] ?? ''));

            return [
                'items' => public_status_items_by_tag($tag, $limit, $offset),
                'action' => tag_url($tag),
            ];
        }

        $feed = (string) ($params['feed'] ?? 'all') === 'following' ? 'following' : 'all';

        if ($feed === 'following') {
            $userId = (int) ($user['id'] ?? 0);

            return [
                'items' => $userId > 0 ? public_status_items_for_user($userId, $limit, $offset) : [],
                'action' => '/?feed=following',
            ];
        }

        return [
            'items' => public_status_items($limit, $offset),
            'action' => '/',
        ];
    }
}

if (!function_exists('public_home_feed_html')) {
    function public_home_feed_html(string $feed = 'all', ?array $user = null): string
    {
        $user ??= auth();
        $feed = $feed === 'following' ? 'following' : 'all';
        $currentFeedUrl = $feed === 'following' ? '/?feed=following' : '/';
        $followingLoginRequired = $feed === 'following' && $user === null;
        $limit = public_status_page_limit();
        $items = $followingLoginRequired
            ? []
            : ($feed === 'following'
                ? public_status_items_for_user((int) ($user['id'] ?? 0), $limit)
                : public_status_items($limit));
        $feedId = 'status-feed-' . $feed;

        ob_start();
        ?>
        <?php if ($user !== null && user_is_muted($user)): ?>
            <div class="alert alert-warning">
                <?= icon('lock') ?> <span><?= et('moderation.messages.account_muted', ['until' => datetime(user_muted_until($user))]) ?></span>
            </div>
        <?php elseif ($user !== null): ?>
            <?= status_composer($currentFeedUrl, $user) ?>
        <?php endif; ?>

        <nav class="feed-switch home-feed-switch" aria-label="<?= et('public.feed_title') ?>">
            <a class="feed-switch-link" href="/" data-ajax data-url="/api/home-feed?feed=all" data-ajax-target=".home-feed-section" data-history="/"<?= $feed === 'all' ? ' aria-current="page"' : '' ?>>
                <?= et('public.feed_all') ?>
            </a>
            <a class="feed-switch-link" href="/?feed=following" data-ajax data-url="/api/home-feed?feed=following" data-ajax-target=".home-feed-section" data-history="/?feed=following"<?= $feed === 'following' ? ' aria-current="page"' : '' ?>>
                <?= et('public.feed_following') ?>
            </a>
        </nav>

        <?php if ($followingLoginRequired): ?>
            <div class="alert alert-info cluster">
                <span><?= et('public.feed_following_login') ?></span>
                <a class="btn btn-secondary btn-sm" href="<?= e(status_login_url('', $currentFeedUrl)) ?>"><?= icon('login') ?> <span><?= et('common.login') ?></span></a>
            </div>
        <?php else: ?>
            <?php if ($items === []): ?>
                <div class="alert alert-info" data-status-empty><?= et($feed === 'following' ? 'public.feed_empty_following' : 'public.feed_empty') ?></div>
            <?php endif; ?>
            <div class="status-feed" id="<?= e($feedId) ?>" data-status-feed>
                <?= status_feed_html($items, $currentFeedUrl, $user) ?>
            </div>
            <?= status_feed_more_control($feedId, 'home', count($items), $limit, ['feed' => $feed]) ?>
        <?php endif; ?>
        <?php

        return trim((string) ob_get_clean());
    }
}

if (!function_exists('status_feed_html')) {
    function status_feed_html(array $items, string $action, ?array $user = null): string
    {
        $user ??= auth();
        $html = '';

        foreach ($items as $item) {
            $html .= status_card($item, $action, $user);
        }

        return $html;
    }
}

if (!function_exists('status_feed_next_url')) {
    function status_feed_next_url(string $context, int $offset, int $limit, array $params = []): string
    {
        $query = array_merge($params, [
            'context' => $context,
            'offset' => max(0, $offset),
            'limit' => max(1, min(50, $limit)),
        ]);

        return '/api/status-feed?' . http_build_query($query);
    }
}

if (!function_exists('status_feed_more_control')) {
    function status_feed_more_control(string $feedId, string $context, int $loaded, int $limit, array $params = []): string
    {
        if ($loaded < $limit) {
            return '';
        }

        $nextUrl = status_feed_next_url($context, $loaded, $limit, $params);

        ob_start();
        ?>
        <div class="status-feed-more" data-status-feed-more data-status-feed-target="#<?= e($feedId) ?>" data-status-feed-url="<?= e($nextUrl) ?>">
            <button class="btn btn-secondary status-feed-more-button" type="button" data-status-feed-load>
                <?= icon('plus') ?> <span><?= et('public.load_more_posts') ?></span>
            </button>
            <span class="status-feed-more-state" data-status-feed-state hidden><?= et('public.loading_posts') ?></span>
        </div>
        <?php

        return trim((string) ob_get_clean());
    }
}

if (!function_exists('status_comments_section')) {
    function status_comments_section(array $item, ?array $user, string $action): string
    {
        $contentId = (int) ($item['id'] ?? 0);

        if ($contentId < 1) {
            return '';
        }

        $latestComment = status_latest_parent_comment($contentId);
        $commentsCount = array_key_exists('comments_count', $item)
            ? (int) ($item['comments_count'] ?? 0)
            : status_comment_count($contentId);

        if ($latestComment === null) {
            return '';
        }

        ob_start();
        ?>
        <section class="status-comments">
            <button class="link-button status-comments-open" type="button" data-modal-open="<?= e(status_post_modal_id($contentId)) ?>" data-modal-url="<?= e(status_post_modal_url($contentId, $action)) ?>" data-status-comments-label data-status-id="<?= e($contentId) ?>">
                <?= et('account.status_view_comments', ['count' => $commentsCount]) ?>
            </button>
            <?= status_comment_item($latestComment, $user, $action, 0, 'preview-' . $contentId, false, false) ?>

            <?php if ($user === null): ?>
                <a class="btn btn-secondary btn-sm status-comment-login" href="<?= e(status_login_url('#' . status_anchor($contentId), $action)) ?>">
                    <?= icon('login') ?> <span><?= et('account.status_comment_login') ?></span>
                </a>
            <?php endif; ?>
        </section>
        <?php

        return trim((string) ob_get_clean());
    }
}

if (!function_exists('status_comment_thread_section')) {
    function status_comment_thread_section(array $item, ?array $user, string $action, string $context): string
    {
        $contentId = (int) ($item['id'] ?? 0);

        if ($contentId < 1) {
            return '';
        }

        $comments = status_comments($contentId);

        if ($comments === [] && $user === null) {
            return '';
        }

        ob_start();
        ?>
        <section class="status-comments status-comments-thread" id="status-comments-thread-<?= e($contentId) ?>">
            <?php if ($user !== null): ?>
                <?= status_comment_form($contentId, $action, $user, 0, '', $context) ?>
            <?php endif; ?>

            <div class="status-comment-list" data-status-comment-list data-status-id="<?= e($contentId) ?>">
                <?php foreach ($comments as $comment): ?>
                    <?= status_comment_item($comment, $user, $action, 0, $context, true, true) ?>
                <?php endforeach; ?>
            </div>

            <?php if ($user === null): ?>
                <a class="btn btn-secondary btn-sm status-comment-login" href="<?= e(status_login_url('#status-comments-thread-' . $contentId, $action)) ?>">
                    <?= icon('login') ?> <span><?= et('account.status_comment_login') ?></span>
                </a>
            <?php endif; ?>
        </section>
        <?php

        return trim((string) ob_get_clean());
    }
}

if (!function_exists('status_comment_form')) {
    function status_comment_form(int $contentId, string $action, array $user, int $parentId = 0, string $mention = '', string $context = ''): string
    {
        $avatarUrl = user_avatar_url($user);
        $isReply = $parentId > 0;
        $label = et($isReply ? 'account.status_reply' : 'account.status_comment');

        ob_start();
        ?>
        <form class="status-comment-form<?= $isReply ? ' is-reply' : '' ?>" method="post" action="<?= e($action) ?>" data-status-form data-status-id="<?= e($contentId) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="comment">
            <input type="hidden" name="id" value="<?= e($contentId) ?>">
            <input type="hidden" name="parent_id" value="<?= e($parentId) ?>">
            <input type="hidden" name="context" value="<?= e($context) ?>">
            <div class="avatar avatar-sm">
                <?php if ($avatarUrl !== ''): ?>
                    <img src="<?= e($avatarUrl) ?>" alt="<?= e(user_display_name($user)) ?>" loading="lazy">
                <?php else: ?>
                    <?= icon('user') ?>
                <?php endif; ?>
            </div>
            <div class="status-comment-input-shell">
                <textarea class="textarea status-comment-input" name="comment" rows="1" maxlength="2000" placeholder="<?= et($isReply ? 'account.status_reply_placeholder' : 'account.status_comment_placeholder') ?>" aria-label="<?= $label ?>" required><?= e($mention) ?></textarea>
                <button class="btn btn-primary btn-icon btn-sm status-comment-submit" type="submit" title="<?= $label ?>" aria-label="<?= $label ?>">
                    <?= icon('send') ?>
                </button>
            </div>
        </form>
        <?php

        return trim((string) ob_get_clean());
    }
}

if (!function_exists('status_comment_mention')) {
    function status_comment_mention(string $name): string
    {
        $handle = slug($name);

        return $handle !== '' ? '@' . $handle . ' ' : '';
    }
}

if (!function_exists('status_comment_item')) {
    function status_comment_item(array $comment, ?array $user, string $action, int $depth = 0, string $context = '', bool $showReplies = true, bool $showReplyForm = true): string
    {
        $commentId = (int) ($comment['id'] ?? 0);
        $contentId = (int) ($comment['content_id'] ?? 0);
        $authorId = (int) ($comment['user_id'] ?? 0);
        $authorName = trim((string) ($comment['author_name'] ?? ''));
        $avatarUrl = (string) ($comment['avatar_url'] ?? '');
        $createdAt = (string) ($comment['created_at'] ?? '');
        $replies = $depth === 0 ? (array) ($comment['replies'] ?? []) : [];
        $canDelete = status_comment_can_delete($comment, $user);
        $userId = (int) ($user['id'] ?? 0);
        $likesCount = array_key_exists('likes_count', $comment)
            ? (int) ($comment['likes_count'] ?? 0)
            : status_comment_like_count($commentId);
        $liked = $userId > 0 && status_comment_user_liked($commentId, $userId);
        $commentDomId = 'comment-' . ($context !== '' ? preg_replace('/[^A-Za-z0-9_-]/', '', $context) . '-' : '') . $commentId;

        ob_start();
        ?>
        <article class="status-comment<?= $depth > 0 ? ' is-child' : '' ?>" id="<?= e($commentDomId) ?>" data-comment-id="<?= e($commentId) ?>" data-content-id="<?= e($contentId) ?>" data-parent-id="<?= e((int) ($comment['parent_id'] ?? 0)) ?>">
            <a class="avatar avatar-sm" href="<?= e(author_url($authorId)) ?>" aria-label="<?= e($authorName) ?>">
                <?php if ($avatarUrl !== ''): ?>
                    <img src="<?= e($avatarUrl) ?>" alt="<?= e($authorName) ?>" loading="lazy">
                <?php else: ?>
                    <?= icon('user') ?>
                <?php endif; ?>
            </a>
            <div class="status-comment-main">
                <div class="status-comment-bubble">
                    <?php if ($authorName !== ''): ?>
                        <a class="status-comment-author" href="<?= e(author_url($authorId)) ?>"><?= e($authorName) ?></a>
                    <?php endif; ?>
                    <div class="status-comment-body"><?= render_mentions((string) ($comment['body'] ?? ''), false) ?></div>
                </div>
                <div class="status-comment-meta">
                    <?php if ($createdAt !== ''): ?>
                        <time datetime="<?= e(date_iso($createdAt)) ?>"><?= e(datetime($createdAt)) ?></time>
                    <?php endif; ?>
                    <?= status_comment_like_control($commentId, $likesCount, $liked, $user, $action, $contentId) ?>
                    <?php if ($user !== null && $showReplyForm): ?>
                        <details class="status-reply-details">
                            <summary><?= et('account.status_reply') ?></summary>
                            <?= status_comment_form($contentId, $action, $user, $commentId, $depth > 0 ? status_comment_mention($authorName) : '', $context) ?>
                        </details>
                    <?php endif; ?>
                    <?php if ($canDelete): ?>
                        <?= status_comment_delete_form($commentId, $action, $contentId) ?>
                    <?php endif; ?>
                </div>

                <?php if ($showReplies && $replies !== []): ?>
                    <div class="status-comment-replies" data-comment-replies data-comment-id="<?= e($commentId) ?>">
                        <?php foreach ($replies as $reply): ?>
                            <?= status_comment_item($reply, $user, $action, 1, $context, true, $showReplyForm) ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </article>
        <?php

        return trim((string) ob_get_clean());
    }
}

if (!function_exists('status_comment_delete_form')) {
    function status_comment_delete_form(int $commentId, string $action, int $contentId = 0): string
    {
        ob_start();
        ?>
        <form class="status-comment-delete" method="post" action="<?= e($action) ?>" data-status-form<?= $contentId > 0 ? ' data-status-id="' . e($contentId) . '"' : '' ?> data-confirm="<?= et('account.status_comment_delete_confirm') ?>" data-confirm-title="<?= et('account.status_comment_delete_title') ?>" data-confirm-ok="<?= et('common.delete') ?>" data-confirm-cancel="<?= et('common.cancel') ?>" data-confirm-variant="danger">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="comment_delete">
            <input type="hidden" name="comment_id" value="<?= e($commentId) ?>">
            <button class="link-button text-danger" type="submit"><?= et('account.status_comment_delete') ?></button>
        </form>
        <?php

        return trim((string) ob_get_clean());
    }
}

if (!function_exists('status_comment_like_control')) {
    function status_comment_like_control(int $commentId, int $likesCount, bool $liked, ?array $user, string $action, int $contentId = 0): string
    {
        ob_start();
        ?>
        <?php if ($user !== null): ?>
            <form class="status-comment-like" method="post" action="<?= e($action) ?>" data-status-form<?= $contentId > 0 ? ' data-status-id="' . e($contentId) . '"' : '' ?>>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="comment_like">
                <input type="hidden" name="comment_id" value="<?= e($commentId) ?>">
                <button class="link-button status-comment-like-button<?= $liked ? ' is-active' : '' ?>" type="submit" data-comment-like-button data-comment-id="<?= e($commentId) ?>">
                    <?= icon('thumb-up', 'icon status-like-icon status-like-icon-outline') ?><?= icon('thumb-up-filled', 'icon status-like-icon status-like-icon-filled') ?> <span data-comment-like-count data-comment-id="<?= e($commentId) ?>"><?= e($likesCount) ?></span>
                </button>
            </form>
        <?php else: ?>
            <span class="status-comment-like-button" aria-label="<?= et('account.status_like') ?>" data-comment-like-button data-comment-id="<?= e($commentId) ?>">
                <?= icon('thumb-up', 'icon status-like-icon status-like-icon-outline') ?> <span data-comment-like-count data-comment-id="<?= e($commentId) ?>"><?= e($likesCount) ?></span>
            </span>
        <?php endif; ?>
        <?php

        return trim((string) ob_get_clean());
    }
}

if (!function_exists('status_post_modal')) {
    function status_post_modal(array $item, ?array $user, string $action): string
    {
        $contentId = (int) ($item['id'] ?? 0);

        if ($contentId < 1) {
            return '';
        }

        return render('modals/status-post', [
            'item' => $item,
            'user' => $user,
            'action' => $action,
        ]);
    }
}

if (!function_exists('status_report_modal')) {
    function status_report_modal(array $item, ?array $user, string $action): string
    {
        $contentId = (int) ($item['id'] ?? 0);
        $authorId = (int) ($item['author_id'] ?? $item['user_id'] ?? 0);

        if ($contentId < 1 || $user === null || $authorId === (int) ($user['id'] ?? 0)) {
            return '';
        }

        return render('modals/status-report', [
            'item' => $item,
            'user' => $user,
            'action' => $action,
        ]);
    }
}

if (!function_exists('status_login_url')) {
    function status_login_url(string $fragment = '', string $fallback = ''): string
    {
        $next = auth_safe_next_url($fallback);

        if ($next === '') {
            $next = auth_safe_next_url((string) ($_SERVER['REQUEST_URI'] ?? route_path()));
        }

        if ($next === '') {
            $next = auth_referer_next_url();
        }

        if ($next === '') {
            $next = '/';
        }

        if ($fragment !== '' && str_starts_with($fragment, '#') && !str_contains($next, '#')) {
            $next .= $fragment;
        }

        return '/login?next=' . rawurlencode($next);
    }
}

if (!function_exists('status_edit_modal')) {
    function status_edit_modal(array $item, string $action): string
    {
        $contentId = (int) ($item['id'] ?? 0);

        if ($contentId < 1) {
            return '';
        }

        return render('modals/status-edit', [
            'item' => $item,
            'action' => $action,
        ]);
    }
}

if (!function_exists('status_create_for_user')) {
    function status_create_for_user(array $user, string $redirect = '/'): void
    {
        $payload = status_payload();
        $userId = (int) ($user['id'] ?? 0);
        $body = (string) ($payload['body'] ?? '');

        if (trim($body) === '') {
            flash('error', t('account.messages.status_required'));
            redirect($redirect);
        }

        moderation_require_not_muted($user, $redirect);
        moderation_require_action($user, 'post', $redirect);
        moderation_require_unique_body($user, $body, $redirect);

        $now = date_db();
        $contentId = (int) insert('content', [
            'body' => $body,
            'author_id' => $userId,
            'published_at' => $now,
            'created_at' => $now,
        ]);
        status_sync_tags($contentId, (array) ($payload['tags'] ?? []));
        moderation_record_action($user, 'post');

        flash('success', t('account.messages.status_created'));
        redirect($redirect);
    }
}

if (!function_exists('app_existing_tables')) {
    function app_existing_tables(array $tables): array
    {
        $tables = array_values(array_unique(array_filter(array_map(
            static fn (mixed $table): string => trim((string) $table),
            $tables
        ), static fn (string $table): bool => preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) === 1)));

        if ($tables === []) {
            return [];
        }

        $driver = (string) config('database.driver', 'mysql');
        $placeholders = implode(', ', array_fill(0, count($tables), '?'));

        if ($driver === 'sqlite') {
            $rows = all(
                'SELECT name FROM sqlite_master WHERE type = ? AND name IN (' . $placeholders . ')',
                array_merge(['table'], $tables)
            );

            return array_values(array_map(static fn (array $row): string => (string) ($row['name'] ?? ''), $rows));
        }

        $rows = all(
            'SELECT TABLE_NAME
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN (' . $placeholders . ')',
            $tables
        );

        return array_values(array_map(static fn (array $row): string => (string) ($row['TABLE_NAME'] ?? ''), $rows));
    }
}

if (!function_exists('maintenance_cleanup_batch_size')) {
    function maintenance_cleanup_batch_size(mixed $value): int
    {
        $size = (int) $value;
        $allowed = [500, 1000, 2500, 5000];

        return in_array($size, $allowed, true) ? $size : 1000;
    }
}

if (!function_exists('maintenance_cleanup_tasks')) {
    function maintenance_cleanup_tasks(): array
    {
        return [
            'orphan_tag_relations' => [
                'icon' => 'tag',
                'label' => t('maintenance.tasks.orphan_tag_relations'),
                'description' => t('maintenance.tasks.orphan_tag_relations_help'),
            ],
            'orphan_terms' => [
                'icon' => 'tag',
                'label' => t('maintenance.tasks.orphan_terms'),
                'description' => t('maintenance.tasks.orphan_terms_help'),
            ],
            'old_action_limits' => [
                'icon' => 'clock',
                'label' => t('maintenance.tasks.old_action_limits'),
                'description' => t('maintenance.tasks.old_action_limits_help'),
            ],
            'old_read_notifications' => [
                'icon' => 'bell',
                'label' => t('maintenance.tasks.old_read_notifications'),
                'description' => t('maintenance.tasks.old_read_notifications_help'),
            ],
        ];
    }
}

if (!function_exists('maintenance_cleanup_run')) {
    function maintenance_cleanup_run(array $selected, int $batchSize = 1000): array
    {
        $tasks = maintenance_cleanup_tasks();
        $batchSize = maintenance_cleanup_batch_size($batchSize);
        $results = [];

        foreach ($selected as $task) {
            $task = trim((string) $task);

            if (!isset($tasks[$task])) {
                continue;
            }

            try {
                $results[$task] = maintenance_cleanup_task_run($task, $batchSize);
            } catch (Throwable $exception) {
                $results[$task] = [
                    'task' => $task,
                    'before' => null,
                    'changed' => 0,
                    'remaining' => null,
                    'batch_size' => $batchSize,
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return $results;
    }
}

if (!function_exists('maintenance_cleanup_task_run')) {
    function maintenance_cleanup_task_run(string $task, int $batchSize): array
    {
        $before = maintenance_cleanup_count($task);
        $changed = maintenance_cleanup_delete($task, $batchSize);
        $remaining = maintenance_cleanup_count($task);

        return [
            'task' => $task,
            'before' => $before,
            'changed' => $changed,
            'remaining' => $remaining,
            'batch_size' => $batchSize,
            'done' => $remaining < 1,
        ];
    }
}

if (!function_exists('maintenance_cleanup_count')) {
    function maintenance_cleanup_count(string $task): int
    {
        return match ($task) {
            'orphan_tag_relations' => (int) val(
                'SELECT COUNT(*)
                FROM content_tags ct
                LEFT JOIN content c ON c.id = ct.content_id
                LEFT JOIN terms t ON t.id = ct.term_id
                WHERE c.id IS NULL OR t.id IS NULL'
            ),
            'orphan_terms' => (int) val(
                'SELECT COUNT(*)
                FROM terms t
                LEFT JOIN content_tags ct ON ct.term_id = t.id
                WHERE ct.term_id IS NULL'
            ),
            'old_action_limits' => (int) val(
                'SELECT COUNT(*)
                FROM user_action_limits
                WHERE bucket_start < ?',
                [date_db('-30 days')]
            ),
            'old_read_notifications' => (int) val(
                'SELECT COUNT(*)
                FROM notifications
                WHERE read_at IS NOT NULL AND read_at < ?',
                [date_db('-90 days')]
            ),
            default => 0,
        };
    }
}

if (!function_exists('maintenance_cleanup_delete')) {
    function maintenance_cleanup_delete(string $task, int $batchSize): int
    {
        $batchSize = maintenance_cleanup_batch_size($batchSize);

        return match ($task) {
            'orphan_tag_relations' => maintenance_cleanup_delete_orphan_tag_relations($batchSize),
            'orphan_terms' => maintenance_cleanup_delete_limited(
                'DELETE FROM terms
                WHERE id NOT IN (
                    SELECT term_id FROM content_tags
                )
                LIMIT ' . $batchSize
            ),
            'old_action_limits' => maintenance_cleanup_delete_limited(
                'DELETE FROM user_action_limits
                WHERE bucket_start < ?
                LIMIT ' . $batchSize,
                [date_db('-30 days')]
            ),
            'old_read_notifications' => maintenance_cleanup_delete_limited(
                'DELETE FROM notifications
                WHERE read_at IS NOT NULL AND read_at < ?
                LIMIT ' . $batchSize,
                [date_db('-90 days')]
            ),
            default => 0,
        };
    }
}

if (!function_exists('maintenance_cleanup_delete_limited')) {
    function maintenance_cleanup_delete_limited(string $sql, array $params = []): int
    {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }
}

if (!function_exists('maintenance_cleanup_delete_orphan_tag_relations')) {
    function maintenance_cleanup_delete_orphan_tag_relations(int $batchSize): int
    {
        $sql = 'DELETE ct
            FROM content_tags ct
            INNER JOIN (
                SELECT content_id, term_id
                FROM (
                    SELECT ct.content_id, ct.term_id
                    FROM content_tags ct
                    LEFT JOIN content c ON c.id = ct.content_id
                    LEFT JOIN terms t ON t.id = ct.term_id
                    WHERE c.id IS NULL OR t.id IS NULL
                    LIMIT ' . $batchSize . '
                ) orphan_rows
            ) x ON x.content_id = ct.content_id AND x.term_id = ct.term_id';

        return maintenance_cleanup_delete_limited($sql);
    }
}

if (!function_exists('app_apply_user_locale')) {
    function app_apply_user_locale(): void
    {
        $user = auth();

        if ($user === null) {
            return;
        }

        $locale = language_code((string) ($user['locale'] ?? ''));

        if ($locale !== '' && array_key_exists($locale, language_packages())) {
            locale($locale);
        }
    }
}

if (!function_exists('app_touch_user_activity')) {
    function app_touch_user_activity(?array $user = null): void
    {
        $user ??= auth();
        $id = (int) ($user['id'] ?? 0);

        if ($id < 1) {
            return;
        }

        Core::session();

        $key = '_last_seen_touch_' . $id;
        $now = time();
        $interval = max(15, (int) config('auth.online_touch_interval', 60));

        if ((int) ($_SESSION[$key] ?? 0) > $now - $interval) {
            return;
        }

        try {
            update('users', ['last_seen_at' => date_db()], ['id' => $id]);
            $_SESSION[$key] = $now;
        } catch (Throwable) {
            // Activity tracking is optional and must not interrupt the request.
        }
    }
}

if (!function_exists('app_sql_identifier')) {
    function app_sql_identifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new InvalidArgumentException('Invalid SQL identifier: ' . $identifier);
        }

        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}

if (!function_exists('app_auth_account_ready')) {
    function app_auth_account_ready(): bool
    {
        try {
            return (int) val(
                sprintf(
                    'SELECT COUNT(*) FROM %s WHERE %s IS NOT NULL AND %s <> ?',
                    app_sql_identifier('users'),
                    app_sql_identifier('password'),
                    app_sql_identifier('password')
                ),
                ['']
            ) > 0;
        } catch (Throwable) {
            return false;
        }
    }
}

if (!function_exists('app_db_status')) {
    function app_db_status(?array $requiredTables = null): array
    {
        $requiredTables ??= app_required_tables();
        $status = [
            'installed' => (bool) config('install.installed', false),
            'connected' => false,
            'account_ready' => false,
            'ready' => false,
            'missing_tables' => [],
            'error' => null,
        ];

        if (!$status['installed']) {
            return $status;
        }

        try {
            db()->query('SELECT 1');
            $status['connected'] = true;
            $existingTables = array_flip(app_existing_tables(array_map('strval', $requiredTables)));

            foreach ($requiredTables as $table) {
                if (!isset($existingTables[(string) $table])) {
                    $status['missing_tables'][] = (string) $table;
                }
            }

            if ($status['missing_tables'] === []) {
                $status['account_ready'] = app_auth_account_ready();
            }

            $status['ready'] = $status['missing_tables'] === [] && $status['account_ready'];
        } catch (Throwable $exception) {
            $status['error'] = $exception->getMessage();
        }

        return $status;
    }
}

if (!function_exists('app_db_ready')) {
    function app_db_ready(?array $requiredTables = null): bool
    {
        return (bool) app_db_status($requiredTables)['ready'];
    }
}

if (!function_exists('asset')) {
    function asset(string $path, ?bool $version = null): string
    {
        return Core::asset($path, $version);
    }
}

if (!function_exists('icon')) {
    function icon(string $name, string $class = 'icon', ?string $label = null, array $attributes = []): string
    {
        return Core::icon($name, $class, $label, $attributes);
    }
}

if (!function_exists('q')) {
    function q(string $sql, array $params = []): PDOStatement
    {
        return Core::query($sql, $params);
    }
}

if (!function_exists('query')) {
    function query(string $sql, array $params = []): PDOStatement
    {
        return Core::query($sql, $params);
    }
}

if (!function_exists('run')) {
    function run(string $sql, array $params = []): int
    {
        return Core::exec($sql, $params);
    }
}

if (!function_exists('all')) {
    function all(string $sql, array $params = []): array
    {
        return Core::all($sql, $params);
    }
}

if (!function_exists('one')) {
    function one(string $sql, array $params = []): ?array
    {
        return Core::one($sql, $params);
    }
}

if (!function_exists('val')) {
    function val(string $sql, array $params = []): mixed
    {
        return Core::value($sql, $params);
    }
}

if (!function_exists('db_select')) {
    function db_select(string $sql): CoreQuery
    {
        return Core::select($sql);
    }
}

if (!function_exists('insert')) {
    function insert(string $table, array $data): string
    {
        return Core::insert($table, $data);
    }
}

if (!function_exists('update')) {
    function update(string $table, array $data, array $where): int
    {
        return Core::update($table, $data, $where);
    }
}

if (!function_exists('delete')) {
    function delete(string $table, array $where): int
    {
        return Core::delete($table, $where);
    }
}

if (!function_exists('find')) {
    function find(string $table, array $where, array|string $columns = '*'): ?array
    {
        return Core::find($table, $where, $columns);
    }
}

if (!function_exists('records')) {
    function records(string $table, array $where = [], array|string $columns = '*', ?int $limit = null, ?int $offset = null): array
    {
        return Core::get($table, $where, $columns, $limit, $offset);
    }
}

if (!function_exists('total')) {
    function total(string $table, array $where = []): int
    {
        return Core::count($table, $where);
    }
}

if (!function_exists('paginate')) {
    function paginate(
        string $table,
        array $where = [],
        array|string $columns = '*',
        ?int $page = null,
        int $perPage = 15,
        ?string $orderBy = null,
        string $direction = 'ASC'
    ): array {
        return Core::paginate($table, $where, $columns, $page, $perPage, $orderBy, $direction);
    }
}

if (!function_exists('pagination')) {
    function pagination(array $pagination, ?string $baseUrl = null, string $pageName = 'page', int $window = 2): string
    {
        return Core::pagination($pagination, $baseUrl, $pageName, $window);
    }
}

if (!function_exists('pager')) {
    function pager(array $pagination, ?string $baseUrl = null, string $pageName = 'page', int $window = 2): string
    {
        return Core::pagination($pagination, $baseUrl, $pageName, $window);
    }
}

if (!function_exists('pagination_meta')) {
    function pagination_meta(int $total, ?int $page = null, int $perPage = 15): array
    {
        return Core::paginationMeta($total, $page, $perPage);
    }
}

if (!function_exists('pagination_sql')) {
    function pagination_sql(array $pagination): string
    {
        $perPage = max(1, min(200, (int) ($pagination['per_page'] ?? 15)));
        $offset = max(0, (int) ($pagination['offset'] ?? 0));

        return ' LIMIT ' . $perPage . ' OFFSET ' . $offset;
    }
}

if (!function_exists('admin_per_page_options')) {
    function admin_per_page_options(): array
    {
        $configured = (array) config('admin.per_page_options', [10, 25, 50, 100]);
        $options = [];

        foreach ($configured as $option) {
            $value = max(1, min(200, (int) $option));
            $options[$value] = $value;
        }

        if ($options === []) {
            $options = [10 => 10, 25 => 25, 50 => 50, 100 => 100];
        }

        ksort($options);

        return array_values($options);
    }
}

if (!function_exists('admin_per_page')) {
    function admin_per_page(?int $value = null): int
    {
        $options = admin_per_page_options();
        $default = (int) config('admin.per_page', $options[0] ?? 25);
        $default = in_array($default, $options, true) ? $default : ($options[0] ?? 25);
        $value ??= (int) get('per_page', $default);
        $value = max(1, min(200, $value));

        return in_array($value, $options, true) ? $value : $default;
    }
}

if (!function_exists('admin_page')) {
    function admin_page(string $name = 'page'): int
    {
        return max(1, (int) get($name, 1));
    }
}

if (!function_exists('admin_list_query')) {
    function admin_list_query(array $params = [], bool $ajax = true): array
    {
        $query = [];

        if ($ajax) {
            $query['api'] = 'list';
            $query['view'] = 'html';
        }

        foreach ($params as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }

            $query[(string) $key] = $value;
        }

        return $query;
    }
}

if (!function_exists('admin_list_url')) {
    function admin_list_url(string $path, array $params = [], bool $ajax = true): string
    {
        $query = admin_list_query($params, $ajax);

        return $path . ($query !== [] ? '?' . http_build_query($query) : '');
    }
}

if (!function_exists('admin_pagination')) {
    function admin_pagination(array $pagination, string $path, string $target, array $params = [], string $pageName = 'page', int $window = 2): string
    {
        $page = max(1, (int) ($pagination['page'] ?? 1));
        $lastPage = max(1, (int) ($pagination['last_page'] ?? 1));
        $total = max(0, (int) ($pagination['total'] ?? 0));
        $from = max(0, (int) ($pagination['from'] ?? 0));
        $to = max(0, (int) ($pagination['to'] ?? 0));
        $window = max(1, $window);

        if ($lastPage <= 1) {
            return '';
        }

        $item = static function (string $label, int|string|null $targetPage, string $class = '', bool $disabled = false, bool $current = false) use ($path, $target, $params, $pageName): string {
            $classes = trim('pagination-link ' . $class . ($current ? ' is-active' : ''));

            if ($disabled || $targetPage === null) {
                return '<span class="' . e($classes) . '" aria-disabled="true">' . e($label) . '</span>';
            }

            if ($current) {
                return '<span class="' . e($classes) . '" aria-current="page">' . e($label) . '</span>';
            }

            $query = $params;
            $query[$pageName] = (int) $targetPage;
            $href = admin_list_url($path, $query, true);
            $history = admin_list_url($path, $query, false);

            return '<a class="' . e($classes) . '" href="' . e($href) . '" data-ajax data-ajax-target="' . e($target) . '" data-history="' . e($history) . '">' . e($label) . '</a>';
        };

        $pages = [1, $lastPage];
        $start = max(1, $page - $window);
        $end = min($lastPage, $page + $window);

        for ($i = $start; $i <= $end; $i++) {
            $pages[] = $i;
        }

        $pages = array_values(array_unique($pages));
        sort($pages);

        $html = '<nav class="pagination admin-pagination" aria-label="' . et('common.pagination') . '">';
        $html .= '<div class="pagination-summary">' . e(t('common.pagination_summary', ['from' => (string) $from, 'to' => (string) $to, 'total' => (string) $total])) . '</div>';
        $html .= '<div class="pagination-list">';
        $html .= $item(t('common.previous', [], null, 'Previous'), $pagination['prev_page'] ?? null, 'pagination-prev', $page <= 1);

        $previous = null;

        foreach ($pages as $pageNumber) {
            if ($previous !== null && $pageNumber > $previous + 1) {
                $html .= '<span class="pagination-ellipsis" aria-hidden="true">...</span>';
            }

            $html .= $item((string) $pageNumber, $pageNumber, '', false, $pageNumber === $page);
            $previous = $pageNumber;
        }

        $html .= $item(t('common.next', [], null, 'Next'), $pagination['next_page'] ?? null, 'pagination-next', $page >= $lastPage);
        $html .= '</div></nav>';

        return $html;
    }
}

if (!function_exists('admin_per_page_control')) {
    function admin_per_page_control(string $path, string $target, array $params = [], ?int $selected = null): string
    {
        $selected ??= admin_per_page();
        $params['page'] = 1;

        ob_start();
        ?>
        <form class="admin-per-page-form" action="<?= e($path) ?>" method="get" data-ajax-form data-ajax-target="<?= e($target) ?>" data-history="<?= e($path) ?>">
            <input type="hidden" name="api" value="list">
            <input type="hidden" name="view" value="html">
            <?php foreach ($params as $key => $value): ?>
                <?php if ($key === 'per_page' || $value === '' || $value === null) {
                    continue;
                } ?>
                <input type="hidden" name="<?= e((string) $key) ?>" value="<?= e((string) $value) ?>">
            <?php endforeach; ?>
            <label class="field-inline">
                <span class="label"><?= et('common.per_page') ?></span>
                <select class="select select-sm" name="per_page" data-submit-on-change>
                    <?php foreach (admin_per_page_options() as $option): ?>
                        <option value="<?= e((string) $option) ?>"<?= $selected === $option ? ' selected' : '' ?>><?= e((string) $option) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>
        <?php

        return trim((string) ob_get_clean());
    }
}

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return Core::e($value);
    }
}

if (!function_exists('h')) {
    function h(mixed $value): string
    {
        return Core::e($value);
    }
}

if (!function_exists('t')) {
    function t(string $key, array $replace = [], ?string $locale = null, ?string $default = null): string
    {
        return Core::t($key, $replace, $locale, $default);
    }
}

if (!function_exists('et')) {
    function et(string $key, array $replace = [], ?string $locale = null, ?string $default = null): string
    {
        return e(t($key, $replace, $locale, $default));
    }
}

if (!function_exists('locale')) {
    function locale(?string $locale = null): string
    {
        return Core::locale($locale);
    }
}

if (!function_exists('language_code')) {
    function language_code(string $code): string
    {
        $code = strtolower(trim(str_replace('_', '-', $code)));

        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $code) ? $code : '';
    }
}

if (!function_exists('language_names')) {
    function language_names(): array
    {
        $defaults = [
            'af' => 'Afrikaans',
            'ar' => 'العربية',
            'bg' => 'Български',
            'ca' => 'Català',
            'cs' => 'Česky',
            'da' => 'Dansk',
            'de' => 'Deutsch',
            'el' => 'Ελληνικά',
            'en' => 'English',
            'es' => 'Español',
            'et' => 'Eesti',
            'fi' => 'Suomi',
            'fr' => 'Français',
            'he' => 'עברית',
            'hi' => 'हिन्दी',
            'hr' => 'Hrvatski',
            'hu' => 'Magyar',
            'it' => 'Italiano',
            'ja' => '日本語',
            'ko' => '한국어',
            'lt' => 'Lietuvių',
            'lv' => 'Latviešu',
            'nl' => 'Nederlands',
            'no' => 'Norsk',
            'pl' => 'Polski',
            'pt' => 'Português',
            'pt-br' => 'Português (Brasil)',
            'ro' => 'Română',
            'ru' => 'Русский',
            'sk' => 'Slovensky',
            'sl' => 'Slovenščina',
            'sr' => 'Српски',
            'sv' => 'Svenska',
            'tr' => 'Türkçe',
            'uk' => 'Українська',
            'vi' => 'Tiếng Việt',
            'zh' => '中文',
        ];
        $configured = config('i18n.languages', []);
        $configured = is_array($configured) ? $configured : [];
        $languages = [];

        foreach ($configured as $code => $label) {
            $normalized = is_int($code) ? language_code((string) $label) : language_code((string) $code);

            if ($normalized !== '') {
                $languages[$normalized] = is_int($code) ? ($defaults[$normalized] ?? strtoupper($normalized)) : (string) $label;
            }
        }

        return $languages + $defaults;
    }
}

if (!function_exists('language_name')) {
    function language_name(string $code, ?string $default = null): string
    {
        $code = language_code($code);

        if ($code === '') {
            return (string) ($default ?? '');
        }

        $names = language_names();

        if (isset($names[$code])) {
            return (string) $names[$code];
        }

        $path = rtrim((string) config('i18n.directory', base_path('lang')), DIRECTORY_SEPARATOR . '/\\')
            . DIRECTORY_SEPARATOR . $code . '.json';

        if (is_file($path)) {
            $data = json_decode((string) file_get_contents($path), true);

            if (is_array($data) && !empty($data['install']['language_label'])) {
                return (string) $data['install']['language_label'];
            }
        }

        return (string) ($default ?? strtoupper($code));
    }
}

if (!function_exists('languages')) {
    function languages(?array $codes = null, bool $includeFiles = true): array
    {
        $items = $codes === null ? language_names() : [];

        if ($codes !== null) {
            foreach ($codes as $key => $value) {
                $code = is_int($key) ? language_code((string) $value) : language_code((string) $key);

                if ($code !== '') {
                    $items[$code] = is_int($key) ? language_name($code) : (string) $value;
                }
            }
        }

        if ($includeFiles) {
            $directory = rtrim((string) config('i18n.directory', base_path('lang')), DIRECTORY_SEPARATOR . '/\\');

            foreach (glob($directory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
                $code = language_code(pathinfo($file, PATHINFO_FILENAME));

                if ($code !== '') {
                    $items[$code] = language_name($code);
                }
            }
        }

        foreach ([locale()] as $code) {
            $code = language_code((string) $code);

            if ($code !== '' && !isset($items[$code])) {
                $items[$code] = language_name($code);
            }
        }

        ksort($items);

        return $items;
    }
}

if (!function_exists('language_packages')) {
    function language_packages(): array
    {
        $directory = rtrim((string) config('i18n.directory', base_path('lang')), DIRECTORY_SEPARATOR . '/\\');
        $defaults = language_names();
        $items = [];

        foreach (glob($directory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            $code = language_code(pathinfo($file, PATHINFO_FILENAME));

            if ($code === '') {
                continue;
            }

            $data = json_decode((string) file_get_contents($file), true);
            $items[$code] = is_array($data) && !empty($data['install']['language_label'])
                ? (string) $data['install']['language_label']
                : ($defaults[$code] ?? strtoupper($code));
        }

        ksort($items);

        return $items;
    }
}

if (!function_exists('language_options')) {
    function language_options(?string $selected = null, ?array $codes = null): string
    {
        $selected = language_code((string) $selected);
        $html = '';
        $items = $codes === null ? language_packages() : languages($codes, false);

        foreach ($items as $code => $label) {
            $html .= '<option value="' . e($code) . '"' . ($selected === $code ? ' selected' : '') . '>' . e(strtoupper($code) . ' - ' . $label) . '</option>';
        }

        return $html;
    }
}

if (!function_exists('date_format_presets')) {
    function date_format_presets(): array
    {
        return [
            'd.m.Y' => 'd.m.Y',
            'j.n.Y' => 'j.n.Y',
            'd. m. Y' => 'd. m. Y',
            'Y-m-d' => 'Y-m-d',
            'd/m/Y' => 'd/m/Y',
            'm/d/Y' => 'm/d/Y',
            'd-m-Y' => 'd-m-Y',
            'M j, Y' => 'M j, Y',
        ];
    }
}

if (!function_exists('time_format_presets')) {
    function time_format_presets(): array
    {
        return [
            'H:i' => 'H:i',
            'H:i:s' => 'H:i:s',
            'G:i' => 'G:i',
            'g:i A' => 'g:i A',
            'h:i A' => 'h:i A',
        ];
    }
}

if (!function_exists('datetime_format_presets')) {
    function datetime_format_presets(): array
    {
        return [
            'd.m.Y H:i' => 'd.m.Y H:i',
            'j.n.Y H:i' => 'j.n.Y H:i',
            'd. m. Y H:i' => 'd. m. Y H:i',
            'Y-m-d H:i' => 'Y-m-d H:i',
            'Y-m-d H:i:s' => 'Y-m-d H:i:s',
            'd/m/Y H:i' => 'd/m/Y H:i',
            'm/d/Y g:i A' => 'm/d/Y g:i A',
            'M j, Y g:i A' => 'M j, Y g:i A',
            'Y-m-d\TH:i' => 'Y-m-d\TH:i',
        ];
    }
}

if (!function_exists('datetime_format_preset_options')) {
    function datetime_format_preset_options(string $type, ?string $selected = null): string
    {
        $selected = trim((string) $selected);
        $presets = match ($type) {
            'date' => date_format_presets(),
            'time' => time_format_presets(),
            'datetime' => datetime_format_presets(),
            default => [],
        };
        $sample = new DateTimeImmutable('2026-07-05 15:04:09');
        $html = '';

        foreach ($presets as $format => $label) {
            $example = $sample->format((string) $format);
            $html .= '<option value="' . e($format) . '"' . ($selected === $format ? ' selected' : '') . '>'
                . e((string) $label . ' - ' . $example)
                . '</option>';
        }

        return $html;
    }
}

if (!function_exists('datetime_format_preset_exists')) {
    function datetime_format_preset_exists(string $type, string $format): bool
    {
        $presets = match ($type) {
            'date' => date_format_presets(),
            'time' => time_format_presets(),
            'datetime' => datetime_format_presets(),
            default => [],
        };

        return array_key_exists($format, $presets);
    }
}

if (!function_exists('timezone_presets')) {
    function timezone_presets(): array
    {
        $defaults = [
            'UTC',
            'Europe/Prague',
            'Europe/Bratislava',
            'Europe/Warsaw',
            'Europe/Berlin',
            'Europe/Vienna',
            'Europe/London',
            'America/New_York',
            'America/Chicago',
            'America/Denver',
            'America/Los_Angeles',
            'America/Toronto',
            'America/Sao_Paulo',
            'Asia/Dubai',
            'Asia/Tokyo',
            'Asia/Shanghai',
            'Australia/Sydney',
        ];
        $configured = config('datetime.timezones', []);
        $items = is_array($configured) && $configured !== [] ? $configured : $defaults;
        $valid = array_flip(timezone_identifiers_list());
        $presets = [];

        foreach ($items as $key => $value) {
            $timezone = is_int($key) ? (string) $value : (string) $key;
            $label = is_int($key) ? $timezone : (string) $value;

            if (isset($valid[$timezone])) {
                $presets[$timezone] = $label;
            }
        }

        return $presets;
    }
}

if (!function_exists('timezone_preset_label')) {
    function timezone_preset_label(string $timezone, ?string $label = null): string
    {
        $date = new DateTimeImmutable('now', new DateTimeZone($timezone));
        $offset = $date->format('P');

        return '(UTC' . ($offset === '+00:00' ? '' : $offset) . ') ' . ($label ?: $timezone);
    }
}

if (!function_exists('timezone_options')) {
    function timezone_options(?string $selected = null): string
    {
        $selected = trim((string) $selected);
        $presets = timezone_presets();
        $valid = array_flip(timezone_identifiers_list());

        if ($selected !== '' && isset($valid[$selected]) && !isset($presets[$selected])) {
            $presets = [$selected => $selected] + $presets;
        }

        $html = '';

        foreach ($presets as $timezone => $label) {
            $html .= '<option value="' . e($timezone) . '"' . ($selected === $timezone ? ' selected' : '') . '>'
                . e(timezone_preset_label((string) $timezone, (string) $label))
                . '</option>';
        }

        return $html;
    }
}

if (!function_exists('timezone')) {
    function timezone(): DateTimeZone
    {
        return Core::timezone();
    }
}

if (!function_exists('now')) {
    function now(?string $format = null): DateTimeImmutable|string
    {
        return Core::now($format);
    }
}

if (!function_exists('datetime')) {
    function datetime(mixed $value = null, ?string $format = null, ?bool $relative = null): string
    {
        if ($relative === true || ($relative === null && $format === null && (bool) config('datetime.relative', false))) {
            return relative_time($value);
        }

        return Core::dateTime($value, $format);
    }
}

if (!function_exists('relative_time')) {
    function relative_time(mixed $value = null, mixed $base = null): string
    {
        $target = relative_datetime_value($value);
        $origin = relative_datetime_value($base);
        $seconds = $target->getTimestamp() - $origin->getTimestamp();
        $absolute = abs($seconds);

        if ($absolute < 45) {
            return t('relative.now');
        }

        [$unit, $count] = match (true) {
            $absolute < 90 => ['minute', 1],
            $absolute < 45 * 60 => ['minute', (int) round($absolute / 60)],
            $absolute < 90 * 60 => ['hour', 1],
            $absolute < 22 * 3600 => ['hour', (int) round($absolute / 3600)],
            $absolute < 36 * 3600 => ['day', 1],
            $absolute < 7 * 86400 => ['day', (int) round($absolute / 86400)],
            $absolute < 4 * 604800 => ['week', max(1, (int) round($absolute / 604800))],
            $absolute < 45 * 86400 => ['month', 1],
            $absolute < 345 * 86400 => ['month', max(1, (int) round($absolute / (30 * 86400)))],
            $absolute < 545 * 86400 => ['year', 1],
            default => ['year', max(1, (int) round($absolute / (365 * 86400)))],
        };
        $direction = $seconds < 0 ? 'past' : 'future';
        $form = relative_plural_form($count);

        return t('relative.' . $direction . '.' . $unit . '.' . $form, ['count' => (string) $count]);
    }
}

if (!function_exists('relative_date')) {
    function relative_date(mixed $value = null, mixed $base = null): string
    {
        return relative_time($value, $base);
    }
}

if (!function_exists('time_ago')) {
    function time_ago(mixed $value = null, mixed $base = null): string
    {
        return relative_time($value, $base);
    }
}

if (!function_exists('relative_datetime_value')) {
    function relative_datetime_value(mixed $value = null): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value->setTimezone(timezone());
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone(timezone());
        }

        if (is_int($value) || is_float($value)) {
            return (new DateTimeImmutable('@' . (int) $value))->setTimezone(timezone());
        }

        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return new DateTimeImmutable('now', timezone());
        }

        return new DateTimeImmutable($value, timezone());
    }
}

if (!function_exists('relative_plural_form')) {
    function relative_plural_form(int $count): string
    {
        $language = strtolower(strtok(locale(), '-_') ?: 'en');

        if (in_array($language, ['cs', 'sk'], true)) {
            return $count === 1 ? 'one' : ($count >= 2 && $count <= 4 ? 'few' : 'many');
        }

        return $count === 1 ? 'one' : 'many';
    }
}

if (!function_exists('date_value')) {
    function date_value(mixed $value = null, ?string $format = null): string
    {
        return Core::dateValue($value, $format);
    }
}

if (!function_exists('time_value')) {
    function time_value(mixed $value = null, ?string $format = null): string
    {
        return Core::timeValue($value, $format);
    }
}

if (!function_exists('date_iso')) {
    function date_iso(mixed $value = null): string
    {
        return Core::dateIso($value);
    }
}

if (!function_exists('date_db')) {
    function date_db(mixed $value = null): string
    {
        return Core::dateDb($value);
    }
}

if (!function_exists('slug')) {
    function slug(string $text, string $separator = '-'): string
    {
        return Core::slug($text, $separator);
    }
}

if (!function_exists('redirect')) {
    function redirect(string $url, int $status = 302): never
    {
        Core::redirect($url, $status);
    }
}

if (!function_exists('capture')) {
    function capture(callable $callback): string
    {
        return Core::capture($callback);
    }
}

if (!function_exists('render')) {
    function render(string $template, array $data = [], ?string $directory = null): string
    {
        return Core::render($template, $data, $directory);
    }
}

if (!function_exists('view')) {
    function view(string $template, array $data = [], ?string $directory = null): string
    {
        return Core::render($template, $data, $directory);
    }
}

if (!function_exists('layout')) {
    function layout(string $template, array $data = [], mixed $content = null, ?string $directory = null): void
    {
        Core::layout($template, $data, $content, $directory);
    }
}

if (!function_exists('json')) {
    function json(mixed $data, int $status = 200): never
    {
        Core::json($data, $status);
    }
}

if (!function_exists('api')) {
    function api(mixed $data = null, ?string $message = null, int $status = 200, array $meta = []): never
    {
        Core::apiOk($data, $message, $status, $meta);
    }
}

if (!function_exists('api_ok')) {
    function api_ok(mixed $data = null, ?string $message = null, int $status = 200, array $meta = []): never
    {
        Core::apiOk($data, $message, $status, $meta);
    }
}

if (!function_exists('api_created')) {
    function api_created(mixed $data = null, ?string $message = 'Created.', array $meta = []): never
    {
        Core::apiCreated($data, $message, $meta);
    }
}

if (!function_exists('api_no_content')) {
    function api_no_content(): never
    {
        Core::apiNoContent();
    }
}

if (!function_exists('api_error')) {
    function api_error(string $message = 'Request failed.', int $status = 400, string $code = 'error', array $details = []): never
    {
        Core::apiError($message, $status, $code, $details);
    }
}

if (!function_exists('api_validation')) {
    function api_validation(array $errors, string $message = 'Validation failed.'): never
    {
        Core::apiValidation($errors, $message);
    }
}

if (!function_exists('api_endpoint')) {
    function api_endpoint(array|string $methods, callable $handler): never
    {
        Core::apiEndpoint($methods, $handler);
    }
}

if (!function_exists('route')) {
    function route(array|string $methods, string $path, callable $handler): void
    {
        Core::route($methods, $path, $handler);
    }
}

if (!function_exists('api_route')) {
    function api_route(array|string $methods, string $path, callable $handler): void
    {
        Core::apiRoute($methods, $path, $handler);
    }
}

if (!function_exists('route_get')) {
    function route_get(string $path, callable $handler): void
    {
        Core::route('GET', $path, $handler);
    }
}

if (!function_exists('route_post')) {
    function route_post(string $path, callable $handler): void
    {
        Core::route('POST', $path, $handler);
    }
}

if (!function_exists('route_put')) {
    function route_put(string $path, callable $handler): void
    {
        Core::route('PUT', $path, $handler);
    }
}

if (!function_exists('route_patch')) {
    function route_patch(string $path, callable $handler): void
    {
        Core::route('PATCH', $path, $handler);
    }
}

if (!function_exists('route_delete')) {
    function route_delete(string $path, callable $handler): void
    {
        Core::route('DELETE', $path, $handler);
    }
}

if (!function_exists('dispatch_routes')) {
    function dispatch_routes(?string $path = null, ?string $method = null): bool
    {
        return Core::dispatch($path, $method);
    }
}

if (!function_exists('autoroute')) {
    function autoroute(?string $path = null, ?string $directory = null): bool
    {
        return Core::autoroute($path, $directory);
    }
}

if (!function_exists('route_path')) {
    function route_path(?string $path = null): string
    {
        return Core::path($path);
    }
}

if (!function_exists('require_method')) {
    function require_method(array|string $methods): void
    {
        Core::requireMethod($methods);
    }
}

if (!function_exists('body')) {
    function body(?string $key = null, mixed $default = null): mixed
    {
        return Core::payload($key, $default);
    }
}

if (!function_exists('payload')) {
    function payload(?string $key = null, mixed $default = null): mixed
    {
        return Core::payload($key, $default);
    }
}

if (!function_exists('input')) {
    function input(?string $key = null, mixed $default = null): mixed
    {
        return Core::input($key, $default);
    }
}

if (!function_exists('request')) {
    function request(?string $key = null, mixed $default = null): mixed
    {
        return Core::request($key, $default);
    }
}

if (!function_exists('validate')) {
    function validate(array $data, array $rules, array $messages = []): array
    {
        return Core::validate($data, $rules, $messages);
    }
}

if (!function_exists('api_validated')) {
    function api_validated(array $rules, ?array $data = null, array $messages = []): array
    {
        return Core::validated($rules, $data, $messages);
    }
}

if (!function_exists('wants_json')) {
    function wants_json(): bool
    {
        return Core::wantsJson();
    }
}

if (!function_exists('wants_partial')) {
    function wants_partial(): bool
    {
        return Core::wantsPartial();
    }
}

if (!function_exists('is_json')) {
    function is_json(): bool
    {
        return Core::isJson();
    }
}

if (!function_exists('bearer_token')) {
    function bearer_token(): ?string
    {
        return Core::bearerToken();
    }
}

if (!function_exists('get')) {
    function get(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $_GET;
        }

        return $_GET[$key] ?? $default;
    }
}

if (!function_exists('post')) {
    function post(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $_POST;
        }

        return $_POST[$key] ?? $default;
    }
}

if (!function_exists('method')) {
    function method(): string
    {
        return Core::method();
    }
}

if (!function_exists('is_post')) {
    function is_post(): bool
    {
        return Core::isPost();
    }
}

if (!function_exists('flash')) {
    function flash(string $key, mixed $value = null): mixed
    {
        if (func_num_args() === 2) {
            return Core::flash($key, $value);
        }

        return Core::flash($key);
    }
}

if (!function_exists('auth')) {
    function auth(?string $key = null, mixed $default = null): mixed
    {
        return Core::auth($key, $default);
    }
}

if (!function_exists('auth_id')) {
    function auth_id(): mixed
    {
        return Core::authId();
    }
}

if (!function_exists('auth_check')) {
    function auth_check(): bool
    {
        return Core::authCheck();
    }
}

if (!function_exists('auth_attempt')) {
    function auth_attempt(array $credentials): bool
    {
        return Core::authAttempt($credentials);
    }
}

if (!function_exists('auth_login')) {
    function auth_login(array|int|string $user, bool $remember = false): bool
    {
        return Core::authLogin($user, $remember);
    }
}

if (!function_exists('auth_logout')) {
    function auth_logout(): void
    {
        Core::authLogout();
    }
}

if (!function_exists('require_auth')) {
    function require_auth(?string $redirect = null): array
    {
        return Core::requireAuth($redirect);
    }
}

if (!function_exists('require_role')) {
    function require_role(array|string $roles, ?string $redirect = null): array
    {
        $user = require_auth($redirect);

        if (auth_is($roles)) {
            return $user;
        }

        if (str_starts_with(route_path(), '/api') || wants_json() || isset($_GET['api'])) {
            api_error(t('auth.forbidden'), 403, 'forbidden');
        }

        flash('error', t('auth.forbidden'));
        redirect($redirect ?? (string) config('auth.login_url', '/login'));
    }
}

if (!function_exists('require_admin')) {
    function require_admin(?string $redirect = null): array
    {
        return require_role('admin', $redirect);
    }
}

if (!function_exists('guest_only')) {
    function guest_only(?string $redirect = null): void
    {
        Core::guestOnly($redirect);
    }
}

if (!function_exists('auth_is')) {
    function auth_is(array|string $roles): bool
    {
        return Core::authIs($roles);
    }
}

if (!function_exists('auth_password')) {
    function auth_password(string $password): string
    {
        return Core::authPassword($password);
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return Core::csrfToken();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return Core::csrfField();
    }
}

if (!function_exists('csrf_check')) {
    function csrf_check(?string $token = null): bool
    {
        return Core::verifyCsrf($token);
    }
}

if (!function_exists('csrf_require')) {
    function csrf_require(?string $token = null): void
    {
        Core::requireCsrf($token);
    }
}

if (!function_exists('captcha_field')) {
    function captcha_field(string $context = 'form'): string
    {
        return Core::captchaField($context);
    }
}

if (!function_exists('captcha_check')) {
    function captcha_check(string $context = 'form'): bool
    {
        return Core::captchaCheck($context);
    }
}

if (!function_exists('captcha_refresh')) {
    function captcha_refresh(string $context = 'form'): string
    {
        return Core::captchaRefresh($context);
    }
}
