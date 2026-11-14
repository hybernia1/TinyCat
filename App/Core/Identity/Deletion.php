<?php
declare(strict_types=1);

namespace TinyCat\Core\Identity;

use Closure;

/** Prepared inside the account transaction; files are touched only after commit. */
final readonly class Deletion
{
    /** @var Closure(): void */
    public Closure $afterDelete;
    /** @var Closure(): void */
    public Closure $afterCommit;

    /** @param callable(): void|null $afterDelete
     * @param callable(): void|null $afterCommit */
    public function __construct(?callable $afterDelete = null, ?callable $afterCommit = null)
    {
        $this->afterDelete = Closure::fromCallable($afterDelete ?? static function (): void {});
        $this->afterCommit = Closure::fromCallable($afterCommit ?? static function (): void {});
    }
}
