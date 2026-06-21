<?php

namespace App\Services;

/**
 * Evaluates a workflow's optional field-value conditions gate against a trigger
 * payload (used by WorkflowEngine after the trigger_config match).
 *
 * Stored shape (workflows.conditions):
 *   {
 *     "logic": "or",
 *     "groups": [
 *       { "logic": "and", "conditions": [
 *         { "field_key": "budget", "operator": "greater_than", "value": "10000" }
 *       ] }
 *     ]
 *   }
 *
 * Groups are combined by the top-level logic; conditions within a group by the
 * group's logic (default OR between groups, AND within a group, but the stored
 * logic fields are honoured). NULL / empty conditions => the workflow matches
 * (no gating), exactly as before this feature.
 *
 * The payload is the form-submission context keyed by field_key. Single-step
 * submissions drop null values (an unanswered field is an ABSENT key) while
 * multi-step drafts may carry an explicit null / '' / [] — so is_empty /
 * is_not_empty use array_key_exists + emptiness to read identically across both.
 */
class WorkflowConditionEvaluator
{
    /** The twelve supported operators (also the validator allowlist). */
    public const OPERATORS = [
        'equals', 'not_equals',
        'contains', 'not_contains',
        'starts_with', 'ends_with',
        'is_empty', 'is_not_empty',
        'greater_than', 'less_than',
        'greater_than_or_equal', 'less_than_or_equal',
    ];

    /**
     * @param  array<string, mixed>|null  $conditions
     * @param  array<string, mixed>  $payload
     */
    public function matches(?array $conditions, array $payload): bool
    {
        if ($conditions === null) {
            return true;
        }

        $groups = $conditions['groups'] ?? null;
        if (! is_array($groups) || $groups === []) {
            return true; // no gating
        }

        $useAnd = ($conditions['logic'] ?? 'or') === 'and';

        $results = [];
        foreach ($groups as $group) {
            $results[] = is_array($group) && $this->matchesGroup($group, $payload);
        }

        return $useAnd
            ? ! in_array(false, $results, true)   // AND between groups: all must match
            : in_array(true, $results, true);     // OR between groups: any group matches
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  array<string, mixed>  $payload
     */
    private function matchesGroup(array $group, array $payload): bool
    {
        $conditions = $group['conditions'] ?? null;
        if (! is_array($conditions) || $conditions === []) {
            return true; // an empty group does not gate
        }

        $useOr = ($group['logic'] ?? 'and') === 'or';

        $results = [];
        foreach ($conditions as $condition) {
            $results[] = is_array($condition) && $this->matchesCondition($condition, $payload);
        }

        return $useOr
            ? in_array(true, $results, true)      // OR within group
            : ! in_array(false, $results, true);  // AND within group: all must match
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, mixed>  $payload
     */
    private function matchesCondition(array $condition, array $payload): bool
    {
        $key = (string) ($condition['field_key'] ?? '');
        $operator = (string) ($condition['operator'] ?? '');
        $value = $condition['value'] ?? null;

        $present = array_key_exists($key, $payload);
        $actual = $present ? $payload[$key] : null;

        return match ($operator) {
            'is_empty' => $this->isEmpty($present, $actual),
            'is_not_empty' => ! $this->isEmpty($present, $actual),
            'equals' => $this->equals($actual, $value),
            'not_equals' => ! $this->equals($actual, $value),
            'contains' => $this->contains($actual, $value),
            'not_contains' => ! $this->contains($actual, $value),
            'starts_with' => is_scalar($actual) && $value !== null && str_starts_with((string) $actual, (string) $value),
            'ends_with' => is_scalar($actual) && $value !== null && str_ends_with((string) $actual, (string) $value),
            'greater_than' => $this->numeric($actual, $value, fn (float $a, float $b): bool => $a > $b),
            'less_than' => $this->numeric($actual, $value, fn (float $a, float $b): bool => $a < $b),
            'greater_than_or_equal' => $this->numeric($actual, $value, fn (float $a, float $b): bool => $a >= $b),
            'less_than_or_equal' => $this->numeric($actual, $value, fn (float $a, float $b): bool => $a <= $b),
            default => false,
        };
    }

    private function isEmpty(bool $present, mixed $actual): bool
    {
        return ! $present || $actual === null || $actual === '' || $actual === [];
    }

    /** Scalar: exact (string) match. Array (checkbox): true iff the sole element equals value. */
    private function equals(mixed $actual, mixed $value): bool
    {
        if (is_array($actual)) {
            $sole = reset($actual);

            return count($actual) === 1 && is_scalar($sole) && (string) $sole === (string) $value;
        }

        return is_scalar($actual) && (string) $actual === (string) $value;
    }

    /** Scalar: substring. Array: membership (value is in the array). */
    private function contains(mixed $actual, mixed $value): bool
    {
        if (is_array($actual)) {
            return in_array((string) $value, array_map(static fn (mixed $v): string => (string) (is_scalar($v) ? $v : ''), $actual), true);
        }

        return is_scalar($actual) && $value !== null && str_contains((string) $actual, (string) $value);
    }

    /**
     * Numeric comparison; if either side is non-numeric the condition does not match.
     *
     * @param  callable(float, float): bool  $compare
     */
    private function numeric(mixed $actual, mixed $value, callable $compare): bool
    {
        if (! is_numeric($actual) || ! is_numeric($value)) {
            return false;
        }

        return $compare((float) $actual, (float) $value);
    }
}
