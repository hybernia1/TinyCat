<?php
declare(strict_types=1);

namespace TinyCat\Moderation;

use TinyCat\Support\Components;

final class Views
{
    /** @param array<mixed> $data */
    public static function reports(array $data): string
    {
        return Components::render('moderation/reports', ['items' => array_map(Resource::report(...), (array) ($data['reports'] ?? [])), 'sorting' => \TinyCat\Support\AdminSorting::headings('reports')]);
    }
}
