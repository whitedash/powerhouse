<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property string $content
 * @property string $category
 * @property bool $is_public
 * @property bool $is_published
 * @property int $sort_order
 * @property int $views
 * @property int $author_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $author
 */
class KnowledgeBaseArticle extends Model
{
    protected $table = 'support_knowledge_base';

    protected $fillable = [
        'title',
        'slug',
        'content',
        'category',
        'is_public',
        'is_published',
        'sort_order',
        'views',
        'author_id',
    ];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'is_published' => 'boolean',
            'sort_order' => 'integer',
            'views' => 'integer',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
