<?php
declare(strict_types=1);
namespace TinyCat\Core\Ui;

/** An active publication owner's explicit authorization decision. */
final readonly class InteractionTarget
{
    public function __construct(public string $owner, public string $kind, public int $id, public string $url, public bool $read, public bool $interact, public bool $manage, public bool $comments = true, public bool $reactions = true) {}
}
