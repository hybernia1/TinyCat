<?php
declare(strict_types=1);

namespace TinyCat\Http;

final class Result
{
    /** @param array<string, mixed> $data */
    public static function from(array $data): self
    {
        $message = isset($data['message']) ? (string) $data['message'] : null;
        $code = isset($data['code']) ? (string) $data['code'] : null;
        $params = (array) ($data['params'] ?? []);
        unset($data['message'], $data['code'], $data['params']);
        $meta = [];
        if (isset($data['pagination'])) { $meta['pagination'] = $data['pagination']; unset($data['pagination']); }
        return new self($data, $message, $meta, $code, $params);
    }
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     * @param array<mixed> $params
     */
    public function __construct(
        public readonly array $data,
        public readonly ?string $message = null,
        public readonly array $meta = [],
        public readonly ?string $code = null,
        public readonly array $params = []
    ) {
    }
}
