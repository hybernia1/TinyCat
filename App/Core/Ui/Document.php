<?php
declare(strict_types=1);
namespace TinyCat\Core\Ui;

use TinyCat\Support\View;

/** Shared public document; themes supply only their page shell. */
final class Document
{
    /** @param array<string, mixed> $page */
    public static function render(array $page, string $content): void
    {
        $prepared = DocumentData::prepare($page);
        $prepared['content'] = $content;
        $prepared['public'] = (bool) ($page['public'] ?? false);
        $prepared['hasPublicSidebar'] = false;
        self::renderPrepared($prepared, View::render('public-shell', $prepared, __DIR__ . '/templates'));
    }

    /** @param array<string, mixed> $prepared */
    public static function renderPrepared(array $prepared, string $shell): void
    {
        $prepared['head'] = View::render('head', $prepared, __DIR__ . '/templates');
        $prepared['shell'] = $shell;
        $prepared['iconSpriteUrl'] = \TinyCat\Support\Assets::asset('icons.svg');
        echo View::render('document', $prepared, __DIR__ . '/templates');
    }
}
