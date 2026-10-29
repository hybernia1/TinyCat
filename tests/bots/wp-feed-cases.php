<?php
declare(strict_types=1);

// Included by metadata-dns.php to exercise the real client without network access.
$wpRoot = 'https://wp-feed.example/blog/wp-json/';
$GLOBALS['dnsAnswers']['wp-feed.example'] = ['93.184.216.34'];
$GLOBALS['dns6Answers']['wp-feed.example'] = [];
$assert(\TinyCat\Bots\WordPressFeed::hint('https://wordpress.org/?v=7', 'https://wp-feed.example/blog/', 'https://wp-feed.example/blog/feed/') === $wpRoot, 'WP hints retain subdirectory installations.');
$assert(\TinyCat\Bots\WordPressFeed::hint('https://wordpress.org.evil.example/', 'https://wp-feed.example/', 'https://wp-feed.example/feed/') === '', 'Spoofed generator hosts are not WordPress hints.');
$assert(\TinyCat\Bots\WordPressFeed::hint('https://wordpress.org/', 'http://127.0.0.1/', 'https://wp-feed.example/feed/') === '', 'Feed metadata cannot redirect API probing to another origin or private host.');
$wpItem = static fn (int $id, string $slug): array => ['guid' => 'https://wp-feed.example/?p=' . $id, 'url' => 'https://wp-feed.example/blog/' . $slug,
    'title' => $slug, 'image_urls' => [], 'image_url' => '', '_wordpress_root' => $wpRoot];
$wpItems = [$wpItem(7, 'article-a'), $wpItem(8, 'article-b')];
$wpItems[] = array_replace($wpItem(9, 'article-c'), ['image_urls' => ['https://wp-feed.example/feed.jpg'], 'image_url' => 'https://wp-feed.example/feed.jpg']);
$wpIndex = $wpRoot . '?_fields=namespaces';
$wpBatch = $wpRoot . 'wp/v2/posts?' . http_build_query(['include' => '7,8', 'per_page' => 100, '_embed' => 'wp:featuredmedia', '_fields' => 'id,link,_embedded,_links'], '', '&', PHP_QUERY_RFC3986);
$GLOBALS['responses'][$wpIndex] = $GLOBALS['responses'][$wpBatch] = ['HTTP/1.1 200 OK', 'Content-Type: application/json'];
$GLOBALS['bodies'][$wpIndex] = json_encode(['namespaces' => ['wp/v2']]);
$wpPost = static fn (int $id, string $slug, string $image): array => ['id' => $id, 'link' => 'https://wp-feed.example/blog/' . $slug,
    '_embedded' => ['wp:featuredmedia' => [['source_url' => $image]]]];
$GLOBALS['bodies'][$wpBatch] = json_encode([$wpPost(7, 'article-a', 'https://wp-feed.example/featured-a.jpg'), $wpPost(8, 'wrong-article', 'https://wp-feed.example/wrong.jpg')]);
$beforeWp = count($GLOBALS['httpCalls']);
$enrichedWp = \TinyCat\Bots\WordPressFeed::images($wpItems);
$assert(array_slice($GLOBALS['httpCalls'], $beforeWp) === [$wpIndex, $wpBatch], 'Missing feed artwork uses one root verification and a single batch, not one request per item.');
$assert($enrichedWp[0]['image_url'] === 'https://wp-feed.example/featured-a.jpg' && $enrichedWp[1]['image_urls'] === [], 'Only matching post IDs and canonical article URLs receive API artwork.');
$assert($enrichedWp[2] === $wpItems[2], 'Feed-provided artwork keeps priority and never enters the API batch.');
$assert($enrichedWp[0]['guid'] === $wpItems[0]['guid'] && $enrichedWp[0]['url'] === $wpItems[0]['url'], 'Artwork enrichment preserves feed identity, GUID and article URL.');
$assert(str_contains(\TinyCat\Status\Providers::wordpressUrl($wpItems[0]['url']), '/wp/v2/posts/7?'), 'Verified feed matches populate the existing WordPress link-provider cache.');
\TinyCat\Bots\WordPressFeed::images($wpItems);
$assert(count($GLOBALS['httpCalls']) === $beforeWp + 2, 'Positive and image-free results are cached without repeated API requests.');
$malformedItems = [$wpItem(10, 'wrong-id'), $wpItem(11, 'private-image')];
$malformedBatch = $wpRoot . 'wp/v2/posts?' . http_build_query(['include' => '10,11', 'per_page' => 100, '_embed' => 'wp:featuredmedia', '_fields' => 'id,link,_embedded,_links'], '', '&', PHP_QUERY_RFC3986);
$GLOBALS['responses'][$malformedBatch] = ['HTTP/1.1 200 OK', 'Content-Type: application/json'];
$GLOBALS['bodies'][$malformedBatch] = json_encode([$wpPost(99, 'wrong-id', 'https://wp-feed.example/wrong.jpg'), $wpPost(11, 'private-image', 'http://127.0.0.1/secret')]);
$badWp = \TinyCat\Bots\WordPressFeed::images($malformedItems);
$assert($badWp[0]['image_urls'] === [] && $badWp[1]['image_urls'] === [], 'Wrong IDs and private image URLs cannot be assigned to feed items.');
$failedItem = $wpItem(12, 'offline');
$failedItem['_wordpress_root'] = 'https://wp-feed.example/offline/wp-json/';
$beforeOffline = count($GLOBALS['httpCalls']);
$assert(\TinyCat\Bots\WordPressFeed::images([$failedItem]) === [$failedItem], 'Unavailable API roots leave ordinary RSS content usable.');
\TinyCat\Bots\WordPressFeed::images([$failedItem]);
$assert(count($GLOBALS['httpCalls']) === $beforeOffline + 1, 'Negative API discovery is cached to avoid repeatedly probing unavailable endpoints.');
$noHint = $wpItem(15, 'plain'); unset($noHint['_wordpress_root']);
$beforePlain = count($GLOBALS['httpCalls']);
$assert(\TinyCat\Bots\WordPressFeed::images([$noHint]) === [$noHint] && count($GLOBALS['httpCalls']) === $beforePlain, 'Feeds without a WordPress declaration never trigger API probes.');
$slugItem = $wpItem(0, 'slug-only'); $slugItem['guid'] = 'urn:article:slug-only';
$slugBatch = $wpRoot . 'wp/v2/posts?' . http_build_query(['slug' => 'slug-only', 'per_page' => 100, '_embed' => 'wp:featuredmedia', '_fields' => 'id,link,_embedded,_links'], '', '&', PHP_QUERY_RFC3986);
$GLOBALS['responses'][$slugBatch] = ['HTTP/1.1 200 OK', 'Content-Type: application/json'];
$GLOBALS['bodies'][$slugBatch] = json_encode([$wpPost(14, 'slug-only', 'https://wp-feed.example/slug.jpg')]);
$slugResult = \TinyCat\Bots\WordPressFeed::images([$slugItem]);
$assert($slugResult[0]['image_url'] === 'https://wp-feed.example/slug.jpg', 'Feeds without numeric GUIDs use a verified permalink-slug batch.');
$redirectItem = $wpItem(16, 'redirect'); $redirectItem['_wordpress_root'] = 'https://wp-feed.example/redirect/wp-json/';
$GLOBALS['responses'][$redirectItem['_wordpress_root'] . '?_fields=namespaces'] = ['HTTP/1.1 302 Found', 'Location: http://127.0.0.1/private'];
$beforeRedirect = count($GLOBALS['httpCalls']);
$assert(\TinyCat\Bots\WordPressFeed::images([$redirectItem]) === [$redirectItem] && count($GLOBALS['httpCalls']) === $beforeRedirect + 1, 'WordPress probing cannot follow redirects to another origin or a private host.');
