<?php
declare(strict_types=1);
require __DIR__ . '/src/Feed.php';

function parameter(string $key, string $default): string
{
    if (isset($_GET[$key]) && !is_string($_GET[$key])) { throw new InvalidArgumentException("Neplatný parametr $key."); }
    return $_GET[$key] ?? $default;
}

try {
    $config = require __DIR__ . (is_file(__DIR__ . '/config.php') ? '/config.php' : '/config.example.php');
    $accessToken = (string) ($config['access_token'] ?? '');
    if ($accessToken !== '' && (!is_string($_GET['token'] ?? null) || !hash_equals($accessToken, $_GET['token']))) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        echo 'Neplatný nebo chybějící přístupový token.';
        exit;
    }
    header('Referrer-Policy: no-referrer');
    $path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');
    $base = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    if ($path === $base) { $path = ''; }
    if ($base !== '/' && $base !== '.' && str_starts_with($path, $base . '/')) { $path = substr($path, strlen($base)); }
    $routes = [];
    // Only trusted local modules are loaded; request paths never become filenames.
    foreach (glob(__DIR__ . '/modules/*/module.php') ?: [] as $file) {
        $module = basename(dirname($file));
        foreach (require $file as $resource => $handler) {
            $route = '/' . $module . '/' . $resource;
            if (!is_callable($handler) || isset($routes[$route])) { throw new LogicException('Neplatný modul feedu.'); }
            $routes[$route] = $handler;
        }
    }
    if (!isset($routes[$path])) {
        http_response_code($path === '' ? 200 : 404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Dostupné zdroje:\n" . implode("\n", array_keys($routes)) . "\n";
        exit;
    }
    $result = $routes[$path]($config);
    if ($result['type'] === 'rss') {
        $body = Feed::document($result['data']);
        $type = 'application/rss+xml';
    } elseif ($result['type'] === 'json') {
        $body = json_encode($result['data'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $type = 'application/json';
    } else { throw new LogicException('Neplatný formát odpovědi modulu.'); }
    header('Content-Type: ' . $type . '; charset=utf-8');
    header($accessToken !== '' ? 'Cache-Control: private, no-store' : 'Cache-Control: public, max-age=300');
    echo $body;
} catch (Throwable $error) {
    http_response_code($error instanceof InvalidArgumentException ? 400 : 502);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $error instanceof RuntimeException || $error instanceof InvalidArgumentException
        ? $error->getMessage() : 'Feed se nepodařilo vytvořit. Zkontrolujte konfiguraci a PHP rozšíření cURL.';
}
