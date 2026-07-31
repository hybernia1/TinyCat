<?php
declare(strict_types=1);

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');

echo "User-agent: *\n";
echo "Disallow: /admin\n";
echo "Disallow: /api\n";
echo "Disallow: /account\n";
echo "Disallow: /install\n";
echo "Disallow: /login\n";
echo "Disallow: /logout\n";
echo "Disallow: /notifications\n";
echo "Disallow: /recovery\n";
echo "Disallow: /register\n";
echo "Disallow: /search\n";
echo "Sitemap: " . absolute_url('/sitemap.xml') . "\n";
