<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\KnowledgeBaseArticle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Internal Help & docs editor. The same support_knowledge_base table
 * backs the customer portal — `is_public=false` keeps an article
 * staff-only while `is_published=false` hides it everywhere.
 */
class HelpController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString() ?: null;
        $category = $request->string('category')->toString() ?: null;

        $articles = KnowledgeBaseArticle::query()
            ->with('author:id,name')
            ->where('is_published', true)
            ->when($search, fn ($q, $s) => $q->where(function ($qq) use ($s) {
                $qq->where('title', 'like', "%{$s}%")
                    ->orWhere('content', 'like', "%{$s}%");
            }))
            ->when($category, fn ($q, $c) => $q->where('category', $c))
            ->orderBy('category')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (KnowledgeBaseArticle $a): array => [
                'id' => $a->id,
                'title' => $a->title,
                'slug' => $a->slug,
                'category' => $a->category,
                'is_public' => $a->is_public,
                'is_published' => $a->is_published,
                'views' => $a->views,
                'author' => $a->author?->name,
                'updated_at' => $a->updated_at?->diffForHumans(),
                'excerpt' => Str::limit(strip_tags($a->content), 120),
            ])
            ->all();

        $categories = KnowledgeBaseArticle::query()
            ->where('is_published', true)
            ->distinct()
            ->pluck('category')
            ->filter()
            ->values()
            ->all();

        return Inertia::render('Internal/Help/Index', [
            'articles' => $articles,
            'categories' => $categories,
            'filters' => [
                'search' => $search,
                'category' => $category,
            ],
        ]);
    }

    public function show(string $slug): Response
    {
        $article = KnowledgeBaseArticle::query()
            ->with('author:id,name')
            ->where('slug', $slug)
            ->where('is_published', true)
            ->firstOrFail();

        // Track popularity. Skipping the touch on updated_at because
        // a read shouldn't move "last edited".
        $article->timestamps = false;
        $article->increment('views');
        $article->timestamps = true;

        $related = KnowledgeBaseArticle::query()
            ->where('category', $article->category)
            ->where('id', '!=', $article->id)
            ->where('is_published', true)
            ->orderBy('sort_order')
            ->take(4)
            ->get(['id', 'title', 'slug'])
            ->all();

        return Inertia::render('Internal/Help/Show', [
            'article' => [
                'id' => $article->id,
                'title' => $article->title,
                'slug' => $article->slug,
                'category' => $article->category,
                'is_public' => $article->is_public,
                'is_published' => $article->is_published,
                'views' => $article->views,
                'content_raw' => $article->content,
                'content_html' => $this->renderMarkdown($article->content),
                'author' => $article->author?->name,
                'updated_at' => $article->updated_at?->diffForHumans(),
            ],
            'related' => $related,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'category' => ['required', 'string', 'max:100'],
            'is_public' => ['sometimes', 'boolean'],
            'is_published' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $article = KnowledgeBaseArticle::create([
            'title' => $data['title'],
            'slug' => $this->uniqueSlug($data['title']),
            'content' => $data['content'],
            'category' => $data['category'],
            'is_public' => $data['is_public'] ?? true,
            'is_published' => $data['is_published'] ?? true,
            'sort_order' => $data['sort_order'] ?? 0,
            'author_id' => $request->user()->id,
        ]);

        $this->logAction($request, 'kb.article_created', $article->id, [
            'title' => $article->title,
            'category' => $article->category,
        ]);

        return back()->with('success', 'Article published.');
    }

    public function update(int $id, Request $request): RedirectResponse
    {
        $article = KnowledgeBaseArticle::findOrFail($id);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'category' => ['required', 'string', 'max:100'],
            'is_public' => ['sometimes', 'boolean'],
            'is_published' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        // Only regenerate the slug if the title actually changed —
        // keeps existing bookmarks valid.
        if ($data['title'] !== $article->title) {
            $article->slug = $this->uniqueSlug($data['title'], $article->id);
        }

        $article->title = $data['title'];
        $article->content = $data['content'];
        $article->category = $data['category'];
        $article->is_public = $data['is_public'] ?? $article->is_public;
        $article->is_published = $data['is_published'] ?? $article->is_published;
        $article->sort_order = $data['sort_order'] ?? $article->sort_order;
        $article->save();

        $this->logAction($request, 'kb.article_updated', $article->id, [
            'title' => $article->title,
        ]);

        return back()->with('success', 'Article updated.');
    }

    /**
     * Soft-delete by unpublishing — keeps view counts and inbound
     * links intact while removing the article from every listing.
     */
    public function destroy(int $id, Request $request): RedirectResponse
    {
        $article = KnowledgeBaseArticle::findOrFail($id);
        $article->is_published = false;
        $article->save();

        $this->logAction($request, 'kb.article_deleted', $article->id, [
            'title' => $article->title,
        ]);

        return back()->with('success', 'Article unpublished.');
    }

    /**
     * Generate a slug from the title that is unique across the table.
     * Pass $ignoreId when updating so the article doesn't collide with
     * its own existing slug.
     */
    private function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'article';
        $slug = $base;
        $n = 2;

        while (KnowledgeBaseArticle::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()
        ) {
            $slug = $base.'-'.$n;
            $n++;
        }

        return $slug;
    }

    private function renderMarkdown(string $markdown): string
    {
        $converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        return (string) $converter->convert($markdown);
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function logAction(Request $request, string $action, int $articleId, array $after): void
    {
        ActivityLog::create([
            'user_id' => $request->user()?->id,
            'user_role' => $request->user()?->role,
            'action' => $action,
            'entity_type' => KnowledgeBaseArticle::class,
            'entity_id' => $articleId,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
