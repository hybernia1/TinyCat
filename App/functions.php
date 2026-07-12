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
        return ['users', 'content', 'terms', 'content_tags', 'links', 'content_links', 'content_likes', 'content_comments', 'comment_likes', 'user_followers', 'notifications', 'content_reports', 'user_action_limits', 'settings'];
    }
}

if (!function_exists('site_name')) {
    function site_name(): string
    {
        return (string) config('site.name', 'TinyCat');
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

if (!function_exists('status_meta_title')) {
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
}

if (!function_exists('status_meta_link_title')) {
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
}

if (!function_exists('status_link_title_is_placeholder')) {
    function status_link_title_is_placeholder(string $title): bool
    {
        return in_array(strtolower(trim($title)), [
            'youtube video',
            'vimeo video',
            'dailymotion video',
        ], true);
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
        return user_avatar_url($item) ?: site_meta_image_url();
    }
}

if (!function_exists('avatar_url')) {
    function avatar_url(string $username, array|string|null $config = null): string
    {
        return Avatar::url($username, $config);
    }
}

if (!function_exists('user_avatar_url')) {
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

if (!function_exists('user_public_payload')) {
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
}

if (!function_exists('theme_choices')) {
    function theme_choices(): array
    {
        return [
            'system' => t('account.theme_system'),
            'light' => t('account.theme_light'),
            'dark' => t('account.theme_dark'),
        ];
    }
}

if (!function_exists('theme_normalize')) {
    function theme_normalize(string $theme): string
    {
        $theme = strtolower(trim($theme));

        return in_array($theme, ['system', 'light', 'dark'], true) ? $theme : 'system';
    }
}

if (!function_exists('user_theme')) {
    function user_theme(?array $user): string
    {
        return theme_normalize((string) ($user['theme'] ?? 'system'));
    }
}

if (!function_exists('theme_options')) {
    function theme_options(string $selected = 'system'): string
    {
        $selected = theme_normalize($selected);
        $html = '';

        foreach (theme_choices() as $value => $label) {
            $html .= '<option value="' . e($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . e($label) . '</option>';
        }

        return $html;
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
}

if (!function_exists('image_apply_orientation')) {
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
}

if (!function_exists('auth_account_url')) {
    function auth_account_url(): string
    {
        return '/account';
    }
}

if (!function_exists('auth_landing_url')) {
    function auth_landing_url(?array $user = null): string
    {
        $user ??= auth();

        if ($user !== null && (string) ($user['role'] ?? '') === 'admin') {
            return '/admin';
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

if (!function_exists('auth_normalize_next_url')) {
    function auth_normalize_next_url(string $next): string
    {
        $fragment = (string) (parse_url($next, PHP_URL_FRAGMENT) ?: '');

        if (preg_match('/^status-(?:comments-thread-)?([1-9][0-9]*)$/', $fragment, $match) === 1) {
            return '/status/' . $match[1];
        }

        return $next;
    }
}

if (!function_exists('auth_safe_next_url')) {
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

if (!function_exists('auth_request_next_url')) {
    function auth_request_next_url(): string
    {
        $next = auth_safe_next_url((string) input('next', ''));

        if ($next !== '') {
            return $next;
        }

        return auth_referer_next_url();
    }
}

if (!function_exists('auth_redirect_after_login')) {
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
}

if (!function_exists('auth_login_request')) {
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
}

if (!function_exists('auth_password_max_length')) {
    function auth_password_max_length(): int
    {
        return 1024;
    }
}

if (!function_exists('auth_password_too_long')) {
    function auth_password_too_long(string $password): bool
    {
        return strlen($password) > auth_password_max_length();
    }
}

if (!function_exists('registration_url')) {
    function registration_url(string $next = ''): string
    {
        $next = auth_safe_next_url($next);

        return '/register' . ($next !== '' ? '?next=' . rawurlencode($next) : '');
    }
}

if (!function_exists('registration_request')) {
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
            'note' => '',
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

if (!function_exists('user_profile_update_request')) {
    function user_profile_update_request(array $user): array
    {
        $id = (int) ($user['id'] ?? 0);
        $bio = plain_text_limit((string) post('bio', ''), 500);
        $locale = language_code((string) post('locale', ''));
        $theme = theme_normalize((string) post('theme', 'system'));
        $errors = [];

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

        locale($locale);

        return [
            'user' => user_public_payload(auth() ?: $user),
            'message' => t('account.messages.profile_saved'),
            'redirect' => author_url($id),
        ];
    }
}

if (!function_exists('user_avatar_update_request')) {
    function user_avatar_update_request(array $user): array
    {
        $id = (int) ($user['id'] ?? 0);

        if ($id < 1) {
            api_error(t('auth.login_required'), 401, 'unauthorized', ['redirect' => '/login']);
        }

        $file = $_FILES['avatar'] ?? null;

        if (!is_array($file)) {
            api_error(t('account.messages.avatar_required'), 422, 'avatar_required');
        }

        try {
            $oldConfig = $user['avatar_config'] ?? null;
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
}

if (!function_exists('author_url')) {
    function author_url(int $id): string
    {
        return $id > 0 ? '/author/' . $id : '/';
    }
}

if (!function_exists('author_api_url')) {
    function author_api_url(int $id, string $action = 'follow', array $params = []): string
    {
        $query = ['author_id' => $id] + $params;

        return '/api/author/' . rawurlencode($action) . '?' . http_build_query($query);
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

        return ($now->getTimestamp() - $seen->getTimestamp()) <= 300;
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

if (!function_exists('status_internal_url_path')) {
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
}

if (!function_exists('status_external_url_pattern')) {
    function status_external_url_pattern(): string
    {
        return '~(?<![@\p{L}\p{N}_])(?:https?://|www\.)[^\s<>"\']+~iu';
    }
}

if (!function_exists('status_strip_external_urls')) {
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
}

if (!function_exists('moderation_blocked_url_hosts')) {
    function moderation_blocked_url_hosts(?string $value = null): array
    {
        return moderation_blocked_url_rules($value);
    }
}

if (!function_exists('moderation_blocked_url_rules')) {
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
}

if (!function_exists('moderation_blocked_url_rule')) {
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
}

if (!function_exists('moderation_url_host')) {
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
}

if (!function_exists('moderation_blocked_url_match')) {
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
}

if (!function_exists('moderation_require_allowed_urls')) {
    function moderation_require_allowed_urls(string $text): void
    {
        $blockedHost = moderation_blocked_url_match($text);

        if ($blockedHost !== '') {
            api_error(t('moderation.messages.blocked_url', ['host' => $blockedHost]), 422, 'blocked_url', ['host' => $blockedHost]);
        }
    }
}

if (!function_exists('moderation_text_contains_blocked_host')) {
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
}

if (!function_exists('moderation_host_is_blocked')) {
    function moderation_host_is_blocked(string $host, array $blockedHosts): bool
    {
        return moderation_host_blocked_by($host, $blockedHosts) !== '';
    }
}

if (!function_exists('moderation_host_blocked_by')) {
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
}

if (!function_exists('author_mention_map')) {
    function author_mention_map(): array
    {
        static $map = null;

        if ($map !== null) {
            return $map;
        }

        $map = [];

        foreach (author_mention_users() as $user) {
            $handle = (string) ($user['handle'] ?? '');
            $id = (int) ($user['id'] ?? 0);

            if ($handle !== '' && $id > 0 && !isset($map[$handle])) {
                $map[$handle] = $id;
            }
        }

        return $map;
    }
}

if (!function_exists('author_mention_user_cache')) {
    function &author_mention_user_cache(): array
    {
        static $users = [];

        return $users;
    }
}

if (!function_exists('author_mention_users')) {
    function author_mention_users(): array
    {
        static $loaded = false;
        $users =& author_mention_user_cache();

        if ($loaded) {
            return $users;
        }

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

        $loaded = true;

        return $users;
    }
}

if (!function_exists('author_mention_users_by_ids')) {
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
}

if (!function_exists('status_author_url_mention')) {
    function status_author_url_mention(string $url): string
    {
        $path = status_internal_url_path($url);

        if ($path === '' || preg_match('~^/author/([0-9]+)/?$~', $path, $match) !== 1) {
            return '';
        }

        $authorId = (int) ($match[1] ?? 0);

        return $authorId > 0 && isset(author_mention_users_by_ids([$authorId])[$authorId]) ? '@' . $authorId : '';
    }
}

if (!function_exists('normalize_author_urls_for_storage')) {
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
}

if (!function_exists('normalize_mentions_for_storage')) {
    function normalize_mentions_for_storage(string $text): string
    {
        $text = normalize_author_urls_for_storage($text);
        $map = author_mention_map();
        $users = author_mention_users();
        $pattern = '/(?<![A-Za-z0-9_])@([0-9]+|[a-z][a-z0-9_]{2,31})/i';

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

if (!function_exists('mentions_for_editing')) {
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
}

if (!function_exists('render_mentions')) {
    function render_mentions(string $text): string
    {
        return render_mentions_segment(status_strip_external_urls($text));
    }
}

if (!function_exists('render_status_text')) {
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
}

if (!function_exists('render_status_body')) {
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
}

if (!function_exists('render_mentions_segment')) {
    function render_mentions_segment(string $text): string
    {
        $pattern = '/(?<![\\p{L}\\p{N}_])([@#])([\\p{L}\\p{N}][\\p{L}\\p{N}_-]{0,79})/u';
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
}

if (!function_exists('status_url_split_tail')) {
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
                u.avatar_config AS author_avatar_config,
                u.bio AS author_bio,
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
        status_preload_links($ids);
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
                u.avatar_config,
                u.bio,
                COUNT(*) AS posts_count,
                MAX(c.published_at) AS latest_at
            FROM content c' . $feedIndex . '
            INNER JOIN users u ON u.id = c.author_id'
        )
            ->where('c.published_at >= ?', date_db('-' . $days . ' days'))
            ->where('u.status = ?', 'active')
            ->group('u.id, u.username, u.avatar_config, u.bio')
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

        return part('sidebar', [
            'active_tag' => $activeTag,
            'tags' => $tags,
            'authors' => $authors,
            'needs_refresh' => $needsRefresh,
            'sidebar_url' => $sidebarUrl,
        ]);
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
            'avatar_url' => user_avatar_url($item),
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
                u.username AS author_username,
                u.avatar_config AS author_avatar_config
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

if (!function_exists('public_search_link_content_rows')) {
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
                        u.username AS author_username,
                        u.avatar_config AS author_avatar_config
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
                // Link search below can still provide useful results.
            }
        }

        if (count($recent) >= $limit) {
            return $recent;
        }

        $existingIds = array_values(array_filter(array_map(
            static fn (array $item): int => (int) ($item['id'] ?? 0),
            $recent
        ), static fn (int $id): bool => $id > 0));

        foreach (public_search_link_content_rows($query, $limit - count($recent), $existingIds) as $item) {
            $recent[] = $item;

            if (count($recent) >= $limit) {
                break;
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

if (!function_exists('status_editor_suggestions')) {
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
}

if (!function_exists('status_term_ids_for_content')) {
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
}

if (!function_exists('status_cleanup_unused_term_ids')) {
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
}

if (!function_exists('status_links_from_text')) {
    function status_links_from_text(string $text): array
    {
        return array_values(array_filter(
            StatusLinks::extract($text),
            static fn (array $link): bool => !status_link_is_internal($link)
        ));
    }
}

if (!function_exists('status_link_is_internal')) {
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
}

if (!function_exists('status_link_metadata_ttl')) {
    function status_link_metadata_ttl(): int
    {
        return 86400;
    }
}

if (!function_exists('status_link_metadata_cache')) {
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
}

if (!function_exists('status_link_data')) {
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
}

if (!function_exists('status_link_find_by_hash')) {
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
}

if (!function_exists('status_link_upsert')) {
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
}

if (!function_exists('status_link_metadata_fresh')) {
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
}

if (!function_exists('status_link_metadata_has_content')) {
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

        return $title !== '' || $description !== '';
    }
}

if (!function_exists('status_link_apply_cached_metadata')) {
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
}

if (!function_exists('status_link_prepare_metadata')) {
    function status_link_prepare_metadata(array $link, ?array $cached): array
    {
        if ($cached !== null && status_link_metadata_fresh($cached)) {
            return status_link_apply_cached_metadata($link, $cached, true);
        }

        $enriched = LinkMetadata::enrich($link);
        $enriched['_metadata_updated_at'] = date_db();

        if (!empty($enriched['_metadata_fetched']) || $cached === null) {
            return $enriched;
        }

        return status_link_apply_cached_metadata($enriched, $cached, false);
    }
}

if (!function_exists('status_sync_links')) {
    function status_sync_links(int $contentId, array $links): void
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

            $link = status_link_prepare_metadata($link, $metadataCache[$hash] ?? null);
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
}

if (!function_exists('status_link_ids_for_content')) {
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
}

if (!function_exists('status_cleanup_unused_link_ids')) {
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
}

if (!function_exists('status_cleanup_unused_links')) {
    function status_cleanup_unused_links(): void
    {
        try {
            run(
                'DELETE l
                FROM links l
                LEFT JOIN content_links cl ON cl.link_id = l.id
                WHERE cl.link_id IS NULL'
            );
        } catch (Throwable) {
            // Cleanup is opportunistic; a failed cleanup must not block posting.
        }
    }
}

if (!function_exists('status_links_cache')) {
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
}

if (!function_exists('status_preload_links')) {
    function status_preload_links(array $contentIds): void
    {
        status_links_cache($contentIds);
    }
}

if (!function_exists('status_links_for_content')) {
    function status_links_for_content(int $contentId): array
    {
        $links = status_links_cache([$contentId]);

        return $links[$contentId] ?? [];
    }
}

if (!function_exists('status_link_display_url')) {
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
}

if (!function_exists('status_video_embed_allowed')) {
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
}

if (!function_exists('status_video_embed_url')) {
    function status_video_embed_url(array $link): string
    {
        $provider = (string) ($link['provider'] ?? '');
        $videoId = trim((string) ($link['video_id'] ?? ''));

        if ($provider === 'youtube' && $videoId !== '') {
            return 'https://www.youtube.com/embed/' . rawurlencode($videoId);
        }

        return (string) ($link['embed_url'] ?? '');
    }
}

if (!function_exists('status_video_thumbnail_url')) {
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
}

if (!function_exists('status_link_card_html')) {
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
}

if (!function_exists('status_links_html')) {
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

if (!function_exists('status_api_url')) {
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
        $focus = in_array($focus, ['locale', 'theme', 'bio'], true) ? $focus : '';
        $query = ['author_id' => max(0, $authorId)];

        if ($focus !== '') {
            $query['focus'] = $focus;
        }

        return '/api/profile-edit-modal?' . http_build_query($query);
    }
}

if (!function_exists('author_avatar_edit_modal_id')) {
    function author_avatar_edit_modal_id(int $authorId): string
    {
        return 'avatar-edit-modal-' . max(0, $authorId);
    }
}

if (!function_exists('author_avatar_edit_modal_url')) {
    function author_avatar_edit_modal_url(int $authorId): string
    {
        return '/api/avatar-edit-modal?' . http_build_query(['author_id' => max(0, $authorId)]);
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
        return part('status/field', ['item' => $item]);
    }
}

if (!function_exists('status_composer')) {
    function status_composer(string $action, array $user): string
    {
        return part('status/composer', [
            'action' => $action,
            'user' => $user,
        ]);
    }
}

if (!function_exists('notification_icon')) {
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
            'content_mention' => t('notifications.messages.content_mention', ['actor' => $actor]),
            'comment_mention' => t('notifications.messages.comment_mention', ['actor' => $actor]),
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

if (!function_exists('notification_mentioned_user_ids')) {
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
}

if (!function_exists('notification_create_for_mentions')) {
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
                u.username AS actor_username,
                u.avatar_config AS actor_avatar_config,
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
            $avatarUrl = user_avatar_url($notification);
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

if (!function_exists('notifications_apply_action')) {
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
}

if (!function_exists('notification_item_html')) {
    function notification_item_html(array $notification): string
    {
        $id = (int) ($notification['id'] ?? 0);
        $isUnread = trim((string) ($notification['read_at'] ?? '')) === '';
        $actorName = trim((string) ($notification['actor_name'] ?? ''));
        $avatarUrl = user_avatar_url($notification);
        $createdAt = (string) ($notification['created_at'] ?? '');
        $contentText = meta_text((string) ($notification['content_body'] ?? ''), 120);
        $url = notification_url($notification);

        ob_start();
        ?>
        <article class="notification-item<?= $isUnread ? ' is-unread' : '' ?>">
            <a class="notification-main" href="<?= e($url) ?>">
                <span class="notification-avatar">
                    <?php if ($avatarUrl !== ''): ?>
                        <img src="<?= e($avatarUrl) ?>" alt="<?= e($actorName) ?>" loading="lazy">
                    <?php else: ?>
                        <?= icon(notification_icon((string) ($notification['type'] ?? ''))) ?>
                    <?php endif; ?>
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
}

if (!function_exists('notifications_page_html')) {
    function notifications_page_html(array $notifications, int $unread): string
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
                <div class="notifications-list">
                    <?php if ($notifications === []): ?>
                        <div class="notification-empty"><?= icon('bell') ?> <span><?= et('notifications.empty') ?></span></div>
                    <?php else: ?>
                        <?php foreach ($notifications as $notification): ?>
                            <?= notification_item_html($notification) ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </article>
        </section>
        <?php

        return trim((string) ob_get_clean());
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
}

if (!function_exists('notification_badge_text')) {
    function notification_badge_text(int $count): string
    {
        return $count > 99 ? '99+' : (string) max(0, $count);
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
        $body = plain_text_limit((string) input('comment', ''), 2000);
        moderation_require_allowed_urls($body);
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
        $body = plain_text_limit((string) input('body', ''), 2000);
        moderation_require_allowed_urls($body);

        $body = normalize_mentions_for_storage($body);
        $body = normalize_tags_for_storage($body);
        $body = plain_text_limit($body, 2000);

        return [
            'body' => $body,
            'tags' => status_tags_from_text($body),
            'links' => status_links_from_text($body),
        ];
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

if (!function_exists('status_delete_content')) {
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
}

if (!function_exists('status_actions')) {
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
}

if (!function_exists('status_manage_actions')) {
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
}

if (!function_exists('status_card')) {
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
}

if (!function_exists('status_json_report')) {
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
}

if (!function_exists('status_json_update')) {
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
}

if (!function_exists('status_json_delete')) {
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
}

if (!function_exists('public_home_feed_api_url')) {
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
}

if (!function_exists('public_home_feed_payload')) {
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
    function status_feed_next_url(string $context, int $offset, int $limit, array $params = [], bool $html = true): string
    {
        $query = array_merge($params, [
            'context' => $context,
            'offset' => max(0, $offset),
            'limit' => max(1, min(50, $limit)),
        ]);

        if ($html) {
            $query['view'] = 'html';
        }

        return '/api/status-feed?' . http_build_query($query);
    }
}

if (!function_exists('status_feed_payload')) {
    function status_feed_payload(string $context, int $limit, int $offset, array $params = [], ?array $user = null): array
    {
        $feed = status_feed_context_items($context, $limit, $offset, $params, $user);
        $items = (array) ($feed['items'] ?? []);
        $action = (string) ($feed['action'] ?? '/');
        $count = count($items);
        $nextOffset = $offset + $count;
        $done = $count < $limit;
        $data = [
            'context' => $context,
            'items' => $items,
            'count' => $count,
            'offset' => $offset,
            'next_offset' => $nextOffset,
            'done' => $done,
            'next_url' => $done ? '' : status_feed_next_url($context, $nextOffset, $limit, $params, false),
        ];

        return api_payload($data, static fn (): array => [
            'html' => status_feed_html($items, $action, $user),
            'count' => $count,
            'offset' => $offset,
            'next_offset' => $nextOffset,
            'done' => $done,
            'next_url' => $done ? '' : status_feed_next_url($context, $nextOffset, $limit, $params, true),
        ]);
    }
}

if (!function_exists('status_feed_more_control')) {
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
}

if (!function_exists('status_comments_section')) {
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
}

if (!function_exists('status_comment_thread_section')) {
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
}

if (!function_exists('status_comment_form')) {
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
}

if (!function_exists('status_comment_delete_form')) {
    function status_comment_delete_form(int $commentId, string $action, int $contentId = 0): string
    {
        return part('status/comment-delete-form', [
            'comment_id' => $commentId,
            'action' => $action,
            'content_id' => $contentId,
        ]);
    }
}

if (!function_exists('status_comment_like_control')) {
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
            $next = auth_safe_next_url($next . $fragment) ?: $next;
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

        $item['body'] = mentions_for_editing((string) ($item['body'] ?? ''));

        return render('modals/status-edit', [
            'item' => $item,
            'action' => $action,
        ]);
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
            'orphan_content_links' => [
                'icon' => 'link',
                'label' => t('maintenance.tasks.orphan_content_links'),
                'description' => t('maintenance.tasks.orphan_content_links_help'),
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
            'orphan_content_links' => (int) val(
                'SELECT COUNT(*)
                FROM content_links cl
                LEFT JOIN content c ON c.id = cl.content_id
                LEFT JOIN links l ON l.id = cl.link_id
                WHERE c.id IS NULL OR l.id IS NULL'
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
            'orphan_content_links' => maintenance_cleanup_delete_orphan_content_links($batchSize),
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

if (!function_exists('maintenance_cleanup_delete_orphan_content_links')) {
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
}

if (!function_exists('admin_per_page')) {
    function admin_per_page(?int $value = null): int
    {
        $options = admin_per_page_options();
        $default = 25;
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
}

if (!function_exists('admin_per_page_control')) {
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
    function t(string $key, array $replace = [], ?string $locale = null): string
    {
        return Core::t($key, $replace, $locale);
    }
}

if (!function_exists('et')) {
    function et(string $key, array $replace = [], ?string $locale = null): string
    {
        return e(t($key, $replace, $locale));
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
        return $defaults;
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

        $path = base_path('lang/' . $code . '.json');

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
}

if (!function_exists('language_packages')) {
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

if (!function_exists('part')) {
    function part(string $template, array $data = []): string
    {
        return trim(render('parts/' . trim($template, '/'), $data));
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

if (!function_exists('api_payload')) {
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
        $password = $credentials['password'] ?? null;

        if (is_string($password) && auth_password_too_long($password)) {
            return false;
        }

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

        if (str_starts_with(route_path(), '/api') || wants_json()) {
            api_error(t('auth.forbidden'), 403, 'forbidden');
        }

        flash('error', t('auth.forbidden'));
        redirect($redirect ?? '/login');
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
