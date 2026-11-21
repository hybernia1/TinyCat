<?php
declare(strict_types=1);

namespace TinyCat\Social\Presentation;

use TinyCat\Support\Components;

use TinyCat\Social\Posts\Tags;

/** Presentation components: prepare inputs, then render their private partial. */
final class Views
{
    /** @param array<mixed> $data */
    public static function sidebar(array $data = []): string
    {
        return Components::render('site/sidebar', Sidebar::resource($data) + [
            'active_tag' => Tags::status_tag_normalize((string) ($data['active_tag'] ?? '')),
            'url' => (string) ($data['sidebar_url'] ?? '/api/site/sidebar'),
        ]);
    }
}
