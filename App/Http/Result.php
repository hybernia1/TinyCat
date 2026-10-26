<?php
declare(strict_types=1);

namespace TinyCat\Http;

final class Result
{
    /** @param array<string, mixed> $data */
    public static function from(array $data): self
    {
        $message = isset($data['message']) ? (string) $data['message'] : null;
        unset($data['message']);
        $meta = [];
        if (isset($data['pagination'])) { $meta['pagination'] = $data['pagination']; unset($data['pagination']); }
        return new self($data, $message, $meta);
    }
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly array $data,
        public readonly ?string $message = null,
        public readonly array $meta = []
    ) {
    }
}
