<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeBaseArticle;
use App\Services\KbContentService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public knowledge base at /kb. Read-only and unauthenticated.
 *
 * Every query is scoped to is_public && is_published — the public
 * equivalent of the IDOR rule. An internal or unpublished article is
 * invisible here and 404s on direct slug access; we never leak staff-only
 * or draft content.
 */
class KnowledgeBaseController extends Controller
{
    public function __construct(private readonly KbContentService $content) {}

    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString() ?: null;
        $category = $request->string('category')->toString() ?: null;

        $articles = KnowledgeBaseArticle::query()
            ->where('is_public', true)
            ->where('is_published', true)
            ->when($search, fn ($q, $s) => $q->where(function ($qq) use ($s) {
                $qq->where('title', 'like', "%{$s}%")
                    ->orWhere('content', 'like', "%{$s}%");
            }))
            ->when($category, fn ($q, $c) => $q->where('category', $c))
            ->orderBy('sort_order')
            ->orderByDesc('views')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (KnowledgeBaseArticle $a): array => [
                'title' => $a->title,
                'slug' => $a->slug,
                'category' => $a->category,
                // No excerpt column — derive from the body, tags stripped.
                'excerpt' => Str::limit(strip_tags($a->content), 160),
            ]);

        $categories = KnowledgeBaseArticle::query()
            ->where('is_public', true)
            ->where('is_published', true)
            ->distinct()
            ->pluck('category')
            ->filter()
            ->values()
            ->all();

        return Inertia::render('Public/KnowledgeBase/Index', [
            'articles' => $articles,
            'categories' => $categories,
            'filters' => ['search' => $search, 'category' => $category],
        ]);
    }

    public function show(string $slug): Response
    {
        // Scoped findOrFail — internal/unpublished slugs 404.
        $article = KnowledgeBaseArticle::query()
            ->where('slug', $slug)
            ->where('is_public', true)
            ->where('is_published', true)
            ->firstOrFail();

        // Simple every-hit popularity counter; a read must not move updated_at.
        $article->timestamps = false;
        $article->increment('views');
        $article->timestamps = true;

        $related = KnowledgeBaseArticle::query()
            ->where('category', $article->category)
            ->where('id', '!=', $article->id)
            ->where('is_public', true)
            ->where('is_published', true)
            ->orderBy('sort_order')
            ->take(4)
            ->get(['title', 'slug'])
            ->all();

        return Inertia::render('Public/KnowledgeBase/Article', [
            'article' => [
                'title' => $article->title,
                'slug' => $article->slug,
                'category' => $article->category,
                'views' => $article->views,
                // Sanitised HTML — safe for v-html on this public page.
                'content_html' => $this->content->renderSafe($article->content),
                'updated_at' => $article->updated_at?->format('d M Y'),
            ],
            'related' => $related,
        ]);
    }
}
