<?php
declare(strict_types=1);
namespace TinyCat\Core\Modules;

/** An owner declares its public interface paths, never a translated copy of content. */
final readonly class LocalizedRoute
{
    /** @param list<string> $query */
    public function __construct(public string $id, public string $pattern, public array $query = [], public bool $translatedDocument = false, public int $order = 0)
    {
        if (@preg_match('~' . $pattern . '~Du', '/') === false) { throw new \InvalidArgumentException('Invalid localized route pattern.'); }
        foreach ($query as $key) { if (preg_match('/^[a-z][a-z0-9_]*$/D', $key) !== 1) { throw new \InvalidArgumentException('Invalid localized query key.'); } }
    }
    public function matches(string $path): bool { return preg_match('~' . $this->pattern . '~Du', $path) === 1; }
}
