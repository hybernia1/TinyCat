<?php
declare(strict_types=1);

define('TINYCAT', true);
require dirname(__DIR__, 2) . '/App/Core/Runtime/autoload.php';

use TinyCat\Core\Ui\Oembed;

foreach (['link', 'photo', 'video', 'rich'] as $type) {
    $data = ['type' => $type, 'title' => 'Other entity <&>', 'url' => 'https://catalog.example/items/7', 'width' => 320, 'height' => 240, 'html' => '<iframe src="https://catalog.example/items/7/embed"></iframe>'];
    $json = json_decode(Oembed::render($data), true, flags: JSON_THROW_ON_ERROR);
    $xml = simplexml_load_string(Oembed::render($data, 'xml'));
    if ($json !== ['version' => '1.0'] + $data || $xml === false || (string) $xml->type !== $type || (string) $xml->title !== $data['title'] || (string) $xml->html !== $data['html']) { throw new RuntimeException('oEmbed must preserve owner types and metadata in both protocol formats.'); }
}
try { Oembed::render([], 'yaml'); throw new LogicException('Unsupported oEmbed format was accepted.'); }
catch (InvalidArgumentException) {}
foreach (get_included_files() as $file) {
    if (str_contains(str_replace('\\', '/', $file), '/App/Modules/')) { throw new RuntimeException('Core oEmbed presentation loaded a functional module.'); }
}
foreach ([TinyCat\Database\Connection::class, TinyCat\Core\Settings\Store::class, TinyCat\Core\Ui\Site::class] as $class) {
    if (class_exists($class, false)) { throw new RuntimeException('oEmbed serialization loaded installation state.'); }
}
echo "PASS standalone oEmbed presentation, owner types and equivalent safe JSON/XML\n";
