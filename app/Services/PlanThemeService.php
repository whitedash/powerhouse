<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\PlanTheme;
use App\Models\User;
use App\Support\PlanThemeTokens;
use Illuminate\Support\Facades\DB;

/**
 * Create/update/delete reusable plan themes — FormThemeService's mechanics
 * mirrored: tokens normalised against the canonical key set (only known
 * keys persist, '' collapses to null), and custom_css written only when
 * the actor holds the manageCustomCss gate — a staff edit preserves an
 * existing super_admin value rather than wiping it.
 */
class PlanThemeService
{
    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): PlanTheme
    {
        $tokens = $this->normaliseTokens($data['tokens'] ?? [], $actor, null);

        return DB::transaction(function () use ($data, $tokens, $actor): PlanTheme {
            $theme = PlanTheme::create([
                'name' => $data['name'],
                'tokens' => $tokens,
                'created_by' => $actor->id,
            ]);

            $this->log($actor, 'plan_theme.created', $theme->id, after: ['name' => $theme->name]);

            return $theme;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(PlanTheme $theme, array $data, User $actor): PlanTheme
    {
        $tokens = $this->normaliseTokens($data['tokens'] ?? [], $actor, $theme);

        return DB::transaction(function () use ($theme, $data, $tokens, $actor): PlanTheme {
            $before = ['name' => $theme->name];

            $theme->update(['name' => $data['name'], 'tokens' => $tokens]);

            $this->log($actor, 'plan_theme.updated', $theme->id, before: $before, after: ['name' => $theme->name]);

            return $theme;
        });
    }

    public function delete(PlanTheme $theme, User $actor): void
    {
        DB::transaction(function () use ($theme, $actor): void {
            $snapshot = ['id' => $theme->id, 'name' => $theme->name];

            // products.theme_id is nullOnDelete — products revert to the
            // default look, no orphans.
            $theme->delete();

            $this->log($actor, 'plan_theme.deleted', $snapshot['id'], before: $snapshot);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normaliseTokens(array $input, User $actor, ?PlanTheme $existing): array
    {
        $allowed = array_keys(PlanThemeTokens::defaults());
        $tokens = [];

        foreach ($allowed as $key) {
            if (! array_key_exists($key, $input)) {
                continue;
            }
            $value = $input[$key];
            if ($value === '') {
                $value = null;
            }
            $tokens[$key] = $value;
        }

        if ($actor->can('manageCustomCss', PlanTheme::class)) {
            $tokens['custom_css'] = ($input['custom_css'] ?? null) ?: null;
        } else {
            $existingCss = $existing?->tokens['custom_css'] ?? null;
            if ($existingCss !== null) {
                $tokens['custom_css'] = $existingCss;
            } else {
                unset($tokens['custom_css']);
            }
        }

        return $tokens;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function log(User $actor, string $action, int $entityId, ?array $before = null, ?array $after = null): void
    {
        ActivityLog::create([
            'user_id' => $actor->id,
            'user_role' => $actor->role,
            'action' => $action,
            'entity_type' => 'plan_theme',
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 500),
        ]);
    }
}
