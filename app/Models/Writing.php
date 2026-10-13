<?php

namespace App\Models;

use App\Enums\ContentStatus;
use App\Models\Concerns\HasMedia;
use App\Models\Concerns\HasSlug;
use App\Models\Concerns\HasTags;
use App\Models\Concerns\Publishable;
use App\Support\Markdown\FocusableCodeBlockExtension;
use Carbon\CarbonImmutable;
use Database\Factories\WritingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;
use Spatie\Feed\Feedable;
use Spatie\Feed\FeedItem;

/**
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property string|null $summary
 * @property string|null $body
 * @property ContentStatus $status
 * @property CarbonImmutable|null $published_at
 * @property bool $is_placeholder
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property int $sort_order
 * @property string|null $excerpt
 * @property string|null $body_html
 * @property int|null $reading_time_minutes
 * @property int|null $series_id
 * @property string|null $og_image_path
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read WritingSeries|null $series
 */
#[Fillable([
    'title',
    'summary',
    'body',
    'status',
    'published_at',
    'is_placeholder',
    'meta_title',
    'meta_description',
    'sort_order',
    'excerpt',
    'series_id',
    'og_image_path',
])]
class Writing extends Model implements Feedable
{
    /** @use HasFactory<WritingFactory> */
    use HasFactory, HasMedia, HasSlug, HasTags, Publishable;

    /**
     * Words per minute used to estimate the reading time.
     */
    private const WORDS_PER_MINUTE = 200;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ContentStatus::class,
            'published_at' => 'datetime',
            'is_placeholder' => 'boolean',
            'sort_order' => 'integer',
            'reading_time_minutes' => 'integer',
        ];
    }

    /**
     * Register the listener that renders the Markdown source after every save.
     *
     * The rendered pair is derived state, so it is written back through
     * `saveQuietly()` — the write must not re-enter this listener.
     */
    protected static function booted(): void
    {
        static::saved(function (self $writing): void {
            $writing->renderBody();
        });
    }

    /**
     * Get the series the writing belongs to.
     *
     * @return BelongsTo<WritingSeries, $this>
     */
    public function series(): BelongsTo
    {
        return $this->belongsTo(WritingSeries::class, 'series_id');
    }

    /**
     * Render the Markdown source into HTML and estimate the reading time.
     *
     * An empty body leaves both derived columns NULL rather than storing an
     * empty string or a one-minute reading time for nothing.
     */
    protected function renderBody(): void
    {
        $body = $this->body;

        if (blank($body)) {
            $bodyHtml = null;
            $readingTimeMinutes = null;
        } else {
            $bodyHtml = (string) $this->markdownConverter()->convert($body);
            $readingTimeMinutes = max(1, (int) ceil(str_word_count(strip_tags($bodyHtml)) / self::WORDS_PER_MINUTE));
        }

        if ($this->body_html === $bodyHtml && $this->reading_time_minutes === $readingTimeMinutes) {
            return;
        }

        $this->body_html = $bodyHtml;
        $this->reading_time_minutes = $readingTimeMinutes;

        $this->saveQuietly();
    }

    /**
     * Build the Markdown converter used for the stored body.
     *
     * Raw HTML in the source is escaped and unsafe links are dropped, so a
     * writing body can never inject markup into the public page.
     *
     * The one addition to the CommonMark defaults is
     * {@see FocusableCodeBlockExtension}, which puts `tabindex="0"` on every
     * code block so the `overflow-x: auto` scroll container the public
     * stylesheet gives it is reachable by keyboard (§7.2, WCAG 2.1.1).
     */
    protected function markdownConverter(): MarkdownConverter
    {
        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new FocusableCodeBlockExtension);

        return new MarkdownConverter($environment);
    }

    /**
     * Get the writings that appear in the Atom feed, newest first.
     *
     * This is the `items` callable `config/feed.php` names for
     * `feeds.writings` (`[Writing::class, 'getFeedItems']`, §3.9). The feed is
     * capped at 20 items so the document stays small however long the archive
     * grows.
     *
     * @return Collection<int, static>
     */
    public static function getFeedItems(): Collection
    {
        return static::query()
            ->published()
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get();
    }

    /**
     * Map the writing to its feed item, one setter per `FeedItem` field (§3.9).
     *
     * `summary` is the excerpt rather than the body: Atom consumers render the
     * summary inline, and the full rendered HTML already has a canonical home on
     * `/writing/{slug}`. `updated_at` is never null for a persisted row; the
     * fallback only satisfies the setter's non-nullable contract.
     */
    public function toFeedItem(): FeedItem
    {
        return FeedItem::create()
            ->id((string) $this->getKey())
            ->title($this->title)
            ->summary((string) $this->excerpt)
            ->updated($this->updated_at ?? $this->freshTimestamp())
            ->link(route('writing.show', $this))
            ->authorName((string) Profile::query()->value('name'));
    }
}
