<?php
declare(strict_types=1);

namespace TinyCat\Core\Runtime;

use Closure;

final readonly class Context
{
    /** @var Closure(): (array<string, mixed>|null) */
    private Closure $identity;
    /** @var Closure(): bool */
    private Closure $ready;
    /** @var Closure(): mixed */
    private Closure $locale;
    /** @var Closure(string, mixed): mixed */
    private Closure $dates;

    /** @param (callable(): (array<string, mixed>|null))|null $identity
     * @param (callable(): bool)|null $ready
     * @param (callable(): mixed)|null $locale
     * @param (callable(string, mixed): mixed)|null $dates */
    public function __construct(public Configuration $configuration = new Configuration(), ?callable $identity = null, ?callable $ready = null, ?callable $locale = null, ?callable $dates = null)
    {
        $this->identity = $identity === null ? static fn (): null => null : Closure::fromCallable($identity);
        $this->ready = $ready === null ? static fn (): bool => true : Closure::fromCallable($ready);
        $this->locale = $locale === null
            ? static fn (): mixed => $configuration->get('i18n.locale', $configuration->get('install.locale', 'en'))
            : Closure::fromCallable($locale);
        $this->dates = $dates === null ? $configuration->get(...) : Closure::fromCallable($dates);
    }

    /** @return array<string, mixed>|null */
    public function identity(): ?array { return ($this->identity)(); }
    public function ready(): bool { return ($this->ready)(); }
    public function locale(): mixed { return ($this->locale)(); }
    public function dateOption(string $key, mixed $default = null): mixed { return ($this->dates)($key, $default); }
}
