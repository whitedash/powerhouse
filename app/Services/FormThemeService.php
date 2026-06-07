<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\FormTheme;
use App\Models\User;
use App\Support\FormThemeTokens;
use Illuminate\Support\Facades\DB;

/**
 * Create/update/delete reusable form themes from the design editor.
 *
 * Tokens are normalised against the canonical key set
 * (FormThemeTokens::defaults()) so only known keys are persisted, empty
 * optional fields collapse to null (→ fall back to the default at render),
 * and unknown keys are dropped. custom_css is only written when the actor
 * may manage it; on update an existing value is preserved otherwise so a
 * staff edit never silently wipes a super_admin's CSS.
 */
class FormThemeService
{
    /**
     * @param  array<string, mixed>  $data  validated StoreFormThemeRequest data
     */
    public function create(array $data, User $actor): FormTheme
    {
        $tokens = $this->normaliseTokens($data['tokens'] ?? [], $actor, null);

        return DB::transaction(function () use ($data, $tokens, $actor): FormTheme {
            $theme = FormTheme::create([
                'name' => $data['name'],
                'tokens' => $tokens,
                'created_by' => $actor->id,
            ]);

            $this->log($actor, 'form_theme.created', $theme->id, after: [
                'name' => $theme->name,
            ]);

            return $theme;
        });
    }

    /**
     * @param  array<string, mixed>  $data  validated StoreFormThemeRequest data
     */
    public function update(FormTheme $theme, array $data, User $actor): FormTheme
    {
        $tokens = $this->normaliseTokens($data['tokens'] ?? [], $actor, $theme);

        return DB::transaction(function () use ($theme, $data, $tokens, $actor): FormTheme {
            $before = ['name' => $theme->name];

            $theme->update([
                'name' => $data['name'],
                'tokens' => $tokens,
            ]);

            $this->log($actor, 'form_theme.updated', $theme->id, before: $before, after: [
                'name' => $theme->name,
            ]);

            return $theme;
        });
    }

    public function delete(FormTheme $theme, User $actor): void
    {
        DB::transaction(function () use ($theme, $actor): void {
            $snapshot = ['id' => $theme->id, 'name' => $theme->name];

            // forms.theme_id is nullOnDelete, so any forms using this theme
            // simply revert to the default tokens — no orphaned rows.
            $theme->delete();

            $this->log($actor, 'form_theme.deleted', $snapshot['id'], before: $snapshot);
        });
    }

    /**
     * Keep only known token keys; collapse empty optional fields to null;
     * gate custom_css on the actor's ability (preserving the prior value
     * on update when the actor can't manage it).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normaliseTokens(array $input, User $actor, ?FormTheme $existing): array
    {
        $allowed = array_keys(FormThemeTokens::defaults());
        $tokens = [];

        foreach ($allowed as $key) {
            if (! array_key_exists($key, $input)) {
                continue;
            }
            $value = $input[$key];
            // Optional string fields: treat '' as "unset" so they fall back.
            if ($value === '') {
                $value = null;
            }
            $tokens[$key] = $value;
        }

        if ($actor->can('manageCustomCss', FormTheme::class)) {
            // Honour whatever the (super_admin) actor sent, incl. clearing it.
            $tokens['custom_css'] = ($input['custom_css'] ?? null) ?: null;
        } else {
            // Non-super-admin: never accept custom_css; keep the existing one.
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
            'entity_type' => 'form_theme',
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 500),
        ]);
    }
}
