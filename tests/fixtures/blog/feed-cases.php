<?php
declare(strict_types=1);

// Included by the existing SQLite and MySQL Blog domain fixtures; no extra runner.
use TinyCat\Blog\{FeedSources, FeedStore};
use TinyCat\Core\Feeds\FeedParser;

require_once dirname(__DIR__, 2) . '/support/http-client-double.php';

$feedInput = ['name' => 'Fixture feed', 'feed_url' => 'https://93.184.216.34/articles.xml', 'author_id' => 1, 'interval_minutes' => 60];
$sourceId = FeedSources::save($feedInput);
$feedSource = FeedSources::runtime(FeedSources::find($sourceId));
$pipeline = FeedSources::pipeline();
$long = str_repeat('Complete article paragraph. ', 160);
$xml = '<rss xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel><title>Fixture</title><item><guid>full-body</guid><title>Full feed article</title><description>Short summary</description><content:encoded><![CDATA[<p>' . $long . '</p>]]></content:encoded></item></channel></rss>';
$feedItems = FeedParser::parse($xml);
$assert(count($feedItems) === 1 && $feedItems[0]['description'] === 'Short summary' && strlen($feedItems[0]['content']) > 2000, 'Feed parser retains full content independently of the social summary');
$jsonItems = FeedParser::parse(json_encode(['version' => 'https://jsonfeed.org/version/1.1', 'title' => 'JSON fixture', 'items' => [['id' => 'json-full', 'title' => 'JSON article', 'summary' => 'Short summary', 'content_text' => '', 'content_html' => '<p>' . $long . '</p>']]], JSON_THROW_ON_ERROR));
$assert($jsonItems[0]['description'] === 'Short summary' && $jsonItems[0]['content'] === trim($long), 'JSON Feed keeps HTML full content when optional plain content is empty');
$beforeSource = FeedSources::find($sourceId);
$preview = FeedSources::preview(['document' => $xml]);
$assert($preview['items'][0]['body'] === trim($long) && FeedSources::find($sourceId) === $beforeSource && (int) $pdo->query('SELECT COUNT(*) FROM blog_feed_history')->fetchColumn() === 0, 'Preview shares article preparation without schedule changes or GUID claims');
$imported = $pipeline->import($feedSource, $feedItems);
$importIds = $imported['content_ids'];
$importedId = (int) ($importIds[0] ?? 0);
$assert($imported['status'] === 'posted' && $imported['imported_count'] === 1 && \TinyCat\Blog\Articles::find($importedId) === null, 'Blog imports as hidden by default without a bot account');
$article = \TinyCat\Blog\Articles::find($importedId, false);
$assert($article['body'] === $preview['items'][0]['body'] && strlen($article['body']) > 2000 && (string) $pdo->query('SELECT body FROM blog_articles WHERE id = ' . $importedId)->fetchColumn() === $article['body'], 'Preview and import preserve the same complete article and explicit target kind');
$again = $pipeline->import($feedSource, $feedItems);
$assert($again['status'] === 'current' && $again['imported_count'] === 0, 'Repeated GUIDs create no second article');
$reject = $pipeline->import($feedSource, [['guid' => 'repairable', 'title' => '', 'content' => 'Text']]);
$assert($reject['imported_count'] === 0 && $reject['skipped_items'][0]['code'] === 'article_invalid' && (int) $pdo->query('SELECT COUNT(*) FROM blog_feed_history')->fetchColumn() === 1, 'Rejected articles are diagnostic only, not successful claims');
$repaired = $pipeline->import($feedSource, [['guid' => 'repairable', 'title' => 'Repaired', 'content' => 'Text']]);
$importIds = [...$importIds, ...$repaired['content_ids']];
$assert($repaired['imported_count'] === 1, 'A repaired item can be retried with the original GUID');
$mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
$pdo->exec($mysql ? "CREATE TRIGGER reject_feed_article BEFORE INSERT ON blog_articles FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected feed failure'" : "CREATE TRIGGER reject_feed_article BEFORE INSERT ON blog_articles BEGIN SELECT RAISE(ABORT, 'injected feed failure'); END");
$failureItem = [['guid' => 'retry-technical', 'title' => 'Retry', 'content' => 'Retry body']];
$beforeCount = (int) $pdo->query('SELECT COUNT(*) FROM blog_articles')->fetchColumn();
$failed = $pipeline->import($feedSource, $failureItem);
$assert($failed['status'] === 'error' && (int) $pdo->query('SELECT COUNT(*) FROM blog_articles')->fetchColumn() === $beforeCount && (int) $pdo->query('SELECT COUNT(*) FROM blog_feed_history')->fetchColumn() === 2, 'Publication failure rolls back publication, specialization, mapping and GUID together');
$pdo->exec('DROP TRIGGER reject_feed_article');
$retry = $pipeline->import($feedSource, $failureItem);
$importIds = [...$importIds, ...$retry['content_ids']];
$assert($retry['imported_count'] === 1, 'A technical failure does not consume the external item');
$GLOBALS['sourceImageFixtures'][$feedSource['feed_url']] = str_replace('full-body', 'scheduled-body', $xml);
$GLOBALS['sourceImageHeaders'] = ['HTTP/1.1 200 OK', 'Content-Type: application/rss+xml'];
$execution = \TinyCat\Core\Tasks\Execution::run('article_feeds', ['source_id' => $sourceId], 'admin', 1, true);
$scheduledIds = $execution['tasks']['article_feeds']['results'][0]['content_ids'] ?? [];
$importIds = [...$importIds, ...$scheduledIds];
$assert($execution['ok'] && count($scheduledIds) === 1 && (int) $pdo->query("SELECT COUNT(*) FROM scheduler_runs WHERE task = 'article_feeds' AND mode = 'admin' AND status = 'completed'")->fetchColumn() === 1, 'Manual import uses the core runner, lock and persistent history');
$planned = \TinyCat\Core\Tasks\Execution::run('article_feeds', [], 'cli', 0, true);
$assert($planned['ok'] && $planned['tasks']['article_feeds']['changed'] === 0, 'Planned execution respects the same claimed source interval');
unset($GLOBALS['sourceImageFixtures'][$feedSource['feed_url']], $GLOBALS['sourceImageHeaders']);
$originUrl = 'https://93.184.216.34/original-article';
$otherUrls = ['https://93.184.216.34/a', 'https://93.184.216.34/b', 'https://93.184.216.34/c'];
foreach ([$originUrl, ...$otherUrls] as $url) { $GLOBALS['sourceImageFixtures'][$url] = '<html><title>Link fixture</title></html>'; }
$GLOBALS['sourceImageHeaders'] = ['HTTP/1.1 200 OK', 'Content-Type: text/html'];
$originItem = ['guid' => 'origin-first', 'title' => 'Origin article', 'content' => implode(' ', $otherUrls), 'url' => $originUrl];
$withOrigin = $pipeline->import($feedSource, [$originItem]);
$importIds = [...$importIds, ...$withOrigin['content_ids']];
$changedGuid = $pipeline->import($feedSource, [array_replace($originItem, ['guid' => 'origin-changed'])]);
$assert($withOrigin['imported_count'] === 1 && $changedGuid['imported_count'] === 0, 'Origin URL deduplication survives GUID changes even beyond the article link-card limit');
foreach ([$originUrl, ...$otherUrls] as $url) { unset($GLOBALS['sourceImageFixtures'][$url]); }
unset($GLOBALS['sourceImageHeaders']);
$store = new FeedStore();
$pdo->exec('UPDATE blog_sources SET next_run_at = NULL WHERE id = ' . $sourceId);
$assert($store->claim($feedSource, false) && !$store->claim($feedSource, false), 'A due source is claimed only once before its next interval');
FeedSources::save($feedInput + ['enabled' => false], $sourceId);
$stale = $pipeline->import($feedSource, [['guid' => 'stale', 'title' => 'Stale', 'content' => 'Stale body']]);
$assert($stale['status'] === 'error' && $stale['imported_count'] === 0, 'A disabled or edited source cannot publish a previously prepared article');
// Keep the surrounding domain fixture's baseline counts and selection unchanged.
$pdo->exec('DELETE FROM blog_sources WHERE id = ' . $sourceId);
foreach ($importIds as $importId) { \TinyCat\Blog\Articles::delete((int) $importId, ['id' => 1, 'role' => 'admin', 'status' => 'active']); }
