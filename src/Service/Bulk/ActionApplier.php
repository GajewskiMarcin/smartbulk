<?php
/**
 * SmartBulk — ActionApplier
 *
 * Pure function: given an old value + an action spec, compute the new value.
 * No DB access — read/write handled by BulkEditorService.
 *
 * @author    marcingajewski.pl
 * @copyright 2026 marcingajewski.pl
 * @license   https://opensource.org/licenses/AFL-3.0 AFL-3.0
 */

declare(strict_types=1);

namespace SmartBulk\Service\Bulk;

use InvalidArgumentException;

final class ActionApplier
{
    /**
     * @param mixed $oldValue
     * @param array<string,mixed> $action  { operator, value?, find?, replace?, math_op?, math_value? }
     * @param string $fieldType 'text'|'numeric'|'int'|'bool'|'enum'
     * @param array<string,mixed> $fieldDef Optional field metadata (min_value, char_limit, pattern).
     * @return mixed  New value (same conceptual type as $oldValue)
     */
    public function apply($oldValue, array $action, string $fieldType, array $fieldDef = [])
    {
        return $this->applyWithFlags($oldValue, $action, $fieldType, $fieldDef)['value'];
    }

    /**
     * Like apply() but also returns flags about what happened during enforcement
     * (truncate, min_value clamp). Flags are used by the preview summary.
     *
     * @param mixed $oldValue
     * @param array<string,mixed> $action
     * @param array<string,mixed> $fieldDef
     * @return array{value: mixed, flags: string[]}
     */
    public function applyWithFlags($oldValue, array $action, string $fieldType, array $fieldDef = []): array
    {
        $operator = (string) ($action['operator'] ?? 'skip');
        if ($operator === 'skip') {
            return ['value' => $oldValue, 'flags' => []];
        }
        if ($operator === 'clear') {
            return ['value' => $this->zeroValueFor($fieldType), 'flags' => []];
        }

        switch ($fieldType) {
            case 'text': {
                $raw   = $this->applyText((string) ($oldValue ?? ''), $operator, $action);
                $final = $this->enforceTextLimits($raw, $fieldDef);
                $flags = [];
                if ($raw !== $final) $flags[] = 'truncated';
                return ['value' => $final, 'flags' => $flags];
            }
            case 'numeric': {
                $raw   = $this->applyNumeric(is_numeric($oldValue) ? (float) $oldValue : 0.0, $operator, $action);
                $final = $this->enforceMinValue($raw, $fieldDef);
                $flags = [];
                if (abs(((float) $raw) - ((float) $final)) > 1e-9) $flags[] = 'below_min_clamped';
                return ['value' => $final, 'flags' => $flags];
            }
            case 'int': {
                $raw   = $this->applyInt(is_numeric($oldValue) ? (int) $oldValue : 0, $operator, $action);
                $final = (int) $this->enforceMinValue($raw, $fieldDef);
                $flags = [];
                if ($raw !== $final) $flags[] = 'below_min_clamped';
                return ['value' => $final, 'flags' => $flags];
            }
            case 'bool':
                return ['value' => $this->applyBool($operator, $action), 'flags' => []];
            case 'enum':
                return ['value' => (string) ($action['value'] ?? $oldValue), 'flags' => []];
        }
        throw new InvalidArgumentException("Unknown field type '{$fieldType}'");
    }

    /**
     * @param array<string,mixed> $fieldDef
     * @param float|int $value
     * @return float|int
     */
    private function enforceMinValue($value, array $fieldDef)
    {
        if (!array_key_exists('min_value', $fieldDef)) {
            return $value;
        }
        $min = $fieldDef['min_value'];
        if ($value < $min) {
            return $min;
        }
        return $value;
    }

    /** @param array<string,mixed> $fieldDef */
    private function enforceTextLimits(string $value, array $fieldDef): string
    {
        $limit = isset($fieldDef['char_limit']) ? (int) $fieldDef['char_limit'] : 0;
        if ($limit > 0 && function_exists('mb_strlen') && mb_strlen($value) > $limit) {
            return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
        }
        return $value;
    }

    /**
     * @param array<string,mixed> $action
     */
    private function applyText(string $old, string $operator, array $action): string
    {
        $value = (string) ($action['value'] ?? '');
        switch ($operator) {
            case 'set':     return $value;
            case 'append':  return $old . $value;
            case 'prepend': return $value . $old;
            case 'replace': {
                $find    = (string) ($action['find'] ?? '');
                $replace = (string) ($action['replace'] ?? '');
                if ($find === '') return $old;
                return str_replace($find, $replace, $old);
            }
        }
        throw new InvalidArgumentException("Unsupported text operator '{$operator}'");
    }

    /** @param array<string,mixed> $action */
    private function applyNumeric(float $old, string $operator, array $action): float
    {
        if ($operator === 'set') {
            return (float) ($action['value'] ?? 0);
        }
        if ($operator === 'math') {
            return $this->applyMath($old, (string) ($action['math_op'] ?? '+'), (float) ($action['math_value'] ?? 0));
        }
        throw new InvalidArgumentException("Unsupported numeric operator '{$operator}'");
    }

    /** @param array<string,mixed> $action */
    private function applyInt(int $old, string $operator, array $action): int
    {
        if ($operator === 'set') {
            return (int) ($action['value'] ?? 0);
        }
        if ($operator === 'math') {
            $result = $this->applyMath((float) $old, (string) ($action['math_op'] ?? '+'), (float) ($action['math_value'] ?? 0));
            return (int) round($result);
        }
        throw new InvalidArgumentException("Unsupported int operator '{$operator}'");
    }

    /** @param array<string,mixed> $action */
    private function applyBool(string $operator, array $action): int
    {
        if ($operator === 'set') {
            return ((bool) ($action['value'] ?? false)) ? 1 : 0;
        }
        throw new InvalidArgumentException("Unsupported bool operator '{$operator}'");
    }

    private function applyMath(float $old, string $op, float $arg): float
    {
        switch ($op) {
            case '+':  return round($old + $arg, 6);
            case '-':  return round($old - $arg, 6);
            case '*':  return round($old * $arg, 6);
            case '/':  return $arg == 0.0 ? $old : round($old / $arg, 6);
            case '+%': return round($old * (1 + $arg / 100), 6);
            case '-%': return round($old * (1 - $arg / 100), 6);
        }
        throw new InvalidArgumentException("Unsupported math op '{$op}'");
    }

    /** @return mixed */
    private function zeroValueFor(string $fieldType)
    {
        return match ($fieldType) {
            'text'    => '',
            'numeric' => 0.0,
            'int'     => 0,
            'bool'    => 0,
            'enum'    => '',
            default   => null,
        };
    }
}
