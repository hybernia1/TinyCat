<?php
declare(strict_types=1);

namespace TinyCat\Http;

use InvalidArgumentException;
use JsonException;
use \TinyCat\Support\Values;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Request
{
    private static ?self $current = null;
    /** @var array<mixed>|null */
    private ?array $payload = null;

    /**
     * @param array<mixed>|null $serverData
     * @param array<mixed>|null $queryData
     * @param array<mixed>|null $postData
     * @param array<mixed>|null $filesData
     */
    public function __construct(
        private readonly ?array $serverData = null,
        private readonly ?array $queryData = null,
        private readonly ?array $postData = null,
        private readonly ?array $filesData = null,
        private readonly ?string $rawBody = null
    ) {
    }

    public static function current(): self
    {
        return self::$current ??= new self();
    }

    public static function reset(): void
    {
        self::$current = null;
    }

    public function path(?string $path = null): string
    {
        if ($path === null || $path === '') {
            $uri = (string) ($this->server()['REQUEST_URI'] ?? '/');
            $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
        }

        $path = str_replace('\\', '/', rawurldecode($path));
        $path = '/' . trim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    public function payload(?string $key = null, mixed $default = null): mixed
    {
        if ($this->payload === null) {
            $this->payload = $this->parsePayload();
        }

        if ($key === null || $key === '') {
            return $this->payload;
        }

        return Values::get($this->payload, $key, $default);
    }

    public function input(?string $key = null, mixed $default = null): mixed
    {
        $input = array_replace_recursive($this->query(), $this->payload());

        if ($key === null || $key === '') {
            return $input;
        }

        return Values::get($input, $key, $default);
    }

    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    public function wantsJson(): bool
    {
        $accept = strtolower((string) ($this->server()['HTTP_ACCEPT'] ?? ''));
        $requestedWith = strtolower((string) ($this->server()['HTTP_X_REQUESTED_WITH'] ?? ''));

        return str_contains($accept, 'application/json') || $requestedWith === 'xmlhttprequest';
    }

    public function requestScheme(): string
    {
        $https = strtolower((string) ($this->server()['HTTPS'] ?? ''));

        return in_array($https, ['on', '1'], true)
            || (int) ($this->server()['SERVER_PORT'] ?? 0) === 443
            || strtolower((string) ($this->server()['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
            ? 'https'
            : 'http';
    }

    private function isJson(): bool
    {
        $contentType = strtolower((string) ($this->server()['CONTENT_TYPE'] ?? $this->server()['HTTP_CONTENT_TYPE'] ?? ''));

        return str_contains($contentType, 'application/json');
    }

    /**
     * @return array<mixed>
     */
    private function parsePayload(): array
    {
        if ($this->post() !== []) {
            return $this->post();
        }

        $raw = $this->rawBody ?? file_get_contents('php://input');
        $raw = $raw === false ? '' : trim($raw);

        if ($raw === '') {
            return [];
        }

        if ($this->isJson()) {
            try {
                $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new InvalidArgumentException('Invalid JSON request body.', 400, $exception);
            }

            if (!is_array($data)) {
                throw new InvalidArgumentException('JSON request body must be an object or array.', 400);
            }

            return $data;
        }

        $contentType = strtolower((string) ($this->server()['CONTENT_TYPE'] ?? $this->server()['HTTP_CONTENT_TYPE'] ?? ''));

        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($raw, $data);

            return $data;
        }

        return [];
    }

    public function method(): string
    {
        $method = strtoupper((string) ($this->server()['REQUEST_METHOD'] ?? 'GET'));

        if ($method !== 'POST') {
            return $method;
        }

        $override = '';

        foreach ([
            $this->server()['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? null,
            $this->post()['_method'] ?? null,
            $this->payload('_method', null),
        ] as $candidate) {
            if ($candidate !== null && !is_string($candidate)) {
                throw new InvalidArgumentException('Invalid HTTP method override.', 400);
            }

            $candidate = strtoupper(trim((string) $candidate));

            if ($candidate !== '') {
                $override = $candidate;
                break;
            }
        }

        return in_array($override, ['PUT', 'PATCH', 'DELETE'], true) ? $override : $method;
    }

    public function bearerToken(): ?string
    {
        $header = (string) ($this->server()['HTTP_AUTHORIZATION'] ?? $this->server()['REDIRECT_HTTP_AUTHORIZATION'] ?? '');

        if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /** @return array<mixed> */
    /**
     * @return array<mixed>
     */
    public function server(): array
    {
        return $this->serverData ?? $_SERVER;
    }

    /** @return array<mixed> */
    /**
     * @return array<mixed>
     */
    public function query(): array
    {
        return $this->queryData ?? $_GET;
    }

    /** @return array<mixed> */
    /**
     * @return array<mixed>
     */
    public function post(): array
    {
        return $this->postData ?? $_POST;
    }

    /** @return array<mixed> */
    /**
     * @return array<mixed>
     */
    public function files(): array
    {
        return $this->filesData ?? $_FILES;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $key = strtoupper(str_replace('-', '_', $name));
        if (!in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
            $key = 'HTTP_' . $key;
        }
        $value = $this->server()[$key] ?? $default;
        return is_string($value) ? $value : $default;
    }

    /**
     * @param array<mixed> $rules
     * @param array<mixed> $messages
     * @return array<mixed>
     */
    public function validated(array $rules, array $messages = []): array
    {
        return Validator::validated($rules, $this->input(), $messages);
    }
}
