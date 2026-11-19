<?php
declare(strict_types=1);

namespace TinyCat\Core\Modules;

use Closure;

final readonly class ScheduledTask
{
    public const string ID_PATTERN = '[a-z][a-z0-9_.-]{0,39}';
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
     * @param (callable(array<string, mixed>): array<string, mixed>)|null $detail
     * @param array<string, string> $optionLabels */
    public function __construct(public string $id, callable $runner, public array $options = [], public array $admin = [], public int $order = 100, public array $modeOptions = [], ?callable $normalize = null, ?callable $healthAge = null, ?callable $detail = null, public ?string $batchOption = null, public array $optionLabels = [])
    {
        if ($id === 'all' || preg_match('/^' . self::ID_PATTERN . '$/D', $id) !== 1) { throw new \InvalidArgumentException('Invalid scheduled task ID.'); }
        foreach (array_keys($options) as $key) {
            if (preg_match('/^[a-z][a-z0-9_]*$/D', $key) !== 1) { throw new \InvalidArgumentException('Invalid scheduled task default option.'); }
        }
        if ($batchOption !== null && !array_key_exists($batchOption, $options)) { throw new \InvalidArgumentException('Unknown scheduled task batch option.'); }
        $this->runner = Closure::fromCallable($runner);
        $this->normalize = $normalize === null ? null : Closure::fromCallable($normalize);
        $this->healthAge = $healthAge === null ? null : Closure::fromCallable($healthAge);
        $this->detail = $detail === null ? null : Closure::fromCallable($detail);
        $allowed = array_keys($options);
        foreach ($modeOptions as $names) { $allowed = [...$allowed, ...$names]; }
        foreach ($optionLabels as $name => $label) {
            if (!in_array($name, $allowed, true) || $label === '') { throw new \InvalidArgumentException('Invalid scheduled task option label.'); }
        }
        foreach ($modeOptions as $mode => $names) {
            if (!in_array($mode, ['cli', 'http', 'admin'], true) || count($names) !== count(array_unique($names))) {
                throw new \InvalidArgumentException('Invalid scheduled task mode options.');
            }
            foreach ($names as $name) {
                if (preg_match('/^[a-z][a-z0-9_]*$/D', $name) !== 1) { throw new \InvalidArgumentException('Invalid scheduled task option name.'); }
            }
        }
    }

    /** @return list<string> */
    public function optionKeys(string $mode): array { return array_values(array_unique([...array_keys($this->options), ...($this->modeOptions[$mode] ?? [])])); }

    /** Keep the public 5.x shape used by execution, pages and API adapters.
     * @return array{runner: Closure, options: array<string, int>, admin: array<string, string>} */
    public function definition(): array
    {
        $admin = array_replace(['icon' => 'clock', 'title' => $this->id, 'help' => '', 'schedule' => ''], $this->admin);
        if ($admin['icon'] === '') { $admin['icon'] = 'clock'; }
        return ['runner' => $this->runner, 'options' => $this->options,
            'admin' => $admin];
    }
}
