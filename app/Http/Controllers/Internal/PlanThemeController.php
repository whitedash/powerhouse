<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePlanThemeRequest;
use App\Models\PlanTheme;
use App\Services\PlanThemeService;
use App\Support\PlanThemeTokens;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Internal CRUD for reusable plan themes (the plans design editor) —
 * FormThemeController mirrored. custom_css is read- and write-gated to
 * the manageCustomCss ability: stripped from every payload, and ignored
 * on write, for anyone else.
 */
class PlanThemeController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', PlanTheme::class);

        $canCustomCss = $request->user()->can('manageCustomCss', PlanTheme::class);

        $themes = PlanTheme::query()
            ->withCount('products')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (PlanTheme $t): array => [
                'id' => $t->id,
                'name' => $t->name,
                'tokens' => $this->visibleTokens($t->tokens ?? [], $canCustomCss),
                'products_count' => (int) ($t->products_count ?? 0),
                'created_by' => $t->createdBy->name,
                'created_at' => $t->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Internal/Plans/Themes/Index', [
            'themes' => $themes,
            'default_tokens' => $this->visibleTokens(PlanThemeTokens::defaults(), $canCustomCss),
            'can' => [
                'manage_custom_css' => $canCustomCss,
            ],
        ]);
    }

    public function store(StorePlanThemeRequest $request, PlanThemeService $service): RedirectResponse
    {
        $theme = $service->create($request->validated(), $request->user());

        return back()->with('success', "Theme \"{$theme->name}\" created.");
    }

    public function update(int $id, StorePlanThemeRequest $request, PlanThemeService $service): RedirectResponse
    {
        $theme = PlanTheme::findOrFail($id);
        Gate::authorize('update', $theme);

        $service->update($theme, $request->validated(), $request->user());

        return back()->with('success', 'Theme updated. Widgets using it update within ~5 minutes (embed cache).');
    }

    public function destroy(int $id, Request $request, PlanThemeService $service): RedirectResponse
    {
        $theme = PlanTheme::findOrFail($id);
        Gate::authorize('delete', $theme);

        $service->delete($theme, $request->user());

        return back()->with('success', 'Theme deleted. Products that used it reverted to the default look.');
    }

    /**
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
