<?php
declare(strict_types=1);

// Included by write-path-integrity.php with its isolated database and HTTP doubles.
$raster = imagecreatetruecolor(1200, 600);
ob_start(); imagepng($raster); $png = (string) ob_get_clean(); imagedestroy($raster);
$imageUrl = 'http://93.184.216.34/source-image.png';
$badImageUrl = 'http://93.184.216.34/not-image.png';
$GLOBALS['sourceImageFixtures'] = [$imageUrl => $png, $badImageUrl => '<svg>not a raster</svg>'];
$GLOBALS['sourceImageRequests'] = [];
$imageSource = array_replace($source, ['post_template' => '{{image}}']);
$imageItem = ['guid' => 'image-only', 'image_urls' => [$badImageUrl, $imageUrl]];
$pathsBefore = glob($root . '/uploads/status-images/' . date('Y/m') . '/*.webp') ?: [];
try {
    $rssUrl = 'http://93.184.216.34/image-source.rss';
    $GLOBALS['sourceImageFixtures'][$rssUrl] = '<rss><channel><item><guid>adapter</guid><enclosure type="image/png" url="' . $imageUrl . '"/></item></channel></rss>';
    $adapterItems = \TinyCat\Bots\FeedParser::fetch($rssUrl);
    $assert(count($adapterItems) === 1 && $adapterItems[0]['image_url'] === $imageUrl, 'The Unified feed parser downloads and normalizes image-only entries through the shared client.');
    $imageResult = $importItems->invoke(null, $imageSource, [$imageItem], 1);
    $imageId = (int) ($imageResult['content_id'] ?? 0);
    $imageRecord = \TinyCat\Status\Images::status_image_record($imageId);
    $assert($imageResult['status'] === 'posted' && $imageRecord !== null, 'Image-only templates create real attachments and skip invalid candidates.');
    $assert($value('SELECT body FROM content WHERE id = ' . $imageId) === '', 'The image placeholder never leaves markup or remote image URLs in the text.');
    $assert((int) $imageRecord['width'] === 800 && (int) $imageRecord['height'] === 400 && (int) $imageRecord['bytes'] <= \TinyCat\Status\Images::status_image_max_upload_bytes(), 'Source attachments use normal dimensions, WebP conversion and output limits.');
    $beforeRequests = count($GLOBALS['sourceImageRequests']);
    $duplicateImage = $importItems->invoke(null, $imageSource, [$imageItem], 1);
    $assert($duplicateImage['status'] === 'current' && count($GLOBALS['sourceImageRequests']) === $beforeRequests, 'Duplicate image items do not download or create another file.');
    $plainImage = $importItems->invoke(null, $source, [array_replace($imageItem, ['guid' => 'image-opt-in', 'title' => 'Text only'])], 1);
    $assert($plainImage['status'] === 'posted' && \TinyCat\Status\Images::status_image_record((int) $plainImage['content_id']) === null && count($GLOBALS['sourceImageRequests']) === $beforeRequests, 'Templates without the marker retain their existing text-only behavior.');
    $wpRoot = 'http://93.184.216.34/wp-json/';
    $GLOBALS['sourceImageFixtures'][$wpRoot . '?_fields=namespaces'] = '{}';
    $wpWithoutMarker = $importItems->invoke(null, $source, [['guid' => 'wp-no-image-marker', 'title' => 'Text only', 'url' => 'http://93.184.216.34/article', '_wordpress_root' => $wpRoot, 'image_urls' => []]], 1);
    $assert($wpWithoutMarker['status'] === 'posted' && count($GLOBALS['sourceImageRequests']) === $beforeRequests, 'WordPress hints do not trigger API requests without an image template marker.');
    $knownWpItem = array_replace($imageItem, ['image_urls' => [], 'url' => 'http://93.184.216.34/article', '_wordpress_root' => $wpRoot]);
    $knownWp = $importItems->invoke(null, $imageSource, [$knownWpItem], 1);
    $assert($knownWp['status'] === 'current' && count($GLOBALS['sourceImageRequests']) === $beforeRequests, 'Already imported WordPress GUIDs are filtered before artwork API requests.');
    $missingImage = $importItems->invoke(null, $imageSource, [['guid' => 'image-missing']], 1);
    $assert($missingImage['skipped_items'][0]['code'] === 'status_required', 'An image-only item without an image cannot create an empty post.');
    $blockedImage = $importItems->invoke(null, $imageSource, [['guid' => 'image-private', 'image_urls' => ['http://127.0.0.1/private']]], 1);
    $assert($blockedImage['status'] === 'error' && count($GLOBALS['sourceImageRequests']) === $beforeRequests, 'Private attachment URLs are rejected before transport.');
    $GLOBALS['sourceImageHeaders'] = ['HTTP/1.1 302 Found', 'Location: http://127.0.0.1/private'];
    $redirectedImage = $importItems->invoke(null, $imageSource, [['guid' => 'image-private-redirect', 'image_urls' => [$imageUrl]]], 1);
    unset($GLOBALS['sourceImageHeaders']);
    $assert($redirectedImage['status'] === 'error' && count($GLOBALS['sourceImageRequests']) === $beforeRequests + 1, 'Attachment redirects retain private-host protections.');
    $tooWide = imagecreatetruecolor(8193, 1);
    ob_start(); imagepng($tooWide); $widePng = (string) ob_get_clean(); imagedestroy($tooWide);
    $GLOBALS['sourceImageFixtures'][$badImageUrl] = $widePng;
    $wideImage = $importItems->invoke(null, $imageSource, [['guid' => 'image-too-wide', 'image_urls' => [$badImageUrl]]], 1);
    $assert($wideImage['status'] === 'error', 'Source images respect the same source dimension limit as uploads.');
    $historyCount = (int) $value('SELECT COUNT(*) FROM bot_feed_history');
    $database->exec("CREATE TRIGGER fail_image_insert BEFORE INSERT ON content_images BEGIN SELECT RAISE(ABORT, 'image relation failure'); END");
    $paths = glob($root . '/uploads/status-images/' . date('Y/m') . '/*.webp') ?: [];
    $imageFailure = $importItems->invoke(null, $imageSource, [['guid' => 'image-rollback', 'image_urls' => [$imageUrl]]], 1);
    $database->exec('DROP TRIGGER fail_image_insert');
    $assert($imageFailure['status'] === 'error' && (int) $value('SELECT COUNT(*) FROM bot_feed_history') === $historyCount, 'Attachment persistence failures roll back the post and GUID claim.');
    $assert((glob($root . '/uploads/status-images/' . date('Y/m') . '/*.webp') ?: []) === $paths, 'Attachment persistence failures remove the prepared image file.');
    $imageRetry = $importItems->invoke(null, $imageSource, [['guid' => 'image-rollback', 'image_urls' => [$imageUrl]]], 1);
    $assert($imageRetry['status'] === 'posted', 'An image transaction failure remains retryable.');
    $paths = glob($root . '/uploads/status-images/' . date('Y/m') . '/*.webp') ?: [];
    $database->exec("UPDATE bot_sources SET feed_hash = 'changed-during-download' WHERE id = 2");
    $staleImage = $importItems->invoke(null, $imageSource, [['guid' => 'image-stale-source', 'image_urls' => [$imageUrl]]], 1);
    $assert($staleImage['status'] === 'error' && (glob($root . '/uploads/status-images/' . date('Y/m') . '/*.webp') ?: []) === $paths, 'A source changed during image download leaves no orphan attachment.');
    $database->prepare('UPDATE bot_sources SET feed_hash = ? WHERE id = 2')->execute([\TinyCat\Bots\Importer::feedSourceHash($imageSource['feed_url'])]);
    $disabledConfig = $config->getValue();
    $disabledConfig['content_images']['enabled'] = false;
    $config->setValue(null, $disabledConfig);
    $beforeRequests = count($GLOBALS['sourceImageRequests']);
    $disabledImage = $importItems->invoke(null, $imageSource, [['guid' => 'image-disabled', 'image_urls' => [$imageUrl]]], 1);
    $assert($disabledImage['skipped_items'][0]['code'] === 'status_required' && count($GLOBALS['sourceImageRequests']) === $beforeRequests, 'Disabled image uploads prevent attachment downloads and empty posts.');
    unset($disabledConfig['content_images']); $config->setValue(null, $disabledConfig);
} finally {
    foreach (array_diff(glob($root . '/uploads/status-images/' . date('Y/m') . '/*.webp') ?: [], $pathsBefore) as $imageFile) { unlink($imageFile); }
    unset($GLOBALS['sourceImageFixtures'], $GLOBALS['sourceImageStreams'], $GLOBALS['sourceImageRequests'], $GLOBALS['sourceImageHeaders']);
}
