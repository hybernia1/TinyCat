<?php
declare(strict_types=1);

// Included by run.php; every URL is a public IP literal, with no network fetches.
$jsonFeed = ['version' => 'https://jsonfeed.org/version/1.1', 'title' => 'Photo feed', 'authors' => [['name' => 'Feed author']], 'items' => [
    ['id' => 'new', 'content_text' => 'A photo', 'title' => 'Newest', 'url' => 'https://93.184.216.34/new', 'image' => 'https://93.184.216.34/photo.png', 'date_published' => '2026-10-29T10:00:00Z', 'tags' => ['photo', 'photo'], 'authors' => [['name' => 'Item author']]],
    ['id' => 'old', 'content_html' => '<p>Older text</p><img src="/old.png">', 'date_published' => '2026-10-28T10:00:00Z'],
]];
$jsonItems = \TinyCat\Bots\FeedParser::parse(json_encode($jsonFeed), 'https://93.184.216.34/feed.json');
$assert(array_column($jsonItems, 'guid') === ['old', 'new'], 'JSON Feed items retain stable IDs and chronological import order.');
$assert($jsonItems[0]['image_url'] === 'https://93.184.216.34/old.png' && $jsonItems[0]['description'] === 'Older text', 'JSON Feed HTML content supplies safe images and normalized descriptions.');
$assert($jsonItems[1]['author'] === 'Item author' && $jsonItems[1]['categories'] === ['photo'], 'JSON Feed authors and categories override feed defaults and deduplicate tags.');
$jsonFeed['version'] = 'https://jsonfeed.org/version/1';
$jsonFeed['items'][1]['author'] = ['name' => 'Legacy author'];
$legacyJson = \TinyCat\Bots\FeedParser::parse(json_encode($jsonFeed));
$assert($legacyJson[0]['author'] === 'Legacy author', 'Legacy JSON Feed authors override feed-level defaults.');
$jsonFeed['items'] = [['id' => ['bad'], 'content_text' => 'Invalid'], ['id' => 'missing-content'], ['id' => 'safe', 'content_text' => 'Valid', 'image' => 'http://127.0.0.1/private']];
$safeJson = \TinyCat\Bots\FeedParser::parse(json_encode($jsonFeed));
$assert(count($safeJson) === 1 && $safeJson[0]['image_urls'] === [], 'Malformed JSON Feed entries and private images cannot enter the import.');
$jsonFeed['version'] = 'https://evil.example/1.1';
$assert(\TinyCat\Bots\FeedParser::parse(json_encode($jsonFeed)) === [], 'Arbitrary JSON documents cannot masquerade as JSON Feed.');
$jsonFeed['version'] = 'https://jsonfeed.org/version/1.1';
$jsonFeed['items'] = array_fill(0, 105, ['id' => 'bounded', 'content_text' => 'Text']);
$assert(count(\TinyCat\Bots\FeedParser::parse(json_encode($jsonFeed))) === 100, 'JSON work is bounded like XML feeds.');
$jsonDoc = \TinyCat\Bots\FeedParser::document("\xEF\xBB\xBF" . json_encode($jsonFeed));
$assert($jsonDoc['format'] === 'json_feed_1_1' && count($jsonDoc['items']) === 100, 'BOM-prefixed JSON is recognized by preview and importer consistently.');
$rdf = '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns="http://purl.org/rss/1.0/" xmlns:dc="http://purl.org/dc/elements/1.1/"><channel rdf:about="https://93.184.216.34/feed"><title>RDF feed</title></channel><item rdf:about="rdf-item"><title>RDF post</title><link>https://93.184.216.34/post</link><description>RDF text</description><dc:creator>RDF author</dc:creator><dc:date>2026-10-29T10:00:00Z</dc:date></item></rdf:RDF>';
$rdfDoc = \TinyCat\Bots\FeedParser::document($rdf);
$assert($rdfDoc['format'] === 'rss_1' && $rdfDoc['title'] === 'RDF feed' && $rdfDoc['items'][0]['guid'] === 'rdf-item' && $rdfDoc['items'][0]['author'] === 'RDF author', 'RSS 1.0 handles namespaces, RDF identity and Dublin Core metadata.');
$discovery = \TinyCat\Bots\FeedParser::document('<!DOCTYPE html><html><head><link rel="alternate" type="application/feed+json" title="JSON" href="/feed.json"><link rel="alternate" type="application/rss+xml" href="http://127.0.0.1/private"></head></html>', 'https://93.184.216.34/blog');
$assert($discovery['format'] === 'discovery' && $discovery['sources'] === [['name' => 'JSON', 'feed_url' => 'https://93.184.216.34/feed.json']], 'Website discovery advertises only supported, safe alternate feeds.');
