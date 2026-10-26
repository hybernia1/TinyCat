<?php
declare(strict_types=1);

namespace TinyCat\Http;

use InvalidArgumentException;
use \TinyCat\Support\Values;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Validator
{

    /**
     * @param array<mixed> $data
     * @param array<mixed> $rules
     * @param array<mixed> $messages
     * @return array<mixed>
     */
    public static function validate(array $data, array $rules, array $messages = [], bool $structured = false): array
    {
        $errors = [];

        foreach ($rules as $field => $fieldRules) {
            $field = (string) $field;
            $fieldRules = self::normalizeRules($fieldRules);
            $exists = Values::has($data, $field);
            $value = Values::get($data, $field);
            $required = self::hasAnyRule($fieldRules, ['required']);
            $nullable = self::hasAnyRule($fieldRules, ['nullable']);

            if ($required && self::blank($value, $exists)) {
                $errors[$field][] = self::validationMessage($messages, $field, 'required', $structured);
                continue;
            }

            if (!$exists || (($value === null || $value === '') && $nullable)) {
                continue;
            }

            foreach ($fieldRules as $rule) {
                [$name, $params] = self::parseRule($rule);

                if (in_array($name, ['required', 'nullable'], true)) {
                    continue;
                }

                if (!self::passesRule($value, $name, $params, $fieldRules)) {
                    $errors[$field][] = self::validationMessage($messages, $field, $name, $structured);
                }
            }
        }

        return $errors;
    }

    /**
     * @param array<mixed> $rules
     * @param array<mixed>|null $data
     * @param array<mixed> $messages
     * @return array<mixed>
     */
    public static function validated(array $rules, ?array $data = null, array $messages = []): array
    {
        $data ??= Request::current()->input();
        $errors = self::validate($data, $rules, $messages, true);

        if ($errors !== []) {
            Response::validation($errors);
        }

        $validated = [];

        foreach (array_keys($rules) as $field) {
            $field = (string) $field;

            if (Values::has($data, $field)) {
                $validated[$field] = Values::get($data, $field);
            }
        }

        return $validated;
    }

    /**
     * @param array<mixed>|string $rules
     * @return array<mixed>
     */
    private static function normalizeRules(array|string $rules): array
    {
        $rules = is_string($rules) ? explode('|', $rules) : $rules;

        return array_values(array_filter(array_map(
            static fn (mixed $rule): string => trim((string) $rule),
            $rules
        ), static fn (string $rule): bool => $rule !== ''));
    }

    /**
     * @return array<mixed>
     */
    private static function parseRule(string $rule): array
    {
        [$name, $params] = array_pad(explode(':', $rule, 2), 2, '');

        return [strtolower($name), $params === '' ? [] : array_map('trim', explode(',', $params))];
    }

    /**
     * @param array<mixed> $params
     * @param array<mixed> $rules
     */
    private static function passesRule(mixed $value, string $rule, array $params, array $rules): bool
    {
        $numericRule = self::hasAnyRule($rules, ['int', 'integer', 'float', 'number', 'numeric']);

        return match ($rule) {
            'accepted' => in_array($value, [true, 1, '1', 'yes', 'on', 'true'], true),
            'array' => is_array($value),
            'bool', 'boolean' => is_bool($value) || in_array($value, [0, 1, '0', '1', 'true', 'false', 'on', 'off', 'yes', 'no'], true),
            'date' => is_string($value) && strtotime($value) !== false,
            'email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'float', 'number', 'numeric' => is_numeric($value),
            'in' => in_array((string) $value, array_map('strval', $params), true),
            'int', 'integer' => filter_var($value, FILTER_VALIDATE_INT) !== false,
            'max' => self::valueSize($value, $numericRule) <= (float) ($params[0] ?? 0),
            'min' => self::valueSize($value, $numericRule) >= (float) ($params[0] ?? 0),
            'between' => self::valueSize($value, $numericRule) >= (float) ($params[0] ?? 0)
                && self::valueSize($value, $numericRule) <= (float) ($params[1] ?? INF),
            'string' => is_string($value),
            'url' => is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false,
            default => throw new InvalidArgumentException('Unknown validation rule: ' . $rule),
        };
    }

    /**
     * @param array<mixed> $rules
     * @param array<mixed> $needles
     */
    private static function hasAnyRule(array $rules, array $needles): bool
    {
        foreach ($rules as $rule) {
            [$name] = self::parseRule($rule);

            if (in_array($name, $needles, true)) {
                return true;
            }
        }

        return false;
    }

    private static function valueSize(mixed $value, bool $numeric): float
    {
        if ($numeric && is_numeric($value)) {
            return (float) $value;
        }

        if (is_array($value)) {
            return (float) count($value);
        }

        $value = Values::string($value);

        return (float) (function_exists('mb_strlen') ? mb_strlen($value) : strlen($value));
    }

    private static function blank(mixed $value, bool $exists): bool
    {
        return !$exists || $value === null || $value === '' || $value === [];
    }

    /**
     * @param array<mixed> $messages
     * @return array<string, mixed>|string
     */
    private static function validationMessage(array $messages, string $field, string $rule, bool $structured): array|string
    {
        $custom = is_array($messages[$field] ?? null) ? ($messages[$field][$rule] ?? null) : null;
        $custom ??= $messages[$field . '.' . $rule] ?? $messages[$rule] ?? null;
        if (!$structured) { return (string) ($custom ?? 'validation.' . $field . '.' . $rule); }
        $params = ['field' => $field, 'rule' => $rule];
        $message = $custom !== null ? (string) $custom : \TinyCat\Support\Localization::t($rule === 'required' ? 'common.validation_required' : 'common.validation_invalid', $params);
        return Response::issue($custom !== null ? 'invalid_value' : 'validation_' . $rule, $message, $params);
    }
}
