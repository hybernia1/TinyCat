<?php
declare(strict_types=1);

namespace TinyCat\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class Dates
{

    public static function timezone(): DateTimeZone
    {
        static $zones = [];
        $name = (string) \TinyCat\Settings\Store::config('datetime.timezone', date_default_timezone_get());

        return $zones[$name] ??= new DateTimeZone($name);
    }

    public static function dateTime(mixed $value = null, ?string $format = null): string
    {
        $format ??= (string) \TinyCat\Settings\Store::config('datetime.datetime', 'Y-m-d H:i:s');

        return self::toDateTime($value)->format($format);
    }

    public static function dateValue(mixed $value = null, ?string $format = null): string
    {
        $format ??= (string) \TinyCat\Settings\Store::config('datetime.date', 'Y-m-d');

        return self::toDateTime($value)->format($format);
    }

    public static function dateIso(mixed $value = null): string
    {
        return self::toDateTime($value)->format((string) \TinyCat\Settings\Store::config('datetime.iso', DATE_ATOM));
    }

    public static function dateDb(mixed $value = null): string
    {
        return self::toDateTime($value)->format((string) \TinyCat\Settings\Store::config('datetime.database', 'Y-m-d H:i:s'));
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

    /**
     * @return array<mixed>
     */
    public static function date_format_presets(): array
    {
        return [
            'd.m.Y' => 'd.m.Y',
            'j.n.Y' => 'j.n.Y',
            'd. m. Y' => 'd. m. Y',
            'Y-m-d' => 'Y-m-d',
            'd/m/Y' => 'd/m/Y',
            'm/d/Y' => 'm/d/Y',
            'd-m-Y' => 'd-m-Y',
            'M j, Y' => 'M j, Y',
        ];
    }

    /**
     * @return array<mixed>
     */
    public static function time_format_presets(): array
    {
        return [
            'H:i' => 'H:i',
            'H:i:s' => 'H:i:s',
            'G:i' => 'G:i',
            'g:i A' => 'g:i A',
            'h:i A' => 'h:i A',
        ];
    }

    /**
     * @return array<mixed>
     */
    public static function datetime_format_presets(): array
    {
        return [
            'd.m.Y H:i' => 'd.m.Y H:i',
            'j.n.Y H:i' => 'j.n.Y H:i',
            'd. m. Y H:i' => 'd. m. Y H:i',
            'Y-m-d H:i' => 'Y-m-d H:i',
            'Y-m-d H:i:s' => 'Y-m-d H:i:s',
            'd/m/Y H:i' => 'd/m/Y H:i',
            'm/d/Y g:i A' => 'm/d/Y g:i A',
            'M j, Y g:i A' => 'M j, Y g:i A',
            'Y-m-d\TH:i' => 'Y-m-d\TH:i',
        ];
    }

    public static function datetime_format_preset_options(string $type, ?string $selected = null): string
    {
        $selected = trim((string) $selected);
        $presets = match ($type) {
            'date' => \TinyCat\Support\Dates::date_format_presets(),
            'time' => \TinyCat\Support\Dates::time_format_presets(),
            'datetime' => \TinyCat\Support\Dates::datetime_format_presets(),
            default => [],
        };
        $sample = new DateTimeImmutable('2026-07-05 15:04:09');
        $html = '';

        foreach ($presets as $format => $label) {
            $example = $sample->format((string) $format);
            $html .= '<option value="' . \TinyCat\Support\Html::e($format) . '"' . ($selected === $format ? ' selected' : '') . '>'
                . \TinyCat\Support\Html::e((string) $label . ' - ' . $example)
                . '</option>';
        }

        return $html;
    }

    public static function datetime_format_preset_exists(string $type, string $format): bool
    {
        $presets = match ($type) {
            'date' => \TinyCat\Support\Dates::date_format_presets(),
            'time' => \TinyCat\Support\Dates::time_format_presets(),
            'datetime' => \TinyCat\Support\Dates::datetime_format_presets(),
            default => [],
        };

        return array_key_exists($format, $presets);
    }

    /**
     * @return array<mixed>
     */
    public static function timezone_presets(): array
    {
        $defaults = [
            'UTC',
            'Europe/Prague',
            'Europe/Bratislava',
            'Europe/Warsaw',
            'Europe/Berlin',
            'Europe/Vienna',
            'Europe/London',
            'America/New_York',
            'America/Chicago',
            'America/Denver',
            'America/Los_Angeles',
            'America/Toronto',
            'America/Sao_Paulo',
            'Asia/Dubai',
            'Asia/Tokyo',
            'Asia/Shanghai',
            'Australia/Sydney',
        ];
        $items = $defaults;
        $valid = array_flip(timezone_identifiers_list());
        $presets = [];

        foreach ($items as $value) {
            $timezone = $value;
            $label = $timezone;

            if (isset($valid[$timezone])) {
                $presets[$timezone] = $label;
            }
        }

        return $presets;
    }

    public static function timezone_preset_label(string $timezone, ?string $label = null): string
    {
        $date = new DateTimeImmutable('now', new DateTimeZone($timezone));
        $offset = $date->format('P');

        return '(UTC' . ($offset === '+00:00' ? '' : $offset) . ') ' . ($label ?: $timezone);
    }

    public static function timezone_options(?string $selected = null): string
    {
        $selected = trim((string) $selected);
        $presets = \TinyCat\Support\Dates::timezone_presets();
        $valid = array_flip(timezone_identifiers_list());

        if ($selected !== '' && isset($valid[$selected]) && !isset($presets[$selected])) {
            $presets = [$selected => $selected] + $presets;
        }

        $html = '';

        foreach ($presets as $timezone => $label) {
            $html .= '<option value="' . \TinyCat\Support\Html::e($timezone) . '"' . ($selected === $timezone ? ' selected' : '') . '>'
                . \TinyCat\Support\Html::e(\TinyCat\Support\Dates::timezone_preset_label((string) $timezone, (string) $label))
                . '</option>';
        }

        return $html;
    }

    public static function displayDateTime(mixed $value = null, ?string $format = null, ?bool $relative = null): string
    {
        if ($relative === true || ($relative === null && $format === null && (bool) \TinyCat\Settings\Store::config('datetime.relative', false))) {
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

        return \TinyCat\Support\Localization::plural('relative.' . $direction . '.' . $unit, $count);
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
