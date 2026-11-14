<?php
declare(strict_types=1);

namespace TinyCat\Core\Identity;

/** Optional Accounts integration implemented by an already selected module provider. */
interface ProvidesLifecycle
{
    public static function accountLifecycle(): Participant;
}
