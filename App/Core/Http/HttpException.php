<?php
declare(strict_types=1);

namespace TinyCat\Http;

/** An explicitly safe public failure. Numeric codes on other exceptions are not HTTP statuses. */
final class HttpException extends \InvalidArgumentException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        string $message,
        public readonly int $status = 400,
        public readonly string $errorCode = 'bad_request',
        public readonly array $details = [],
        ?\Throwable $previous = null,
    ) {
        if ($status < 400 || $status > 599 || preg_match('/^[a-z][a-z0-9_]*$/D', $errorCode) !== 1) {
            throw new \LogicException('Invalid public failure definition.');
        }
        parent::__construct($message, $status, $previous);
    }
}
