<?php
declare(strict_types=1);
namespace TinyCat\Core\Runtime;

/** Narrow request policies; headers, readiness, maintenance and dispatch stay in the kernel. */
final readonly class HttpPolicy
{
    /** @var \Closure(string): (string|null)|null */
    public ?\Closure $prepare;
    /** @var \Closure(string): bool|null */
    public ?\Closure $maintenanceExempt;
    /** @var \Closure(string): bool|null */
    public ?\Closure $databaseExempt;
    /** @var \Closure(string): void|null */
    public ?\Closure $databaseUnavailable;
    /** @var \Closure(string): void|null */
    public ?\Closure $beforeDispatch;
    /** @var \Closure(): void|null */
    public ?\Closure $notFound;

    /** @param (callable(string): (string|null))|null $prepare
     * @param (callable(string): bool)|null $maintenanceExempt
     * @param (callable(string): bool)|null $databaseExempt
     * @param (callable(string): void)|null $databaseUnavailable
     * @param (callable(string): void)|null $beforeDispatch
     * @param (callable(): void)|null $notFound */
    public function __construct(?callable $prepare = null, ?callable $maintenanceExempt = null, ?callable $databaseExempt = null, ?callable $databaseUnavailable = null, ?callable $beforeDispatch = null, ?callable $notFound = null)
    {
        $this->prepare = $prepare === null ? null : \Closure::fromCallable($prepare);
        $this->maintenanceExempt = $maintenanceExempt === null ? null : \Closure::fromCallable($maintenanceExempt);
        $this->databaseExempt = $databaseExempt === null ? null : \Closure::fromCallable($databaseExempt);
        $this->databaseUnavailable = $databaseUnavailable === null ? null : \Closure::fromCallable($databaseUnavailable);
        $this->beforeDispatch = $beforeDispatch === null ? null : \Closure::fromCallable($beforeDispatch);
        $this->notFound = $notFound === null ? null : \Closure::fromCallable($notFound);
    }
}
