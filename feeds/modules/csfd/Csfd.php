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
        $directory = dirname(__DIR__, 2) . '/var/csfd';
        $file = $directory . '/news.json';
        if (is_file($file) && filemtime($file) > time() - (int)($config['cache_seconds'] ?? 1800)) {
            $items = json_decode((string)file_get_contents($file), true);
            if (is_array($items) && $items !== [] && array_is_list($items)) { return $items; }
        }
        $curl = curl_init('https://www.csfd.cz/novinky/');
        $html = '';
        curl_setopt_array($curl, [CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
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
        $items = self::parse($html);
        if (!is_dir($directory)) { mkdir($directory, 0700, true); }
        $temporary = tempnam($directory, 'csfd-');
        if ($temporary !== false) {
            file_put_contents($temporary, json_encode($items, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX);
            if (!@rename($temporary, $file)) { @unlink($temporary); }
        }
        return $items;
    }
}
