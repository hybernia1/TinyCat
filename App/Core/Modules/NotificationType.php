<?php
declare(strict_types=1);
namespace TinyCat\Core\Modules;

/** A selected owner supplies subject visibility and canonical links in batches. */
final readonly class NotificationType
{
    /** @var \Closure(array<mixed>): array<mixed> */
    public \Closure $hydrate;
    /** @var \Closure(array<string, mixed>): string */
    public \Closure $target;
    /** @var \Closure(array<string, mixed>): string */
    public \Closure $message;

    /** @param callable(array<mixed>): array<mixed> $hydrate
     * @param callable(array<string, mixed>): string $target
     * @param callable(array<string, mixed>): string|null $message
     * @param string $subject Validated policy: none, optional or required. */
    public function __construct(
        public string $id,
        callable $hydrate,
        callable $target,
        public string $hydrationGroup = '',
        public string $subject = 'none',
        ?callable $message = null,
        public string $icon = 'bell',
    )
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,39}$/D', $id) !== 1 || !in_array($subject, ['none', 'optional', 'required'], true) || preg_match('/^[a-z][a-z0-9-]*$/D', $icon) !== 1) {
            throw new \InvalidArgumentException('Invalid notification type definition.');
        }
        $this->hydrate = \Closure::fromCallable($hydrate);
        $this->target = \Closure::fromCallable($target);
        $this->message = $message === null ? static fn (array $row): string => \TinyCat\Support\Localization::t('notifications.messages.generic') : \Closure::fromCallable($message);
    }

    public function validateSubject(?int $id): void
    {
        if (($id !== null && $id < 1) || ($this->subject === 'required' && $id === null) || ($this->subject === 'none' && $id !== null)) {
            throw new \InvalidArgumentException('Invalid subject for notification type: ' . $this->id);
        }
    }
}
