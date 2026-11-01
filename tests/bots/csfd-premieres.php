<?php
declare(strict_types=1);
require __DIR__ . '/../../feeds/src/Feed.php';
require __DIR__ . '/../../feeds/modules/csfd/Csfd.php';

function check(bool $ok): void {
    if (!$ok) { throw new RuntimeException('ČSFD premieres regression failed.'); }
}
$article = '<article><figure><img src="//image.pmgstatic.com/poster.jpg"></figure><header><h3><a class="film-title-name" href="/film/123-test/prehled/">Film &amp; test</a></h3><p>Komedie</p></header><p>Režie: Autor</p></article>';
$group = static fn(string $date, string $body): string => '<div class="updated-box-content"><div class="update-box-sub-header">v kinech od ' . $date . '</div><div>' . $body . '</div></div>';
$items = Csfd::parsePremieres($group('5.11.2026', $article . $article) . $group('12.11.2026', $article));
check(count($items) === 2);
check($items[0]['id'] === 'csfd:premieres:123:2026-11-05');
check($items[0]['title'] === 'Film & test');
check($items[0]['description'] === 'V kinech od 5.11.2026. Komedie Režie: Autor');
check($items[0]['image_url'] === 'https://image.pmgstatic.com/poster.jpg');
check(Csfd::parsePremieres($group('5.11.2026', str_replace('//image.pmgstatic.com/poster.jpg', 'https://evil.test/x', $article)))[0]['image_url'] === '');
foreach (['<h1>Access denied</h1>', $group('31.11.2026', $article), $article] as $invalid) {
    try { Csfd::parsePremieres($invalid); } catch (RuntimeException) { continue; }
    throw new RuntimeException('Invalid source accepted.');
}
$xml = Feed::document(['items' => $items]);
check((new DOMDocument())->loadXML($xml));
check(!str_contains($xml, '<pubDate>'));
$news = Csfd::parse('<article class="article-news"><header><h3><a href="/novinky/42-test/">News</a></h3></header><div class="article-news-textshort">Text<span class="span-more-small">více</span></div></article>');
check($news[0]['id'] === 'csfd:news:42' && $news[0]['description'] === 'Text');
echo "ČSFD premieres OK\n";
