<?php
declare(strict_types=1);

namespace TinyCat\Security;

/** HTTP adapter for the normalized API. */
final class RequestLimits
{
    public static function requireAllowed(string $action, ?int $accountId = null): void
    {
        $retry = RateLimiter::request_retry_after($action, $accountId);
        if ($retry === 0) { return; }
        \TinyCat\Http\Response::error(
            \TinyCat\Support\Localization::t($action === 'email-check' ? 'account.messages.email_check_limited' : 'moderation.messages.action_limited'),
            429,
            $action === 'email-check' ? 'email_check_limited' : 'action_limited',
            ['retry_after' => $retry]
        );
    }
}
