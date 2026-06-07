<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFormThemeRequest;
use App\Models\FormTheme;
use App\Services\FormThemeService;
use App\Support\FormThemeTokens;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Internal CRUD for reusable form themes (the design editor).
 *
 * Themes are standalone + reusable across forms. The custom_css token is
 * raw CSS injected into the embed widget's shadow root, so it is read- and
 * write-gated to super_admin (FormThemePolicy::manageCustomCss) — stripped
 * from every payload, and ignored on write, for anyone else.
 */
class FormThemeController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', FormTheme::class);

        $canCustomCss = $request->user()->can('manageCustomCss', FormTheme::class);

        $themes = FormTheme::query()
            ->withCount('forms')
            ->with('createdBy:id,name')
            ->orderByDesc('created_at')
            ->paginate(20)
            ->through(fn (FormTheme $t): array => [
                'id' => $t->id,
                'name' => $t->name,
                // Stored (partial) overrides — minus custom_css unless allowed.
                'tokens' => $this->visibleTokens($t->tokens ?? [], $canCustomCss),
                'forms_count' => (int) ($t->forms_count ?? 0),
                'created_by' => $t->createdBy->name,
                'created_at' => $t->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Internal/Forms/Themes/Index', [
            'themes' => $themes,
            // The canonical default token set — the editor overlays a theme's
            // overrides on top of this so every control has a starting value.
            'default_tokens' => $this->visibleTokens(FormThemeTokens::defaults(), $canCustomCss),
            'can' => [
                'manage_custom_css' => $canCustomCss,
            ],
        ]);
    }

    public function store(StoreFormThemeRequest $request, FormThemeService $service): RedirectResponse
    {
        $theme = $service->create($request->validated(), $request->user());

        return back()->with('success', "Theme \"{$theme->name}\" created.");
    }

    public function update(int $id, StoreFormThemeRequest $request, FormThemeService $service): RedirectResponse
    {
        $theme = FormTheme::findOrFail($id);
        // authorize() on the FormRequest already gated 'update'; this is the
        // belt-and-braces per the IDOR rule (findOrFail + explicit gate).
        Gate::authorize('update', $theme);

        $service->update($theme, $request->validated(), $request->user());

        return back()->with('success', 'Theme updated. Forms using it update within ~5 minutes (embed cache).');
    }

    public function destroy(int $id, Request $request, FormThemeService $service): RedirectResponse
    {
        $theme = FormTheme::findOrFail($id);
        Gate::authorize('delete', $theme);

        $service->delete($theme, $request->user());

        return back()->with('success', 'Theme deleted. Forms that used it reverted to the default look.');
    }

    /**
     * Drop custom_css from a token array unless the actor may manage it.
     *
     * @param  array<string, mixed>  $tokens
     * @return array<string, mixed>
     */
    private function visibleTokens(array $tokens, bool $canCustomCss): array
    {
        if (! $canCustomCss) {
            unset($tokens['custom_css']);
        }

        return $tokens;
    }
}
