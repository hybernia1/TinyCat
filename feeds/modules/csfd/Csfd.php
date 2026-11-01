<?php
declare(strict_types=1);

final class Csfd
{
    public static function parse(string $html): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new DOMDocument();
            if (!$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                throw new RuntimeException('ČSFD vrátilo neplatné HTML.');
            }
        } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        $xpath = new DOMXPath($dom);
        $items = [];
        foreach ($xpath->query('//article[contains(concat(" ", normalize-space(@class), " "), " article-news ")]') as $article) {
            $link = $xpath->query('.//header//h3/a', $article)->item(0);
            if (!$link instanceof DOMElement) { continue; }
            $href = $link->getAttribute('href');
            if (!preg_match('~^/novinky/([0-9]+)-[a-zA-Z0-9-]+/$~D', $href, $match)) { continue; }
            $excerpt = $xpath->query('.//div[contains(concat(" ", normalize-space(@class), " "), " article-news-textshort ")]', $article)->item(0);
            if (!$excerpt) { continue; }
            foreach ($xpath->query('.//span[contains(concat(" ", normalize-space(@class), " "), " span-more-small ")]', $excerpt) as $more) {
                $more->parentNode->removeChild($more);
            }
            $image = $xpath->query('.//figure//img', $article)->item(0);
            $src = $image instanceof DOMElement ? $image->getAttribute('src') : '';
            if (str_starts_with($src, '//')) { $src = 'https:' . $src; }
            if (!preg_match('~^https://image\.pmgstatic\.com/[A-Za-z0-9/_.%-]+$~D', $src)) { $src = ''; }
            $author = $xpath->query('.//span[contains(concat(" ", normalize-space(@class), " "), " author ")]/a', $article)->item(0);
            $items['csfd:news:' . $match[1]] = [
                'id' => 'csfd:news:' . $match[1], 'title' => Feed::text($link->textContent),
                'description' => Feed::text($excerpt->textContent), 'url' => 'https://www.csfd.cz' . $href,
                'image_url' => $src, 'categories' => ['Filmové novinky'],
                'author' => $author ? Feed::text($author->textContent) : '',
            ];
            if (count($items) >= 20) { break; }
        }
        if ($items === []) { throw new RuntimeException('ČSFD nevrátilo novinky: změna HTML nebo blokování přístupu.'); }
        return array_values($items);
    }

    public static function news(array $config): array
    {
        return self::load($config, 'news', 'https://www.csfd.cz/novinky/', [self::class, 'parse']);
    }

    public static function premieres(array $config): array
    {
        return self::load($config, 'premieres-' . (new DateTimeImmutable('now', new DateTimeZone('Europe/Prague')))->format('Y-m'),
            'https://www.csfd.cz/kino/premiery/', [self::class, 'parsePremieres']);
    }

    public static function parsePremieres(string $html): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new DOMDocument();
            if (!$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                throw new RuntimeException('ČSFD vrátilo neplatné HTML.');
            }
        } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        $xpath = new DOMXPath($dom);
        $items = [];
        foreach ($xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " updated-box-content ")]') as $group) {
            $heading = $xpath->query('./div[contains(concat(" ", normalize-space(@class), " "), " update-box-sub-header ")]', $group)->item(0);
            if (!$heading || !preg_match('~v kinech od\s+(\d{1,2})\.(\d{1,2})\.(\d{4})~u', Feed::text($heading->textContent), $date)) { continue; }
            if (!checkdate((int)$date[2], (int)$date[1], (int)$date[3])) { continue; }
            $day = sprintf('%04d-%02d-%02d', $date[3], $date[2], $date[1]);
            foreach ($xpath->query('.//article', $group) as $article) {
                $link = $xpath->query('.//h3/a[contains(concat(" ", normalize-space(@class), " "), " film-title-name ")]', $article)->item(0);
                if (!$link instanceof DOMElement || !preg_match('~^/film/([0-9]+)-[a-zA-Z0-9-]+/(?:prehled/)?$~D', $link->getAttribute('href'), $film)) { continue; }
                $title = Feed::text($link->textContent);
                if ($title === '') { continue; }
                $description = ['V kinech od ' . (int)$date[1] . '.' . (int)$date[2] . '.' . $date[3] . '.'];
                foreach ($xpath->query('.//span[contains(concat(" ", normalize-space(@class), " "), " bullet ")]', $article) as $bullet) {
                    $bullet->appendChild($dom->createTextNode(' / '));
                }
                foreach ($xpath->query('.//p[not(ancestor::figure)]', $article) as $paragraph) {
                    $text = Feed::text($paragraph->textContent);
                    if ($text !== '') { $description[] = $text; }
                }
                $image = $xpath->query('.//figure//img', $article)->item(0);
                $src = $image instanceof DOMElement ? $image->getAttribute('src') : '';
                if (str_starts_with($src, '//')) { $src = 'https:' . $src; }
                if (!preg_match('~^https://image\.pmgstatic\.com/[A-Za-z0-9/_.%-]+$~D', $src)) { $src = ''; }
                $id = 'csfd:premieres:' . $film[1] . ':' . $day;
                $items[$id] = ['id' => $id, 'title' => $title, 'description' => implode(' ', $description),
                    'url' => 'https://www.csfd.cz' . $link->getAttribute('href'), 'image_url' => $src,
                    'categories' => ['Kino premiéry']];
            }
        }
        if ($items === []) { throw new RuntimeException('ČSFD nevrátilo premiéry: prázdný seznam, změna HTML nebo blokování přístupu.'); }
        return array_values($items);
    }

    private static function load(array $config, string $key, string $url, callable $parser): array
    {
        $directory = dirname(__DIR__, 2) . '/var/csfd';
        $file = $directory . '/' . $key . '.json';
        if (is_file($file) && filemtime($file) > time() - (int)($config['cache_seconds'] ?? 1800)) {
            $items = json_decode((string)file_get_contents($file), true);
            if (is_array($items) && $items !== [] && array_is_list($items)) { return $items; }
        }
        $curl = curl_init($url);
        $html = '';
        curl_setopt_array($curl, [CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'TinyCatFeeds/1.0', CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => ['Accept: text/html'],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$html): int {
                if (strlen($html) + strlen($chunk) > 2097152) { return 0; }
                $html .= $chunk;
                return strlen($chunk);
            }]);
        $ok = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($ok === false || $status !== 200) { throw new RuntimeException('ČSFD nelze načíst (HTTP ' . $status . ').'); }
        $items = $parser($html);
        if (!is_dir($directory)) { mkdir($directory, 0700, true); }
        $temporary = tempnam($directory, 'csfd-');
        if ($temporary !== false) {
            file_put_contents($temporary, json_encode($items, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX);
            if (!@rename($temporary, $file)) { @unlink($temporary); }
        }
        return $items;
    }
}
