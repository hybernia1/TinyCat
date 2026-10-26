<?php
declare(strict_types=1);

namespace TinyCat\Http;

use InvalidArgumentException;
use Throwable;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Response
{
    /**
     * @param array<mixed> $headers
     */
    private static function json(mixed $data, int $status = 200, array $headers = []): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        foreach ($headers as $name => $value) {
            if (!preg_match('/^[A-Za-z0-9-]+$/', (string) $name)) {
                throw new InvalidArgumentException('Invalid response header: ' . $name);
            }

            header((string) $name . ': ' . (string) $value);
        }

        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    /**
     * @param array<mixed> $meta
     * @param array<mixed> $params
     */
    public static function success(mixed $data = null, ?string $message = null, int $status = 200, array $meta = [], ?string $code = null, array $params = []): never
    {
        self::assertData($data);
        self::assertData($meta);
        self::assertData($params);
        self::json(self::envelope(true, $status, $message, $data, $meta, null, $code ?? ($message !== null ? 'request_completed' : 'ok'), $params), $status);
    }

    /**
     * @param array<mixed> $meta
     */
    public static function created(mixed $data = null, ?string $message = 'Created.', array $meta = []): never
    {
        self::success($data, $message, 201, $meta);
    }

    /**
     * @param array<mixed> $details
     * @param array<mixed> $params
     */
    public static function error(string $message = 'Request failed.', int $status = 400, string $code = 'error', array $details = [], array $params = []): never
    {
        if (isset($details['errors']) && is_array($details['errors'])) { $details['errors'] = self::issues($details['errors']); }
        self::assertData($details);
        self::assertData($params);
        $payload = self::envelope(false, $status, $message, null, [], [
            'details' => $details,
        ], $code, $params);

        self::json($payload, $status);
    }

    /**
     * @param array<mixed> $errors
     */
    public static function validation(array $errors, string $message = 'Validation failed.'): never
    {
        self::error($message, 422, 'validation_error', ['errors' => $errors]);
    }

    /** @param array<mixed> $params
     * @return array<string, mixed>
     */
    public static function issue(string $code, string $message, array $params = []): array
    {
        return ['code' => $code, 'params' => $params, 'message' => $message];
    }

    /** @param array<mixed> $errors
     * @return array<mixed>
     */
    private static function issues(array $errors): array
    {
        foreach ($errors as $field => &$issues) {
            $issues = array_map(static function (mixed $issue) use ($field): array {
                if (is_array($issue) && isset($issue['code'], $issue['message'])) {
                    return ['code' => (string) $issue['code'], 'params' => (array) ($issue['params'] ?? []) + ['field' => (string) $field], 'message' => (string) $issue['message']];
                }
                return ['code' => 'invalid_value', 'params' => ['field' => (string) $field], 'message' => (string) $issue];
            }, (array) $issues);
        }
        unset($issues);
        return $errors;
    }

    public static function exception(Throwable $exception, bool $debug = false): never
    {
        $status = (int) $exception->getCode();

        if ($status < 400 || $status > 599) {
            $status = $exception instanceof InvalidArgumentException ? 400 : 500;
        }

        $message = $status >= 500 && !$debug ? 'Server error.' : $exception->getMessage();
        $code = $status >= 500 ? 'server_error' : 'bad_request';
        $details = [];

        if ($debug) {
            $details = [
                'exception' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ];
        }

        self::error($message, $status, $code, $details);
    }

    /**
     * @param array<mixed> $meta
     * @param array<mixed>|null $error
     * @param array<mixed> $params
     * @return array<mixed>
     */
    private static function envelope(
        bool $ok,
        int $status,
        ?string $message,
        mixed $data,
        array $meta,
        ?array $error = null,
        string $code = 'ok',
        array $params = []
    ): array {
        if (!preg_match('/^[a-z][a-z0-9_]*$/D', $code)) { throw new \LogicException('API outcome codes must be stable identifiers.'); }
        $payload = [
            'ok' => $ok,
            'status' => $status,
            'code' => $code,
            'params' => $params === [] ? (object) [] : $params,
            'message' => $message,
            'data' => $data,
            'meta' => $meta === [] ? (object) [] : $meta,
        ];

        if ($error !== null) {
            if (($error['details'] ?? null) === []) {
                $error['details'] = (object) [];
            }

            $payload['error'] = $error;
        }

        return $payload;
    }

    private static function assertData(mixed $data): void
    {
        if (is_object($data) || is_resource($data)) {
            throw new \LogicException('API resources must contain JSON data values.');
        }
        if (!is_array($data)) {
            return;
        }
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match('/(?:^|_)html(?:$|_)/i', $key) === 1) {
                throw new \LogicException('HTML presentation fields are not allowed in module API resources.');
            }
            if (is_string($key) && preg_match('/(?:^|_)(?:label|formatted)$|^(?:ui|components|translations|role_options|status_options|username_hint)$/i', $key) === 1) {
                throw new \LogicException('UI presentation fields are not allowed in module API resources: ' . $key);
            }
            self::assertData($value);
        }
    }
}
