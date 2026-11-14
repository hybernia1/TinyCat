<?php
declare(strict_types=1);

namespace TinyCat\Core\Identity;

/** Internal field diagnostics; the HTTP adapter owns translated API issues. */
final class ValidationException extends \DomainException
{
    /** @param array<string, list<string>> $issues Message keys, never user input or credentials. */
    public function __construct(public readonly array $issues) { parent::__construct('Account validation failed.'); }
}
