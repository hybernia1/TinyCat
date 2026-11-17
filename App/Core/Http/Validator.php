<?php
declare(strict_types=1);

namespace TinyCat\Http;

use LogicException;
use \TinyCat\Support\Values;

if (!defined('TINYCAT')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Validator
{

    /** Public data constraints derived from the same owner rules used by validation.
     * @param array<string, array<mixed>|string> $rules
     * @return array<string, array<string, mixed>> */
    public static function constraints(array $rules): array
    {
        $fields = [];
        foreach ($rules as $field => $definition) {
            $normalized = self::normalizeRules($definition); $types = [];
            foreach ($normalized as $rule) {
                [$name] = self::parseRule($rule);
                $type = match ($name) {
                    'string', 'email', 'url', 'date', 'max_bytes' => 'string',
                    'int', 'integer' => 'integer', 'float', 'number', 'numeric' => 'number',
                    'bool', 'boolean', 'accepted' => 'boolean', 'array' => 'array', default => null,
                };
                if ($type !== null) { $types[$type] = true; }
            }
            if (count($types) !== 1) { throw new LogicException('Constraint metadata requires one explicit field type.'); }
            $type = (string) array_key_first($types);
            $constraints = ['type' => $type, 'required' => self::hasAnyRule($normalized, ['required']), 'nullable' => self::hasAnyRule($normalized, ['nullable'])];
            foreach ($normalized as $rule) {
                [$name, $params] = self::parseRule($rule);
                if ($name === 'in') { $constraints['values'] = isset($constraints['values']) ? array_values(array_intersect($constraints['values'], $params)) : $params; }
                elseif ($name === 'max_bytes') { $constraints['max_bytes'] = min($constraints['max_bytes'] ?? PHP_INT_MAX, (int) $params[0]); }
                elseif (in_array($name, ['min', 'max', 'between'], true)) {
                    $suffix = in_array($type, ['integer', 'number'], true) ? '' : ($type === 'array' ? '_items' : '_length');
                    if ($name !== 'max') { $constraints['min' . $suffix] = max($constraints['min' . $suffix] ?? -INF, 0 + $params[0]); }
                    if ($name !== 'min') { $constraints['max' . $suffix] = min($constraints['max' . $suffix] ?? INF, 0 + $params[$name === 'between' ? 1 : 0]); }
                }
            }
            $fields[$field] = $constraints;
        }
        return $fields;
    }

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

        $normalized = [];
        foreach ($rules as $rule) {
            if (!is_string($rule)) { throw new LogicException('Validation rules must be strings.'); }
            $rule = trim($rule);
            if ($rule === '') { continue; }
            [$name, $params] = self::parseRule($rule);
            $count = match ($name) {
                'required', 'nullable', 'accepted', 'array', 'bool', 'boolean', 'date', 'email', 'float', 'number', 'numeric', 'int', 'integer', 'string', 'url' => 0,
                'min', 'max', 'max_bytes' => 1,
                'between' => 2,
                'in' => count($params) > 0 ? count($params) : -1,
                default => throw new LogicException('Unknown validation rule: ' . $name),
            };
            if (count($params) !== $count) { throw new LogicException('Invalid validation rule parameters: ' . $name); }
            if (in_array($name, ['min', 'max', 'between', 'max_bytes'], true)) {
                foreach ($params as $param) { if (!is_numeric($param) || !is_finite((float) $param)) { throw new LogicException('Validation bounds must be finite numbers.'); } }
                if ($name === 'max_bytes' && ((float) $params[0] < 0 || (float) $params[0] > PHP_INT_MAX || floor((float) $params[0]) !== (float) $params[0])) { throw new LogicException('Byte bounds must be nonnegative integers.'); }
                if ($name === 'between' && (float) $params[0] > (float) $params[1]) { throw new LogicException('Validation bounds must be ordered.'); }
            }
            $normalized[] = $rule;
        }
        return $normalized;
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
            'float', 'number', 'numeric' => is_numeric($value) && is_finite((float) $value),
            'in' => is_scalar($value) && in_array((string) $value, array_map('strval', $params), true),
            'int', 'integer' => (is_int($value) || is_string($value)) && filter_var($value, FILTER_VALIDATE_INT) !== false,
            'max' => self::valueSize($value, $numericRule) <= (float) ($params[0] ?? 0),
            'max_bytes' => is_string($value) && strlen($value) <= (int) $params[0],
            'min' => self::valueSize($value, $numericRule) >= (float) ($params[0] ?? 0),
            'between' => self::valueSize($value, $numericRule) >= (float) ($params[0] ?? 0)
                && self::valueSize($value, $numericRule) <= (float) ($params[1] ?? INF),
            'string' => is_string($value),
            'url' => is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false,
            default => throw new LogicException('Unknown validation rule: ' . $rule),
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
        if ($custom !== null && !is_string($custom)) { throw new LogicException('Validation messages must be strings.'); }
        if (!$structured) { return (string) ($custom ?? 'validation.' . $field . '.' . $rule); }
        $params = ['field' => $field, 'rule' => $rule];
        $message = $custom !== null ? (string) $custom : \TinyCat\Support\Localization::t($rule === 'required' ? 'common.validation_required' : 'common.validation_invalid', $params);
        return Response::issue($custom !== null ? 'invalid_value' : 'validation_' . $rule, $message, $params);
    }
}
