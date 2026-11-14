<?php
declare(strict_types=1);

namespace TinyCat\Core\Identity;

use Closure;

/** A domain integration definition, never an eager database operation. */
final readonly class Participant
{
    /** @var Closure(array<mixed>): Deletion */
    public Closure $prepareDelete;
    /** @var Closure(array<mixed>, bool): void */
    public Closure $statusChanged;

    /** @param callable(array<mixed>): Deletion|null $prepareDelete
     * @param callable(array<mixed>, bool): void|null $statusChanged */
    public function __construct(?callable $prepareDelete = null, ?callable $statusChanged = null)
    {
        $this->prepareDelete = Closure::fromCallable($prepareDelete ?? static fn (array $user): Deletion => new Deletion());
        $this->statusChanged = Closure::fromCallable($statusChanged ?? static function (array $user, bool $active): void {});
    }
}
