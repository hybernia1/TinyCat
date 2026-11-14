<?php
declare(strict_types=1);

namespace TinyCat\Core\Modules;

use Closure;

final readonly class ScheduledTask
{
    /** @var Closure(array<string, mixed>): array<string, mixed> */
    public Closure $runner;
    /** @var Closure(array<string, int>): array<string, int>|null */
    public ?Closure $normalize;
    /** @var Closure(): (int|null)|null */
    public ?Closure $healthAge;
    /** @var Closure(array<string, mixed>): array<string, mixed>|null */
    public ?Closure $detail;

    /** @param callable(array<string, mixed>): array<string, mixed> $runner
     * @param array<string, int> $options
     * @param array<string, string> $admin
     * @param array<string, list<string>> $modeOptions
     * @param (callable(array<string, int>): array<string, int>)|null $normalize
     * @param (callable(): (int|null))|null $healthAge
     * @param (callable(array<string, mixed>): array<string, mixed>)|null $detail */
    public function __construct(public string $id, callable $runner, public array $options = [], public array $admin = [], public int $order = 100, public array $modeOptions = [], ?callable $normalize = null, ?callable $healthAge = null, ?callable $detail = null)
    {
        $this->runner = Closure::fromCallable($runner);
        $this->normalize = $normalize === null ? null : Closure::fromCallable($normalize);
        $this->healthAge = $healthAge === null ? null : Closure::fromCallable($healthAge);
        $this->detail = $detail === null ? null : Closure::fromCallable($detail);
        foreach ($modeOptions as $mode => $names) {
            if (!in_array($mode, ['cli', 'http', 'admin'], true) || count($names) !== count(array_unique($names))) {
                throw new \InvalidArgumentException('Invalid scheduled task mode options.');
            }
        }
    }

    /** @return list<string> */
    public function optionKeys(string $mode): array { return array_values(array_unique([...array_keys($this->options), ...($this->modeOptions[$mode] ?? [])])); }

    /** Keep the public 5.x shape used by execution, pages and API adapters.
     * @return array{runner: Closure, options: array<string, int>, admin: array<string, string>} */
    public function definition(): array
    {
        return ['runner' => $this->runner, 'options' => $this->options,
            'admin' => array_replace(['icon' => 'clock', 'title' => $this->id, 'help' => '', 'schedule' => ''], $this->admin)];
    }
}
