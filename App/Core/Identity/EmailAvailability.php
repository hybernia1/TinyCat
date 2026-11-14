<?php
declare(strict_types=1);

namespace TinyCat\Core\Identity;

use TinyCat\Http\Request;
use TinyCat\Http\Response;
use TinyCat\Support\Localization;

final class EmailAvailability
{
    /** @return array{available: bool} */
    public static function check(?int $ignoreId = null): array
    {
        header('Cache-Control: no-store, max-age=0');
        \TinyCat\Core\Security\RequestLimits::requireAllowed('email-check');
        Request::current()->validated(['email' => 'required|string']);
        $email = \TinyCat\Core\Identity\Email::normalize((string) Request::current()->input('email', ''));
        if (!\TinyCat\Core\Identity\Email::valid($email)) {
            Response::validation(['email' => [Response::issue('email_invalid', Localization::t('account.messages.email_invalid'))]]);
        }
        return ['available' => !\TinyCat\Core\Identity\Email::taken($email, $ignoreId)];
    }
}
