<?php
declare(strict_types=1);
namespace TinyCat\Core\Mail;
use TinyCat\Core\Mail\Templates;

/** Account delivery is contributed by its owner; Accounts can run without Mail. */
final class ServiceProvider implements \TinyCat\Core\Modules\Module, \TinyCat\Core\Modules\ProvidesContributions, \TinyCat\Core\Runtime\InitializesModule
{
    public static function contributions(): \TinyCat\Core\Modules\Contributions { return new \TinyCat\Core\Modules\Contributions(mailDelivery: true, settings: Settings::sections()); }

    public static function initialize(): void
    {
        \TinyCat\Core\Identity\Integration::provide('tinycat.mail', ['register' => self::registerAccount(...), 'recovery' => self::recovery(...)]);
    }
    public static function register(\TinyCat\Http\Api $api): void {}
    /** @param array<mixed> $input
     * @return array<mixed> */
    public static function registerAccount(array $input): array
    {
        $result = \TinyCat\Core\Identity\Registration::create($input, (bool) \TinyCat\Core\Runtime\Startup::option('auth.registration.auto_approve', false), \TinyCat\Support\Localization::locale());
        Templates::email_template_send('welcome', (int) $result['user_id'], ['login_url' => self::url('/login')]);
        return $result;
    }
    public static function recovery(string $email): void
    {
        \TinyCat\Core\Identity\Recovery::request($email, static function (int $id, string $token): void {
            Templates::email_template_send('password_reset', $id, ['reset_url' => self::url('/recovery?token=' . rawurlencode($token))]);
        });
    }
    private static function url(string $path): string { return rtrim((string) \TinyCat\Core\Runtime\Startup::option('app.url', ''), '/') . $path; }
}
