<?php
declare(strict_types=1);

namespace TinyCat\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class Dates
{
    /** @var \Closure(string, mixed): mixed|null */
    private static ?\Closure $optionResolver = null;

    /** @param callable(string, mixed): mixed $resolver */
    public static function configure(callable $resolver): void { self::$optionResolver = \Closure::fromCallable($resolver); }

    private static function option(string $key, mixed $default = null): mixed
    {
        return self::$optionResolver !== null ? (self::$optionResolver)($key, $default) : Config::get($key, $default);
    }

    public static function timezone(): DateTimeZone
    {
        static $zones = [];
        $name = (string) self::option('datetime.timezone', date_default_timezone_get());

        return $zones[$name] ??= new DateTimeZone($name);
    }

    public static function dateTime(mixed $value = null, ?string $format = null): string
    {
        $format ??= (string) self::option('datetime.datetime', 'Y-m-d H:i:s');

        return self::toDateTime($value)->format($format);
    }

    public static function dateIso(mixed $value = null): string
    {
        return self::toDateTime($value)->format((string) self::option('datetime.iso', DATE_ATOM));
    }

    public static function dateDb(mixed $value = null): string
    {
        return self::toDateTime($value)->format((string) self::option('datetime.database', 'Y-m-d H:i:s'));
    }

    private static function toDateTime(mixed $value = null): DateTimeImmutable
    {
        $timezone = self::timezone();

        if ($value instanceof DateTimeImmutable) {
            return $value->setTimezone($timezone);
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone($timezone);
        }

        if ($value === null || $value === '') {
            return new DateTimeImmutable('now', $timezone);
        }

        if (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value))) {
            return (new DateTimeImmutable('@' . (int) $value))->setTimezone($timezone);
        }

        return new DateTimeImmutable((string) $value, $timezone);
    }

    public static function displayDateTime(mixed $value = null, ?string $format = null, ?bool $relative = null): string
    {
        if ($relative === true || ($relative === null && $format === null && (bool) self::option('datetime.relative', false))) {
            return \TinyCat\Support\Dates::relative_time($value);
        }

        return \TinyCat\Support\Dates::dateTime($value, $format);
    }

    public static function relative_time(mixed $value = null, mixed $base = null): string
    {
        $target = \TinyCat\Support\Dates::relative_datetime_value($value);
        $origin = \TinyCat\Support\Dates::relative_datetime_value($base);
        $seconds = $target->getTimestamp() - $origin->getTimestamp();
        $absolute = abs($seconds);

        if ($absolute < 45) {
            return \TinyCat\Support\Localization::t('relative.now');
        }

        [$unit, $count] = match (true) {
            $absolute < 90 => ['minute', 1],
            $absolute < 45 * 60 => ['minute', (int) round($absolute / 60)],
            $absolute < 90 * 60 => ['hour', 1],
            $absolute < 22 * 3600 => ['hour', (int) round($absolute / 3600)],
            $absolute < 36 * 3600 => ['day', 1],
            $absolute < 7 * 86400 => ['day', (int) round($absolute / 86400)],
            $absolute < 4 * 604800 => ['week', max(1, (int) round($absolute / 604800))],
            $absolute < 45 * 86400 => ['month', 1],
            $absolute < 345 * 86400 => ['month', max(1, (int) round($absolute / (30 * 86400)))],
            $absolute < 545 * 86400 => ['year', 1],
            default => ['year', max(1, (int) round($absolute / (365 * 86400)))],
        };
        $direction = $seconds < 0 ? 'past' : 'future';

        $key = 'relative.' . $direction . '.' . $unit;
        return $count === 1
            ? \TinyCat\Support\Localization::t($key . '.single')
            : \TinyCat\Support\Localization::plural($key, $count);
    }

    public static function relative_datetime_value(mixed $value = null): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value->setTimezone(\TinyCat\Support\Dates::timezone());
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone(\TinyCat\Support\Dates::timezone());
        }

        if (is_int($value) || is_float($value)) {
            return (new DateTimeImmutable('@' . (int) $value))->setTimezone(\TinyCat\Support\Dates::timezone());
        }

        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return new DateTimeImmutable('now', \TinyCat\Support\Dates::timezone());
        }

        return new DateTimeImmutable($value, \TinyCat\Support\Dates::timezone());
    }

}
