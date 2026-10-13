<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ContentStatus;
use App\Enums\ContentType;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The machinery the eight §5.8 admin resource controllers share.
 *
 * Every admin resource answers the same action set — `index`, `create`,
 * `store`, `edit`, `update`, `destroy`, `publish`, `unpublish`, the three bulk
 * actions, and `reorder` for the six ordered types — and every one of them
 * speaks the same two contracts: the §5.9 list contract (`?status=&q=&tag=&page=`
 * in, 20 rows out) and the §5.10 prop contract (`records` + `filters` + `meta`
 * on an index, `record` on an edit, `record: null` + `lastCreated` on a create).
 *
 * This class is the one copy of both. It mirrors {@see ApiController}
 * in shape and for the same reason: the eight controllers differ only in their
 * model, their form requests and their per-type columns, and everything else
 * would otherwise be eight copies to keep in step.
 *
 * Two behaviours are deliberately *not* inherited. `store` and `update` are
 * declared per controller so each one type-hints its own admin form request —
 * that is what runs validation — and `edit`, `destroy`, `publish` and
 * `unpublish` are declared per controller so each one type-hints its own model,
 * which is what makes implicit route-model binding resolve the
 * `{work_experience}`-style parameter and answer 404 for a missing record
 * (§5.8). Each of those thin declarations delegates straight back here.
 *
 * Nothing here reaches for the API: the admin UI never calls `/api/v1` and
 * every write goes through `/admin/*`. The shared layer is the policies (§5.7),
 * which both surfaces authorise through.
 */
abstract class AdminController extends Controller
{
    use AuthorizesRequests;

    /**
     * The page size §5.9 fixes for every admin list.
     *
     * The page number is Laravel's own `?page=N`; §4.7's `page[number]` belongs
     * to the API and the admin never reads it.
     */
    protected const PER_PAGE = 20;

    /**
     * The model the resource is built on.
     *
     * @return class-string<Model>
     */
    abstract protected function modelClass(): string;

    /**
     * Apply the pinned admin order to a list query (§5.9).
     *
     * Implementations annotate the parameter as `Builder<Model>` rather than as
     * their own `Builder<WorkExperience>`: the builder they are handed *is* the
     * resource's own, but the analyser only sees the `Model` this class
     * declares, and a narrower annotation is a variance error rather than a
     * stronger guarantee.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    abstract protected function applyDefaultOrder(Builder $query): Builder;

    /**
     * One record as the flat, form-shaped payload a page receives (§5.10).
     *
     * The keys are the field names the admin form requests validate, so an
     * `Edit.vue` can hand this straight to `useForm()` and a `ResourceTable.vue`
     * can read the columns §5.9 lists from the same object. `slug` is included
     * because the edit screen displays it read-only; it is never a form field.
     *
     * @return array<string, mixed>
     */
    abstract protected function recordPayload(Model $model): array;

    /**
     * The `ContentType` this resource serves, or `null` for `tags`.
     *
     * Seven of the eight admin resources are content types, and the enum is
     * already the authority on their singular spelling, their plural kebab-case
     * route segment and their label — so all three derive from it and there is
     * one copy of each. `tags` is not a content type (§2.5) and has no case, so
     * it is the one resource that declares the three spellings itself.
     */
    protected function contentType(): ?ContentType
    {
        return null;
    }

    /**
     * The singular snake_case name of the resource — the session key segment
     * and the `meta.type` value, e.g. `work_experience`.
     */
    protected function type(): string
    {
        $type = $this->contentType();

        return $type instanceof ContentType ? $type->value : 'tag';
    }

    /**
     * The plural kebab-case route segment, e.g. `work-experiences` (§5.8).
     *
     * Every route name, redirect target and Inertia component path is built
     * from it, so the admin URIs and the §5.8 table cannot drift.
     */
    protected function routeSegment(): string
    {
        return $this->contentType()?->routeSegment() ?? 'tags';
    }

    /**
     * The human-readable resource label, e.g. `Work Experience`.
     */
    protected function label(): string
    {
        return $this->contentType()?->label() ?? 'Tag';
    }

    /**
     * Whether the list supports `reorder` (§5.9).
     *
     * True for the six ordered types. `writings` is ordered by `published_at`
     * and `tags` by the ASCII-normalised key, and neither exposes a control
     * that could change either, so both answer `false`.
     */
    protected function orderable(): bool
    {
        return true;
    }

    /**
     * Whether the resource has a publication state at all (§2.5).
     *
     * Derived from the model rather than declared: the seven content models use
     * the `Publishable` trait and `Tag` does not, and the trait's `publish()`
     * is exactly the capability the question is about.
     */
    protected function publishable(): bool
    {
        return method_exists($this->modelClass(), 'publish');
    }

    /**
     * The columns `?q=` matches partially.
     *
     * The same pair the API searches, so one filter box means the same thing on
     * both surfaces.
     *
     * @return list<string>
     */
    protected function searchableColumns(): array
    {
        return ['title', 'summary'];
    }

    /**
     * The fields a create form may carry over from the previous save (§7.2).
     *
     * WCAG 3.3.7 is about *redundant* entry: a second role at the same employer
     * re-asks the organisation, the location and the dates. The identity of the
     * record — its title, its summary, its body — is deliberately absent, because
     * repeating those is not redundancy, it is a different record.
     *
     * @return list<string>
     */
    protected function prefillFields(): array
    {
        return [];
    }

    /**
     * `GET /admin/{resource}` — one filtered page of the collection (§5.9).
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', $this->modelClass());

        $filters = $this->filters($request);

        /** @var LengthAwarePaginator<int, Model> $records */
        $records = $this->listQuery($filters)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        /*
         * Read the page's own metadata before the rows are reshaped: `through()`
         * re-points the paginator at arrays rather than models, and the totals
         * are the same either way.
         */
        $meta = $this->meta($records);

        $records->through(fn (Model $model): array => $this->recordPayload($model));

        return Inertia::render($this->page('Index'), [
            'records' => $records,
            'filters' => $filters,
            'meta' => $meta,
        ]);
    }

    /**
     * `GET /admin/{resource}/create` — the blank form (§5.9).
     *
     * `lastCreated` is the previous save of the same type, read server-side so
     * it survives Inertia navigation. It is the whole prefill mechanism: the
     * page offers a visible "Prefill from last entry" button and populates
     * nothing until the admin activates it.
     */
    public function create(Request $request): Response
    {
        $this->authorize('create', $this->modelClass());

        return Inertia::render($this->page('Create'), [
            'record' => null,
            'lastCreated' => $request->session()->get($this->prefillKey()),
        ]);
    }

    /**
     * `DELETE /admin/{resource}` — bulk delete; body `ids: int[]` (§5.8).
     *
     * Authorisation runs over the whole selection before the first delete, so a
     * selection the policy refuses changes nothing rather than half of it.
     */
    public function bulkDestroy(Request $request): RedirectResponse
    {
        $records = $this->bulkRecords($request);

        foreach ($records as $record) {
            $this->authorize('delete', $record);
        }

        foreach ($records as $record) {
            $record->delete();
        }

        return $this->bulkSaved($records->count(), 'deleted');
    }

    /**
     * `PATCH /admin/{resource}/publish` — bulk publish; body `ids: int[]`.
     */
    public function bulkPublish(Request $request): RedirectResponse
    {
        $records = $this->bulkRecords($request);

        foreach ($records as $record) {
            $this->authorize('publish', $record);
        }

        if (! $this->publishable()) {
            return $this->noPublicationState('publish');
        }

        foreach ($records as $record) {
            $this->markPublished($record);
        }

        return $this->bulkSaved($records->count(), 'published');
    }

    /**
     * `PATCH /admin/{resource}/unpublish` — bulk unpublish; body `ids: int[]`.
     */
    public function bulkUnpublish(Request $request): RedirectResponse
    {
        $records = $this->bulkRecords($request);

        foreach ($records as $record) {
            $this->authorize('unpublish', $record);
        }

        if (! $this->publishable()) {
            return $this->noPublicationState('unpublish');
        }

        foreach ($records as $record) {
            $this->markUnpublished($record);
        }

        return $this->bulkSaved($records->count(), 'unpublished');
    }

    /**
     * `POST /admin/{resource}` — create one record (§5.9).
     *
     * Declared per controller, which is what type-hints the resource's own
     * `Store{Type}Request`; the write itself is here.
     */
    protected function storeRecord(FormRequest $request): RedirectResponse
    {
        $this->authorize('create', $this->modelClass());

        $class = $this->modelClass();

        /** @var Model $record */
        $record = $class::query()->create($this->writableAttributes($request));

        /*
         * The members a create did not supply are filled in by the database —
         * `status` is `draft`, `sort_order` is `0`, `is_placeholder` is `false`
         * (§2.2) — and the in-memory model never sees them, so re-read the row
         * before it is shaped into a payload or a prefill.
         */
        $record->refresh();

        $this->syncTags($record, $request);

        $this->rememberForPrefill($request, $record);

        return $this->saved('created');
    }

    /**
     * `PATCH /admin/{resource}/{id}` — partial update (§5.9).
     *
     * Declared per controller so the resource's own `Update{Type}Request` runs.
     */
    protected function updateRecord(FormRequest $request, Model $record): RedirectResponse
    {
        $this->authorize('update', $record);

        $record->fill($this->writableAttributes($request));
        $record->save();

        $this->syncTags($record, $request);

        return $this->saved('updated');
    }

    /**
     * `GET /admin/{resource}/{id}/edit` — the populated form (§5.9).
     */
    protected function editPage(Model $record): Response
    {
        $this->authorize('view', $record);

        if (method_exists($record, 'tags')) {
            $record->load('tags');
        }

        return Inertia::render($this->page('Edit'), [
            'record' => $this->recordPayload($record),
        ]);
    }

    /**
     * `DELETE /admin/{resource}/{id}` — delete one record (§5.9).
     *
     * Declared per controller so the `{work_experience}`-style parameter binds.
     */
    protected function destroyRecord(Model $record): RedirectResponse
    {
        $this->authorize('delete', $record);

        $record->delete();

        return $this->saved('deleted');
    }

    /**
     * `PATCH /admin/{resource}/{id}/publish` — set `status=published` (§5.8).
     */
    protected function publishRecord(Model $record): RedirectResponse
    {
        $this->authorize('publish', $record);

        if (! $this->publishable()) {
            return $this->noPublicationState('publish');
        }

        $this->markPublished($record);

        return $this->saved('published');
    }

    /**
     * `PATCH /admin/{resource}/{id}/unpublish` — set `status=draft` (§5.8).
     */
    protected function unpublishRecord(Model $record): RedirectResponse
    {
        $this->authorize('unpublish', $record);

        if (! $this->publishable()) {
            return $this->noPublicationState('unpublish');
        }

        $this->markUnpublished($record);

        return $this->saved('unpublished');
    }

    /**
     * `PATCH /admin/{resource}/reorder` — apply the full ordered `ids` array.
     *
     * The position in the submitted array *is* the new `sort_order`, so the
     * list's own ordering (`sort_order` then `id`) reproduces what the admin
     * saw. Ids the resource does not own are ignored rather than rejected: the
     * array is the list the admin just rearranged, and a stale row in it is not
     * worth a 422.
     *
     * `reorder` is a collection-level ability and takes no model, so it is
     * authorised with the class string — the policy's `reorder()` is declared
     * `reorder(User $user)` and the Gate drops a string first argument before
     * calling it (§5.7).
     */
    protected function reorderRecords(Request $request): RedirectResponse
    {
        $this->authorize('reorder', $this->modelClass());

        $ids = $this->ids($request);
        $class = $this->modelClass();

        /** @var Collection<int, Model> $records */
        $records = $class::query()->whereKey($ids)->get()->keyBy(
            fn (Model $record): int => (int) $record->getKey(),
        );

        foreach ($ids as $position => $id) {
            $records->get($id)?->update(['sort_order' => $position]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $this->label().' reordered.',
        ]);

        return $this->indexRoute();
    }

    /**
     * The Inertia component name of one of the resource's pages (§5.8).
     */
    private function page(string $name): string
    {
        return 'admin/'.$this->routeSegment().'/'.$name;
    }

    /**
     * The session key the §7.2 prefill is stored under.
     */
    private function prefillKey(): string
    {
        return 'admin.last_created.'.$this->type();
    }

    /**
     * The index query: the read scope, the filters, then the pinned order.
     *
     * There is no `published()` scope here — the admin is the one writer who
     * must see drafts (§4.11) — and no sort parameter either: v1 ships filters
     * only, so the ordering is the controller's (§5.9).
     *
     * @param  array<string, string|null>  $filters
     * @return Builder<Model>
     */
    private function listQuery(array $filters): Builder
    {
        $class = $this->modelClass();

        /** @var Builder<Model> $query */
        $query = $class::query();

        if (method_exists($class, 'tags')) {
            $query->with('tags');
        }

        $this->applyFilters($query, $filters);

        return $this->applyDefaultOrder($query);
    }

    /**
     * Apply the `?status=`, `?q=` and `?tag=` filters to a list query (§5.9).
     *
     * @param  Builder<Model>  $query
     * @param  array<string, string|null>  $filters
     */
    protected function applyFilters(Builder $query, array $filters): void
    {
        if ($filters['status'] !== null) {
            $query->where('status', $filters['status']);
        }

        if ($filters['q'] !== null) {
            $needle = $this->searchTerm($filters['q']);
            $columns = $this->searchableColumns();

            if ($needle !== '') {
                $query->where(function (Builder $query) use ($columns, $needle): void {
                    foreach ($columns as $column) {
                        $query->orWhere($column, 'like', '%'.$needle.'%');
                    }
                });
            }
        }

        if ($filters['tag'] !== null) {
            $this->applyTagFilter($query, $filters['tag']);
        }
    }

    /**
     * Narrow a list to the records carrying one or more tag slugs.
     *
     * @param  Builder<Model>  $query
     */
    protected function applyTagFilter(Builder $query, string $tag): void
    {
        $slugs = $this->listValues($tag);

        if ($slugs === []) {
            return;
        }

        $query->whereHas('tags', fn (Builder $tags): Builder => $tags->whereIn('tags.slug', $slugs));
    }

    /**
     * The filters the list reads out of the query string (§5.9).
     *
     * The shape is the same for every resource — the shared table binds its
     * controls to it — so `status` is present and `null` on a resource that has
     * no publication state, rather than missing.
     *
     * @return array{status: string|null, q: string|null, tag: string|null}
     */
    private function filters(Request $request): array
    {
        return [
            'status' => $this->publishable() ? $this->statusFilter($request) : null,
            'q' => $this->textFilter($request, 'q'),
            'tag' => $this->textFilter($request, 'tag'),
        ];
    }

    /**
     * The `?status=` filter, or null for an absent or unrecognised value.
     *
     * An unreadable value widens the list rather than failing it: a filter is a
     * view of the collection, and a hand-edited URL must still answer 200.
     */
    private function statusFilter(Request $request): ?string
    {
        $status = $request->query('status');

        return is_string($status) ? ContentStatus::tryFrom($status)?->value : null;
    }

    /**
     * A trimmed string filter, or null when it is absent or empty.
     */
    private function textFilter(Request $request, string $name): ?string
    {
        $value = $request->query($name);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * The needle `?q=` matches.
     *
     * LIKE's own wildcards are stripped rather than escaped: `ESCAPE` is spelled
     * differently on SQLite and MariaDB, and a wildcard is never useful in a
     * filter box.
     */
    private function searchTerm(string $value): string
    {
        return trim(str_replace(['%', '_'], '', $value));
    }

    /**
     * The values of a comma-separated list filter.
     *
     * @return list<string>
     */
    protected function listValues(string $value): array
    {
        $values = [];

        foreach (explode(',', $value) as $item) {
            $item = trim($item);

            if ($item !== '') {
                $values[] = $item;
            }
        }

        return $values;
    }

    /**
     * The resource-level metadata an index page needs beyond its rows.
     *
     * `records` is the paginator — Laravel's own page, links and total — so this
     * carries what the paginator cannot say: which resource the page is looking
     * at and what the resource supports. `orderable` is what lets the page hide
     * the reorder control for `writings` and `tags`, and `publishable` is what
     * lets it hide the publish toggle for `tags` (§5.8, §5.9).
     *
     * @param  LengthAwarePaginator<int, Model>  $records
     * @return array<string, mixed>
     */
    private function meta(LengthAwarePaginator $records): array
    {
        return [
            'type' => $this->type(),
            'segment' => $this->routeSegment(),
            'label' => $this->label(),
            'orderable' => $this->orderable(),
            'publishable' => $this->publishable(),
            'total' => $records->total(),
            'page' => $records->currentPage(),
            'perPage' => $records->perPage(),
            'lastPage' => $records->lastPage(),
        ];
    }

    /**
     * The records a bulk action names, in the order the request listed them.
     *
     * A soft-deleted or unknown id simply resolves to nothing: deleting twice is
     * idempotent and a stale selection is not worth a 404.
     *
     * @return Collection<int, Model>
     */
    private function bulkRecords(Request $request): Collection
    {
        $ids = $this->ids($request);
        $class = $this->modelClass();

        if ($ids === []) {
            return new Collection;
        }

        /** @var Collection<int, Model> $records */
        $records = $class::query()->whereKey($ids)->get();

        return $records;
    }

    /**
     * The `ids: int[]` body a bulk action or a reorder carries (§5.8).
     *
     * @return list<int>
     */
    private function ids(Request $request): array
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        return array_values(array_map(fn (mixed $id): int => (int) $id, $validated['ids']));
    }

    /**
     * The model attributes a validated admin payload sets.
     *
     * `tag_ids` is removed because it is not a column: the admin payload is flat
     * and deliberately not the API's JSON:API relationship document, so the
     * member is applied to the pivot by {@see self::syncTags()} instead.
     *
     * @return array<string, mixed>
     */
    private function writableAttributes(FormRequest $request): array
    {
        $validated = $request->validated();

        unset($validated['tag_ids']);

        return $validated;
    }

    /**
     * Apply the payload's `tag_ids` member to the record's pivot (§5.10).
     *
     * A payload that omits the member leaves the associations alone, so a
     * partial update that never touched the picker cannot silently untag a
     * record; an empty list clears them, which is what the picker sends when the
     * admin removes every chip.
     */
    private function syncTags(Model $record, FormRequest $request): void
    {
        $validated = $request->validated();

        if (! array_key_exists('tag_ids', $validated)) {
            return;
        }

        if (! method_exists($record, 'tags')) {
            return;
        }

        $relation = $record->tags();

        if (! $relation instanceof MorphToMany) {
            return;
        }

        $relation->sync(array_map(fn (mixed $id): int => (int) $id, (array) $validated['tag_ids']));
    }

    /**
     * Store the fields a following create may offer to prefill (§7.2).
     *
     * Nothing is written when the type carries nothing over — a tag's name is
     * unique, so there is no redundant entry to spare the admin — because an
     * empty array is still truthy to the page's `v-if` and would offer a button
     * that populates nothing.
     */
    private function rememberForPrefill(FormRequest $request, Model $record): void
    {
        $payload = $this->recordPayload($record);
        $prefill = array_intersect_key($payload, array_flip($this->prefillFields()));

        if ($prefill === []) {
            return;
        }

        $request->session()->put($this->prefillKey(), $prefill);
    }

    /**
     * Publish one record through the model's own `Publishable` contract.
     *
     * The `method_exists()` guard is for the analyser, which only knows the
     * `Model` the base class declares; {@see self::publishable()} is the runtime
     * guard, and the two cannot disagree because both ask the model class.
     */
    private function markPublished(Model $record): void
    {
        if (method_exists($record, 'publish')) {
            $record->publish();
        }
    }

    /**
     * Return one record to draft through the model's `Publishable` contract.
     */
    private function markUnpublished(Model $record): void
    {
        if (method_exists($record, 'unpublish')) {
            $record->unpublish();
        }
    }

    /**
     * The redirect a successful write answers with: the index, plus a toast.
     *
     * The `toast` key is the §3.11 shape `initializeFlashToast()` already turns
     * into a `vue-sonner` toast, so the admin needs no second mechanism.
     */
    private function saved(string $action): RedirectResponse
    {
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $this->label().' '.$action.'.',
        ]);

        return $this->indexRoute();
    }

    /**
     * The redirect a successful bulk action answers with.
     */
    private function bulkSaved(int $count, string $action): RedirectResponse
    {
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $count.' '.Str::plural('record', $count).' '.$action.'.',
        ]);

        return $this->indexRoute();
    }

    /**
     * The answer a publish action gives for a resource that has no status.
     *
     * `tags` is the one such resource (§2.5): it is a full six-method CRUD
     * resource with the same route set as the seven content types, but it has
     * neither a `status` nor a `published_at` column, so `publish` and
     * `unpublish` are policy-checked no-ops that say so rather than reporting a
     * change they did not make. The index's `meta.publishable` is what keeps the
     * control off the tag list in the first place.
     */
    private function noPublicationState(string $action): RedirectResponse
    {
        Inertia::flash('toast', [
            'type' => 'info',
            'message' => Str::plural($this->label()).' have no publication state, so there is nothing to '.$action.'.',
        ]);

        return $this->indexRoute();
    }

    /**
     * The resource's index route.
     */
    private function indexRoute(): RedirectResponse
    {
        return to_route('admin.'.$this->routeSegment().'.index');
    }
}
