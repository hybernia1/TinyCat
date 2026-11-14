<?php
declare(strict_types=1);
namespace TinyCat\Core\Identity;
use TinyCat\Core\Identity\Integration;

final class AuthResource
{
    /** @return array<string, mixed> */
    public static function make(string $mode, string $next): array
    {
        $mode = Login::auth_modal_mode($mode);
        $next = Login::auth_safe_next_url($next);
        $other = $mode === 'register' ? 'login' : 'register';
        return \TinyCat\Core\Identity\Resource::authentication($mode, $next, Login::auth_url_with_next('/' . $other, $next), Login::auth_modal_url($other, $next),
            Integration::registrationEnabled(),
            \TinyCat\Core\Security\Captcha::configuration($mode, $mode === 'register' || \TinyCat\Core\Security\Captcha::loginRequired()));
    }
}
