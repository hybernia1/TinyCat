<?php
declare(strict_types=1);

define('TINYCAT', true);
require dirname(__DIR__, 2) . '/App/Core/Runtime/autoload.php';

use TinyCat\Core\Ui\Rss;

$items = [
    ['title' => 'Product <one>', 'url' => 'https://catalog.example/products/1', 'html' => '<p>A]]>B</p>',
        'categories' => [['name' => 'Tools & parts', 'url' => 'https://catalog.example/categories/tools']],
        'enclosure' => ['url' => 'https://cdn.example/manual.pdf', 'type' => 'application/pdf', 'length' => 42]],
    ['title' => 'Event', 'url' => 'https://events.example/2', 'image_url' => 'https://cdn.example/event.png', 'image_type' => 'image/png'],
];
$xml = simplexml_load_string(Rss::render('Exact title & name', 'Description', 'https://catalog.example/', 'https://catalog.example/feed', $items, 'https://cdn.example/logo.png', 'pt_BR'));
if ($xml === false || (string) $xml->channel->title !== 'Exact title & name' || (string) $xml->channel->language !== 'pt-BR') { throw new RuntimeException('RSS must use explicit owner metadata without installation settings.'); }
if (count($xml->channel->item) !== 2 || (string) $xml->channel->item[0]->title !== 'Product <one>' || (string) $xml->channel->item[0]->description !== '<p>A]]>B</p>') { throw new RuntimeException('RSS must preserve owner items and safely encode XML and CDATA.'); }
if ((string) $xml->channel->item[0]->category['domain'] !== 'https://catalog.example/categories/tools' || (string) $xml->channel->item[0]->enclosure['type'] !== 'application/pdf' || (string) $xml->channel->image->url !== 'https://cdn.example/logo.png') { throw new RuntimeException('RSS must preserve owner URLs and attachment types.'); }
$media = $xml->channel->item[1]->children('http://search.yahoo.com/mrss/');
if ((string) $media->content->attributes()['url'] !== 'https://cdn.example/event.png' || (string) $media->content->attributes()['type'] !== 'image/png') { throw new RuntimeException('RSS media metadata must not depend on the entity kind.'); }
if (Rss::html('<script>bad</script> https://example.com?a=1&b=2') !== '<p>&lt;script&gt;bad&lt;/script&gt; <a href="https://example.com?a=1&amp;b=2">https://example.com?a=1&amp;b=2</a></p>') { throw new RuntimeException('RSS prose must escape markup and link text.'); }
foreach (get_included_files() as $file) {
    if (str_contains(str_replace('\\', '/', $file), '/App/Modules/')) { throw new RuntimeException('Core RSS presentation loaded a functional module.'); }
}
foreach ([TinyCat\Database\Connection::class, TinyCat\Core\Settings\Store::class, TinyCat\Core\Ui\Site::class] as $class) {
    if (class_exists($class, false)) { throw new RuntimeException('RSS presentation loaded installation state: ' . $class); }
}
echo "PASS standalone RSS presentation, owner metadata, XML safety and unrelated entity items\n";
