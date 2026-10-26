<?php
declare(strict_types=1);

namespace TinyCat\Mail;

final class Smtp
{
    /**
     * @return array<mixed>
     */
    public static function email_smtp_default_config(): array
    {
        return [
            'host' => trim((string) \TinyCat\Settings\Store::config('email.smtp.host', '')),
            'port' => max(1, min(65535, (int) \TinyCat\Settings\Store::config('email.smtp.port', 587))),
            'username' => trim((string) \TinyCat\Settings\Store::config('email.smtp.username', '')),
            'password' => (string) \TinyCat\Settings\Store::config('email.smtp.password', ''),
            'encryption' => strtolower(trim((string) \TinyCat\Settings\Store::config('email.smtp.encryption', 'tls'))),
            'from_address' => trim((string) \TinyCat\Settings\Store::config('email.from_address', '')),
            'from_name' => trim((string) \TinyCat\Settings\Store::config('email.from_name', 'TinyCat')),
        ];
    }

    /**
     * @return array<mixed>
     */
    public static function email_smtp_config(): array
    {
        $defaults = \TinyCat\Mail\Smtp::email_smtp_default_config();
        $stored = \TinyCat\Settings\Store::setting('email.smtp', $defaults);

        if (!is_array($stored)) {
            return $defaults;
        }

        return [
            'host' => trim((string) ($stored['host'] ?? $defaults['host'])),
            'port' => max(1, min(65535, (int) ($stored['port'] ?? $defaults['port']))),
            'username' => trim((string) ($stored['username'] ?? $defaults['username'])),
            'password' => (string) ($stored['password'] ?? $defaults['password']),
            'encryption' => strtolower(trim((string) ($stored['encryption'] ?? $defaults['encryption']))),
            'from_address' => trim((string) ($stored['from_address'] ?? $defaults['from_address'])),
            'from_name' => trim((string) ($stored['from_name'] ?? $defaults['from_name'])),
        ];
    }

    public static function email_smtp_send(string $to, string $subject, string $body): bool
    {
        $smtp = \TinyCat\Mail\Smtp::email_smtp_config();
        $host = $smtp['host'];
        $from = \TinyCat\Users\Profiles::user_email_normalize($smtp['from_address']);
        if ($host === '' || !\TinyCat\Users\Profiles::user_email_valid($to) || !\TinyCat\Users\Profiles::user_email_valid($from)) {
            return false;
        }

        $port = $smtp['port'];
        $encryption = $smtp['encryption'];
        $transport = $encryption === 'ssl' ? 'ssl://' . $host : $host;
        $socket = @stream_socket_client($transport . ':' . $port, $errno, $error, 10);
        if (!is_resource($socket)) {
            return false;
        }

        stream_set_timeout($socket, 10);
        $read = static function () use ($socket): string {
            $response = '';

            while (($line = fgets($socket, 512)) !== false) {
                $response .= $line;

                if (strlen($line) < 4 || $line[3] === ' ') {
                    break;
                }
            }

            return $response;
        };
        $expect = static function (array $codes) use (&$read): bool {
            $response = $read();
            $code = (int) substr($response, 0, 3);

            return in_array($code, $codes, true);
        };
        $write = static function (string $line) use ($socket): void {
            fwrite($socket, $line . "\r\n");
        };
        $fail = static function () use ($socket): bool {
            fclose($socket);
            return false;
        };

        if (!$expect([220])) return $fail();

        $serverName = preg_replace('/[^a-z0-9.-]/i', '', (string) ($_SERVER['SERVER_NAME'] ?? 'localhost')) ?: 'localhost';
        $write('EHLO ' . $serverName);
        if (!$expect([250])) return $fail();

        if ($encryption === 'tls') {
            $write('STARTTLS');
            if (!$expect([220]) || !@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                return $fail();
            }

            $write('EHLO ' . $serverName);
            if (!$expect([250])) return $fail();
        }

        $username = $smtp['username'];
        if ($username !== '') {
            $write('AUTH LOGIN');
            if (!$expect([334])) return $fail();
            $write(base64_encode($username));
            if (!$expect([334])) return $fail();
            $write(base64_encode($smtp['password']));
            if (!$expect([235])) return $fail();
        }

        $write('MAIL FROM:<' . $from . '>');
        if (!$expect([250])) return $fail();
        $write('RCPT TO:<' . $to . '>');
        if (!$expect([250, 251])) return $fail();
        $write('DATA');
        if (!$expect([354])) return $fail();

        $fromName = str_replace(["\r", "\n"], '', $smtp['from_name']);
        $subject = str_replace(["\r", "\n"], '', $subject);
        $encodedSubject = function_exists('mb_encode_mimeheader') ? mb_encode_mimeheader($subject, 'UTF-8') : $subject;
        $body = preg_replace('/\r\n|\r|\n/', "\r\n", $body) ?? $body;
        $body = preg_replace('/^\./m', '..', $body) ?? $body;
        $headers = 'From: ' . $fromName . ' <' . $from . ">\r\n"
            . 'To: <' . $to . ">\r\n"
            . 'Subject: ' . $encodedSubject . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n";
        fwrite($socket, $headers . $body . "\r\n.\r\n");
        if (!$expect([250])) return $fail();

        $write('QUIT');
        fclose($socket);
        return true;
    }
}
