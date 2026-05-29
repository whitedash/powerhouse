<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repurposes support_knowledge_base for the Help & docs feature.
 *
 * The original draft of this table modelled product-scoped FAQ
 * entries (product_id, body, status enum, published_at). Nothing
 * ever queried those columns — the only references were the model
 * shell itself — so reshaping in place is safer than a parallel
 * table. The new shape backs both the internal Help editor and the
 * portal-facing knowledge base:
 *
 *  - slug for stable URLs
 *  - content (markdown) replaces body
 *  - category for sidebar grouping
 *  - is_public / is_published split staff visibility from
 *    customer visibility (a draft is unpublished; an
 *    internal-only article is published-but-not-public)
 *  - sort_order for manual ordering within a category
 *  - views as a lightweight popularity signal
 *  - author_id replaces created_by; identical semantics
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('support_knowledge_base', function (Blueprint $table) {
            // FKs first — Laravel won't let us drop the parent constraint
            // and the column in one breath.
            $table->dropForeign(['product_id']);
            $table->dropForeign(['created_by']);
        });

        Schema::table('support_knowledge_base', function (Blueprint $table) {
            $table->dropColumn(['product_id', 'body', 'status', 'created_by', 'published_at']);
        });

        Schema::table('support_knowledge_base', function (Blueprint $table) {
            $table->string('slug')->unique()->after('title');
            $table->longText('content')->after('slug');
            $table->string('category', 100)->after('content');
            $table->boolean('is_public')->default(true)->after('category');
            $table->boolean('is_published')->default(true)->after('is_public');
            $table->unsignedInteger('sort_order')->default(0)->after('is_published');
            $table->unsignedInteger('views')->default(0)->after('sort_order');
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete()->after('views');

            $table->index(['is_published', 'category']);
        });
    }

    public function down(): void
    {
        Schema::table('support_knowledge_base', function (Blueprint $table) {
            $table->dropForeign(['author_id']);
            $table->dropIndex(['is_published', 'category']);
            $table->dropColumn([
                'slug', 'content', 'category', 'is_public',
                'is_published', 'sort_order', 'views', 'author_id',
            ]);
        });

        Schema::table('support_knowledge_base', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->longText('body')->after('title');
            $table->enum('status', ['draft', 'published'])->default('draft')->after('body');
            $table->foreignId('created_by')->after('status')->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at')->nullable()->after('created_by');
        });
    }
};
