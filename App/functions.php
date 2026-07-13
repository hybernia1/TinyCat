<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/Core.php';
require_once __DIR__ . '/Avatar.php';
require_once __DIR__ . '/StatusLinks.php';
require_once __DIR__ . '/LinkMetadata.php';

function config(?string $key = null, mixed $default = null): mixed
{
    return Core::config($key, $default);
}

function setting(?string $key = null, mixed $default = null): mixed
{
    return Core::setting($key, $default);
}

function setting_set(string $key, mixed $value, string $type = 'string', string $group = 'general'): void
{
    Core::setSetting($key, $value, $type, $group);
}

function public_path(string $path = ''): string
{
    return Core::publicPath($path);
}

function base_path(string $path = ''): string
{
    return Core::basePath($path);
}

function db(): PDO
{
    return Core::db();
}

function app_required_tables(): array
{
    return ['users', 'content', 'terms', 'content_tags', 'links', 'content_links', 'content_likes', 'content_comments', 'comment_likes', 'user_followers', 'notifications', 'content_reports', 'user_action_limits', 'settings'];
}

function site_name(): string
{
    return (string) config('site.name', 'TinyCat');
}

function site_logo_url(): string
{
    return trim((string) config('site.logo_url', ''));
}

function site_favicon_url(): string
{
    return trim((string) config('site.favicon_url', ''));
}

function site_footer_html(): string
{
    return trim((string) config('site.footer_html', ''));
}

function site_meta_image_url(): string
{
    return site_logo_url() ?: site_favicon_url();
}

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

function app_request_scheme(): string
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));

    return in_array($https, ['on', '1'], true)
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        ? 'https'
        : 'http';
}

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

function status_meta_title(array $item, int $limit = 110): string
{
    $author = trim((string) ($item['author_name'] ?? $item['author_username'] ?? $item['username'] ?? ''));
    $body = status_strip_external_urls((string) ($item['body'] ?? ''));
    $body = meta_text($body, 90);

    if ($body !== '') {
        $title = $author !== '' ? $author . ': ' . $body : $body;

        return meta_text($title, $limit);
    }

    $linkTitle = status_meta_link_title($item, 90);

    if ($linkTitle !== '') {
        $title = $author !== '' ? $author . ': ' . $linkTitle : $linkTitle;

        return meta_text($title, $limit);
    }

    if ($author !== '') {
        return t('public.status_title_by', ['author' => $author]);
    }

    return t('public.status_title');
}

function status_meta_link_title(array $item, int $limit = 90): string
{
    $contentId = (int) ($item['id'] ?? $item['content_id'] ?? 0);

    if ($contentId < 1) {
        return '';
    }

    foreach (status_links_for_content($contentId) as $link) {
        $link = (array) $link;

        if (status_link_is_internal($link)) {
            continue;
        }

        $title = meta_text((string) ($link['title'] ?? ''), $limit);

        if ($title !== '' && !status_link_title_is_placeholder($title)) {
            return $title;
        }
    }

    return '';
}

function status_link_title_is_placeholder(string $title): bool
{
    return in_array(strtolower(trim($title)), [
        'youtube video',
        'vimeo video',
        'dailymotion video',
    ], true);
}

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

function status_meta_image(array $item): string
{
    return user_avatar_url($item) ?: site_meta_image_url();
}

function avatar_url(string $username, array|string|null $config = null): string
{
    return Avatar::url($username, $config);
}

function user_avatar_url(?array $user): string
{
    if ($user === null) {
        return '';
    }

    foreach (['username', 'author_username', 'actor_username', 'author_name', 'actor_name', 'name'] as $key) {
        $username = username_normalize((string) ($user[$key] ?? ''));

        if (username_valid($username)) {
            return avatar_url($username, $user['avatar_config'] ?? $user['author_avatar_config'] ?? $user['actor_avatar_config'] ?? null);
        }
    }

    return '';
}

function user_avatar_html(?array $user, string $alt = '', string $fallbackIcon = 'user'): string
{
    $url = user_avatar_url($user);
    $fallback = '<span class="avatar-fallback" data-user-avatar-fallback'
        . ($url !== '' ? ' hidden' : '') . '>' . icon($fallbackIcon) . '</span>';

    if ($url === '') {
        return $fallback;
    }

    return '<img src="' . e($url) . '" alt="' . e($alt) . '" loading="lazy" data-user-avatar-image>' . $fallback;
}

function user_display_name(?array $user): string
{
    if ($user === null) {
        return '';
    }

    return trim((string) ($user['username'] ?? ''));
}

function user_public_payload(?array $user): ?array
{
    if ($user === null || (int) ($user['id'] ?? 0) < 1) {
        return null;
    }

    return [
        'id' => (int) ($user['id'] ?? 0),
        'username' => (string) ($user['username'] ?? ''),
        'role' => (string) ($user['role'] ?? ''),
        'status' => (string) ($user['status'] ?? ''),
        'locale' => (string) ($user['locale'] ?? ''),
        'theme' => user_theme($user),
        'avatar_url' => user_avatar_url($user),
    ];
}

function theme_choices(): array
{
    return [
        'system' => t('account.theme_system'),
        'light' => t('account.theme_light'),
        'dark' => t('account.theme_dark'),
    ];
}

function theme_normalize(string $theme): string
{
    $theme = strtolower(trim($theme));

    return in_array($theme, ['system', 'light', 'dark'], true) ? $theme : 'system';
}

function user_theme(?array $user): string
{
    return theme_normalize((string) ($user['theme'] ?? 'system'));
}

function theme_options(string $selected = 'system'): string
{
    $selected = theme_normalize($selected);
    $html = '';

    foreach (theme_choices() as $value => $label) {
        $html .= '<option value="' . e($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . e($label) . '</option>';
    }

    return $html;
}

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

    $maxSize = 64 * 1024 * 1024;
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
        $source = image_apply_orientation($source, $tmpName);
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

    $baseDirectory = base_path('uploads/site');
    $baseUrl = '/uploads/site';
    $subfolder = date('Y/m');
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

    if (!imagewebp($canvas, $target, 86)) {
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

function image_apply_orientation(GdImage $image, string $path): GdImage
{
    if (!function_exists('exif_read_data')) {
        return $image;
    }

    $exif = @exif_read_data($path);
    $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

    $rotate = static function (GdImage $source, int $angle): GdImage {
        $rotated = imagerotate($source, $angle, 0);

        return $rotated instanceof GdImage ? $rotated : $source;
    };

    $flip = static function (GdImage $source, int $mode): GdImage {
        if (function_exists('imageflip')) {
            imageflip($source, $mode);
        }

        return $source;
    };

    return match ($orientation) {
        2 => $flip($image, IMG_FLIP_HORIZONTAL),
        3 => $rotate($image, 180),
        4 => $flip($image, IMG_FLIP_VERTICAL),
        5 => $flip($rotate($image, -90), IMG_FLIP_HORIZONTAL),
        6 => $rotate($image, -90),
        7 => $flip($rotate($image, 90), IMG_FLIP_HORIZONTAL),
        8 => $rotate($image, 90),
        default => $image,
    };
}

function auth_account_url(): string
{
    return '/account';
}

function auth_landing_url(?array $user = null): string
{
    $user ??= auth();

    if ($user !== null && (string) ($user['role'] ?? '') === 'admin') {
        return '/admin';
    }

    $id = (int) ($user['id'] ?? 0);

    return $id > 0 ? author_url($id) : auth_account_url();
}

function auth_next_path(string $next): string
{
    return route_path((string) (parse_url($next, PHP_URL_PATH) ?: '/'));
}

function auth_normalize_next_url(string $next): string
{
    $fragment = (string) (parse_url($next, PHP_URL_FRAGMENT) ?: '');

    if (preg_match('/^status-(?:comments-thread-)?([1-9][0-9]*)$/', $fragment, $match) === 1) {
        return '/status/' . $match[1];
    }

    return $next;
}

function auth_safe_next_url(string $next): string
{
    $next = auth_normalize_next_url(trim($next));

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

function auth_request_next_url(): string
{
    $next = auth_safe_next_url((string) input('next', ''));

    if ($next !== '') {
        return $next;
    }

    return auth_referer_next_url();
}

function auth_redirect_after_login(?array $user, string $next = ''): string
{
    $fallback = auth_landing_url($user);
    $next = auth_safe_next_url($next);

    if ($next === '') {
        return $fallback;
    }

    if (str_starts_with(route_path($next), '/admin') && (string) ($user['role'] ?? '') !== 'admin') {
        return $fallback;
    }

    return $next;
}

function auth_login_request(): array
{
    if (!captcha_check('login')) {
        captcha_refresh('login');
        api_error(t('auth.invalid_captcha'), 422, 'captcha_invalid', [
            'captcha_html' => captcha_field('login'),
        ]);
    }

    $password = (string) input('password', '');

    if (auth_password_too_long($password)) {
        captcha_refresh('login');
        api_error(t('auth.invalid_login'), 422, 'invalid_login', [
            'captcha_html' => captcha_field('login'),
        ]);
    }

    if (!auth_attempt([
        'username' => username_normalize((string) input('username', '')),
        'password' => $password,
        'remember' => input('remember', ''),
    ])) {
        captcha_refresh('login');
        api_error(t('auth.invalid_login'), 422, 'invalid_login', [
            'captcha_html' => captcha_field('login'),
        ]);
    }

    captcha_refresh('login');

    $user = auth();
    $next = auth_request_next_url();

    return [
        'user' => user_public_payload($user),
        'redirect' => auth_redirect_after_login($user, $next),
    ];
}

function auth_password_max_length(): int
{
    return 1024;
}

function auth_password_too_long(string $password): bool
{
    return strlen($password) > auth_password_max_length();
}

function registration_request(): array
{
    $next = auth_request_next_url();

    if (!registration_enabled()) {
        api_error(t('auth.registration_disabled'), 403, 'registration_disabled');
    }

    if (!captcha_check('register')) {
        captcha_refresh('register');
        api_error(t('auth.invalid_captcha'), 422, 'captcha_invalid', [
            'captcha_html' => captcha_field('register'),
        ]);
    }

    $username = username_normalize((string) input('username', ''));
    $password = (string) input('password', '');
    $passwordConfirm = (string) input('password_confirm', '');
    $errors = [];

    if (!username_valid($username)) {
        $errors[] = t('account.messages.username_invalid');
    } elseif (user_username_taken($username)) {
        $errors[] = t('account.messages.username_taken');
    }

    if (strlen($password) < 8) {
        $errors[] = t('account.messages.password_short');
    } elseif (auth_password_too_long($password)) {
        $errors[] = t('account.messages.password_too_long');
    } elseif ($password !== $passwordConfirm) {
        $errors[] = t('account.messages.password_mismatch');
    }

    if ((string) input('platform_terms', '') !== '1') {
        $errors[] = t('auth.platform_terms_required');
    }

    if ($errors !== []) {
        captcha_refresh('register');
        api_error(implode(' ', $errors), 422, 'validation_error', [
            'errors' => $errors,
            'captcha_html' => captcha_field('register'),
        ]);
    }

    $status = registration_auto_approve() ? 'active' : 'waiting';
    $userId = (int) insert('users', [
        'username' => $username,
        'password' => auth_password($password),
        'role' => 'user',
        'status' => $status,
        'locale' => locale(),
        'theme' => 'system',
        'bio' => '',
        'recovery_hash' => user_recovery_hash_generate(),
    ]);

    captcha_refresh('register');

    $data = [
        'user_id' => $userId,
        'status' => $status,
        'approved' => $status === 'active',
        'redirect' => '/login' . ($next !== '' ? '?next=' . rawurlencode($next) : ''),
    ];

    if ($status === 'active') {
        auth_login($userId);
        $data['user'] = user_public_payload(auth());
        $data['redirect'] = auth_redirect_after_login(auth(), $next);
    }

    return $data;
}

function registration_enabled(): bool
{
    return (bool) config('auth.registration.enabled', false);
}

function registration_auto_approve(): bool
{
    return (bool) config('auth.registration.auto_approve', false);
}

function username_normalize(string $username): string
{
    return strtolower(trim($username));
}

function username_valid(string $username): bool
{
    return preg_match('/^[a-z][a-z0-9_]{2,31}$/', username_normalize($username)) === 1;
}

function username_hint(): string
{
    return t('account.username_hint');
}

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

function user_recovery_hash_generate(): string
{
    return bin2hex(random_bytes(32));
}

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

function user_recovery_hash_rotate(int $id): string
{
    if ($id < 1) {
        return '';
    }

    $hash = user_recovery_hash_generate();
    update('users', ['recovery_hash' => $hash], ['id' => $id]);

    return $hash;
}

function user_recovery_hash_normalize(string $hash): string
{
    $hash = strtolower(trim($hash));

    return preg_match('/^[a-f0-9]{64,128}$/', $hash) === 1 ? $hash : '';
}

function user_find_by_recovery_hash(string $hash): ?array
{
    $hash = user_recovery_hash_normalize($hash);

    if ($hash === '') {
        return null;
    }

    return one(
        'SELECT *
            FROM users
            WHERE recovery_hash = ? AND status = ? AND role <> ?
            LIMIT 1',
        [$hash, 'active', 'bot']
    );
}

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

function moderation_action_rule(array $user, string $action): array
{
    $rules = moderation_action_rules();
    $level = moderation_user_reputation($user);

    return $rules[$level][$action] ?? $rules['normal'][$action] ?? [3600, 60];
}

function moderation_bucket_start(int $window): string
{
    $window = max(60, $window);
    $bucket = intdiv(time(), $window) * $window;

    return date_db($bucket);
}

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

function user_muted_until(array $user): string
{
    $mutedUntil = trim((string) ($user['muted_until'] ?? ''));

    if ($mutedUntil === '') {
        return '';
    }

    $timestamp = strtotime($mutedUntil);

    return $timestamp !== false && $timestamp > time() ? $mutedUntil : '';
}

function user_is_muted(array $user): bool
{
    return user_muted_until($user) !== '';
}

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

function moderation_body_fingerprint(string $body): string
{
    $body = strtolower(trim((string) preg_replace('/\s+/', ' ', $body)));

    return $body !== '' ? hash('sha256', $body) : '';
}

function session_action_retry_after(string $key, int $intervalMs): int
{
    $key = preg_replace('/[^a-z0-9_.-]+/i', '', strtolower(trim($key))) ?? '';
    $intervalMs = max(1, min(60_000, $intervalMs));

    if ($key === '') {
        return 0;
    }

    Core::session();

    $now = (int) floor(microtime(true) * 1000);
    $last = (int) ($_SESSION['_action_timestamps'][$key] ?? 0);
    $retryAfter = $last > 0 ? $intervalMs - ($now - $last) : 0;

    if ($retryAfter > 0) {
        return min($intervalMs, $retryAfter);
    }

    $_SESSION['_action_timestamps'][$key] = $now;

    return 0;
}

function status_session_interval_rule(string $action): array
{
    $action = str_replace('-', '_', strtolower(trim($action)));

    return match ($action) {
        'react', 'like', 'comment_like' => ['rating', 500],
        'comment', 'comment_delete' => ['comment', 1000],
        'create', 'update', 'delete' => ['post', 5000],
        'report' => ['report', 60_000],
        default => ['status_mutation', 500],
    };
}

function status_json_require_session_interval(string $action): void
{
    [$key, $intervalMs] = status_session_interval_rule($action);
    $retryAfter = session_action_retry_after('status_' . $key, $intervalMs);

    if ($retryAfter > 0) {
        if (!headers_sent()) {
            header('Retry-After: ' . max(1, (int) ceil($retryAfter / 1000)));
        }

        api_error(
            t('moderation.messages.action_too_fast'),
            429,
            'action_too_fast',
            [
                'retry_after_ms' => $retryAfter,
                'interval_ms' => $intervalMs,
            ]
        );
    }
}

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

function profile_link_types(): array
{
    return [
        'website' => t('profile_links.website'),
        'x' => 'X',
        'instagram' => 'Instagram',
        'facebook' => 'Facebook',
    ];
}

function profile_link_social_domains(): array
{
    return [
        'x' => ['x.com', 'twitter.com'],
        'instagram' => ['instagram.com'],
        'facebook' => ['facebook.com', 'fb.com'],
    ];
}

function profile_links_schema_ensure(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    run(
        "CREATE TABLE IF NOT EXISTS user_profile_links (
            user_id INT UNSIGNED NOT NULL,
            link_type VARCHAR(32) NOT NULL,
            link_url VARCHAR(2048) NOT NULL,
            position_index INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, link_type),
            KEY user_profile_links_type_index (link_type, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    run("DELETE FROM user_profile_links WHERE link_type NOT IN ('website', 'x', 'instagram', 'facebook')");
    $ready = true;
}

function profile_link_normalize(string $url, ?string $type = null): string
{
    $url = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', '', $url));
    if ($url === '') {
        return '';
    }
    if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $url)) {
        $url = 'https://' . ltrim($url, '/');
    }
    if (strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return '';
    }

    $parts = parse_url($url);
    if (!is_array($parts)) {
        return '';
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = moderation_url_host($url);
    $port = isset($parts['port']) ? (int) $parts['port'] : null;
    if (
        !in_array($scheme, ['http', 'https'], true)
        || $host === ''
        || filter_var($host, FILTER_VALIDATE_IP) !== false
        || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
        || isset($parts['user'])
        || isset($parts['pass'])
        || ($port !== null && !in_array($port, [80, 443], true))
    ) {
        return '';
    }

    $allowedDomains = profile_link_social_domains()[$type ?? ''] ?? [];
    if ($allowedDomains !== []) {
        $allowed = false;
        foreach ($allowedDomains as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            return '';
        }
    }

    return $url;
}

function profile_links_from_input(): array
{
    $links = [];
    $errors = [];
    $types = profile_link_types();

    foreach (array_keys($types) as $position => $type) {
        $raw = trim((string) input('profile_link_' . $type, ''));
        if ($raw === '') {
            continue;
        }
        $url = profile_link_normalize($raw, $type);
        if ($url === '') {
            $errors['profile_link_' . $type][] = t('profile_links.invalid', ['type' => $types[$type]]);
            continue;
        }
        $blockedBy = moderation_host_blocked_by(moderation_url_host($url), moderation_blocked_url_rules());
        if ($blockedBy !== '') {
            $errors['profile_link_' . $type][] = t('moderation.messages.blocked_url', ['host' => $blockedBy]);
            continue;
        }
        $links[$type] = ['url' => $url, 'position' => $position];
    }

    if ($errors !== []) {
        api_validation($errors, t('profile_links.validation_failed'));
    }
    return $links;
}

function user_profile_links(int $userId): array
{
    if ($userId < 1) {
        return [];
    }
    try {
        $rows = all('SELECT link_type, link_url FROM user_profile_links WHERE user_id = ? ORDER BY position_index ASC, link_type ASC', [$userId]);
    } catch (Throwable) {
        return [];
    }

    $links = [];
    $types = profile_link_types();
    foreach ($rows as $row) {
        $type = (string) ($row['link_type'] ?? '');
        if (array_key_exists($type, $types)) {
            $links[$type] = (string) ($row['link_url'] ?? '');
        }
    }
    return $links;
}

function user_profile_links_for_users(array $userIds): array
{
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0)));
    if ($userIds === []) {
        return [];
    }
    try {
        $rows = db_select('SELECT user_id, link_type, link_url FROM user_profile_links')
            ->whereIn('user_id', $userIds)
            ->order('position_index ASC, link_type ASC')
            ->all();
    } catch (Throwable) {
        return [];
    }

    $result = [];
    $types = profile_link_types();
    foreach ($rows as $row) {
        $userId = (int) ($row['user_id'] ?? 0);
        $type = (string) ($row['link_type'] ?? '');
        if ($userId > 0 && array_key_exists($type, $types)) {
            $result[$userId][$type] = (string) ($row['link_url'] ?? '');
        }
    }
    return $result;
}

function user_profile_links_sync(int $userId, array $links): void
{
    if ($userId < 1) {
        return;
    }
    profile_links_schema_ensure();
    delete('user_profile_links', ['user_id' => $userId]);
    foreach ($links as $type => $link) {
        if (!array_key_exists((string) $type, profile_link_types())) {
            continue;
        }
        insert('user_profile_links', [
            'user_id' => $userId,
            'link_type' => (string) $type,
            'link_url' => (string) ($link['url'] ?? ''),
            'position_index' => (int) ($link['position'] ?? 0),
            'created_at' => date_db(),
        ]);
    }
}

function user_profile_links_fields(array $links = []): string
{
    ob_start();
    ?>
    <div class="grid sm:grid-2 profile-links-fields">
        <?php foreach (profile_link_types() as $type => $label): ?>
            <label class="field">
                <span class="label"><?= e($label) ?></span>
                <input class="input" type="text" inputmode="url" name="profile_link_<?= e($type) ?>" maxlength="2048" value="<?= e((string) ($links[$type] ?? '')) ?>" placeholder="https://">
            </label>
        <?php endforeach; ?>
    </div>
    <?php
    return trim((string) ob_get_clean());
}

function user_profile_links_html(array $links): string
{
    if ($links === []) {
        return '';
    }
    ob_start();
    ?>
    <nav class="profile-links" aria-label="<?= et('profile_links.title') ?>">
        <?php foreach (profile_link_types() as $type => $label): ?>
            <?php if (!empty($links[$type])): ?>
                <a class="profile-link" href="<?= e((string) $links[$type]) ?>" target="_blank" rel="nofollow noopener noreferrer"><?= icon('link') ?> <span><?= e($label) ?></span></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <?php
    return trim((string) ob_get_clean());
}

function user_profile_update_request(array $user): array
{
    $id = (int) ($user['id'] ?? 0);
    $bio = plain_text_limit((string) post('bio', ''), 500);
    $locale = language_code((string) post('locale', ''));
    $theme = theme_normalize((string) post('theme', 'system'));
    $errors = [];
    $profileLinks = profile_links_from_input();

    if ($id < 1) {
        api_error(t('auth.login_required'), 401, 'unauthorized', ['redirect' => '/login']);
    }

    if ($locale === '' || !array_key_exists($locale, language_packages())) {
        $errors[] = t('settings.messages.invalid_language');
    }

    if ($errors !== []) {
        api_error(implode(' ', $errors), 422, 'validation_error', ['errors' => $errors]);
    }

    $data = [
        'bio' => $bio,
        'locale' => $locale,
        'theme' => $theme,
    ];

    update('users', $data, ['id' => $id]);
    user_profile_links_sync($id, $profileLinks);

    locale($locale);

    return [
        'user' => user_public_payload(auth() ?: $user),
        'message' => t('account.messages.profile_saved'),
        'redirect' => author_url($id),
    ];
}

function user_avatar_update_request(array $user): array
{
    $id = (int) ($user['id'] ?? 0);

    if ($id < 1) {
        api_error(t('auth.login_required'), 401, 'unauthorized', ['redirect' => '/login']);
    }

    $action = strtolower(trim((string) input('avatar_action', 'upload')));
    $oldConfig = $user['avatar_config'] ?? null;

    if ($action === 'remove') {
        update('users', ['avatar_config' => null], ['id' => $id]);
        Avatar::delete($oldConfig);

        $updated = $user;
        $updated['avatar_config'] = null;

        return [
            'user' => user_public_payload($updated),
            'avatar_url' => '',
            'message' => t('account.messages.avatar_removed'),
            'redirect' => author_url($id),
        ];
    }

    $file = $_FILES['avatar'] ?? null;

    if (!is_array($file)) {
        api_error(t('account.messages.avatar_required'), 422, 'avatar_required');
    }

    try {
        $config = null;
        $config = Avatar::upload($file, (string) ($user['username'] ?? ''));
        $json = Avatar::configJson($config);

        if ($json === '') {
            throw new RuntimeException('Avatar config could not be stored.');
        }
    } catch (Throwable) {
        Avatar::delete($config ?? null);
        api_error(t('account.messages.avatar_invalid'), 422, 'avatar_invalid');
    }

    update('users', ['avatar_config' => $json], ['id' => $id]);
    Avatar::delete($oldConfig ?? null, $config ?? null);

    $updated = auth() ?: $user;
    $updated['avatar_config'] = $json !== '' ? $json : null;

    return [
        'user' => user_public_payload($updated),
        'avatar_url' => user_avatar_url($updated),
        'message' => t('account.messages.avatar_saved'),
        'redirect' => author_url($id),
    ];
}

function author_url(int $id): string
{
    return $id > 0 ? '/author/' . $id : '/';
}

function author_api_url(int $id, string $action = 'follow', array $params = []): string
{
    $query = ['author_id' => $id] + $params;

    return '/api/author/' . rawurlencode($action) . '?' . http_build_query($query);
}

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
                theme,
                avatar_config,
                bio,
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

function tag_url(string $tag): string
{
    $tag = status_tag_normalize($tag);

    return $tag !== '' ? '/tag/' . rawurlencode($tag) : '/';
}

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

function author_follow_button_html(int $authorId, bool $isFollowing): string
{
    ob_start();
    ?>
        <form method="post" action="<?= e(author_api_url($authorId, 'follow', ['view' => 'html'])) ?>" data-follow-form data-author-id="<?= e($authorId) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $isFollowing ? 'unfollow' : 'follow' ?>">
            <button class="btn <?= $isFollowing ? 'btn-secondary' : 'btn-primary' ?> btn-sm" type="submit">
                <?= icon($isFollowing ? 'check' : 'plus') ?> <span><?= et($isFollowing ? 'public.unfollow' : 'public.follow') ?></span>
            </button>
        </form>
        <?php

    return trim((string) ob_get_clean());
}

function author_following_profiles(int $authorId, int $limit = 12, int $offset = 0): array
{
    if ($authorId < 1) {
        return [];
    }

    $limit = max(1, min(100, $limit));
    $offset = max(0, $offset);
    return all(
        'SELECT u.id,
                u.username,
                u.username AS name,
                u.avatar_config,
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
            LIMIT ' . $limit . ' OFFSET ' . $offset,
        [$authorId, 'active']
    );
}

function author_following_profiles_count(int $authorId): int
{
    if ($authorId < 1) {
        return 0;
    }

    return (int) val(
        'SELECT COUNT(*)
            FROM user_followers uf
            INNER JOIN users u ON u.id = uf.user_id
            WHERE uf.follower_id = ?
                AND u.status = ?',
        [$authorId, 'active']
    );
}

function author_following_profile_html(array $profile): string
{
    $profileId = (int) ($profile['id'] ?? 0);
    $profileName = user_display_name($profile);

    ob_start();
    ?>
        <a class="profile-following-link" href="<?= e(author_url($profileId)) ?>">
            <span class="avatar avatar-sm">
                <?= user_avatar_html($profile, $profileName) ?>
            </span>
            <span class="profile-following-main">
                <strong><?= e($profileName) ?></strong>
                <small><?= et('public.active_user_posts', ['count' => (int) ($profile['posts_count'] ?? 0)]) ?></small>
            </span>
        </a>
        <?php

    return trim((string) ob_get_clean());
}

function author_following_profile_payload(array $profile): array
{
    $id = (int) ($profile['id'] ?? 0);

    return [
        'id' => $id,
        'username' => (string) ($profile['username'] ?? ''),
        'name' => user_display_name($profile),
        'url' => author_url($id),
        'avatar_url' => user_avatar_url($profile),
        'posts_count' => (int) ($profile['posts_count'] ?? 0),
        'followed_at' => (string) ($profile['followed_at'] ?? ''),
    ];
}

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

    return ($now->getTimestamp() - $seen->getTimestamp()) <= 300;
}

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

function status_anchor(int $id): string
{
    return $id > 0 ? 'status-' . $id : '';
}

function status_url(int $id): string
{
    return $id > 0 ? '/status/' . $id : '/';
}

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

function status_internal_url_path(string $url): string
{
    $url = trim($url);

    if ($url === '' || str_starts_with($url, '//')) {
        return '';
    }

    if (str_starts_with($url, '/')) {
        return route_path((string) (parse_url($url, PHP_URL_PATH) ?: $url));
    }

    if (!preg_match('~^https?://~i', $url) || !status_internal_url($url)) {
        return '';
    }

    return route_path((string) (parse_url($url, PHP_URL_PATH) ?: '/'));
}

function status_external_url_pattern(): string
{
    return '~(?<![@\p{L}\p{N}_])(?:https?://|www\.)[^\s<>"\']+~iu';
}

function status_strip_external_urls(string $text): string
{
    if ($text === '') {
        return '';
    }

    $text = (string) preg_replace_callback(status_external_url_pattern(), static function (array $match): string {
        [$url, $tail] = status_url_split_tail((string) ($match[0] ?? ''));

        if (status_internal_url($url)) {
            return $url . $tail;
        }

        return $tail;
    }, $text);

    $text = (string) preg_replace('/[ \t]{2,}/', ' ', $text);
    $text = (string) preg_replace('/\s+([.,;:!?])/u', '$1', $text);
    $text = (string) preg_replace('/[ \t]+\R/u', "\n", $text);
    $text = (string) preg_replace('/\R[ \t]+/u', "\n", $text);
    $text = (string) preg_replace('/\R{3,}/u', "\n\n", $text);

    return trim($text);
}

function moderation_blocked_url_rules(?string $value = null): array
{
    $value = $value ?? (string) config('moderation.blocked_urls', '');
    $items = preg_split('/[,;\r\n]+/', $value) ?: [];
    $rules = [];

    foreach ($items as $item) {
        $rule = moderation_blocked_url_rule((string) $item);

        if ($rule !== '') {
            $rules[$rule] = true;
        }
    }

    return array_keys($rules);
}

function moderation_blocked_url_rule(string $value): string
{
    $value = strtolower(trim((string) preg_replace('/[\x00-\x1F\x7F]+/', '', $value)));

    if ($value === '') {
        return '';
    }

    if (str_starts_with($value, 'domain:')) {
        $domain = trim(substr($value, 7), " \t\n\r\0\x0B./");

        if (preg_match('/^[a-z0-9-]{2,63}(?:\.[a-z0-9-]{2,63})*$/', $domain) !== 1) {
            return '';
        }

        return 'domain:' . $domain;
    }

    return moderation_url_host($value);
}

function moderation_url_host(string $value): string
{
    $value = strtolower(trim((string) preg_replace('/[\x00-\x1F\x7F]+/', '', $value)));
    $value = trim($value, " \t\n\r\0\x0B/");

    if ($value === '') {
        return '';
    }

    $value = (string) preg_replace('/^\*\./', '', $value);
    $candidate = preg_match('~^[a-z][a-z0-9+.-]*://~i', $value) === 1
        ? $value
        : 'https://' . ltrim($value, '/');
    $parts = parse_url($candidate);

    if (!is_array($parts)) {
        return '';
    }

    $host = strtolower(rtrim(trim((string) ($parts['host'] ?? ''), '[]'), '.'));

    if (str_starts_with($host, 'www.')) {
        $host = substr($host, 4);
    }

    if ($host === '' || !str_contains($host, '.') || preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
        return '';
    }

    return $host;
}

function moderation_blocked_url_match(string $text): string
{
    $blockedRules = moderation_blocked_url_rules();

    if ($text === '' || $blockedRules === []) {
        return '';
    }

    if (preg_match_all(StatusLinks::pattern(), $text, $matches)) {
        foreach ((array) ($matches[0] ?? []) as $match) {
            [$url] = StatusLinks::splitTail((string) $match);
            $host = moderation_url_host($url);

            if ($host !== '') {
                $blockedBy = moderation_host_blocked_by($host, $blockedRules);

                if ($blockedBy !== '') {
                    return $blockedBy;
                }
            }
        }
    }

    foreach ($blockedRules as $blockedRule) {
        if (moderation_text_contains_blocked_host($text, (string) $blockedRule)) {
            return (string) $blockedRule;
        }
    }

    return '';
}

function moderation_require_allowed_urls(string $text): void
{
    $blockedHost = moderation_blocked_url_match($text);

    if ($blockedHost !== '') {
        api_error(t('moderation.messages.blocked_url', ['host' => $blockedHost]), 422, 'blocked_url', ['host' => $blockedHost]);
    }
}

function moderation_text_contains_blocked_host(string $text, string $blockedRule): bool
{
    $blockedRule = moderation_blocked_url_rule($blockedRule);

    if ($text === '' || $blockedRule === '') {
        return false;
    }

    if (str_starts_with($blockedRule, 'domain:')) {
        $domain = substr($blockedRule, 7);
        $pattern = '/(?<![@A-Za-z0-9_.-])(?:[A-Za-z0-9-]+\.)+' . preg_quote($domain, '/')
            . '(?=$|[\/?#:,\s!?\)\]\}]|\.(?:\s|$))/i';

        return preg_match($pattern, $text) === 1;
    }

    $pattern = '/(?<![@A-Za-z0-9_.-])(?:[A-Za-z0-9-]+\.)*'
        . preg_quote($blockedRule, '/')
        . '(?=$|[\/?#:,\s!?\)\]\}]|\.(?:\s|$))/i';

    return preg_match($pattern, $text) === 1;
}

function moderation_host_blocked_by(string $host, array $blockedRules): string
{
    $host = moderation_url_host($host);

    if ($host === '') {
        return '';
    }

    foreach ($blockedRules as $blockedRule) {
        $blockedRule = moderation_blocked_url_rule((string) $blockedRule);

        if ($blockedRule === '') {
            continue;
        }

        if (str_starts_with($blockedRule, 'domain:')) {
            $domain = substr($blockedRule, 7);

            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return $blockedRule;
            }

            continue;
        }

        if ($host === $blockedRule || str_ends_with($host, '.' . $blockedRule)) {
            return $blockedRule;
        }
    }

    return '';
}

function &author_mention_user_cache(): array
{
    static $users = [];

    return $users;
}

function author_mention_users_by_ids(array $userIds): array
{
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0)));

    if ($userIds === []) {
        return [];
    }

    $users =& author_mention_user_cache();
    $missing = array_values(array_filter($userIds, static fn (int $id): bool => !array_key_exists($id, $users)));

    if ($missing !== []) {
        foreach ($missing as $id) {
            $users[$id] = null;
        }

        foreach (db_select('SELECT id, username FROM users')
            ->where('status = ?', 'active')
            ->whereIn('id', $missing)
            ->all() as $user) {
            $id = (int) ($user['id'] ?? 0);
            $name = (string) ($user['username'] ?? '');

            if ($id > 0) {
                $users[$id] = [
                    'id' => $id,
                    'name' => $name,
                    'handle' => username_normalize($name),
                ];
            }
        }
    }

    $result = [];

    foreach ($userIds as $id) {
        if (isset($users[$id])) {
            $result[$id] = $users[$id];
        }
    }

    return $result;
}

function status_author_url_mention(string $url): string
{
    $path = status_internal_url_path($url);

    if ($path === '' || preg_match('~^/author/([0-9]+)/?$~', $path, $match) !== 1) {
        return '';
    }

    $authorId = (int) ($match[1] ?? 0);

    return $authorId > 0 && isset(author_mention_users_by_ids([$authorId])[$authorId]) ? '@' . $authorId : '';
}

function normalize_author_urls_for_storage(string $text): string
{
    $pattern = '~(?<![\p{L}\p{N}_])((?:https?://|www\.)[^\s<>"\']+|/author/[0-9]+[^\s<>"\']*)~iu';

    return (string) preg_replace_callback($pattern, static function (array $match): string {
        $raw = (string) ($match[1] ?? '');
        [$url, $tail] = status_url_split_tail($raw);
        $mention = status_author_url_mention($url);

        return $mention !== '' ? $mention . $tail : $raw;
    }, $text);
}

function normalize_mentions_for_storage(string $text): string
{
    $text = normalize_author_urls_for_storage($text);
    $pattern = '/(?<![A-Za-z0-9_])@([0-9]+|[a-z][a-z0-9_]{2,31})/i';

    if (!preg_match_all($pattern, $text, $matches)) {
        return $text;
    }

    $ids = [];
    $handles = [];

    foreach ((array) ($matches[1] ?? []) as $token) {
        $token = strtolower((string) $token);

        if (ctype_digit($token)) {
            $id = (int) $token;

            if ($id > 0) {
                $ids[$id] = $id;
            }
        } else {
            $handle = username_normalize($token);

            if ($handle !== '') {
                $handles[$handle] = $handle;
            }
        }
    }

    $users = author_mention_users_by_ids(array_values($ids));
    $map = [];

    if ($handles !== []) {
        foreach (db_select('SELECT id, username FROM users')
            ->where('status = ?', 'active')
            ->whereIn('username', array_values($handles))
            ->all() as $user) {
            $id = (int) ($user['id'] ?? 0);
            $handle = username_normalize((string) ($user['username'] ?? ''));

            if ($id > 0 && $handle !== '') {
                $map[$handle] = $id;
            }
        }
    }

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

function mentions_for_editing(string $text): string
{
    if ($text === '' || !preg_match_all('/(?<![A-Za-z0-9_])@([1-9][0-9]*)/', $text, $matches)) {
        return $text;
    }

    $ids = array_values(array_unique(array_filter(array_map(
        'intval',
        (array) ($matches[1] ?? [])
    ), static fn (int $id): bool => $id > 0)));
    $users = author_mention_users_by_ids($ids);

    return (string) preg_replace_callback(
        '/(?<![A-Za-z0-9_])@([1-9][0-9]*)/',
        static function (array $match) use ($users): string {
            $id = (int) ($match[1] ?? 0);
            $handle = (string) ($users[$id]['handle'] ?? '');

            return $handle !== '' ? '@' . $handle : (string) ($match[0] ?? '');
        },
        $text
    );
}

function render_mentions(string $text): string
{
    return render_mentions_segment(status_strip_external_urls($text));
}

function render_status_text(string $text, array $hiddenLinkHashes = []): string
{
    if ($text === '') {
        return '';
    }

    $hiddenLinkHashes = array_fill_keys(array_filter(array_map('strval', $hiddenLinkHashes)), true);

    if (!preg_match_all(StatusLinks::pattern(), $text, $matches, PREG_OFFSET_CAPTURE)) {
        return render_mentions_segment($text);
    }

    $offset = 0;
    $html = '';

    foreach ((array) ($matches[0] ?? []) as $match) {
        $raw = (string) ($match[0] ?? '');
        $position = (int) ($match[1] ?? 0);
        [$url, $tail] = StatusLinks::splitTail($raw);

        $html .= render_mentions_segment(substr($text, $offset, $position - $offset));

        if (status_internal_url($url)) {
            $href = status_internal_url_path($url);
            $html .= '<a class="status-inline-link" href="' . e($href !== '' ? $href : $url) . '">' . e($url) . '</a>';
        } else {
            $link = StatusLinks::fromRaw($url, $position);

            if ($link !== null) {
                $hash = (string) ($link['url_hash'] ?? '');

                if ($hash !== '' && isset($hiddenLinkHashes[$hash])) {
                    $html .= e($tail);
                    $offset = $position + strlen($raw);
                    continue;
                }

                $html .= '<a class="status-inline-link" href="' . e((string) ($link['normalized_url'] ?? $url)) . '" target="_blank" rel="nofollow noopener noreferrer ugc">' . e($url) . '</a>';
            } else {
                $html .= e($url);
            }
        }

        $html .= e($tail);
        $offset = $position + strlen($raw);
    }

    $html .= render_mentions_segment(substr($text, $offset));

    return $html;
}

function render_status_body(array $item): string
{
    $hiddenLinkHashes = [];
    $contentId = (int) ($item['id'] ?? 0);

    if ($contentId > 0) {
        foreach (status_links_for_content($contentId) as $link) {
            if (status_link_is_internal((array) $link)) {
                continue;
            }

            $hash = (string) ($link['url_hash'] ?? '');

            if ($hash !== '') {
                $hiddenLinkHashes[] = $hash;
            }
        }
    }

    return trim(render_status_text((string) ($item['body'] ?? ''), $hiddenLinkHashes));
}

function render_mentions_segment(string $text): string
{
    $pattern = '/(?<![\\p{L}\\p{N}_])([@#])([\\p{L}\\p{N}][\\p{L}\\p{N}_-]*)/u';
    $offset = 0;
    $html = '';

    if (!preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
        return nl2br(e($text), false);
    }

    $mentionIds = [];

    foreach ($matches[0] as $index => $_match) {
        $symbol = (string) ($matches[1][$index][0] ?? '');
        $value = (string) ($matches[2][$index][0] ?? '');

        if ($symbol === '@' && ctype_digit($value) && (int) $value > 0) {
            $mentionIds[(int) $value] = (int) $value;
        }
    }

    $users = author_mention_users_by_ids(array_values($mentionIds));

    foreach ($matches[0] as $index => $match) {
        $token = (string) $match[0];
        $position = (int) $match[1];
        $symbol = (string) ($matches[1][$index][0] ?? '');
        $handleRaw = (string) ($matches[2][$index][0] ?? '');
        $authorId = 0;

        $html .= e(substr($text, $offset, $position - $offset));

        if ($symbol === '@') {
            if (ctype_digit($handleRaw) && isset($users[(int) $handleRaw])) {
                $authorId = (int) $handleRaw;
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

function status_url_split_tail(string $url): array
{
    $tail = '';

    while ($url !== '' && preg_match('/[\\.,;:!\\?\\)\\]\\}]+$/', $url, $match) === 1) {
        $chunk = (string) ($match[0] ?? '');
        $tail = $chunk . $tail;
        $url = substr($url, 0, -strlen($chunk));
    }

    return [$url, $tail];
}

function public_status_select_sql(): string
{
    return "SELECT c.id,
                c.body,
                c.author_id,
                c.published_at,
                c.created_at,
                c.edit_locked_at,
                u.username AS author_username,
                u.username AS author_name,
                u.avatar_config AS author_avatar_config,
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

function public_status_query(): CoreQuery
{
    return db_select(public_status_select_sql())
        ->where('u.status = ?', 'active');
}

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

function public_status_items(int $limit = 24, int $offset = 0): array
{
    return public_status_page(public_status_id_query(), $limit, $offset);
}

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

function public_status_items_by_tag(
    string $tag,
    int $limit = 24,
    string $cursorAt = '',
    int $cursorId = 0
): array
{
    $tag = status_tag_normalize($tag);

    if ($tag === '') {
        return [];
    }

    $termId = status_term_id_exact($tag);

    if ($termId < 1) {
        return [];
    }

    $query = public_status_id_query()
        ->join('INNER JOIN content_tags ct ON ct.content_id = c.id')
        ->where('ct.term_id = ?', $termId);

    if ($cursorAt !== '' && $cursorId > 0) {
        return public_status_page(
            $query->where(
                '(
                        c.published_at < ?
                        OR (c.published_at = ? AND c.id < ?)
                    )',
                $cursorAt,
                $cursorAt,
                $cursorId
            ),
            $limit
        );
    }

    return public_status_page($query, $limit);
}

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

function status_preload_feed(array $items): void
{
    $ids = array_values(array_unique(array_filter(array_map(
        static fn (array $item): int => (int) ($item['id'] ?? 0),
        $items
    ), static fn (int $id): bool => $id > 0)));

    if ($ids === []) {
        return;
    }

    $mentionIds = [];

    foreach ($items as $item) {
        if (!preg_match_all('/(?<![A-Za-z0-9_])@([1-9][0-9]*)/', (string) ($item['body'] ?? ''), $matches)) {
            continue;
        }

        foreach ((array) ($matches[1] ?? []) as $mentionId) {
            $mentionId = (int) $mentionId;

            if ($mentionId > 0) {
                $mentionIds[$mentionId] = $mentionId;
            }
        }
    }

    author_mention_users_by_ids(array_values($mentionIds));
    status_preload_latest_parent_comments($ids);

    $userId = (int) (auth()['id'] ?? 0);

    if ($userId > 0) {
        status_preload_user_likes($ids, $userId);
        $commentIds = [];

        foreach ($ids as $contentId) {
            $commentId = (int) (status_latest_parent_comment($contentId)['id'] ?? 0);

            if ($commentId > 0) {
                $commentIds[] = $commentId;
            }
        }

        status_preload_comment_user_likes($commentIds, $userId);
    }

    status_preload_links($ids);
}

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

function public_top_authors(int $limit = 5, int $days = 7, bool $compute = true): array
{
    $limit = max(1, min(20, $limit));
    $days = max(1, min(365, $days));
    $cacheKey = 'public_top_authors_human_' . $limit . '_' . $days;

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
                u.avatar_config,
                u.bio,
                COUNT(*) AS posts_count,
                MAX(c.published_at) AS latest_at
            FROM content c' . $feedIndex . '
            INNER JOIN users u ON u.id = c.author_id'
    )
        ->where('c.published_at >= ?', date_db('-' . $days . ' days'))
        ->where('u.status = ?', 'active')
        ->where('u.role <> ?', 'bot')
        ->group('u.id, u.username, u.avatar_config, u.bio')
        ->order('posts_count DESC, latest_at DESC, u.username ASC')
        ->limit($limit)
        ->all();

    public_stats_cache_set($cacheKey, $authors);

    return $authors;
}

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

function public_stats_cache_fresh(string $key, int $ttl = 300): bool
{
    $file = public_stats_cache_file($key);

    return is_file($file) && filemtime($file) >= time() - max(1, $ttl);
}

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

function public_stats_cache_file(string $key): string
{
    $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $key) ?: 'public_stats';

    return base_path('storage/cache/' . $safe . '.json');
}

function public_sidebar(?string $activeTag = null, bool $compute = false): string
{
    $activeTag = status_tag_normalize((string) $activeTag);
    $tags = public_trending_tags(8, 7, $compute);
    $authors = public_top_authors(5, 7, $compute);
    $needsRefresh = !$compute && (
        !public_stats_cache_fresh('public_trending_tags_8_7', 3600)
        || !public_stats_cache_fresh('public_top_authors_human_5_7', 3600)
    );
    $sidebarUrl = '/api/sidebar' . ($activeTag !== '' ? '?tag=' . rawurlencode($activeTag) : '');

    return part('sidebar', [
        'active_tag' => $activeTag,
        'tags' => $tags,
        'authors' => $authors,
        'needs_refresh' => $needsRefresh,
        'sidebar_url' => $sidebarUrl,
    ]);
}

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

function public_search_empty_result(string $query): array
{
    return [
        'query' => $query,
        'tags' => [],
        'users' => [],
        'content' => [],
    ];
}

function public_search_normalize_query(string $query): string
{
    return trim((string) preg_replace('/\s+/u', ' ', $query));
}

function public_search_query_too_short(string $query): bool
{
    return (function_exists('mb_strlen') ? mb_strlen($query, 'UTF-8') : strlen($query)) < 2;
}

function public_search_guest_limits(): array
{
    return [
        'max' => 10,
        'window' => 300,
        'unlock' => 600,
    ];
}

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

function public_search_captcha_verify(): bool
{
    if (!captcha_check('search')) {
        captcha_refresh('search');
        return false;
    }

    public_search_guest_unlock();

    return true;
}

function public_search_fulltext_query(string $query): string
{
    $tokens = preg_split('/[^\p{L}\p{N}_]+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $terms = [];

    foreach ($tokens as $token) {
        $token = trim($token);
        $length = function_exists('mb_strlen') ? mb_strlen($token, 'UTF-8') : strlen($token);

        if ($length < 3) {
            continue;
        }

        $terms[] = '+' . $token . '*';

        if (count($terms) >= 6) {
            break;
        }
    }

    return implode(' ', $terms);
}

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

    if ((bool) config('install.complete', false)) {
        return $cache[$key] = true;
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

function public_search_text_contains(string $text, string $query): bool
{
    if ($text === '' || $query === '') {
        return false;
    }

    return (function_exists('mb_stripos') ? mb_stripos($text, $query, 0, 'UTF-8') : stripos($text, $query)) !== false;
}

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
        'avatar_url' => user_avatar_url($item),
    ];
}

function public_search_recent_content_scan(string $query, int $limit): array
{
    $feedIndex = (string) config('database.driver', 'mysql') === 'mysql'
        ? ' FORCE INDEX (content_feed_index)'
        : '';
    $scanLimit = max(300, min(5000, $limit * 250));
    $limit = max(1, min(50, $limit));

    return all(
        'SELECT c.id,
                c.body,
                c.author_id,
                c.created_at,
                u.username AS author_name,
                u.username AS author_username,
                u.avatar_config AS author_avatar_config
            FROM (
                SELECT id, published_at
                FROM content' . $feedIndex . '
                ORDER BY published_at DESC, id DESC
                LIMIT ' . $scanLimit . '
            ) recent
            INNER JOIN content c ON c.id = recent.id
            INNER JOIN users u ON u.id = c.author_id
            WHERE u.status = ?
                AND c.body LIKE ?
            ORDER BY recent.published_at DESC, recent.id DESC
            LIMIT ' . $limit,
        ['active', '%' . $query . '%']
    );
}

function public_search_link_content_rows(string $query, int $limit, array $excludeIds = []): array
{
    $limit = max(1, min(50, $limit));
    $excludeIds = array_values(array_unique(array_filter(array_map('intval', $excludeIds), static fn (int $id): bool => $id > 0)));
    $fulltext = public_search_fulltext_query($query);
    $rows = [];

    try {
        $linkQuery = db_select(
            'SELECT c.id,
                    c.body,
                    c.author_id,
                    c.created_at,
                    u.username AS author_name,
                    u.username AS author_username,
                    u.avatar_config AS author_avatar_config,
                    CONCAT_WS(" ", l.title, l.description, l.normalized_url) AS link_excerpt
                FROM content_links cl
                INNER JOIN links l ON l.id = cl.link_id
                INNER JOIN content c ON c.id = cl.content_id
                INNER JOIN users u ON u.id = c.author_id'
        )->where('u.status = ?', 'active');

        if ($fulltext !== '' && public_search_fulltext_ready('links', 'links_search_fulltext')) {
            $linkQuery->where('MATCH(l.normalized_url, l.title, l.description) AGAINST (? IN BOOLEAN MODE)', $fulltext);
        } else {
            $like = '%' . $query . '%';
            $linkQuery->where(
                '(
                        l.normalized_url LIKE ?
                        OR l.title LIKE ?
                        OR l.description LIKE ?
                    )',
                $like,
                $like,
                $like
            );
        }

        if ($excludeIds !== []) {
            $linkQuery->where(
                'c.id NOT IN (' . implode(', ', array_fill(0, count($excludeIds), '?')) . ')',
                $excludeIds
            );
        }

        $seen = array_fill_keys($excludeIds, true);

        foreach ($linkQuery
            ->order('c.published_at DESC, c.id DESC')
            ->limit(max($limit, min(100, $limit * 4)))
            ->all() as $item) {
            $id = (int) ($item['id'] ?? 0);

            if ($id < 1 || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $rows[] = $item;

            if (count($rows) >= $limit) {
                break;
            }
        }
    } catch (Throwable) {
        return [];
    }

    return $rows;
}

function public_search_content_rows(string $query, int $limit): array
{
    $rows = [];
    $seen = [];

    foreach (public_search_recent_content_scan($query, $limit) as $item) {
        $id = (int) ($item['id'] ?? 0);

        if ($id < 1 || isset($seen[$id])) {
            continue;
        }

        $seen[$id] = true;
        $rows[] = $item;

        if (count($rows) >= $limit) {
            return $rows;
        }
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
                        u.username AS author_username,
                        u.avatar_config AS author_avatar_config
                    FROM content c
                    INNER JOIN users u ON u.id = c.author_id'
            )
                ->where('MATCH(c.body) AGAINST (? IN BOOLEAN MODE)', $fulltext)
                ->where('u.status = ?', 'active');

            foreach ($fulltextQuery
                ->order('c.published_at DESC, c.id DESC')
                ->limit(max($limit, min(100, $limit * 4)))
                ->all() as $item) {
                $id = (int) ($item['id'] ?? 0);

                if ($id < 1 || isset($seen[$id]) || !public_search_text_contains((string) ($item['body'] ?? ''), $query)) {
                    continue;
                }

                $seen[$id] = true;
                $rows[] = $item;

                if (count($rows) >= $limit) {
                    break;
                }
            }
        } catch (Throwable) {
            // Link search below can still provide useful results.
        }
    }

    if (count($rows) >= $limit) {
        return $rows;
    }

    foreach (public_search_link_content_rows($query, $limit - count($rows), array_keys($seen)) as $item) {
        $rows[] = $item;

        if (count($rows) >= $limit) {
            break;
        }
    }

    return $rows;
}

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

function public_search_suggestion_users(string $query, int $limit): array
{
    $usernameQuery = username_normalize(ltrim($query, '@'));

    if ($usernameQuery === '') {
        return [];
    }

    $users = [];

    foreach (db_select(
        'SELECT u.id, u.username, u.username AS name, u.avatar_config, u.bio
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
            'avatar_url' => user_avatar_url($user),
        ];
    }

    return $users;
}

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

function status_editor_suggestions(string $query, string $type = 'all', int $limit = 8): array
{
    $query = trim($query);
    $type = in_array($type, ['all', 'tag', 'user'], true) ? $type : 'all';
    $limit = max(1, min(12, $limit));
    $payload = [
        'query' => $query,
        'type' => $type,
        'tags' => [],
        'users' => [],
    ];

    if ($type === 'all' || $type === 'tag') {
        $seen = [];
        $tagQuery = status_tag_normalize($query);

        foreach (public_search_suggestion_tags($query, $limit) as $tag) {
            $name = status_tag_normalize((string) ($tag['title'] ?? $tag['name'] ?? ''));

            if ($name === '' || isset($seen[$name])) {
                continue;
            }

            $seen[$name] = true;
            $payload['tags'][] = [
                'id' => (int) ($tag['id'] ?? 0),
                'type' => 'tag',
                'title' => '#' . $name,
                'value' => $name,
                'url' => tag_url($name),
            ];
        }

        if ($tagQuery !== '' && !isset($seen[$tagQuery])) {
            array_unshift($payload['tags'], [
                'id' => 0,
                'type' => 'tag',
                'title' => '#' . $tagQuery,
                'value' => $tagQuery,
                'url' => tag_url($tagQuery),
            ]);
        }

        $payload['tags'] = array_slice($payload['tags'], 0, $limit);
    }

    if (($type === 'all' || $type === 'user') && $query !== '') {
        foreach (public_search_suggestion_users($query, $limit) as $user) {
            $username = username_normalize((string) ($user['title'] ?? $user['username'] ?? ''));

            if (!username_valid($username)) {
                continue;
            }

            $payload['users'][] = [
                'id' => (int) ($user['id'] ?? 0),
                'type' => 'user',
                'title' => '@' . $username,
                'value' => $username,
                'url' => (string) ($user['url'] ?? ''),
                'avatar_url' => (string) ($user['avatar_url'] ?? ''),
            ];
        }
    }

    return $payload;
}

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
        'SELECT u.id, u.username, u.username AS name, u.avatar_config, u.bio
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
        $userQuery->where('u.username LIKE ?', $like);
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
            'avatar_url' => user_avatar_url($user),
        ];
    }

    foreach (public_search_content_rows($query, $limit) as $item) {
        $id = (int) ($item['id'] ?? 0);
        $authorId = (int) ($item['author_id'] ?? 0);

        if ($id < 1 || $authorId < 1) {
            continue;
        }

        $content[] = public_search_content_result($item, $query, (string) ($item['link_excerpt'] ?? '') ?: null);
        $contentIds[$id] = true;
    }

    return [
        'query' => $query,
        'tags' => $tags,
        'users' => $users,
        'content' => $content,
    ];
}

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

function status_edit_locked(?array $item): bool
{
    return $item !== null && trim((string) ($item['edit_locked_at'] ?? '')) !== '';
}

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

function status_can_edit(?array $item, ?array $user): bool
{
    return $item !== null
        && $user !== null
        && !status_edit_locked($item)
        && (int) ($item['author_id'] ?? 0) === (int) ($user['id'] ?? 0);
}

function status_can_delete(?array $item, ?array $user): bool
{
    if ($item === null || $user === null) {
        return false;
    }

    return (int) ($item['author_id'] ?? 0) === (int) ($user['id'] ?? 0)
        || (string) ($user['role'] ?? '') === 'admin';
}

function status_user_liked(int $contentId, int $userId): bool
{
    if ($contentId < 1 || $userId < 1) {
        return false;
    }

    $cache =& status_user_liked_cache();

    if (!array_key_exists($contentId, $cache[$userId] ?? [])) {
        status_preload_user_likes([$contentId], $userId);
    }

    return (bool) ($cache[$userId][$contentId] ?? false);
}

function &status_user_liked_cache(): array
{
    static $cache = [];

    return $cache;
}

function status_preload_user_likes(array $contentIds, int $userId): void
{
    if ($userId < 1) {
        return;
    }

    $contentIds = array_values(array_unique(array_filter(array_map('intval', $contentIds), static fn (int $id): bool => $id > 0)));
    $cache =& status_user_liked_cache();
    $cache[$userId] ??= [];
    $missing = array_values(array_filter($contentIds, static fn (int $id): bool => !array_key_exists($id, $cache[$userId])));

    if ($missing === []) {
        return;
    }

    foreach ($missing as $contentId) {
        $cache[$userId][$contentId] = false;
    }

    foreach (db_select('SELECT content_id FROM content_likes')
        ->where('user_id = ?', $userId)
        ->whereIn('content_id', $missing)
        ->all() as $row) {
        $contentId = (int) ($row['content_id'] ?? 0);

        if ($contentId > 0) {
            $cache[$userId][$contentId] = true;
        }
    }
}

function status_set_user_liked(int $contentId, int $userId, bool $liked): void
{
    if ($contentId < 1 || $userId < 1) {
        return;
    }

    $cache =& status_user_liked_cache();
    $cache[$userId][$contentId] = $liked;
}

function &status_comments_cache(): array
{
    static $cache = [];

    return $cache;
}

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
                u.username AS author_username,
                u.avatar_config AS author_avatar_config,
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

function status_comment_count(int $contentId): int
{
    if ($contentId < 1) {
        return 0;
    }

    return db_select('SELECT id FROM content_comments')
        ->where('content_id = ?', $contentId)
        ->count();
}

function &status_latest_parent_comment_cache(): array
{
    static $cache = [];

    return $cache;
}

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
        ->where(
            'NOT EXISTS (
                    SELECT 1
                    FROM content_comments newer
                    WHERE newer.content_id = cc.content_id
                        AND newer.parent_id IS NULL
                        AND (
                            newer.created_at > cc.created_at
                            OR (newer.created_at = cc.created_at AND newer.id > cc.id)
                        )
                )'
        )
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

function status_comment_user_liked(int $commentId, int $userId): bool
{
    if ($commentId < 1 || $userId < 1) {
        return false;
    }

    $cache =& status_comment_user_liked_cache();

    if (!array_key_exists($commentId, $cache[$userId] ?? [])) {
        status_preload_comment_user_likes([$commentId], $userId);
    }

    return (bool) ($cache[$userId][$commentId] ?? false);
}

function &status_comment_user_liked_cache(): array
{
    static $cache = [];

    return $cache;
}

function status_preload_comment_user_likes(array $commentIds, int $userId): void
{
    if ($userId < 1) {
        return;
    }

    $commentIds = array_values(array_unique(array_filter(array_map('intval', $commentIds), static fn (int $id): bool => $id > 0)));
    $cache =& status_comment_user_liked_cache();
    $cache[$userId] ??= [];
    $missing = array_values(array_filter($commentIds, static fn (int $id): bool => !array_key_exists($id, $cache[$userId])));

    if ($missing === []) {
        return;
    }

    foreach ($missing as $commentId) {
        $cache[$userId][$commentId] = false;
    }

    foreach (db_select('SELECT comment_id FROM comment_likes')
        ->where('user_id = ?', $userId)
        ->whereIn('comment_id', $missing)
        ->all() as $row) {
        $commentId = (int) ($row['comment_id'] ?? 0);

        if ($commentId > 0) {
            $cache[$userId][$commentId] = true;
        }
    }
}

function status_set_comment_user_liked(int $commentId, int $userId, bool $liked): void
{
    if ($commentId < 1 || $userId < 1) {
        return;
    }

    $cache =& status_comment_user_liked_cache();
    $cache[$userId][$commentId] = $liked;
}

function status_comment_like_count(int $commentId): int
{
    if ($commentId < 1) {
        return 0;
    }

    return db_select('SELECT comment_id FROM comment_likes')
        ->where('comment_id = ?', $commentId)
        ->count();
}

function status_tag_normalize(string $tag): string
{
    $tag = trim($tag);
    $tag = ltrim($tag, "# \t\n\r\0\x0B");
    $tag = slug($tag);

    return strlen($tag) <= status_tag_max_length() ? $tag : '';
}

function status_tag_max_length(): int
{
    return 32;
}

function status_tag_max_count(): int
{
    return 10;
}

function status_tag_tokens_from_text(string $text): array
{
    if (!preg_match_all('/(?<![\\p{L}\\p{N}_])#([\\p{L}\\p{N}][\\p{L}\\p{N}_-]*)/u', $text, $matches)) {
        return [];
    }

    return array_map('strval', (array) ($matches[1] ?? []));
}

function status_tags_from_text(string $text): array
{
    $tags = [];

    foreach (status_tag_tokens_from_text($text) as $item) {
        $tag = status_tag_normalize((string) $item);

        if ($tag !== '') {
            $tags[$tag] = $tag;
        }
    }

    return array_slice(array_values($tags), 0, status_tag_max_count());
}

function status_require_valid_tags(string $text): array
{
    $tags = [];

    foreach (status_tag_tokens_from_text($text) as $item) {
        $normalized = slug((string) $item);

        if (strlen($normalized) > status_tag_max_length()) {
            api_error(t('account.messages.status_tag_too_long', [
                'max' => (string) status_tag_max_length(),
            ]), 422, 'status_tag_too_long');
        }

        if ($normalized !== '') {
            $tags[$normalized] = $normalized;
        }
    }

    if (count($tags) > status_tag_max_count()) {
        api_error(t('account.messages.status_tags_limit', [
            'max' => (string) status_tag_max_count(),
        ]), 422, 'status_tags_limit');
    }

    return array_values($tags);
}

function normalize_tags_for_storage(string $text): string
{
    return (string) preg_replace_callback(
        '/(?<![\\p{L}\\p{N}_])#([\\p{L}\\p{N}][\\p{L}\\p{N}_-]*)/u',
        static function (array $match): string {
            $tag = status_tag_normalize((string) ($match[1] ?? ''));

            return $tag !== '' ? '#' . $tag : (string) ($match[0] ?? '');
        },
        $text
    );
}

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

function status_sync_tags(int $contentId, array $tags): void
{
    if ($contentId < 1) {
        return;
    }

    $previousTermIds = status_term_ids_for_content($contentId);

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

    status_cleanup_unused_term_ids($previousTermIds);
}

function status_term_ids_for_content(int $contentId): array
{
    if ($contentId < 1) {
        return [];
    }

    return array_values(array_unique(array_filter(array_map(
        static fn (array $row): int => (int) ($row['term_id'] ?? 0),
        all('SELECT term_id FROM content_tags WHERE content_id = ?', [$contentId])
    ), static fn (int $id): bool => $id > 0)));
}

function status_cleanup_unused_term_ids(array $termIds): void
{
    $termIds = array_values(array_unique(array_filter(array_map('intval', $termIds), static fn (int $id): bool => $id > 0)));

    if ($termIds === []) {
        return;
    }

    foreach (array_chunk($termIds, 100) as $chunk) {
        $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
        run(
            'DELETE FROM terms
                WHERE id IN (' . $placeholders . ')
                    AND NOT EXISTS (
                        SELECT 1
                        FROM content_tags ct
                        WHERE ct.term_id = terms.id
                    )',
            $chunk
        );
    }
}

function status_links_from_text(string $text): array
{
    return array_values(array_filter(
        StatusLinks::extract($text),
        static fn (array $link): bool => !status_link_is_internal($link)
    ));
}

function status_link_is_internal(array|string $link): bool
{
    if (is_array($link)) {
        foreach (['normalized_url'] as $key) {
            $url = trim((string) ($link[$key] ?? ''));

            if ($url !== '' && status_internal_url($url)) {
                return true;
            }
        }

        return false;
    }

    $url = trim($link);

    return $url !== '' && status_internal_url($url);
}

function status_link_metadata_ttl(): int
{
    return 86400;
}

function status_link_metadata_cache(array $links): array
{
    $hashes = [];

    foreach ($links as $link) {
        $link = (array) $link;
        $hash = (string) ($link['url_hash'] ?? '');

        if ($hash !== '') {
            $hashes[$hash] = $hash;
        }
    }

    if ($hashes === []) {
        return [];
    }

    $cached = [];

    try {
        foreach (db_select('SELECT * FROM links')
            ->whereIn('url_hash', array_values($hashes))
            ->order('updated_at DESC, id DESC')
            ->all() as $row) {
            $hash = (string) ($row['url_hash'] ?? '');

            if ($hash !== '' && !isset($cached[$hash])) {
                $cached[$hash] = $row;
            }
        }
    } catch (Throwable) {
        return [];
    }

    return $cached;
}

function status_link_data(array $link): array
{
    $normalizedUrl = plain_text_limit((string) ($link['normalized_url'] ?? ''), 2048);
    $hash = plain_text_limit((string) ($link['url_hash'] ?? ''), 64);

    if ($normalizedUrl === '' || $hash === '') {
        return [];
    }

    return [
        'normalized_url' => $normalizedUrl,
        'url_hash' => $hash,
        'provider' => plain_text_limit((string) ($link['provider'] ?? 'web'), 40),
        'link_type' => plain_text_limit((string) ($link['link_type'] ?? 'link'), 20),
        'title' => plain_text_limit((string) ($link['title'] ?? ''), 255),
        'description' => plain_text_limit((string) ($link['description'] ?? ''), 500),
        'image_url' => plain_text_limit((string) ($link['image_url'] ?? ''), 2048),
        'video_id' => plain_text_limit((string) ($link['video_id'] ?? ''), 80),
        'embed_url' => plain_text_limit((string) ($link['embed_url'] ?? ''), 2048),
        'updated_at' => (string) ($link['_metadata_updated_at'] ?? date_db()),
    ];
}

function status_link_find_by_hash(string $hash): ?array
{
    $hash = plain_text_limit($hash, 64);

    if ($hash === '') {
        return null;
    }

    return db_select('SELECT * FROM links')
        ->where('url_hash = ?', $hash)
        ->limit(1)
        ->one();
}

function status_link_upsert(array $link): int
{
    $data = status_link_data($link);

    if ($data === []) {
        return 0;
    }

    $existing = status_link_find_by_hash((string) $data['url_hash']);

    if ($existing === null) {
        try {
            $id = insert('links', $data + ['created_at' => date_db()]);

            return max(0, (int) $id);
        } catch (Throwable) {
            $existing = status_link_find_by_hash((string) $data['url_hash']);
        }
    }

    $id = (int) ($existing['id'] ?? 0);

    if ($id > 0) {
        update('links', $data, ['id' => $id]);
    }

    return $id;
}

function status_link_metadata_fresh(?array $link): bool
{
    if ($link === null) {
        return false;
    }

    if (!status_link_metadata_has_content($link)) {
        return false;
    }

    $updatedAt = strtotime((string) ($link['updated_at'] ?? ''));

    return $updatedAt > 0 && $updatedAt >= time() - status_link_metadata_ttl();
}

function status_link_metadata_has_content(array $link): bool
{
    $type = (string) ($link['link_type'] ?? 'link');
    $provider = (string) ($link['provider'] ?? 'web');
    $title = trim((string) ($link['title'] ?? ''));
    $description = trim((string) ($link['description'] ?? ''));

    if ($type === 'video') {
        $fallbackTitle = match ($provider) {
            'youtube' => 'YouTube video',
            'vimeo' => 'Vimeo video',
            'dailymotion' => 'Dailymotion video',
            default => '',
        };

        return $title !== '' && ($fallbackTitle === '' || strcasecmp($title, $fallbackTitle) !== 0);
    }

    $fallback = StatusLinks::fromRaw((string) ($link['normalized_url'] ?? ''));
    $fallbackTitle = trim((string) ($fallback['title'] ?? ''));
    $fallbackDescription = trim((string) ($fallback['description'] ?? ''));
    $hasTitle = $title !== '' && ($fallbackTitle === '' || strcasecmp($title, $fallbackTitle) !== 0);
    $hasDescription = $description !== '' && ($fallbackDescription === '' || strcasecmp($description, $fallbackDescription) !== 0);

    return $hasTitle || $hasDescription || trim((string) ($link['image_url'] ?? '')) !== '';
}

function status_link_apply_cached_metadata(array $link, array $cached, bool $preserveTimestamp): array
{
    foreach (['provider', 'link_type', 'title', 'description', 'image_url', 'video_id', 'embed_url'] as $key) {
        $value = (string) ($cached[$key] ?? '');

        if ($value !== '') {
            $link[$key] = $value;
        }
    }

    $updatedAt = (string) ($cached['updated_at'] ?? '');
    $link['_metadata_updated_at'] = $preserveTimestamp && $updatedAt !== '' ? $updatedAt : date_db();

    return $link;
}

function bot_link_image_cache(string $imageUrl): string
{
    $imageUrl = trim($imageUrl);
    if ($imageUrl === '' || !extension_loaded('gd') || !function_exists('imagewebp')) {
        return '';
    }
    if (str_starts_with($imageUrl, '/uploads/links/')) {
        return bot_link_image_exists($imageUrl) ? $imageUrl : '';
    }
    if (!LinkMetadata::isSafeRemoteUrl($imageUrl)) {
        return '';
    }

    $subfolder = date('Y/m');
    $filename = substr(hash('sha256', $imageUrl), 0, 40) . '.webp';
    $directory = base_path('uploads/links/' . $subfolder);
    $target = $directory . DIRECTORY_SEPARATOR . $filename;
    $localUrl = '/uploads/links/' . $subfolder . '/' . $filename;

    if (is_file($target)) {
        return $localUrl;
    }

    $response = LinkMetadata::fetchImage($imageUrl);
    $body = (string) ($response['body'] ?? '');
    $info = $body !== '' && function_exists('getimagesizefromstring') ? @getimagesizefromstring($body) : false;
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $width = is_array($info) ? (int) ($info[0] ?? 0) : 0;
    $height = is_array($info) ? (int) ($info[1] ?? 0) : 0;
    $mime = is_array($info) ? strtolower((string) ($info['mime'] ?? '')) : '';

    if (
        $response === null
        || !in_array($mime, $allowedMimes, true)
        || $width < 1
        || $height < 1
        || $width > 8192
        || $height > 8192
        || $height > intdiv(20_000_000, $width)
    ) {
        return '';
    }

    $source = @imagecreatefromstring($body);
    if (!$source instanceof GdImage) {
        return '';
    }

    $targetWidth = 184;
    $targetHeight = 172;
    $targetRatio = $targetWidth / $targetHeight;
    $sourceRatio = $width / $height;
    $cropWidth = $width;
    $cropHeight = $height;
    $sourceX = 0;
    $sourceY = 0;

    if ($sourceRatio > $targetRatio) {
        $cropWidth = max(1, (int) round($height * $targetRatio));
        $sourceX = (int) floor(($width - $cropWidth) / 2);
    } elseif ($sourceRatio < $targetRatio) {
        $cropHeight = max(1, (int) round($width / $targetRatio));
        $sourceY = (int) floor(($height - $cropHeight) / 2);
    }

    $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
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

    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        imagedestroy($canvas);
        return '';
    }

    $temporary = $target . '.tmp-' . bin2hex(random_bytes(6));
    $written = imagewebp($canvas, $temporary, 62);
    imagedestroy($canvas);

    if (!$written) {
        @unlink($temporary);
        return '';
    }
    if (is_file($target)) {
        @unlink($temporary);
        return $localUrl;
    }
    if (!@rename($temporary, $target)) {
        @unlink($temporary);
        return '';
    }

    return $localUrl;
}

function bot_link_image_exists(string $url): bool
{
    $relative = str_replace('\\', '/', trim(substr($url, strlen('/uploads/links/')), '/'));
    if (!preg_match('~^[0-9]{4}/[0-9]{2}/[a-f0-9]{40}\.webp$~', $relative) || str_contains($relative, '..')) {
        return false;
    }

    return is_file(base_path('uploads/links/' . $relative));
}

function status_link_prepare_metadata(array $link, ?array $cached, bool $localizeImage = false, string $localImageSource = ''): array
{
    if ($cached !== null && status_link_metadata_fresh($cached)) {
        $prepared = status_link_apply_cached_metadata($link, $cached, true);
    } else {
        $prepared = LinkMetadata::enrich($link);
        $prepared['_metadata_updated_at'] = date_db();

        if (empty($prepared['_metadata_fetched']) && $cached !== null) {
            $prepared = status_link_apply_cached_metadata($prepared, $cached, false);
        }
    }

    if ($localizeImage) {
        $imageUrl = $localImageSource ?: (string) ($prepared['image_url'] ?? '');
        $prepared['image_url'] = bot_link_image_cache($imageUrl);
    }

    return $prepared;
}

function status_sync_links(int $contentId, array $links, string $localImageLinkHash = '', string $localImageSource = ''): void
{
    if ($contentId < 1) {
        return;
    }

    $previousLinkIds = status_link_ids_for_content($contentId);
    $metadataCache = status_link_metadata_cache($links);

    delete('content_links', ['content_id' => $contentId]);

    foreach ($links as $link) {
        $link = (array) $link;
        $hash = (string) ($link['url_hash'] ?? '');

        if (plain_text_limit((string) ($link['normalized_url'] ?? ''), 2048) === '' || $hash === '') {
            continue;
        }

        $cacheImage = $localImageLinkHash !== '' && hash_equals($localImageLinkHash, $hash);
        $link = status_link_prepare_metadata(
            $link,
            $metadataCache[$hash] ?? null,
            $cacheImage,
            $cacheImage ? $localImageSource : ''
        );
        $linkId = status_link_upsert($link);

        if ($linkId < 1) {
            continue;
        }

        try {
            insert('content_links', [
                'content_id' => $contentId,
                'link_id' => $linkId,
                'position_index' => max(0, (int) ($link['position'] ?? 0)),
                'created_at' => date_db(),
            ]);
        } catch (Throwable) {
            // Duplicate links inside one post are ignored by the unique relation key.
        }
    }

    status_cleanup_unused_link_ids($previousLinkIds);
}

function status_link_ids_for_content(int $contentId): array
{
    if ($contentId < 1) {
        return [];
    }

    return array_values(array_unique(array_filter(array_map(
        static fn (array $row): int => (int) ($row['link_id'] ?? 0),
        all('SELECT link_id FROM content_links WHERE content_id = ?', [$contentId])
    ), static fn (int $id): bool => $id > 0)));
}

function status_cleanup_unused_link_ids(array $linkIds): void
{
    $linkIds = array_values(array_unique(array_filter(array_map('intval', $linkIds), static fn (int $id): bool => $id > 0)));

    if ($linkIds === []) {
        return;
    }

    foreach (array_chunk($linkIds, 100) as $chunk) {
        $placeholders = implode(', ', array_fill(0, count($chunk), '?'));

        try {
            run(
                'DELETE FROM links
                    WHERE id IN (' . $placeholders . ')
                        AND NOT EXISTS (
                            SELECT 1
                            FROM content_links cl
                            WHERE cl.link_id = links.id
                        )',
                $chunk
            );
        } catch (Throwable) {
            // Link cleanup is opportunistic and must not block saving a post.
        }
    }
}

function status_links_cache(array $contentIds): array
{
    static $cache = [];

    $ids = array_values(array_unique(array_filter(array_map('intval', $contentIds), static fn (int $id): bool => $id > 0)));

    if ($ids === []) {
        return [];
    }

    $missing = array_values(array_filter($ids, static fn (int $id): bool => !array_key_exists($id, $cache)));

    foreach ($missing as $id) {
        $cache[$id] = [];
    }

    if ($missing !== []) {
        foreach (db_select(
            'SELECT cl.content_id,
                    cl.position_index,
                    l.id AS link_id,
                    l.normalized_url,
                    l.url_hash,
                    l.provider,
                    l.link_type,
                    l.title,
                    l.description,
                    l.image_url,
                    l.video_id,
                    l.embed_url,
                    l.created_at,
                    l.updated_at
                FROM content_links cl
                INNER JOIN links l ON l.id = cl.link_id'
        )
            ->whereIn('cl.content_id', $missing)
            ->order('cl.content_id ASC, cl.position_index ASC, l.id ASC')
            ->all() as $row) {
            $contentId = (int) ($row['content_id'] ?? 0);

            if ($contentId > 0) {
                $cache[$contentId][] = $row;
            }
        }
    }

    $result = [];

    foreach ($ids as $id) {
        $result[$id] = $cache[$id] ?? [];
    }

    return $result;
}

function status_preload_links(array $contentIds): void
{
    status_links_cache($contentIds);
}

function status_links_for_content(int $contentId): array
{
    $links = status_links_cache([$contentId]);

    return $links[$contentId] ?? [];
}

function status_link_display_url(array $link): string
{
    $url = (string) ($link['normalized_url'] ?? '');
    $parts = parse_url($url);

    if (!is_array($parts)) {
        return $url;
    }

    $host = strtolower((string) ($parts['host'] ?? ''));
    $path = (string) ($parts['path'] ?? '');

    return trim($host . ($path !== '/' ? $path : ''));
}

function status_video_embed_allowed(string $url): bool
{
    $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));

    return in_array($host, [
        'www.youtube-nocookie.com',
        'youtube-nocookie.com',
        'www.youtube.com',
        'youtube.com',
        'player.vimeo.com',
        'www.dailymotion.com',
        'dailymotion.com',
    ], true);
}

function status_video_embed_url(array $link): string
{
    $provider = (string) ($link['provider'] ?? '');
    $videoId = trim((string) ($link['video_id'] ?? ''));

    if ($provider === 'youtube' && $videoId !== '') {
        return 'https://www.youtube.com/embed/' . rawurlencode($videoId);
    }

    return (string) ($link['embed_url'] ?? '');
}

function status_video_thumbnail_url(array $link): string
{
    $provider = (string) ($link['provider'] ?? '');
    $videoId = trim((string) ($link['video_id'] ?? ''));
    $imageUrl = trim((string) ($link['image_url'] ?? ''));

    if ($imageUrl !== '') {
        return $imageUrl;
    }

    if ($provider === 'youtube' && $videoId !== '') {
        return 'https://i.ytimg.com/vi/' . rawurlencode($videoId) . '/hqdefault.jpg';
    }

    return '';
}

function status_link_card_html(array $link): string
{
    $type = (string) ($link['link_type'] ?? 'link');
    $provider = (string) ($link['provider'] ?? 'web');
    $url = (string) ($link['normalized_url'] ?? '');
    $title = trim((string) ($link['title'] ?? ''));
    $description = trim((string) ($link['description'] ?? ''));
    $imageUrl = trim((string) ($link['image_url'] ?? ''));
    $displayUrl = status_link_display_url($link);

    if ($url === '') {
        return '';
    }

    if ($title === '') {
        $title = $displayUrl !== '' ? $displayUrl : $url;
    }

    $embedUrl = status_video_embed_url($link);
    $thumbnailUrl = status_video_thumbnail_url($link);

    if ($type === 'video' && status_video_embed_allowed($embedUrl)) {
        ob_start();
        ?>
            <div class="status-video-card" data-status-video data-embed-url="<?= e($embedUrl) ?>">
                <button class="status-video-placeholder" type="button" data-status-video-load aria-label="<?= e($title) ?>">
                    <?php if ($thumbnailUrl !== ''): ?>
                        <img class="status-video-thumb" src="<?= e($thumbnailUrl) ?>" alt="" loading="lazy" referrerpolicy="no-referrer">
                    <?php endif; ?>
                    <span class="status-video-play"><?= icon('play') ?></span>
                    <span class="status-video-copy">
                        <strong><?= e($title) ?></strong>
                        <small><?= e($description !== '' ? $description : $provider) ?></small>
                    </span>
                </button>
            </div>
            <?php

        return trim((string) ob_get_clean());
    }

    ob_start();
    ?>
        <a class="status-link-card<?= $imageUrl !== '' ? ' has-image' : '' ?>" href="<?= e($url) ?>" target="_blank" rel="nofollow noopener noreferrer ugc">
            <?php if ($imageUrl !== ''): ?>
                <span class="status-link-media" data-status-link-media>
                    <img class="status-link-image" src="<?= e($imageUrl) ?>" alt="" loading="lazy" data-status-link-image>
                    <span class="status-link-icon status-link-fallback-icon" data-status-link-fallback><?= icon('image') ?></span>
                </span>
            <?php else: ?>
                <span class="status-link-icon"><?= icon('external-link') ?></span>
            <?php endif; ?>
            <span class="status-link-copy">
                <strong><?= e($title) ?></strong>
                <?php if ($description !== '' && $description !== $displayUrl): ?>
                    <span><?= e($description) ?></span>
                <?php endif; ?>
                <?php if ($displayUrl !== ''): ?>
                    <small><?= e($displayUrl) ?></small>
                <?php endif; ?>
            </span>
        </a>
        <?php

    return trim((string) ob_get_clean());
}

function status_links_html(array $item): string
{
    $contentId = (int) ($item['id'] ?? 0);

    if ($contentId < 1) {
        return '';
    }

    $links = status_links_for_content($contentId);

    if ($links === []) {
        return '';
    }

    $html = '';

    foreach ($links as $link) {
        if (status_link_is_internal((array) $link)) {
            continue;
        }

        $html .= status_link_card_html($link);
    }

    return $html !== '' ? '<div class="status-links">' . $html . '</div>' : '';
}

function status_post_modal_id(int $contentId): string
{
    return 'status-post-modal-' . max(0, $contentId);
}

function status_edit_modal_id(int $contentId): string
{
    return 'status-edit-modal-' . max(0, $contentId);
}

function status_post_modal_url(int $contentId, string $action = ''): string
{
    $query = ['id' => max(0, $contentId)];

    if ($action !== '') {
        $query['action'] = $action;
    }

    return '/api/status-modal?' . http_build_query($query);
}

function status_action_modal_url(string $type, int $contentId, string $action = ''): string
{
    $type = in_array($type, ['report', 'edit'], true) ? $type : 'edit';
    $query = ['id' => max(0, $contentId)];

    if ($action !== '') {
        $query['action'] = $action;
    }

    return '/api/status-' . $type . '-modal?' . http_build_query($query);
}

function status_api_url(string $action, array $params = [], bool $html = true): string
{
    $action = trim(str_replace('_', '-', strtolower($action)), '-');
    $query = [];

    foreach ($params as $key => $value) {
        if ($value !== '' && $value !== null) {
            $query[$key] = $value;
        }
    }

    if ($html) {
        $query['view'] = 'html';
    }

    return '/api/status/' . rawurlencode($action) . ($query !== [] ? '?' . http_build_query($query) : '');
}

function status_report_modal_id(int $contentId): string
{
    return 'status-report-modal-' . max(0, $contentId);
}

function author_profile_edit_modal_id(int $authorId): string
{
    return 'profile-edit-modal-' . max(0, $authorId);
}

function author_profile_edit_modal_url(int $authorId, string $focus = ''): string
{
    $focus = in_array($focus, ['locale', 'theme', 'bio'], true) ? $focus : '';
    $query = ['author_id' => max(0, $authorId)];

    if ($focus !== '') {
        $query['focus'] = $focus;
    }

    return '/api/profile-edit-modal?' . http_build_query($query);
}

function author_avatar_edit_modal_id(int $authorId): string
{
    return 'avatar-edit-modal-' . max(0, $authorId);
}

function author_avatar_edit_modal_url(int $authorId): string
{
    return '/api/avatar-edit-modal?' . http_build_query(['author_id' => max(0, $authorId)]);
}

function author_following_modal_id(int $authorId): string
{
    return 'following-modal-' . max(0, $authorId);
}

function author_following_modal_url(int $authorId, int $page = 1): string
{
    return author_following_api_url($authorId, $page, true);
}

function author_following_api_url(int $authorId, int $page = 1, bool $html = false): string
{
    $query = ['author_id' => max(0, $authorId)];

    if ($page > 1) {
        $query['page'] = $page;
    }

    if ($html) {
        $query['view'] = 'html';
    }

    return '/api/author/following?' . http_build_query($query);
}

function status_time_button(string $createdAt, int $contentId, bool $openModal = true, string $action = ''): string
{
    if ($createdAt === '') {
        return '';
    }

    ob_start();
    ?>
        <?php if ($openModal): ?>
            <button class="link-button public-content-meta status-time-button" type="button" data-modal-open>
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

function status_field(?array $item = null): string
{
    return part('status/field', ['item' => $item]);
}

function status_composer(string $action, array $user): string
{
    return part('status/composer', [
        'action' => $action,
        'user' => $user,
    ]);
}

function notification_icon(string $type): string
{
    return match ($type) {
        'content_like' => 'thumb-up',
        'comment_like' => 'thumb-up',
        'content_comment' => 'message-circle',
        'content_mention', 'comment_mention' => 'user',
        'report_resolved' => 'check',
        'report_dismissed' => 'flag',
        default => 'bell',
    };
}

function notification_message(array $notification): string
{
    $type = (string) ($notification['type'] ?? '');
    $actor = trim((string) ($notification['actor_name'] ?? ''));
    $actor = $actor !== '' ? $actor : t('notifications.someone');

    return match ($type) {
        'content_like' => t('notifications.messages.content_like', ['actor' => $actor]),
        'comment_like' => t('notifications.messages.comment_like', ['actor' => $actor]),
        'content_comment' => t('notifications.messages.content_comment', ['actor' => $actor]),
        'content_mention' => t('notifications.messages.content_mention', ['actor' => $actor]),
        'comment_mention' => t('notifications.messages.comment_mention', ['actor' => $actor]),
        'report_resolved' => t('notifications.messages.report_resolved', ['actor' => $actor]),
        'report_dismissed' => t('notifications.messages.report_dismissed', ['actor' => $actor]),
        default => t('notifications.messages.generic', ['actor' => $actor]),
    };
}

function notification_target_url(array $notification): string
{
    $contentId = (int) ($notification['content_id'] ?? 0);

    return $contentId > 0 ? status_url($contentId) : '/notifications';
}

function notification_url(array $notification): string
{
    $id = (int) ($notification['id'] ?? 0);
    $isUnread = trim((string) ($notification['read_at'] ?? '')) === '';

    if ($id > 0 && $isUnread) {
        return '/notifications/open?id=' . $id;
    }

    return notification_target_url($notification);
}

function notification_create(int $userId, string $type, int $actorId, int $contentId = 0, int $commentId = 0, string $key = ''): void
{
    if ($userId < 1 || $actorId < 1 || $userId === $actorId) {
        return;
    }

    static $recipientRoles = [];
    if (!array_key_exists($userId, $recipientRoles)) {
        $recipientRoles[$userId] = (string) val('SELECT role FROM users WHERE id = ? LIMIT 1', [$userId]);
    }
    if ($recipientRoles[$userId] === 'bot') {
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

function notification_mentioned_user_ids(string $text): array
{
    if ($text === '' || !preg_match_all('/(?<![A-Za-z0-9_])@([1-9][0-9]*)/', $text, $matches)) {
        return [];
    }

    $ids = [];
    $candidateIds = array_values(array_unique(array_filter(array_map(
        'intval',
        (array) ($matches[1] ?? [])
    ), static fn (int $id): bool => $id > 0)));
    $users = author_mention_users_by_ids($candidateIds);

    foreach ($candidateIds as $id) {
        if (isset($users[$id])) {
            $ids[$id] = $id;
        }
    }

    return array_values($ids);
}

function notification_create_for_mentions(string $body, array $actor, int $contentId, int $commentId = 0, array $skipUserIds = []): void
{
    if ($body === '' || $contentId < 1) {
        return;
    }

    $actorId = (int) ($actor['id'] ?? 0);

    if ($actorId < 1) {
        return;
    }

    $mentionedIds = notification_mentioned_user_ids($body);

    if ($mentionedIds === []) {
        return;
    }

    $skip = [$actorId => true];

    foreach ($skipUserIds as $skipUserId) {
        $skipUserId = (int) $skipUserId;

        if ($skipUserId > 0) {
            $skip[$skipUserId] = true;
        }
    }

    $type = $commentId > 0 ? 'comment_mention' : 'content_mention';
    $keyBase = $type . ':' . $contentId . ':' . max(0, $commentId) . ':' . $actorId;

    foreach ($mentionedIds as $mentionedId) {
        if (isset($skip[$mentionedId])) {
            continue;
        }

        notification_create($mentionedId, $type, $actorId, $contentId, $commentId, $keyBase);
    }
}

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

function notification_latest_id(int $userId): int
{
    if ($userId < 1) {
        return 0;
    }

    return (int) db_select('SELECT COALESCE(MAX(id), 0) FROM notifications')
        ->where('user_id = ?', $userId)
        ->value();
}

function notifications_for_user(int $userId, int $limit = 80, string $cursorAt = '', int $cursorId = 0): array
{
    if ($userId < 1) {
        return [];
    }

    $limit = max(1, min(200, $limit));

    $query = db_select(
        'SELECT n.*,
                u.username AS actor_name,
                u.username AS actor_username,
                u.avatar_config AS actor_avatar_config,
                c.body AS content_body
            FROM notifications n
            LEFT JOIN users u ON u.id = n.actor_id
            LEFT JOIN content c ON c.id = n.content_id'
    )
        ->where('n.user_id = ?', $userId);

    if ($cursorAt !== '' && $cursorId > 0) {
        $query->where(
            '(n.created_at < ? OR (n.created_at = ? AND n.id < ?))',
            $cursorAt,
            $cursorAt,
            $cursorId
        );
    }

    return $query
        ->order('n.created_at DESC, n.id DESC')
        ->limit($limit)
        ->all();
}

function notifications_page_limit(): int
{
    return 40;
}

function notifications_page_url(string $cursorAt, int $cursorId): string
{
    return '/api/notifications-page?' . http_build_query([
        'cursor_at' => $cursorAt,
        'cursor_id' => $cursorId,
    ]);
}

function notifications_page_batch(int $userId, string $cursorAt = '', int $cursorId = 0): array
{
    $limit = notifications_page_limit();
    $cursorAt = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $cursorAt) === 1 ? $cursorAt : '';
    $cursorId = max(0, $cursorId);
    $items = notifications_for_user($userId, $limit + 1, $cursorAt, $cursorId);
    $hasMore = count($items) > $limit;

    if ($hasMore) {
        $items = array_slice($items, 0, $limit);
    }

    $last = $items !== [] ? $items[array_key_last($items)] : [];
    $nextAt = $hasMore ? (string) ($last['created_at'] ?? '') : '';
    $nextId = $hasMore ? (int) ($last['id'] ?? 0) : 0;

    return [
        'items' => $items,
        'count' => count($items),
        'done' => !$hasMore,
        'next_url' => $nextAt !== '' && $nextId > 0 ? notifications_page_url($nextAt, $nextId) : '',
    ];
}

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
        $createdAt = (string) ($notification['created_at'] ?? '');
        $contentText = meta_text((string) ($notification['content_body'] ?? ''), 90);
        ?>
            <a class="notification-popover-item<?= $isUnread ? ' is-unread' : '' ?>" href="<?= e(notification_url($notification)) ?>">
                <span class="notification-popover-avatar">
                    <?= user_avatar_html($notification, $actorName, notification_icon((string) ($notification['type'] ?? ''))) ?>
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

function notification_open(int $id, int $userId): string
{
    if ($id < 1 || $userId < 1) {
        return '/notifications';
    }

    $notification = one(
        'SELECT id, content_id, read_at
            FROM notifications
            WHERE id = ? AND user_id = ?
            LIMIT 1',
        [$id, $userId]
    );

    if ($notification === null) {
        return '/notifications';
    }

    if (trim((string) ($notification['read_at'] ?? '')) === '') {
        run(
            'UPDATE notifications
                SET read_at = ?, updated_at = ?
                WHERE id = ? AND user_id = ? AND read_at IS NULL',
            [date_db(), date_db(), $id, $userId]
        );
    }

    return notification_target_url($notification);
}

function notification_mark_all_read(int $userId): void
{
    if ($userId < 1) {
        return;
    }

    run('UPDATE notifications SET read_at = ?, updated_at = ? WHERE user_id = ? AND read_at IS NULL', [date_db(), date_db(), $userId]);
}

function notification_delete(int $id, int $userId): void
{
    if ($id < 1 || $userId < 1) {
        return;
    }

    delete('notifications', ['id' => $id, 'user_id' => $userId]);
}

function notifications_apply_action(int $userId, string $action, int $id = 0): string
{
    if ($action === 'read') {
        notification_mark_read($id, $userId);
        return t('notifications.messages.read_done');
    }

    if ($action === 'read_all') {
        notification_mark_all_read($userId);
        return t('notifications.messages.read_all_done');
    }

    if ($action === 'delete') {
        notification_delete($id, $userId);
        return t('notifications.messages.deleted');
    }

    api_error('Unsupported notification action.', 400, 'unsupported_notification_action');
}

function notification_item_html(array $notification): string
{
    $id = (int) ($notification['id'] ?? 0);
    $isUnread = trim((string) ($notification['read_at'] ?? '')) === '';
    $actorName = trim((string) ($notification['actor_name'] ?? ''));
    $createdAt = (string) ($notification['created_at'] ?? '');
    $contentText = meta_text((string) ($notification['content_body'] ?? ''), 120);
    $url = notification_url($notification);

    ob_start();
    ?>
        <article class="notification-item<?= $isUnread ? ' is-unread' : '' ?>">
            <a class="notification-main" href="<?= e($url) ?>">
                <span class="notification-avatar">
                    <?= user_avatar_html($notification, $actorName, notification_icon((string) ($notification['type'] ?? ''))) ?>
                </span>
                <span class="notification-copy">
                    <strong><?= e(notification_message($notification)) ?></strong>
                    <?php if ($contentText !== ''): ?>
                        <span><?= e($contentText) ?></span>
                    <?php endif; ?>
                    <?php if ($createdAt !== ''): ?>
                        <time datetime="<?= e(date_iso($createdAt)) ?>"><?= e(datetime($createdAt)) ?></time>
                    <?php endif; ?>
                </span>
            </a>
            <div class="notification-actions">
                <?php if ($isUnread): ?>
                    <form method="post" action="/api/notifications/read?view=html" data-ajax-form data-ajax-target="#notifications-view">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= e($id) ?>">
                        <button class="btn btn-ghost btn-icon btn-sm" type="submit" title="<?= et('notifications.mark_read') ?>" aria-label="<?= et('notifications.mark_read') ?>">
                            <?= icon('check') ?>
                        </button>
                    </form>
                <?php endif; ?>
                <form method="post" action="/api/notifications/delete?view=html" data-ajax-form data-ajax-target="#notifications-view">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= e($id) ?>">
                    <button class="btn btn-ghost btn-icon btn-sm text-danger" type="submit" title="<?= et('notifications.delete') ?>" aria-label="<?= et('notifications.delete') ?>">
                        <?= icon('trash') ?>
                    </button>
                </form>
            </div>
        </article>
        <?php

    return trim((string) ob_get_clean());
}

function notification_items_html(array $notifications): string
{
    return implode('', array_map(
        static fn (array $notification): string => notification_item_html($notification),
        $notifications
    ));
}

function notifications_page_html(array $notifications, int $unread, string $nextUrl = ''): string
{
    ob_start();
    ?>
        <section class="notifications-page stack stack-gap-14">
            <article class="card">
                <div class="card-header split">
                    <h1 class="text-lg m-0 cluster gap-2"><?= icon('bell') ?> <?= et('notifications.title') ?></h1>
                    <?php if ($unread > 0): ?>
                        <form method="post" action="/api/notifications/read-all?view=html" data-ajax-form data-ajax-target="#notifications-view">
                            <?= csrf_field() ?>
                            <button class="btn btn-secondary btn-sm" type="submit"><?= icon('check') ?> <span><?= et('notifications.mark_all_read') ?></span></button>
                        </form>
                    <?php endif; ?>
                </div>
                <div class="notifications-list" id="notifications-list">
                    <?php if ($notifications === []): ?>
                        <div class="notification-empty"><?= icon('bell') ?> <span><?= et('notifications.empty') ?></span></div>
                    <?php else: ?>
                        <?= notification_items_html($notifications) ?>
                    <?php endif; ?>
                </div>
            </article>
            <?php if ($nextUrl !== ''): ?>
                <div class="status-feed-more" data-status-feed-more data-status-feed-target="#notifications-list" data-status-feed-url="<?= e($nextUrl) ?>">
                    <button class="btn btn-secondary status-feed-more-button" type="button" data-status-feed-load>
                        <?= icon('plus') ?> <span><?= et('notifications.load_more') ?></span>
                    </button>
                    <span class="status-feed-more-state" data-status-feed-state hidden><?= et('notifications.loading') ?></span>
                </div>
            <?php endif; ?>
        </section>
        <?php

    return trim((string) ob_get_clean());
}

function notification_delete_for_content(int $contentId): void
{
    if ($contentId < 1) {
        return;
    }

    delete('notifications', ['content_id' => $contentId]);
}

function notification_delete_for_comment(int $commentId): void
{
    if ($commentId < 1) {
        return;
    }

    delete('notifications', ['comment_id' => $commentId]);
}

function notification_state(int $userId, bool $includeHtml = true): array
{
    $unread = notification_unread_count($userId);
    $message = '';

    if ($unread === 1) {
        $message = t('notifications.new');
    } elseif ($unread > 1) {
        $message = t('notifications.new_count', ['count' => $unread]);
    }

    $state = [
        'unread' => $unread,
        'latest_id' => notification_latest_id($userId),
        'message' => $message,
    ];

    if ($includeHtml) {
        $state['html'] = notification_preview_html($userId);
    }

    return $state;
}

function notification_badge_text(int $count): string
{
    return $count > 99 ? '99+' : (string) max(0, $count);
}

function status_json_require_not_muted(array $user): void
{
    $mutedUntil = user_muted_until($user);

    if ($mutedUntil !== '') {
        api_error(t('moderation.messages.account_muted', ['until' => datetime($mutedUntil)]), 403, 'muted');
    }
}

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
    status_sync_links($contentId, (array) ($payload['links'] ?? []));
    moderation_record_action($user, 'post');
    notification_create_for_mentions($body, $user, $contentId);

    $item = public_status_item($contentId);

    $data = [
        'action' => 'create',
        'status' => status_json_summary($contentId, $user),
        'status_id' => $contentId,
        'message' => t('account.messages.status_created'),
    ];

    if (wants_partial()) {
        $data['card_html'] = $item !== null ? status_card($item, $redirect, $user) : '';
    }

    return $data;
}

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

    status_set_user_liked($contentId, $userId, !$liked);

    return [
        'action' => 'react',
        'status' => status_json_summary($contentId, $user),
    ];
}

function status_json_comment(int $contentId, int $parentId, array $user, string $redirect = '/', string $context = ''): array
{
    $userId = (int) ($user['id'] ?? 0);
    $status = status_find($contentId);
    $body = plain_text_limit((string) input('comment', ''), 2000);
    moderation_require_allowed_urls($body);
    $body = status_strip_external_urls($body);
    $body = normalize_mentions_for_storage($body);
    status_require_valid_tags($body);
    $body = normalize_tags_for_storage($body);
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
    notification_create_for_mentions($body, $user, $contentId, $commentId, [(int) ($status['author_id'] ?? 0)]);

    $data = [
        'action' => 'comment',
        'status' => status_json_summary($contentId, $user),
        'comment_id' => $commentId,
        'message' => t('account.messages.comment_created'),
    ];

    if (wants_partial()) {
        $data['comment'] = status_json_comment_payload($commentId, $user, $redirect, $context);
    }

    return $data;
}

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

    status_set_comment_user_liked($commentId, $userId, !$liked);

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

function status_payload(): array
{
    $body = plain_text_limit((string) input('body', ''), 2000);
    moderation_require_allowed_urls($body);

    $body = normalize_mentions_for_storage($body);
    $tags = status_require_valid_tags($body);
    $body = normalize_tags_for_storage($body);
    $body = plain_text_limit($body, 2000);

    return [
        'body' => $body,
        'tags' => $tags,
        'links' => status_links_from_text($body),
    ];
}

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

function status_delete_content(int $contentId, bool $deleteReports = true, bool $deleteNotifications = true): void
{
    if ($contentId < 1) {
        return;
    }

    $termIds = status_term_ids_for_content($contentId);
    $linkIds = status_link_ids_for_content($contentId);

    delete('content_likes', ['content_id' => $contentId]);
    foreach (db_select('SELECT id FROM content_comments')->where('content_id = ?', $contentId)->all() as $comment) {
        delete('comment_likes', ['comment_id' => (int) ($comment['id'] ?? 0)]);
    }

    if ($deleteNotifications) {
        notification_delete_for_content($contentId);
    }

    delete('content_comments', ['content_id' => $contentId]);
    delete('content_tags', ['content_id' => $contentId]);
    delete('content_links', ['content_id' => $contentId]);

    if ($deleteReports) {
        delete('content_reports', ['content_id' => $contentId]);
    }

    status_cleanup_unused_term_ids($termIds);
    status_cleanup_unused_link_ids($linkIds);
    delete('content', ['id' => $contentId]);
}

function status_actions(array $item, ?array $user, string $action, bool $openCommentsModal = true): string
{
    $contentId = (int) ($item['id'] ?? 0);

    if ($contentId < 1) {
        return '';
    }

    return part('status/actions', [
        'item' => $item,
        'user' => $user,
        'action' => $action,
        'open_comments_modal' => $openCommentsModal,
    ]);
}

function status_manage_actions(array $item, ?array $user, string $action): string
{
    $contentId = (int) ($item['id'] ?? 0);

    if ($contentId < 1) {
        return '';
    }

    return part('status/manage-actions', [
        'item' => $item,
        'user' => $user,
        'action' => $action,
    ]);
}

function status_card(array $item, string $action = '/', ?array $user = null): string
{
    $contentId = (int) ($item['id'] ?? 0);

    if ($contentId < 1) {
        return '';
    }

    return part('status/card', [
        'item' => $item,
        'action' => $action,
        'user' => $user ?? auth(),
    ]);
}

function public_status_page_limit(): int
{
    return max(1, min(50, (int) config('public.status_limit', 20)));
}

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
        $cursorAt = trim((string) ($params['cursor_at'] ?? ''));
        $cursorAt = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $cursorAt) === 1 ? $cursorAt : '';
        $cursorId = max(0, (int) ($params['cursor_id'] ?? 0));

        return [
            'items' => public_status_items_by_tag($tag, $limit, $cursorAt, $cursorId),
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

    return part('status/home-feed', [
        'feed' => $feed,
        'user' => $user,
        'current_feed_url' => $currentFeedUrl,
        'following_login_required' => $followingLoginRequired,
        'limit' => $limit,
        'items' => $items,
        'feed_id' => $feedId,
    ]);
}

function status_json_report(int $contentId, array $user): array
{
    $userId = (int) ($user['id'] ?? 0);
    $item = status_find($contentId);

    if ($contentId < 1 || $userId < 1 || $item === null) {
        api_error(t('account.messages.status_not_found'), 404, 'status_not_found');
    }

    if ((int) ($item['author_id'] ?? 0) === $userId) {
        api_error(t('moderation.messages.report_own_content'), 403, 'report_own_content');
    }

    $reasons = array_keys(status_report_reasons());
    $reason = (string) input('reason', 'other');
    $reason = in_array($reason, $reasons, true) ? $reason : 'other';
    $note = plain_text_limit((string) input('note', ''), 1000);
    $now = date_db();
    $existing = one(
        'SELECT *
            FROM content_reports
            WHERE content_id = ? AND reporter_id = ?
            LIMIT 1',
        [$contentId, $userId]
    );

    if ($existing !== null && (string) ($existing['status'] ?? '') !== 'open') {
        return [
            'action' => 'report',
            'status_id' => $contentId,
            'modal_close' => true,
            'message' => t('moderation.messages.report_already_reviewed'),
            'type' => 'info',
        ];
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

        return [
            'action' => 'report',
            'status_id' => $contentId,
            'modal_close' => true,
            'message' => t('moderation.messages.report_already_reviewed'),
            'type' => 'info',
        ];
    }

    status_json_require_action($user, 'report');

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

    return [
        'action' => 'report',
        'status_id' => $contentId,
        'modal_close' => true,
        'message' => t('moderation.messages.report_created'),
    ];
}

function status_json_update(int $contentId, array $user, string $redirect = '/'): array
{
    $item = status_find($contentId);

    if (status_edit_locked($item)) {
        api_error(t('account.messages.status_edit_locked'), 423, 'status_edit_locked');
    }

    if (!status_can_edit($item, $user)) {
        api_error(t('account.messages.status_forbidden'), 403, 'status_forbidden');
    }

    status_json_require_not_muted($user);

    $payload = status_payload();
    $body = (string) ($payload['body'] ?? '');

    if (trim($body) === '') {
        api_error(t('account.messages.status_required'), 422, 'status_required');
    }

    status_json_require_unique_body($user, $body, $contentId);
    $oldMentionIds = notification_mentioned_user_ids((string) ($item['body'] ?? ''));

    update('content', [
        'body' => $body,
    ], ['id' => $contentId]);
    status_sync_tags($contentId, (array) ($payload['tags'] ?? []));
    status_sync_links($contentId, (array) ($payload['links'] ?? []));
    notification_create_for_mentions($body, $user, $contentId, 0, $oldMentionIds);

    $data = [
        'action' => 'update',
        'status_id' => $contentId,
        'status' => status_json_summary($contentId, $user),
        'modal_close' => true,
        'message' => t('account.messages.status_saved'),
    ];

    if (wants_partial()) {
        $updated = public_status_item($contentId);
        $data['card_html'] = $updated !== null ? status_card($updated, $redirect, $user) : '';
    }

    return $data;
}

function status_json_delete(int $contentId, array $user): array
{
    $item = status_find($contentId);

    if (!status_can_delete($item, $user)) {
        api_error(t('account.messages.status_forbidden'), 403, 'status_forbidden');
    }

    status_delete_content($contentId);

    return [
        'action' => 'delete',
        'status_id' => $contentId,
        'deleted_status_id' => $contentId,
        'modal_close' => true,
        'message' => t('account.messages.status_deleted'),
    ];
}

function public_home_feed_api_url(string $feed = 'all', bool $html = false): string
{
    $feed = $feed === 'following' ? 'following' : 'all';
    $query = [];

    if ($html) {
        $query['view'] = 'html';
    }

    if ($feed !== 'all') {
        $query['feed'] = $feed;
    }

    return '/api/home-feed' . ($query !== [] ? '?' . http_build_query($query) : '');
}

function public_home_feed_payload(string $feed = 'all', ?array $user = null): array
{
    $user ??= auth();
    $feed = $feed === 'following' ? 'following' : 'all';
    $limit = public_status_page_limit();
    $items = $feed === 'following'
        ? ((int) ($user['id'] ?? 0) > 0 ? public_status_items_for_user((int) ($user['id'] ?? 0), $limit) : [])
        : public_status_items($limit);
    $history = $feed === 'following' ? '/?feed=following' : '/';
    $data = [
        'feed' => $feed,
        'history' => $history,
        'count' => count($items),
        'items' => $items,
    ];

    return api_payload($data, static fn (): array => [
        'html' => public_home_feed_html($feed, $user),
        'feed' => $feed,
        'history' => $history,
    ]);
}

function status_feed_html(array $items, string $action, ?array $user = null): string
{
    $user ??= auth();
    $html = '';

    foreach ($items as $item) {
        $html .= status_card($item, $action, $user);
    }

    return $html;
}

function status_feed_next_url(string $context, int $offset, int $limit, array $params = [], bool $html = true): string
{
    $query = array_merge($params, [
        'context' => $context,
        'limit' => max(1, min(50, $limit)),
    ]);

    if ($context !== 'tag') {
        $query['offset'] = max(0, $offset);
    } else {
        unset($query['offset']);
    }

    if ($html) {
        $query['view'] = 'html';
    }

    return '/api/status-feed?' . http_build_query($query);
}

function status_feed_cursor_params(array $items): array
{
    $last = $items !== [] ? end($items) : null;

    if (!is_array($last)) {
        return [];
    }

    $publishedAt = trim((string) ($last['published_at'] ?? ''));
    $id = (int) ($last['id'] ?? 0);

    if ($publishedAt === '' || $id < 1) {
        return [];
    }

    return [
        'cursor_at' => $publishedAt,
        'cursor_id' => $id,
    ];
}

function bot_schema_ensure(): void
{
    run(
        "CREATE TABLE IF NOT EXISTS bot_sources (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            bot_user_id INT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            feed_url VARCHAR(2048) NOT NULL,
            interval_minutes INT UNSIGNED NOT NULL DEFAULT 60,
            post_template VARCHAR(2000) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            last_checked_at DATETIME NULL,
            last_imported_at DATETIME NULL,
            next_run_at DATETIME NULL,
            last_error VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY bot_sources_due_index (enabled, next_run_at, id),
            KEY bot_sources_user_index (bot_user_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    run(
        "CREATE TABLE IF NOT EXISTS bot_feed_items (
            source_id BIGINT UNSIGNED NOT NULL,
            item_hash CHAR(64) NOT NULL,
            content_id BIGINT UNSIGNED NULL,
            item_guid VARCHAR(2048) NOT NULL,
            item_published_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (source_id, item_hash),
            KEY bot_feed_items_content_index (content_id),
            KEY bot_feed_items_created_index (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    run(
        "CREATE TABLE IF NOT EXISTS bot_feed_history (
            bot_user_id INT UNSIGNED NOT NULL,
            feed_hash CHAR(64) NOT NULL,
            item_hash CHAR(64) NOT NULL,
            content_id BIGINT UNSIGNED NULL,
            item_guid VARCHAR(2048) NOT NULL,
            item_published_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (bot_user_id, feed_hash, item_hash),
            KEY bot_feed_history_content_index (content_id),
            KEY bot_feed_history_created_index (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function bot_feed_url_normalize(string $url): string
{
    $parts = parse_url(trim($url));
    if (!is_array($parts)) {
        return '';
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        return '';
    }

    $port = isset($parts['port']) && !in_array((int) $parts['port'], [80, 443], true) ? ':' . (int) $parts['port'] : '';
    $path = (string) ($parts['path'] ?? '/');
    $path = $path === '/' ? '/' : rtrim($path, '/');
    $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

    return $scheme . '://' . $host . $port . $path . $query;
}

function bot_feed_source_hash(string $url): string
{
    $url = bot_feed_url_normalize($url);
    return $url !== '' ? hash('sha256', $url) : '';
}

function bot_feed_history_has(int $botUserId, string $feedUrl, string $itemGuid): bool
{
    $feedHash = bot_feed_source_hash($feedUrl);
    $itemHash = $itemGuid !== '' ? hash('sha256', $itemGuid) : '';

    return $botUserId > 0
        && $feedHash !== ''
        && $itemHash !== ''
        && (int) val(
            'SELECT COUNT(*) FROM bot_feed_history WHERE bot_user_id = ? AND feed_hash = ? AND item_hash = ?',
            [$botUserId, $feedHash, $itemHash]
        ) > 0;
}

function bot_feed_history_record(
    int $botUserId,
    string $feedUrl,
    string $itemGuid,
    int $contentId,
    string $publishedAt = '',
    string $createdAt = ''
): void {
    $feedHash = bot_feed_source_hash($feedUrl);
    $itemHash = $itemGuid !== '' ? hash('sha256', $itemGuid) : '';
    if ($botUserId < 1 || $feedHash === '' || $itemHash === '') {
        return;
    }

    try {
        insert('bot_feed_history', [
            'bot_user_id' => $botUserId,
            'feed_hash' => $feedHash,
            'item_hash' => $itemHash,
            'content_id' => $contentId > 0 ? $contentId : null,
            'item_guid' => $itemGuid,
            'item_published_at' => $publishedAt !== '' ? $publishedAt : null,
            'created_at' => $createdAt !== '' ? $createdAt : date_db(),
        ]);
    } catch (Throwable) {
        // The global history key is intentionally immutable and race-safe.
    }

    bot_feed_history_prune($botUserId, $feedHash);
}

function bot_feed_history_prune(int $botUserId, string $feedHash, int $keep = 100): void
{
    if ($botUserId < 1 || !preg_match('/^[a-f0-9]{64}$/', $feedHash)) {
        return;
    }

    $keep = max(10, min(500, $keep));
    run(
        'DELETE FROM bot_feed_history
            WHERE bot_user_id = ? AND feed_hash = ?
                AND item_hash NOT IN (
                    SELECT item_hash FROM (
                        SELECT item_hash
                        FROM bot_feed_history
                        WHERE bot_user_id = ? AND feed_hash = ?
                        ORDER BY COALESCE(item_published_at, created_at) DESC, created_at DESC, item_hash DESC
                        LIMIT ' . $keep . '
                    ) recent_items
                )',
        [$botUserId, $feedHash, $botUserId, $feedHash]
    );
}

function bot_source_default_template(): string
{
    return "{{title}}\n\n{{description}}\n\n{{url}}";
}

function bot_cron_token(bool $create = false): string
{
    $token = trim((string) setting('bots.cron_token', config('bots.cron_token', '')));

    if ($token === '' && $create) {
        $token = bin2hex(random_bytes(32));
        setting_set('bots.cron_token', $token, 'string', 'bots');
    }

    return $token;
}

function bot_cron_token_rotate(): string
{
    $token = bin2hex(random_bytes(32));
    setting_set('bots.cron_token', $token, 'string', 'bots');
    return $token;
}

function bot_cron_request_token(): string
{
    $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));

    if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $match) === 1) {
        return trim((string) ($match[1] ?? ''));
    }

    $header = trim((string) ($_SERVER['HTTP_X_TINYCAT_CRON'] ?? ''));

    if ($header !== '') {
        return $header;
    }

    return trim((string) get('bearer', ''));
}

function bot_source_find(int $id): ?array
{
    return $id > 0 ? one('SELECT * FROM bot_sources WHERE id = ? LIMIT 1', [$id]) : null;
}

function bot_sources(?int $botUserId = null): array
{
    $sql = 'SELECT bs.*, u.username FROM bot_sources bs INNER JOIN users u ON u.id = bs.bot_user_id WHERE u.role = ?';
    $params = ['bot'];

    if ($botUserId !== null && $botUserId > 0) {
        $sql .= ' AND bs.bot_user_id = ?';
        $params[] = $botUserId;
    }

    return all($sql . ' ORDER BY u.username ASC, bs.name ASC, bs.id ASC', $params);
}

function bot_users(): array
{
    return all('SELECT id, username, status FROM users WHERE role = ? ORDER BY username ASC', ['bot']);
}

function bot_source_resource(array $source): array
{
    return [
        'id' => (int) ($source['id'] ?? 0),
        'bot_user_id' => (int) ($source['bot_user_id'] ?? 0),
        'bot_username' => (string) ($source['username'] ?? ''),
        'name' => (string) ($source['name'] ?? ''),
        'feed_url' => (string) ($source['feed_url'] ?? ''),
        'interval_minutes' => (int) ($source['interval_minutes'] ?? 60),
        'post_template' => (string) ($source['post_template'] ?? ''),
        'enabled' => (bool) ($source['enabled'] ?? false),
        'last_checked_at' => (string) ($source['last_checked_at'] ?? ''),
        'last_imported_at' => (string) ($source['last_imported_at'] ?? ''),
        'next_run_at' => (string) ($source['next_run_at'] ?? ''),
        'last_error' => (string) ($source['last_error'] ?? ''),
    ];
}

function bot_delete_sources_for_user(int $botUserId): void
{
    if ($botUserId < 1) {
        return;
    }

    $ids = array_map(
        static fn (array $row): int => (int) ($row['id'] ?? 0),
        all('SELECT id FROM bot_sources WHERE bot_user_id = ?', [$botUserId])
    );

    foreach ($ids as $id) {
        if ($id > 0) {
            delete('bot_feed_items', ['source_id' => $id]);
        }
    }

    delete('bot_feed_history', ['bot_user_id' => $botUserId]);
    delete('bot_sources', ['bot_user_id' => $botUserId]);
}

function bot_feed_parse(string $xml): array
{
    if ($xml === '' || !function_exists('simplexml_load_string')) {
        return [];
    }

    $previous = libxml_use_internal_errors(true);
    $feed = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$feed instanceof SimpleXMLElement) {
        return [];
    }

    $nodes = isset($feed->channel->item) ? $feed->channel->item : $feed->entry;
    $items = [];

    foreach ($nodes as $node) {
        $namespaces = $node->getNamespaces(true);
        $link = trim((string) $node->link);

        if ($node->getName() === 'entry') {
            foreach ($node->link as $linkNode) {
                $attributes = $linkNode->attributes();
                $rel = strtolower((string) ($attributes['rel'] ?? 'alternate'));

                if ($rel === '' || $rel === 'alternate') {
                    $link = trim((string) ($attributes['href'] ?? $linkNode));
                    break;
                }
            }
        }

        $creator = '';
        if (isset($namespaces['dc'])) {
            $creator = trim((string) $node->children($namespaces['dc'])->creator);
        }
        if ($creator === '') {
            $creator = trim((string) ($node->author->name ?? $node->author));
        }

        $categories = [];
        foreach ($node->category as $category) {
            $value = trim((string) ($category['term'] ?? $category));
            if ($value !== '') {
                $categories[] = $value;
            }
        }

        $title = bot_feed_text((string) $node->title, 500);
        $descriptionSource = (string) ($node->description ?: $node->summary ?: $node->content);
        $description = bot_feed_text($descriptionSource, 1200);
        $guid = trim((string) ($node->guid ?: $node->id ?: $link));
        $published = trim((string) ($node->pubDate ?: $node->published ?: $node->updated));

        if ($guid === '' || ($title === '' && $link === '')) {
            continue;
        }

        $timestamp = $published !== '' ? strtotime($published) : false;
        $items[] = [
            'guid' => $guid,
            'title' => $title,
            'description' => $description,
            'url' => LinkMetadata::isSafeRemoteUrl($link) ? $link : '',
            'image_url' => bot_feed_image_url($node, $namespaces, $descriptionSource),
            'author' => bot_feed_text($creator, 200),
            'categories' => array_values(array_unique($categories)),
            'published_at' => $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : null,
            '_timestamp' => $timestamp !== false ? $timestamp : 0,
        ];

        if (count($items) >= 100) {
            break;
        }
    }

    usort($items, static fn (array $a, array $b): int => ((int) $a['_timestamp']) <=> ((int) $b['_timestamp']));
    return $items;
}

function bot_feed_text(string $value, int $limit): string
{
    $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    return function_exists('mb_substr') ? mb_substr($value, 0, $limit, 'UTF-8') : substr($value, 0, $limit);
}

function bot_feed_image_url(SimpleXMLElement $node, array $namespaces, string $description = ''): string
{
    $candidates = [];

    if (isset($namespaces['media'])) {
        $media = $node->children((string) $namespaces['media']);
        foreach (['content', 'thumbnail'] as $element) {
            foreach ($media->{$element} as $image) {
                $candidates[] = (string) ($image->attributes()['url'] ?? '');
            }
        }
    }

    foreach ($node->enclosure as $enclosure) {
        $attributes = $enclosure->attributes();
        $type = strtolower((string) ($attributes['type'] ?? ''));
        if ($type === '' || str_starts_with($type, 'image/')) {
            $candidates[] = (string) ($attributes['url'] ?? '');
        }
    }

    if (preg_match('~<img\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1~is', $description, $match) === 1) {
        $candidates[] = (string) ($match[2] ?? '');
    } elseif (preg_match('~<img\b[^>]*\bsrc\s*=\s*([^\s>]+)~is', $description, $match) === 1) {
        $candidates[] = trim((string) ($match[1] ?? ''), "\"'");
    }

    foreach ($candidates as $candidate) {
        $url = html_entity_decode(trim((string) $candidate), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (strlen($url) <= 2048 && LinkMetadata::isSafeRemoteUrl($url)) {
            return $url;
        }
    }

    return '';
}

function bot_render_post(array $source, array $item): string
{
    $values = [
        '{{title}}' => (string) ($item['title'] ?? ''),
        '{{description}}' => (string) ($item['description'] ?? ''),
        '{{url}}' => (string) ($item['url'] ?? ''),
        '{{author}}' => (string) ($item['author'] ?? ''),
        '{{source}}' => (string) ($source['name'] ?? ''),
        '{{categories}}' => implode(', ', (array) ($item['categories'] ?? [])),
    ];
    $body = trim(preg_replace("/\n{3,}/", "\n\n", strtr((string) ($source['post_template'] ?? bot_source_default_template()), $values)) ?? '');
    return function_exists('mb_substr') ? mb_substr($body, 0, 2000, 'UTF-8') : substr($body, 0, 2000);
}

function bot_run_due_sources(int $limit = 10): array
{
    $limit = max(1, min(100, $limit));
    $sources = all(
        'SELECT bs.*, u.username, u.status AS user_status
            FROM bot_sources bs
            INNER JOIN users u ON u.id = bs.bot_user_id
            WHERE bs.enabled = 1 AND u.role = ? AND u.status = ?
                AND (bs.next_run_at IS NULL OR bs.next_run_at <= ?)
            ORDER BY COALESCE(bs.next_run_at, bs.created_at) ASC, bs.id ASC
            LIMIT ' . $limit,
        ['bot', 'active', date_db()]
    );
    $results = [];

    foreach ($sources as $source) {
        $results[] = bot_run_source($source);
    }

    return $results;
}

function bot_run_source(array $source): array
{
    $sourceId = (int) ($source['id'] ?? 0);
    $botUserId = (int) ($source['bot_user_id'] ?? 0);
    $feedUrl = (string) ($source['feed_url'] ?? '');
    $interval = max(5, min(43200, (int) ($source['interval_minutes'] ?? 60)));
    $now = date_db();
    $next = date('Y-m-d H:i:s', time() + $interval * 60);
    $claimed = run(
        'UPDATE bot_sources SET next_run_at = ?, last_checked_at = ?, last_error = NULL WHERE id = ? AND enabled = 1 AND (next_run_at IS NULL OR next_run_at <= ?)',
        [$next, $now, $sourceId, $now]
    );

    if ($sourceId < 1 || $claimed < 1) {
        return ['source_id' => $sourceId, 'status' => 'skipped'];
    }

    try {
        $response = LinkMetadata::fetchDocument((string) ($source['feed_url'] ?? ''));
        if ($response === null) {
            throw new RuntimeException('RSS feed could not be downloaded.');
        }

        $items = bot_feed_parse((string) ($response['body'] ?? ''));
        if ($items === []) {
            throw new RuntimeException('RSS feed contains no usable items.');
        }

        foreach ($items as $item) {
            $itemGuid = (string) ($item['guid'] ?? '');
            $hash = hash('sha256', $itemGuid);
            if (
                (int) val('SELECT COUNT(*) FROM bot_feed_items WHERE source_id = ? AND item_hash = ?', [$sourceId, $hash]) > 0
                || bot_feed_history_has($botUserId, $feedUrl, $itemGuid)
            ) {
                continue;
            }

            $body = bot_render_post($source, $item);
            if ($body === '') {
                throw new RuntimeException('Post template produced an empty post.');
            }

            $publishedAt = date_db();
            $contentId = (int) insert('content', [
                'body' => $body,
                'author_id' => $botUserId,
                'published_at' => $publishedAt,
                'created_at' => $publishedAt,
            ]);
            status_sync_tags($contentId, status_tags_from_text($body));
            $feedLink = StatusLinks::fromRaw((string) ($item['url'] ?? ''));
            status_sync_links(
                $contentId,
                status_links_from_text($body),
                (string) ($feedLink['url_hash'] ?? ''),
                (string) ($item['image_url'] ?? '')
            );
            insert('bot_feed_items', [
                'source_id' => $sourceId,
                'item_hash' => $hash,
                'content_id' => $contentId,
                'item_guid' => (string) ($item['guid'] ?? ''),
                'item_published_at' => $item['published_at'] ?? null,
                'created_at' => $publishedAt,
            ]);
            bot_feed_history_record(
                $botUserId,
                $feedUrl,
                $itemGuid,
                $contentId,
                (string) ($item['published_at'] ?? ''),
                $publishedAt
            );
            update('bot_sources', ['last_imported_at' => $publishedAt], ['id' => $sourceId]);

            return ['source_id' => $sourceId, 'status' => 'posted', 'content_id' => $contentId];
        }

        return ['source_id' => $sourceId, 'status' => 'current'];
    } catch (Throwable $exception) {
        $error = bot_feed_text($exception->getMessage(), 500);
        update('bot_sources', ['last_error' => $error], ['id' => $sourceId]);
        return ['source_id' => $sourceId, 'status' => 'error', 'error' => $error];
    }
}

function status_feed_payload(string $context, int $limit, int $offset, array $params = [], ?array $user = null): array
{
    $feed = status_feed_context_items($context, $limit, $offset, $params, $user);
    $items = (array) ($feed['items'] ?? []);
    $action = (string) ($feed['action'] ?? '/');
    $count = count($items);
    $nextOffset = $offset + $count;
    $done = $count < $limit;
    $nextParams = $params;

    if ($context === 'tag') {
        unset($nextParams['cursor_at'], $nextParams['cursor_id']);
        $nextParams += status_feed_cursor_params($items);
    }

    $data = [
        'context' => $context,
        'items' => $items,
        'count' => $count,
        'done' => $done,
        'next_url' => $done ? '' : status_feed_next_url($context, $nextOffset, $limit, $nextParams, false),
    ];

    if ($context !== 'tag') {
        $data['offset'] = $offset;
        $data['next_offset'] = $nextOffset;
    }

    $htmlData = [
        'html' => status_feed_html($items, $action, $user),
        'count' => $count,
        'done' => $done,
        'next_url' => $done ? '' : status_feed_next_url($context, $nextOffset, $limit, $nextParams, true),
    ];

    if ($context !== 'tag') {
        $htmlData['offset'] = $offset;
        $htmlData['next_offset'] = $nextOffset;
    }

    return api_payload($data, static fn (): array => $htmlData);
}

function status_feed_more_control(string $feedId, string $context, int $loaded, int $limit, array $params = []): string
{
    if ($loaded < $limit) {
        return '';
    }

    return part('status/feed-more', [
        'feed_id' => $feedId,
        'context' => $context,
        'loaded' => $loaded,
        'limit' => $limit,
        'params' => $params,
    ]);
}

function status_comments_section(array $item, ?array $user, string $action): string
{
    $contentId = (int) ($item['id'] ?? 0);

    if ($contentId < 1) {
        return '';
    }

    return part('status/comments-preview', [
        'item' => $item,
        'user' => $user,
        'action' => $action,
    ]);
}

function status_comment_thread_section(array $item, ?array $user, string $action, string $context): string
{
    $contentId = (int) ($item['id'] ?? 0);

    if ($contentId < 1) {
        return '';
    }

    return part('status/comments-thread', [
        'item' => $item,
        'user' => $user,
        'action' => $action,
        'context' => $context,
    ]);
}

function status_comment_form(int $contentId, string $action, array $user, int $parentId = 0, string $mention = '', string $context = ''): string
{
    return part('status/comment-form', [
        'content_id' => $contentId,
        'action' => $action,
        'user' => $user,
        'parent_id' => $parentId,
        'mention' => $mention,
        'context' => $context,
    ]);
}

function status_comment_mention(string $name): string
{
    $handle = slug($name);

    return $handle !== '' ? '@' . $handle . ' ' : '';
}

function status_comment_item(array $comment, ?array $user, string $action, int $depth = 0, string $context = '', bool $showReplies = true, bool $showReplyForm = true): string
{
    return part('status/comment-item', [
        'comment' => $comment,
        'user' => $user,
        'action' => $action,
        'depth' => $depth,
        'context' => $context,
        'show_replies' => $showReplies,
        'show_reply_form' => $showReplyForm,
    ]);
}

function status_comment_delete_form(int $commentId, string $action, int $contentId = 0): string
{
    return part('status/comment-delete-form', [
        'comment_id' => $commentId,
        'action' => $action,
        'content_id' => $contentId,
    ]);
}

function status_comment_like_control(int $commentId, int $likesCount, bool $liked, ?array $user, string $action, int $contentId = 0): string
{
    return part('status/comment-like-control', [
        'comment_id' => $commentId,
        'likes_count' => $likesCount,
        'liked' => $liked,
        'user' => $user,
        'action' => $action,
        'content_id' => $contentId,
    ]);
}

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

function status_report_modal(array $item, ?array $user, string $action): string
{
    $contentId = (int) ($item['id'] ?? 0);
    $authorId = (int) ($item['author_id'] ?? 0);

    if ($contentId < 1 || $user === null || $authorId === (int) ($user['id'] ?? 0)) {
        return '';
    }

    return render('modals/status-report', [
        'item' => $item,
        'user' => $user,
        'action' => $action,
    ]);
}

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
        $next = auth_safe_next_url($next . $fragment) ?: $next;
    }

    return '/login?next=' . rawurlencode($next);
}

function status_edit_modal(array $item, string $action): string
{
    $contentId = (int) ($item['id'] ?? 0);

    if ($contentId < 1) {
        return '';
    }

    $item['body'] = mentions_for_editing((string) ($item['body'] ?? ''));

    return render('modals/status-edit', [
        'item' => $item,
        'action' => $action,
    ]);
}

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

function maintenance_cleanup_batch_size(mixed $value): int
{
    $size = (int) $value;
    $allowed = [500, 1000, 2500, 5000];

    return in_array($size, $allowed, true) ? $size : 1000;
}

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
        'orphan_content_links' => [
            'icon' => 'link',
            'label' => t('maintenance.tasks.orphan_content_links'),
            'description' => t('maintenance.tasks.orphan_content_links_help'),
        ],
        'orphan_links' => [
            'icon' => 'link',
            'label' => t('maintenance.tasks.orphan_links'),
            'description' => t('maintenance.tasks.orphan_links_help'),
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

function maintenance_cleanup_run(array $selected, int $batchSize = 1000): array
{
    $tasks = maintenance_cleanup_tasks();
    $batchSize = maintenance_cleanup_batch_size($batchSize);
    $results = [];

    foreach (array_unique($selected) as $task) {
        $task = trim((string) $task);

        if (!isset($tasks[$task])) {
            continue;
        }

        try {
            $results[$task] = maintenance_cleanup_task_run($task, $batchSize);
        } catch (Throwable $exception) {
            $results[$task] = [
                'task' => $task,
                'changed' => 0,
                'has_more' => false,
                'stalled' => false,
                'batch_size' => $batchSize,
                'error' => $exception->getMessage(),
            ];
        }
    }

    return $results;
}

function maintenance_cleanup_task_run(string $task, int $batchSize): array
{
    $batchSize = maintenance_cleanup_batch_size($batchSize);
    $startedAt = hrtime(true);
    $changed = maintenance_cleanup_delete($task, $batchSize);
    $hasMore = $changed >= $batchSize && maintenance_cleanup_has_rows($task);

    return [
        'task' => $task,
        'changed' => $changed,
        'has_more' => $hasMore,
        'stalled' => $hasMore && $changed < 1,
        'batch_size' => $batchSize,
        'duration_ms' => max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000)),
        'done' => !$hasMore,
    ];
}

function maintenance_cleanup_has_rows(string $task): bool
{
    $value = match ($task) {
        'orphan_tag_relations' => val(
            'SELECT 1
                FROM content_tags ct
                LEFT JOIN content c ON c.id = ct.content_id
                LEFT JOIN terms t ON t.id = ct.term_id
                WHERE c.id IS NULL OR t.id IS NULL
                LIMIT 1'
        ),
        'orphan_terms' => val(
            'SELECT 1
                FROM terms t
                WHERE NOT EXISTS (
                    SELECT 1
                    FROM content_tags ct
                    WHERE ct.term_id = t.id
                )
                LIMIT 1'
        ),
        'orphan_content_links' => val(
            'SELECT 1
                FROM content_links cl
                LEFT JOIN content c ON c.id = cl.content_id
                LEFT JOIN links l ON l.id = cl.link_id
                WHERE c.id IS NULL OR l.id IS NULL
                LIMIT 1'
        ),
        'orphan_links' => val(
            'SELECT 1
                FROM links l
                WHERE NOT EXISTS (
                    SELECT 1
                    FROM content_links cl
                    WHERE cl.link_id = l.id
                )
                LIMIT 1'
        ),
        'old_action_limits' => val(
            'SELECT 1
                FROM user_action_limits
                WHERE bucket_start < ?
                LIMIT 1',
            [date_db('-30 days')]
        ),
        'old_read_notifications' => val(
            'SELECT 1
                FROM notifications
                WHERE read_at IS NOT NULL AND read_at < ?
                LIMIT 1',
            [date_db('-30 days')]
        ),
        default => null,
    };

    return $value !== null && $value !== false;
}

function maintenance_cleanup_delete(string $task, int $batchSize): int
{
    $batchSize = maintenance_cleanup_batch_size($batchSize);

    return match ($task) {
        'orphan_tag_relations' => maintenance_cleanup_delete_orphan_tag_relations($batchSize),
        'orphan_terms' => maintenance_cleanup_delete_limited(
            'DELETE FROM terms
                WHERE NOT EXISTS (
                    SELECT 1
                    FROM content_tags ct
                    WHERE ct.term_id = terms.id
                )
                LIMIT ' . $batchSize
        ),
        'orphan_content_links' => maintenance_cleanup_delete_orphan_content_links($batchSize),
        'orphan_links' => maintenance_cleanup_delete_limited(
            'DELETE FROM links
                WHERE NOT EXISTS (
                    SELECT 1
                    FROM content_links cl
                    WHERE cl.link_id = links.id
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
            [date_db('-30 days')]
        ),
        default => 0,
    };
}

function maintenance_cleanup_delete_limited(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->rowCount();
}

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

function maintenance_cleanup_delete_orphan_content_links(int $batchSize): int
{
    $sql = 'DELETE cl
            FROM content_links cl
            INNER JOIN (
                SELECT content_id, link_id
                FROM (
                    SELECT cl.content_id, cl.link_id
                    FROM content_links cl
                    LEFT JOIN content c ON c.id = cl.content_id
                    LEFT JOIN links l ON l.id = cl.link_id
                    WHERE c.id IS NULL OR l.id IS NULL
                    LIMIT ' . $batchSize . '
                ) orphan_rows
            ) x ON x.content_id = cl.content_id AND x.link_id = cl.link_id';

    return maintenance_cleanup_delete_limited($sql);
}

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
    $interval = 60;

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

function app_sql_identifier(string $identifier): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
        throw new InvalidArgumentException('Invalid SQL identifier: ' . $identifier);
    }

    return '`' . str_replace('`', '``', $identifier) . '`';
}

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

function app_db_status(?array $requiredTables = null): array
{
    $requiredTables ??= app_required_tables();
    $status = [
        'connected' => false,
        'account_ready' => false,
        'ready' => false,
        'missing_tables' => [],
        'error' => null,
    ];

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

function app_db_ready(?array $requiredTables = null): bool
{
    return (bool) app_db_status($requiredTables)['ready'];
}

function asset(string $path, ?bool $version = null): string
{
    return Core::asset($path, $version);
}

function icon(string $name, string $class = 'icon', ?string $label = null, array $attributes = []): string
{
    return Core::icon($name, $class, $label, $attributes);
}

function q(string $sql, array $params = []): PDOStatement
{
    return Core::query($sql, $params);
}

function query(string $sql, array $params = []): PDOStatement
{
    return Core::query($sql, $params);
}

function run(string $sql, array $params = []): int
{
    return Core::exec($sql, $params);
}

function all(string $sql, array $params = []): array
{
    return Core::all($sql, $params);
}

function one(string $sql, array $params = []): ?array
{
    return Core::one($sql, $params);
}

function val(string $sql, array $params = []): mixed
{
    return Core::value($sql, $params);
}

function db_select(string $sql): CoreQuery
{
    return Core::select($sql);
}

function insert(string $table, array $data): string
{
    return Core::insert($table, $data);
}

function update(string $table, array $data, array $where): int
{
    return Core::update($table, $data, $where);
}

function delete(string $table, array $where): int
{
    return Core::delete($table, $where);
}

function find(string $table, array $where, array|string $columns = '*'): ?array
{
    return Core::find($table, $where, $columns);
}

function total(string $table, array $where = []): int
{
    return Core::count($table, $where);
}

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

function pagination(array $pagination, ?string $baseUrl = null, string $pageName = 'page', int $window = 2): string
{
    return Core::pagination($pagination, $baseUrl, $pageName, $window);
}

function pagination_meta(int $total, ?int $page = null, int $perPage = 15): array
{
    return Core::paginationMeta($total, $page, $perPage);
}

function pagination_sql(array $pagination): string
{
    $perPage = max(1, min(200, (int) ($pagination['per_page'] ?? 15)));
    $offset = max(0, (int) ($pagination['offset'] ?? 0));

    return ' LIMIT ' . $perPage . ' OFFSET ' . $offset;
}

function admin_per_page_options(): array
{
    $configured = [10, 25, 50, 100];
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

function admin_per_page(?int $value = null): int
{
    $options = admin_per_page_options();
    $default = 25;
    $default = in_array($default, $options, true) ? $default : ($options[0] ?? 25);
    $value ??= (int) get('per_page', $default);
    $value = max(1, min(200, $value));

    return in_array($value, $options, true) ? $value : $default;
}

function admin_page(string $name = 'page'): int
{
    return max(1, (int) get($name, 1));
}

function admin_list_query(array $params = [], bool $ajax = true): array
{
    $query = [];

    if ($ajax) {
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

function admin_list_url(string $path, array $params = [], bool $ajax = true): string
{
    $query = admin_list_query($params, $ajax);

    return $path . ($query !== [] ? '?' . http_build_query($query) : '');
}

function admin_pagination(array $pagination, string $path, string $target, array $params = [], string $pageName = 'page', int $window = 2, ?string $historyPath = null): string
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

    $item = static function (string $label, int|string|null $targetPage, string $class = '', bool $disabled = false, bool $current = false) use ($path, $target, $params, $pageName, $historyPath): string {
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
        $history = admin_list_url($historyPath ?? $path, $query, false);

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
    $html .= $item(t('common.previous'), $pagination['prev_page'] ?? null, 'pagination-prev', $page <= 1);

    $previous = null;

    foreach ($pages as $pageNumber) {
        if ($previous !== null && $pageNumber > $previous + 1) {
            $html .= '<span class="pagination-ellipsis" aria-hidden="true">...</span>';
        }

        $html .= $item((string) $pageNumber, $pageNumber, '', false, $pageNumber === $page);
        $previous = $pageNumber;
    }

    $html .= $item(t('common.next'), $pagination['next_page'] ?? null, 'pagination-next', $page >= $lastPage);
    $html .= '</div></nav>';

    return $html;
}

function admin_per_page_control(string $path, string $target, array $params = [], ?int $selected = null, ?string $historyPath = null): string
{
    $selected ??= admin_per_page();
    $params['page'] = 1;

    ob_start();
    ?>
        <form class="admin-per-page-form" action="<?= e($path) ?>" method="get" data-ajax-form data-ajax-target="<?= e($target) ?>" data-history="<?= e($historyPath ?? $path) ?>">
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

function e(mixed $value): string
{
    return Core::e($value);
}

function html_attributes(array $attributes): string
{
    $html = '';

    foreach ($attributes as $name => $value) {
        $name = (string) $name;

        if ($name === '' || $value === false || $value === null) {
            continue;
        }

        if (!preg_match('/^[A-Za-z_:][A-Za-z0-9_:\-.]*$/', $name)) {
            throw new InvalidArgumentException('Invalid HTML attribute: ' . $name);
        }

        $html .= $value === true
            ? ' ' . $name
            : ' ' . $name . '="' . e($value) . '"';
    }

    return $html;
}

function h(mixed $value): string
{
    return Core::e($value);
}

function t(string $key, array $replace = [], ?string $locale = null): string
{
    return Core::t($key, $replace, $locale);
}

function et(string $key, array $replace = [], ?string $locale = null): string
{
    return e(t($key, $replace, $locale));
}

function locale(?string $locale = null): string
{
    return Core::locale($locale);
}

function language_code(string $code): string
{
    $code = strtolower(trim(str_replace('_', '-', $code)));

    return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $code) ? $code : '';
}

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
    return $defaults;
}

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

    $path = base_path('lang/' . $code . '.json');

    if (is_file($path)) {
        $data = json_decode((string) file_get_contents($path), true);

        if (is_array($data) && !empty($data['install']['language_label'])) {
            return (string) $data['install']['language_label'];
        }
    }

    return (string) ($default ?? strtoupper($code));
}

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
        $directory = base_path('lang');

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

function language_packages(): array
{
    $directory = base_path('lang');
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
    $items = $defaults;
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

function timezone_preset_label(string $timezone, ?string $label = null): string
{
    $date = new DateTimeImmutable('now', new DateTimeZone($timezone));
    $offset = $date->format('P');

    return '(UTC' . ($offset === '+00:00' ? '' : $offset) . ') ' . ($label ?: $timezone);
}

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

function timezone(): DateTimeZone
{
    return Core::timezone();
}

function now(?string $format = null): DateTimeImmutable|string
{
    return Core::now($format);
}

function datetime(mixed $value = null, ?string $format = null, ?bool $relative = null): string
{
    if ($relative === true || ($relative === null && $format === null && (bool) config('datetime.relative', false))) {
        return relative_time($value);
    }

    return Core::dateTime($value, $format);
}

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

function relative_plural_form(int $count): string
{
    $language = strtolower(strtok(locale(), '-_') ?: 'en');

    if (in_array($language, ['cs', 'sk'], true)) {
        return $count === 1 ? 'one' : ($count >= 2 && $count <= 4 ? 'few' : 'many');
    }

    return $count === 1 ? 'one' : 'many';
}

function date_value(mixed $value = null, ?string $format = null): string
{
    return Core::dateValue($value, $format);
}

function date_iso(mixed $value = null): string
{
    return Core::dateIso($value);
}

function date_db(mixed $value = null): string
{
    return Core::dateDb($value);
}

function slug(string $text, string $separator = '-'): string
{
    return Core::slug($text, $separator);
}

function redirect(string $url, int $status = 302): never
{
    Core::redirect($url, $status);
}

function capture(callable $callback): string
{
    return Core::capture($callback);
}

function render(string $template, array $data = [], ?string $directory = null): string
{
    return Core::render($template, $data, $directory);
}

function view(string $template, array $data = [], ?string $directory = null): string
{
    return Core::render($template, $data, $directory);
}

function part(string $template, array $data = []): string
{
    return trim(render('parts/' . trim($template, '/'), $data));
}

function layout(string $template, array $data = [], mixed $content = null, ?string $directory = null): void
{
    Core::layout($template, $data, $content, $directory);
}

function json(mixed $data, int $status = 200): never
{
    Core::json($data, $status);
}

function api(mixed $data = null, ?string $message = null, int $status = 200, array $meta = []): never
{
    Core::apiOk($data, $message, $status, $meta);
}

function api_ok(mixed $data = null, ?string $message = null, int $status = 200, array $meta = []): never
{
    Core::apiOk($data, $message, $status, $meta);
}

function api_payload(array $data, array|callable|string|null $html = null): array
{
    if (!wants_partial()) {
        return $data;
    }

    if (is_callable($html)) {
        return (array) $html($data);
    }

    if (is_array($html)) {
        return $html;
    }

    return ['html' => (string) $html];
}

function api_created(mixed $data = null, ?string $message = 'Created.', array $meta = []): never
{
    Core::apiCreated($data, $message, $meta);
}

function api_error(string $message = 'Request failed.', int $status = 400, string $code = 'error', array $details = []): never
{
    Core::apiError($message, $status, $code, $details);
}

function api_validation(array $errors, string $message = 'Validation failed.'): never
{
    Core::apiValidation($errors, $message);
}

function api_endpoint(array|string $methods, callable $handler): never
{
    Core::apiEndpoint($methods, $handler);
}

function route(array|string $methods, string $path, callable $handler): void
{
    Core::route($methods, $path, $handler);
}

function api_route(array|string $methods, string $path, callable $handler): void
{
    Core::apiRoute($methods, $path, $handler);
}

function dispatch_routes(?string $path = null, ?string $method = null): bool
{
    return Core::dispatch($path, $method);
}

function autoroute(?string $path = null, ?string $directory = null): bool
{
    return Core::autoroute($path, $directory);
}

function route_path(?string $path = null): string
{
    return Core::path($path);
}

function body(?string $key = null, mixed $default = null): mixed
{
    return Core::payload($key, $default);
}

function payload(?string $key = null, mixed $default = null): mixed
{
    return Core::payload($key, $default);
}

function input(?string $key = null, mixed $default = null): mixed
{
    return Core::input($key, $default);
}

function request(?string $key = null, mixed $default = null): mixed
{
    return Core::request($key, $default);
}

function validate(array $data, array $rules, array $messages = []): array
{
    return Core::validate($data, $rules, $messages);
}

function api_validated(array $rules, ?array $data = null, array $messages = []): array
{
    return Core::validated($rules, $data, $messages);
}

function wants_json(): bool
{
    return Core::wantsJson();
}

function wants_partial(): bool
{
    return Core::wantsPartial();
}

function get(?string $key = null, mixed $default = null): mixed
{
    if ($key === null) {
        return $_GET;
    }

    return $_GET[$key] ?? $default;
}

function post(?string $key = null, mixed $default = null): mixed
{
    if ($key === null) {
        return $_POST;
    }

    return $_POST[$key] ?? $default;
}

function method(): string
{
    return Core::method();
}

function is_post(): bool
{
    return Core::isPost();
}

function flash(string $key, mixed $value = null): mixed
{
    if (func_num_args() === 2) {
        return Core::flash($key, $value);
    }

    return Core::flash($key);
}

function auth(?string $key = null, mixed $default = null): mixed
{
    return Core::auth($key, $default);
}

function auth_check(): bool
{
    return Core::authCheck();
}

function auth_attempt(array $credentials): bool
{
    $password = $credentials['password'] ?? null;

    if (is_string($password) && auth_password_too_long($password)) {
        return false;
    }

    return Core::authAttempt($credentials);
}

function auth_login(array|int|string $user, bool $remember = false): bool
{
    return Core::authLogin($user, $remember);
}

function auth_logout(): void
{
    Core::authLogout();
}

function require_auth(?string $redirect = null): array
{
    return Core::requireAuth($redirect);
}

function require_role(array|string $roles, ?string $redirect = null): array
{
    $user = require_auth($redirect);

    if (auth_is($roles)) {
        return $user;
    }

    if (str_starts_with(route_path(), '/api') || wants_json()) {
        api_error(t('auth.forbidden'), 403, 'forbidden');
    }

    flash('error', t('auth.forbidden'));
    redirect($redirect ?? '/login');
}

function require_admin(?string $redirect = null): array
{
    return require_role('admin', $redirect);
}

function auth_is(array|string $roles): bool
{
    return Core::authIs($roles);
}

function auth_password(string $password): string
{
    return Core::authPassword($password);
}

function csrf_token(): string
{
    return Core::csrfToken();
}

function csrf_field(): string
{
    return Core::csrfField();
}

function csrf_require(?string $token = null): void
{
    Core::requireCsrf($token);
}

function captcha_field(string $context = 'form'): string
{
    return Core::captchaField($context);
}

function captcha_check(string $context = 'form'): bool
{
    return Core::captchaCheck($context);
}

function captcha_refresh(string $context = 'form'): string
{
    return Core::captchaRefresh($context);
}
