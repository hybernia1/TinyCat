<?php
declare(strict_types=1);

require_once __DIR__ . '/../../support/mysql.php';
// Real multipart branding publication and SQL rollback through the registered API.
require_once $root . '/App/Core/Runtime/autoload.php';
if (!extension_loaded('gd') || !function_exists('imagewebp')) { echo "SKIP branding multipart: GD/WebP unavailable.\n"; return; }
$brandingDb = \tinycat_test_database($databasePath);
$brandingPaths = [];
$brandingBoundary = 'tinycat-branding-' . bin2hex(random_bytes(7));
$brandingHeaders = $csrf + ['Content-Type' => 'multipart/form-data; boundary=' . $brandingBoundary];
$brandingBody = static function (int $red, int $blue) use ($brandingBoundary): string {
    $canvas = imagecreatetruecolor(16, 12); imagefill($canvas, 0, 0, imagecolorallocate($canvas, $red, 0, $blue)); ob_start(); imagepng($canvas); $png = (string) ob_get_clean(); imagedestroy($canvas);
    return '--' . $brandingBoundary . "\r\nContent-Disposition: form-data; name=\"_method\"\r\n\r\nPATCH\r\n--" . $brandingBoundary . "\r\nContent-Disposition: form-data; name=\"settings[site.favicon_url]\"\r\n\r\n\r\n--" . $brandingBoundary . "\r\nContent-Disposition: form-data; name=\"settings_files[site.favicon_url]\"; filename=\"same-name.png\"\r\nContent-Type: image/png\r\n\r\n" . $png . "\r\n--" . $brandingBoundary . "--\r\n";
};
try {
    [$status] = $request('POST', '/api/settings', $brandingBody(255, 0), 'admin', $brandingHeaders);
    $firstPath = (string) $brandingDb->query("SELECT setting_value FROM settings WHERE setting_key = 'site.favicon_path'")->fetchColumn(); $brandingPaths[] = $firstPath;
    $firstFile = $root . '/uploads/site/' . $firstPath;
    $assert($status === 200 && preg_match('~^\d{4}/\d{2}/favicon-[a-f0-9]{32}\.webp$~D', $firstPath) === 1 && is_file($firstFile), 'Multipart branding publishes a unique immutable identity');
    $stem = substr($firstFile, 0, -5); $firstHash = hash_file('sha256', $firstFile);
    $assert(is_file($stem . '-32.png') && is_file($stem . '-maskable-512.png'), 'The committed favicon has its complete prepared variant set');
    $before = glob(dirname($firstFile) . '/*') ?: [];
    $brandingDb->exec("CREATE TRIGGER fail_branding BEFORE UPDATE ON settings FOR EACH ROW BEGIN IF NEW.setting_key = 'site.favicon_url' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected branding persistence failure'; END IF; END");
    [$status] = $request('POST', '/api/settings', $brandingBody(0, 255), 'admin', $brandingHeaders);
    $assert($status === 500 && hash_file('sha256', $firstFile) === $firstHash && (string) $brandingDb->query("SELECT setting_value FROM settings WHERE setting_key = 'site.favicon_path'")->fetchColumn() === $firstPath, 'SQL rollback cannot replace the previous branding contents or reference');
    $assert(array_diff(glob(dirname($firstFile) . '/*') ?: [], $before) === [], 'Failed branding persistence removes its entire newly prepared set');
    $brandingDb->exec('DROP TRIGGER fail_branding');
    [$status] = $request('POST', '/api/settings', $brandingBody(0, 255), 'admin', $brandingHeaders);
    $secondPath = (string) $brandingDb->query("SELECT setting_value FROM settings WHERE setting_key = 'site.favicon_path'")->fetchColumn(); $brandingPaths[] = $secondPath;
    $assert($status === 200 && $secondPath !== $firstPath && is_file($root . '/uploads/site/' . $secondPath) && hash_file('sha256', $firstFile) === $firstHash, 'Repeated same-name uploads publish distinct sets without overwriting earlier content');
    [$status] = $request('PATCH', '/api/settings', ['settings' => ['site.favicon_url' => '/uploads/site/' . $secondPath], 'settings_remove' => ['site.favicon_url' => 1]], 'admin', $csrf);
    $assert($status === 200 && $brandingDb->query("SELECT setting_value FROM settings WHERE setting_key = 'site.favicon_path'")->fetchColumn() === '', 'Branding removal atomically clears the authoritative reference');
} finally {
    $brandingDb->exec('DROP TRIGGER IF EXISTS fail_branding');
    $brandingDb->exec("UPDATE settings SET setting_value = '' WHERE setting_key IN ('site.favicon_path', 'site.favicon_url')");
    $connectionProperty = new ReflectionProperty(TinyCat\Database\Connection::class, 'pdo');
    $previousConnection = $connectionProperty->getValue(); TinyCat\Database\Connection::set($brandingDb);
    try { TinyCat\Core\Ui\Branding::deleteUnused(array_values(array_filter($brandingPaths))); }
    finally { $connectionProperty->setValue(null, $previousConnection); }
    $brandingDb = null;
}
