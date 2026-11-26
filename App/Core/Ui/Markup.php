<?php
declare(strict_types=1);

namespace TinyCat\Core\Ui;


/** Explicitly trusted output of server-side view composition; never API data. */
final readonly class Markup
{
    public function __construct(public string $html) {}
}
