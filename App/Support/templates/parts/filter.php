<?php
declare(strict_types=1);

use TinyCat\Support\Components;
use TinyCat\Support\Markup;

/** @var string $id */
/** @var string $action */
/** @var string $target */
/** @var string $body */
/** @var string $footer */
/** @var array<string, mixed> $formAttributes */
echo Components::render('ui/filter-content', [
    'attributes' => ['id' => $id, 'class' => 'stack', 'method' => 'get', 'action' => $action,
        'data-api-method' => 'GET', 'data-ajax-form' => '', 'data-ajax-target' => $target,
        'data-filter-content' => '', 'hidden' => true] + $formAttributes,
    'body' => new Markup($body), 'footer' => new Markup($footer),
]);
