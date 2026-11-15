<?php

namespace App\Content;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One Article's row in the database copy. Only the content library writes it, from the
 * content folder, on each rebuild; read Articles through ContentLibrary.
 *
 * @property int $id
 * @property string $name The Article Name, from its file name; the address is /articles/<name>.
 * @property string $title
 * @property string $summary
 * @property ArticleType $type
 * @property string $product_area
 * @property AudienceTier $audience_tier
 * @property string $owner The Article Owner's GitHub login; never shown.
 * @property ArticleStatus $status
 * @property CarbonImmutable $checked_on
 * @property string $source_path The file it was built from, relative to the content folder.
 * @property string $body_html The body, rendered once per rebuild.
 * @property CarbonImmutable $next_review_on
 * @property list<string> $team_roles Never shown on a Client-tier Article.
 * @property list<string> $source_references
 * @property list<string> $related_articles Article Names, in the writer's order.
 * @property int|null $path_position The place in the Getting-started Path (Getting-started only).
 * @property list<string> $messages Troubleshooting messages, word for word, Placeholders in [brackets].
 * @property array<string, string> $section_names Each Section Name with its heading's words.
 * @property string|null $what_changed What a Retired Article with no replacement tells readers.
 * @property list<string> $terms The terms (by their names in their addresses) its body links to.
 * @property string|null $feature The feature it is about (content/features.yaml).
 * @property bool $joint_only About Stockist, for Joint Operators: opened by address, never listed or searched.
 * @property bool $waiting_for_demo Published, but stored as a Draft till its feature is on the demo.
 */
class Article extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => ArticleType::class,
            'audience_tier' => AudienceTier::class,
            'status' => ArticleStatus::class,
            'checked_on' => 'immutable_date',
            'next_review_on' => 'immutable_date',
            'team_roles' => 'array',
            'source_references' => 'array',
            'related_articles' => 'array',
            'messages' => 'array',
            'section_names' => 'array',
            'terms' => 'array',
            'joint_only' => 'boolean',
            'waiting_for_demo' => 'boolean',
        ];
    }

    /** The Article's address: /articles/<Article Name>. */
    public function address(): string
    {
        return ArticleSource::addressOf($this->name);
    }

    /**
     * @param  Builder<self>  $query
     */
    protected function scopePublished(Builder $query): void
    {
        $query->where('status', ArticleStatus::Published);
    }

    /**
     * Published and not Joint-only: what listings, search and the sitemap show, so a reader at an
     * FMM-only Operator never meets an Article about Stockist (ADR-0006).
     *
     * @param  Builder<self>  $query
     */
    protected function scopeListed(Builder $query): void
    {
        $query->where('status', ArticleStatus::Published)->where('joint_only', false);
    }
}
