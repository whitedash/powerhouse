<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Single source of truth for the task / activity due-date policy.
 *
 * The rule is deliberately defined ONCE here and reused by every
 * user-facing creation path (TaskController store/update,
 * SupportController::createTask, CompanyController::storeTask) so the
 * three surfaces can never drift apart again.
 *
 * Policy:
 *  - Actionable types (task / call / meeting) REQUIRE a due_at.
 *  - note + email are logged activities, not future to-dos → exempt.
 *  - A timed event satisfies the rule via start_at (the calendar path
 *    mirrors start_at → due_at), so don't double-require it.
 *  - On persist, a note never carries a schedule (forced null) and a
 *    bare YYYY-MM-DD is bumped to 09:00 so the slot reads like a
 *    working day.
 */
class TaskDueDate
{
    /** Activity types exempt from the due-date requirement. */
    public const EXEMPT_TYPES = ['note', 'email'];

    /**
     * The conditional due_at validation rule fragment.
     *
     * Reads the live request so the `type` / `start_at` of the current
     * submission drive the requirement — works identically whether the
     * caller sends a `type` (TaskController, SupportController) or omits
     * it (CompanyController, which only ever creates an actionable task).
     *
     * @return array<int, mixed>
     */
    public static function rule(): array
    {
        return [
            'nullable',
            'date',
            Rule::requiredIf(fn (): bool => self::isActionable((string) request()->input('type', 'task'))
                && blank(request()->input('start_at'))),
        ];
    }

    /**
     * Validation messages for the shared rule.
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'due_at.required' => 'A due date is required for tasks, calls and meetings.',
        ];
    }

    /**
     * Resolve the value to persist into due_at from validated data.
     * Notes never carry a schedule (forced null); a bare YYYY-MM-DD is
     * bumped to 09:00.
     *
     * @param  array<string, mixed>  $data
     */
    public static function resolve(array $data): ?Carbon
    {
        if (($data['type'] ?? null) === 'note') {
            return null;
        }

        $value = $data['due_at'] ?? null;
        if (! $value) {
            return null;
        }

        $parsed = Carbon::parse((string) $value);

        // Bare YYYY-MM-DD parses at midnight — bump it to 09:00 so the
        // schedule isn't "due at start of day", which feels off on any
        // human-facing surface.
        if ($parsed->isStartOfDay() && ! str_contains((string) $value, ':')) {
            $parsed->setTime(9, 0, 0);
        }

        return $parsed;
    }

    /**
     * True when the type must carry a due date (everything except the
     * exempt note / email logged-activity types). An unknown/blank type
     * defaults to actionable — CompanyController posts no type and only
     * ever creates a real task.
     */
    public static function isActionable(string $type): bool
    {
        return ! in_array($type, self::EXEMPT_TYPES, true);
    }
}
