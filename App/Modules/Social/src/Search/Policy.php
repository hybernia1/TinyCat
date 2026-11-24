<?php
declare(strict_types=1);
namespace TinyCat\Social\Search;

/** Search budgets belong to Social; CAPTCHA unlocks only the additional UI challenge. */
final class Policy
{
    public const int SUGGESTION_WINDOW = 60;
    public const int SUGGESTION_MAX = 120;

    /** @return array{max: int, window: int, unlock: int} */
    public static function guestSearch(): array { return ['max' => 10, 'window' => 300, 'unlock' => 600]; }

    // Hard IP ceiling allows continued searching after CAPTCHA while bounding database work.
    public const int GUEST_MAX = 120;
}
