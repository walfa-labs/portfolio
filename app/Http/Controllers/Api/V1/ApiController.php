<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ContentStatus;
use App\Enums\ContentType;
use App\Exceptions\JsonApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\EducationResource;
use App\Models\Tag;
use App\Support\ContentQuery;
use App\Support\JsonApi\JsonApiError;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\JsonApi\JsonApiRequest;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * The machinery the eight §4.2 resource controllers share.
 *
 * Every resource in Table A answers the same verb matrix (§4.5), the same
 * filter/sort/include allow-lists (§4.8) and the same caching contract (§4.12).
 * The eight controllers differ only in their model, their API Resource, their
 * form requests and their per-type attributes, which they declare through the
 * abstract methods below.
 *
 * Two behaviours are deliberately not inherited. `store`, `update` and `patch`
 * are declared per controller so that each one type-hints its own form request
 * — that is what runs validation and policy authorisation — and `destroy`
 * authorises through the model's policy because it has no form request.
 *
 * Drafts are invisible to reads and visible to writes. Every public read goes
 * through the `published()` scope, which no query parameter can widen (§4.8);
 * writes bind the model directly, because the one writer is the administrator
 * editing records that are not published yet (§4.11).
 */
abstract class ApiController extends Controller
{
    use AuthorizesRequests;

    /**
     * The page size §4.7 defaults to.
     */
    protected const DEFAULT_PAGE_SIZE = 20;

    /**
     * The range §4.7 accepts for `page[size]`; anything else is a 400, never a clamp.
     */
    protected const MIN_PAGE_SIZE = 1;

    protected const MAX_PAGE_SIZE = 100;

    /**
     * The top-level query members the collection endpoints define (§4.4, §4.6).
     */
    private const COLLECTION_MEMBERS = ['filter', 'sort', 'include', 'fields', 'page'];

    /**
     * The top-level query members a single-resource endpoint defines (§4.6).
     *
     * A single document has no ordering and no filters, so `filter`, `sort` and
     * `page` are undeclared there and answer 400 rather than being ignored.
     */
    private const RESOURCE_MEMBERS = ['include', 'fields'];

    /**
     * The JSON:API type, resolved once per controller instance (§4.2).
     */
    private ?string $type = null;

    /**
     * The introspected columns of the resource's table, keyed by column name.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $columns = [];

    /**
     * The model the resource is built on.
     *
     * @return class-string<Model>
     */
    abstract protected function modelClass(): string;

    /**
     * The API Resource that shapes one record (§4.2).
     *
     * @return class-string<JsonResource>
     */
    abstract protected function resourceClass(): string;

    /**
     * The JSON:API `type` of the resource (§4.2).
     *
     * Asked of the API Resource itself rather than derived here. The framework
     * derives a type from the resource class basename minus `Resource`,
     * snake-cased and `pluralStudly()`-ed, and that derivation is wrong for
     * exactly one of the eight types: `Str::pluralStudly('education')` is
     * `education`, while Table A fixes the type as `educations` — which is why
     * {@see EducationResource} overrides `toType()`. Deriving the type a second
     * time here is what let the controller and the resource disagree, so the
     * resource — the authority on the type it publishes — answers instead, and
     * the two cannot drift again.
     *
     * The controller instance outlives one request (the router caches it), but
     * the answer depends only on the controller class, so it is memoised.
     */
    protected function type(): string
    {
        if ($this->type !== null) {
            return $this->type;
        }

        $class = $this->resourceClass();

        /** @var JsonApiResource $resource */
        $resource = new $class(null);

        return $this->type = $resource->resolveResourceType(JsonApiRequest::createFrom(request()));
    }

    /**
     * The sort applied when the request carries none (§4.8).
     */
    abstract protected function defaultSort(): string;

    /**
     * The sortable fields, without the direction prefix (§4.8).
     *
     * @return list<string>
     */
    abstract protected function allowedSorts(): array;

    /**
     * The allowed filters, keyed by the name in `filter[…]` (§4.8).
     *
     * The keys are the allow-list: Spatie rejects an undeclared name with an
     * `InvalidFilterQuery`, which `bootstrap/app.php` renders as the 400 §4.6
     * defines, so the declaration below is the only copy of the list.
     *
     * @return array<string, callable(Builder<Model>, mixed): void>
     */
    abstract protected function allowedFilters(): array;

    /**
     * The attribute names `fields[<type>]` may select (§4.9).
     *
     * @return list<string>
     */
    abstract protected function allowedFields(): array;

    /**
     * The attribute names a write may set (§4.5).
     *
     * Everything else — `id`, `slug`, the timestamps, the derived columns and
     * any unknown key — is refused with the offending key in `source.pointer`.
     *
     * @return list<string>
     */
    abstract protected function writableAttributes(): array;

    /**
     * The plural kebab-case path and route-name segment (§4.2).
     *
     * The type is snake_case — `work_experiences` — and the segment is its
     * kebab-case spelling. `Str::kebab()` cannot do this: it is `Str::snake()`
     * with `-` as the delimiter, and `Str::snake()` leaves an already-lowercase
     * string alone, underscore and all, so `work_experiences` would come back
     * unchanged and name a route that does not exist.
     */
    protected function routeSegment(): string
    {
        return str_replace('_', '-', $this->type());
    }

    /**
     * The relationships a client may embed.
     *
     * `tags` is the only one in v1, and only the seven content resources have
     * it — a tag has no relationship of its own, so `TagController` overrides
     * this to declare none (§4.9). `series` is an attribute object on writings,
     * never a relationship, so `include=series` is rejected exactly like any
     * other undeclared include.
     *
     * @return list<string>
     */
    protected function includes(): array
    {
        return ['tags'];
    }

    /**
     * The columns `filter[q]` matches partially (§4.8).
     *
     * @return list<string>
     */
    protected function searchableColumns(): array
    {
        return ['title', 'summary'];
    }

    /**
     * The tag filters the seven content resources declare (§4.8).
     *
     * `filter[tag]` is the pinned name; `filter[tags]` is accepted as the same
     * filter because §4.2's worked example and §4.8's table both spell it that
     * way, and rejecting a spelling the document of record uses would punish a
     * client for following it. `filter[tags_all]` narrows to records carrying
     * *every* named tag rather than any of them. Values are comma-separated
     * slugs, and the match is on the tag's slug, never its id.
     *
     * @return array<string, callable(Builder<Model>, mixed): void>
     */
    protected function tagFilters(): array
    {
        $any = function (Builder $query, mixed $value): void {
            $slugs = $this->listValues($value);

            if ($slugs === []) {
                return;
            }

            $query->whereHas('tags', fn (Builder $tags): Builder => $tags->whereIn('tags.slug', $slugs));
        };

        $all = function (Builder $query, mixed $value): void {
            $slugs = $this->listValues($value);

            if ($slugs === []) {
                return;
            }

            $query->whereHas(
                'tags',
                fn (Builder $tags): Builder => $tags->whereIn('tags.slug', $slugs),
                '>=',
                count($slugs),
            );
        };

        return ['tag' => $any, 'tags' => $any, 'tags_all' => $all];
    }

    /**
     * The writable attributes every content resource shares (§4.5).
     *
     * `slug` is absent by design — it is derived server-side and never
     * client-supplied — as are `id` and the timestamps.
     *
     * @return list<string>
     */
    protected function sharedWritableAttributes(): array
    {
        return [
            'title',
            'summary',
            'body',
            'status',
            'published_at',
            'meta_title',
            'meta_description',
            'sort_order',
            'is_placeholder',
        ];
    }

    /**
     * The attribute names `fields[tags]` may select (§4.9).
     *
     * `tags` is the one type that appears in two places: as the resource of
     * `/api/v1/tags` and as the embedded type of the seven content resources.
     * One list serves both, so a fieldset naming the same type on either
     * endpoint is judged by the same allow-list and `TagController`'s own
     * `allowedFields()` is that list rather than a second copy of it.
     *
     * @return list<string>
     */
    protected function tagFields(): array
    {
        return ['name', 'slug', 'description', 'is_placeholder', 'created_at', 'updated_at'];
    }

    /**
     * The attribute names every content resource publishes (§4.4.2).
     *
     * The writable SEO and admin columns (`status`, `meta_title`,
     * `meta_description`, `sort_order`) are deliberately not published, so they
     * are not selectable through a sparse fieldset either. `tags` is listed
     * because a JSON:API fieldset may name a relationship (§4.9).
     *
     * @return list<string>
     */
    protected function sharedFields(): array
    {
        return [
            'title',
            'slug',
            'summary',
            'body',
            'published_at',
            'is_published',
            'is_placeholder',
            'created_at',
            'updated_at',
            'tags',
        ];
    }

    /**
     * The scope every public read starts from (§4.8).
     *
     * Drafts and scheduled records are invisible, and no query parameter widens
     * the scope. The scope is applied wherever the model has one: `tags` carries
     * no publication state — no `status`, no `published_at` — so it is read as
     * it stands, exactly as the public `/tags` page lists it (§3.7).
     *
     * @return Builder<Model>
     */
    protected function readQuery(): Builder
    {
        $class = $this->modelClass();

        if (method_exists($class, 'scopePublished')) {
            return ContentQuery::published($class);
        }

        return $class::query();
    }

    /**
     * The `filter[q]` filter: a partial match over the resource's text columns.
     *
     * The comparison is a plain `like`, which both engines answer
     * case-insensitively under their default collations, and LIKE's own
     * wildcards are stripped from the needle rather than escaped — `ESCAPE` is
     * spelled differently on SQLite and MariaDB, and a wildcard is never useful
     * in a filter box.
     *
     * @return callable(Builder<Model>, mixed): void
     */
    protected function searchFilter(): callable
    {
        return function (Builder $query, mixed $value): void {
            $needle = $this->searchTerm($value);

            if ($needle === '') {
                return;
            }

            $columns = $this->searchableColumns();

            $query->where(function (Builder $query) use ($columns, $needle): void {
                foreach ($columns as $column) {
                    $query->orWhere($column, 'like', '%'.$needle.'%');
                }
            });
        };
    }

    /**
     * The values of a comma-separated list filter.
     *
     * @return list<string>
     */
    protected function listValues(mixed $value): array
    {
        $values = [];

        foreach (is_array($value) ? $value : explode(',', is_scalar($value) ? (string) $value : '') as $item) {
            if (! is_scalar($item)) {
                continue;
            }

            $item = trim((string) $item);

            if ($item !== '') {
                $values[] = $item;
            }
        }

        return $values;
    }

    /**
     * The needle a `filter[q]` matches.
     */
    protected function searchTerm(mixed $value): string
    {
        return is_scalar($value) ? trim(str_replace(['%', '_'], '', (string) $value)) : '';
    }

    /**
     * The boolean a `filter[featured]` or `filter[expired]` carries.
     *
     * `1`, `0`, `true` and `false` are the vocabulary; anything else is an
     * undeclared value and therefore a 400 (§4.8).
     */
    protected function booleanValue(string $filter, mixed $value): bool
    {
        $boolean = is_bool($value) ? $value : filter_var(
            is_scalar($value) ? (string) $value : '',
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE,
        );

        if ($boolean === null) {
            throw new JsonApiException(JsonApiError::invalidQueryParameter(
                'filter['.$filter.'] must be true or false.',
                'filter['.$filter.']',
            ));
        }

        return $boolean;
    }

    /**
     * The value each writable attribute resets to when a full replacement omits
     * it (§4.5).
     *
     * The value is not read from the schema: SQLite reports a default as the
     * raw `dflt_value` (`'draft'`, `'0'`, quotes included) and MariaDB reports
     * it unquoted, so a portable read would be a per-engine parser. The list is
     * therefore written down, and the schema is consulted for the one fact both
     * engines report the same way — whether the column can hold a `null` at all
     * (see {@see self::columnIsNullable()}).
     *
     * Every writable attribute whose column is NOT NULL **and** carries a
     * default must appear here, or a `PUT` that omits it writes `null` into a
     * column that refuses one and the write dies as a 500 instead of resetting.
     * Across the eight tables that is four columns: `status` and `sort_order`
     * and `is_placeholder` on every content table, and `is_featured` on
     * `projects` — the one per-type column in the set. Everything else writable
     * is nullable and resets to `null`.
     *
     * @return array<string, mixed>
     */
    protected function replacementDefaults(): array
    {
        return [
            'status' => ContentStatus::Draft,
            'is_placeholder' => false,
            'sort_order' => 0,
            'is_featured' => false,
        ];
    }

    /**
     * Guard a write's media type before its form request reads the body (§4.6).
     *
     * The request `Content-Type` is a precondition of the write contract, not a
     * validation rule: without it the body has no declared interpretation, so a
     * missing or non-JSON:API media type is a 415 and not the 422 that a body
     * nobody can read would otherwise produce.
     *
     * The constructor is the earliest hook the controller owns that runs after
     * the `api-admin` group has established the session — so an absent one is
     * still a 401 — and before `ControllerDispatcher` resolves, and therefore
     * validates, the method's form request. A `DELETE` carries no body, so
     * Table B gives it no 415.
     *
     * `Route` caches the controller instance it resolves for the life of the
     * process, so this guard answers the first write on a route and
     * {@see self::create()} and {@see self::amend()} answer the rest. A media
     * type Laravel cannot read as JSON at all — `text/plain`, or none — still
     * fails the form request's validation first and answers 422; catching that
     * too needs the check in the `api-admin` middleware group, where no
     * controller code runs.
     */
    public function __construct()
    {
        $request = request();

        if (in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            $this->ensureJsonApiContentType($request);
        }
    }

    /**
     * `GET /api/v1/{resource}` — one page of the collection (§4.4.1).
     */
    public function index(Request $request): JsonResponse
    {
        $this->ensureAcceptable($request);
        $this->ensureQueryIsDeclared($request, self::COLLECTION_MEMBERS);

        $paginator = $this->listQuery()->paginate(
            $this->pageSize($request),
            ['*'],
            'page',
            $this->pageNumber($request),
        );

        // Read the validators before the resource collection is built: wrapping
        // the paginator re-points it at resource objects rather than models.
        $lastModified = $this->newestUpdate($paginator->getCollection());

        return $this->cacheable(
            $request,
            $this->resourceCollection($paginator)->toResponse($request),
            'collection',
            $lastModified,
        );
    }

    /**
     * `GET /api/v1/{resource}/{id}` — one record (§4.4.2).
     *
     * The lookup is explicit rather than implicit route-model binding, for the
     * same reason the public controllers do it that way: binding resolves the id
     * through a plain `where`, which would happily return a draft, and
     * {@see self::readQuery()} is the only place that rule is written down (§4.8).
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $this->ensureAcceptable($request);
        $this->ensureQueryIsDeclared($request, self::RESOURCE_MEMBERS);

        $model = $this->readQuery()->findOrFail($id);

        return $this->cacheable(
            $request,
            $this->resourceFor($model)->toResponse($request),
            'resource:'.$model->getKey(),
            $this->updatedAt($model),
        );
    }

    /**
     * `OPTIONS` on a collection or single-resource URI — discovery only (§4.5).
     *
     * `routes/api.php` currently answers OPTIONS from unnamed closure actions
     * that carry the same pinned `Allow` values, so this method is the
     * controller-side equivalent for a route that prefers a controller action.
     */
    public function options(Request $request): Response
    {
        $parameters = $request->route()?->parameters() ?? [];

        return response('', 200, [
            'Allow' => JsonApiError::allowedMethods(
                $parameters === []
                    ? ['GET', 'HEAD', 'OPTIONS', 'POST']
                    : ['GET', 'HEAD', 'OPTIONS', 'PUT', 'PATCH', 'DELETE'],
            ),
        ]);
    }

    /**
     * `POST /api/v1/{resource}` — create one record (§4.5).
     */
    protected function create(FormRequest $request): JsonResponse
    {
        $this->ensureJsonApiContentType($request);
        $this->ensureAttributesAreWritable($request);

        $class = $this->modelClass();

        /** @var Model $model */
        $model = $class::query()->create($this->attributes($request, replacing: false));

        /*
         * The members a create did not supply are filled in by the database —
         * `is_placeholder` is `false`, `status` is `draft`, `sort_order` is `0`
         * (§2.2) — and the in-memory model never sees them, so the response
         * would report `null` for a column the row stores as `false`. Re-read
         * the row so the document describes what was actually stored.
         */
        $model->refresh();

        $this->syncTags($model, $request, replacing: false);

        // The response carries the new representation and the `Location` of it,
        // and JSON:API requires that URL and the resource object's own
        // `links.self` to be one and the same (§4.5). Both therefore name the
        // same route and address it by the API identifier — the primary key —
        // rather than by handing `route()` the model, which would resolve
        // `Tag::getRouteKeyName()` and publish a slug URI the API does not
        // serve (§2.4, §4.2). The API suite asserts the two strings are equal
        // for all eight resources, so neither side can drift alone.
        return $this->stored(
            $this->resourceFor($model)->toResponse($request),
            route('api.v1.'.$this->routeSegment().'.show', $model->getKey()),
            201,
        );
    }

    /**
     * `PUT` and `PATCH /api/v1/{resource}/{id}` — replace or amend one record.
     *
     * Laravel registers both verbs against the resource's `update` action, so the
     * request's method decides which semantics apply (§4.5): `PUT` resets every
     * writable attribute the client omitted, `PATCH` leaves them alone.
     */
    protected function amend(FormRequest $request, Model $model): JsonResponse
    {
        $this->ensureJsonApiContentType($request);
        $this->ensureDocumentMatchesRoute($request, $model);
        $this->ensureAttributesAreWritable($request);

        $replacing = $request->isMethod('PUT');

        $model->fill($this->attributes($request, $replacing));
        $model->save();

        $this->syncTags($model, $request, $replacing);

        return $this->stored($this->resourceFor($model)->toResponse($request), null, 200);
    }

    /**
     * Resolve one record for a write.
     *
     * A resource whose model overrides `getRouteKeyName()` — `tags` binds by slug
     * on the public site — must not rely on implicit route-model binding, which
     * would resolve the API's numeric id as a slug. Those controllers take the
     * raw id and come through here, which always binds the primary key (§2.4,
     * §4.2).
     */
    protected function findForWrite(string $id): Model
    {
        $class = $this->modelClass();

        return $class::query()->findOrFail($id);
    }

    /**
     * `DELETE /api/v1/{resource}/{id}` — soft-delete one record (§4.5).
     *
     * A second DELETE of the same id is a 404, because the soft-delete scope
     * hides the row; the effect of the verb stays idempotent. The policy check
     * lives here because a delete has no form request to carry it, and the
     * `api-admin` group has already turned an absent session into a 401.
     */
    protected function remove(Model $model): Response
    {
        $this->authorize('delete', $model);

        $model->delete();

        return response()->noContent(204, ['Cache-Control' => 'no-store']);
    }

    /**
     * Build one API Resource for a record.
     */
    private function resourceFor(Model $model): JsonResource
    {
        $class = $this->resourceClass();

        return new $class($model);
    }

    /**
     * Build the API Resource collection for one page.
     *
     * `ResolvesJsonApiDocument::newCollection()` makes this the `ApiCollection`
     * whose `paginationInformation()` replaces Laravel's pagination members with
     * the §4.4.1 ones, so the document is assembled entirely by the resource.
     *
     * @param  LengthAwarePaginator<int, Model>  $paginator
     */
    private function resourceCollection(LengthAwarePaginator $paginator): JsonResource
    {
        $class = $this->resourceClass();

        return $class::collection($paginator);
    }

    /**
     * The collection query: the read scope, then Spatie's allow-listed
     * filtering, sorting and relation embedding (§4.8).
     *
     * The scope is applied before Spatie wraps the builder, so no filter, sort
     * or include can reach a draft; and because the allow-lists are the ones
     * declared above, an undeclared name is a 400 from Spatie rather than a
     * silently ignored parameter.
     *
     * The sparse fieldset is deliberately not handed to Spatie. §4.9 gives
     * `fields[…]` to Laravel's JSON:API pair, which narrows every resource
     * object in the document — the embedded `tags` included. Spatie reads the
     * same parameter for its own purposes and answers a fieldset naming a field
     * of an *included* relation with its own 400 unless `allowedFields()` is
     * declared on the builder; declaring it would make Spatie `select()` the
     * narrowed column list for the primary resource too, dropping the primary
     * key a resource object must still carry. So the fieldset is validated by
     * {@see self::ensureFieldsAreDeclared()} and applied by the resource, and
     * the builder is handed a request without it.
     *
     * @return QueryBuilder<Model>
     */
    private function listQuery(): QueryBuilder
    {
        return QueryBuilder::for($this->readQuery(), $this->requestWithoutFields())
            ->allowedFilters(...$this->spatieFilters())
            ->allowedSorts(...$this->allowedSorts())
            ->allowedIncludes(...$this->includes())
            ->defaultSort($this->defaultSort());
    }

    /**
     * The current request with its sparse fieldset removed.
     *
     * A copy, not the live request: `duplicate()` gives the copy a `query` bag
     * of its own, so the checks the controller runs against the real request
     * still see the fieldset the client sent.
     */
    private function requestWithoutFields(): Request
    {
        return request()->duplicate(array_diff_key(request()->query(), ['fields' => true]));
    }

    /**
     * The filter allow-list as Spatie filter objects.
     *
     * @return list<AllowedFilter>
     */
    private function spatieFilters(): array
    {
        $filters = [];

        foreach ($this->allowedFilters() as $name => $callback) {
            $filters[] = AllowedFilter::callback($name, $callback);
        }

        return $filters;
    }

    /**
     * Give a public read its caching headers and validators (§4.12).
     *
     * `CheckResponseForModifications` is applied to the read routes in
     * `routes/api.php`, so it is the middleware — not this method — that turns a
     * matching `If-None-Match` or `If-Modified-Since` into the 304; here the
     * validators are only declared, and they cover the caller's query parameters
     * because two different pages of one collection are two representations.
     */
    private function cacheable(Request $request, JsonResponse $response, string $shape, ?DateTimeInterface $lastModified): JsonResponse
    {
        $response->headers->set('Cache-Control', 'public, max-age=60, stale-while-revalidate=30');
        $response->setVary('Accept');
        $response->setEtag($this->type().':'.$shape.':'.hash(
            'sha256',
            (string) $response->getContent().'|'.(string) $request->getQueryString(),
        ));

        if ($lastModified !== null) {
            $response->setLastModified($lastModified);
        }

        return $response;
    }

    /**
     * Give a write its response: `no-store`, and `Location` on create (§4.12).
     */
    private function stored(JsonResponse $response, ?string $location, int $status): JsonResponse
    {
        $response->setStatusCode($status);
        $response->headers->set('Cache-Control', 'no-store');
        $response->setVary('Accept');

        if ($location !== null) {
            $response->headers->set('Location', $location);
        }

        return $response;
    }

    /**
     * The newest `updated_at` in a page — the collection's `Last-Modified`.
     *
     * @param  iterable<int, Model>  $models
     */
    private function newestUpdate(iterable $models): ?DateTimeInterface
    {
        $newest = null;

        foreach ($models as $model) {
            $updatedAt = $this->updatedAt($model);

            if ($updatedAt !== null && ($newest === null || $updatedAt > $newest)) {
                $newest = $updatedAt;
            }
        }

        return $newest;
    }

    /**
     * A record's `updated_at`, or null when it has none.
     */
    private function updatedAt(Model $model): ?DateTimeInterface
    {
        $updatedAt = $model->getAttribute('updated_at');

        return $updatedAt instanceof DateTimeInterface ? $updatedAt : null;
    }

    /**
     * The writable payload of a write document (§4.5).
     *
     * A partial update sends exactly what the client wrote. A full replacement
     * resets every writable attribute the client omitted: to its column default
     * when the column has one ({@see self::replacementDefaults()}), to `null`
     * when the column is nullable, and — for a NOT NULL column with no default,
     * which cannot be reset by omission at all — to the same `422` the form
     * requests raise, naming the member §4.5 says is required. The requests
     * already require those members on `PUT`, so this branch is the
     * controller's half of one rule rather than a second opinion; without it a
     * request that ever stopped requiring one would put `null` into a NOT NULL
     * column and turn a documented `422` into a `500`.
     *
     * @return array<string, mixed>
     */
    private function attributes(FormRequest $request, bool $replacing): array
    {
        $supplied = $this->documentMember($request, 'attributes');
        $supplied = is_array($supplied) ? $supplied : [];

        $defaults = $this->replacementDefaults();
        $payload = [];

        foreach ($this->writableAttributes() as $attribute) {
            if (array_key_exists($attribute, $supplied)) {
                $payload[$attribute] = $supplied[$attribute];

                continue;
            }

            if (! $replacing) {
                continue;
            }

            if (array_key_exists($attribute, $defaults)) {
                $payload[$attribute] = $defaults[$attribute];

                continue;
            }

            if (! $this->columnIsNullable($attribute)) {
                throw new JsonApiException(JsonApiError::validationFailed(
                    'The '.$attribute.' attribute is required on a full replacement.',
                    '/data/attributes/'.$attribute,
                ));
            }

            $payload[$attribute] = null;
        }

        return $payload;
    }

    /**
     * Whether the resource's column for an attribute accepts a `null`.
     *
     * The one column fact this class reads from the schema, because it is the
     * one both engines report identically: `getColumns()` answers `nullable`
     * from `pragma table_info` on SQLite and from `information_schema` on
     * MariaDB, while the *value* of a default is spelled differently on each
     * and stays in {@see self::replacementDefaults()}.
     *
     * A column the table does not have — an attribute the resource derives
     * rather than stores — reads as nullable, which is the behaviour every
     * writable attribute had before this check existed.
     */
    private function columnIsNullable(string $attribute): bool
    {
        $class = $this->modelClass();

        /** @var Model $model */
        $model = new $class;

        $table = $model->getTable();

        $columns = $this->columns[$table] ??= collect(Schema::getColumns($table))
            ->keyBy('name')
            ->all();

        return $columns[$attribute]['nullable'] ?? true;
    }

    /**
     * A member of the validated JSON:API document, or null when it is absent.
     *
     * The form requests key their rules on the document's own paths
     * (`data.attributes.title`), so `validated()` hands back the document the
     * client sent, minus everything the rules did not declare.
     */
    private function documentMember(FormRequest $request, string $member): mixed
    {
        $validated = $request->validated();
        $data = $validated['data'] ?? null;

        return is_array($data) ? ($data[$member] ?? null) : null;
    }

    /**
     * Apply `relationships.tags.data` to a record (§4.9).
     *
     * Omitting `data.relationships` clears the associations on a full
     * replacement and leaves them untouched on a partial one; an explicit empty
     * list always clears them (§4.5). Tags are never created here, and an
     * unknown tag id never arrives — the form request rejects it with a 422
     * pointing at `/data/relationships/tags/data/<index>/id`.
     */
    private function syncTags(Model $model, FormRequest $request, bool $replacing): void
    {
        $relation = $this->tagsRelation($model);

        if ($relation === null) {
            return;
        }

        $relationships = $this->documentMember($request, 'relationships');

        if (! is_array($relationships)) {
            if ($replacing) {
                $relation->sync([]);
            }

            return;
        }

        $tags = $relationships['tags'] ?? null;
        $identifiers = is_array($tags) ? ($tags['data'] ?? null) : null;

        $relation->sync($this->tagIds($identifiers));
    }

    /**
     * The record's `tags` relation, or null for a model that has none.
     *
     * @return MorphToMany<Tag, Model, Pivot, string>|null
     */
    private function tagsRelation(Model $model): ?MorphToMany
    {
        if (! method_exists($model, 'tags')) {
            return null;
        }

        $relation = $model->tags();

        return $relation instanceof MorphToMany ? $relation : null;
    }

    /**
     * The tag ids in a `relationships.tags.data` member.
     *
     * @return list<int>
     */
    private function tagIds(mixed $identifiers): array
    {
        if (! is_array($identifiers)) {
            return [];
        }

        $ids = [];

        foreach ($identifiers as $identifier) {
            $id = is_array($identifier) ? ($identifier['id'] ?? null) : null;

            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * Reject a request whose `Accept` header cannot be satisfied (§4.6).
     *
     * `application/json` is admitted alongside the JSON:API media type: it names
     * the same document, and refusing it would 406 every client that reaches for
     * the obvious JSON media type. Anything that names neither — `text/html`,
     * `application/xml` — is a 406, and the response's own `Accept` says what
     * the endpoint produces.
     */
    private function ensureAcceptable(Request $request): void
    {
        if ($request->accepts([JsonApiError::MEDIA_TYPE, JsonApiError::JSON_MEDIA_TYPE])) {
            return;
        }

        throw new JsonApiException(JsonApiError::notAcceptable());
    }

    /**
     * Reject a write that does not declare the JSON:API media type (§4.6).
     *
     * A missing `Content-Type` is a 415 as well: without it the body has no
     * declared interpretation, and the only one this endpoint accepts is
     * JSON:API. A parameterised JSON:API media type is refused too unless the
     * parameter is one JSON:API defines (`ext`, `profile`).
     */
    private function ensureJsonApiContentType(Request $request): void
    {
        $header = trim((string) $request->header('Content-Type'));
        [$type, $parameters] = array_pad(explode(';', $header, 2), 2, '');

        if (strtolower(trim($type)) === JsonApiError::MEDIA_TYPE && $this->onlyExtensionParameters($parameters)) {
            return;
        }

        throw new JsonApiException(
            JsonApiError::unsupportedMediaType()->withHeaders(['Accept' => JsonApiError::MEDIA_TYPE]),
        );
    }

    /**
     * Whether a media-type parameter string carries nothing but the parameters
     * JSON:API defines for its media type (§4.6).
     */
    private function onlyExtensionParameters(string $parameters): bool
    {
        foreach (explode(';', $parameters) as $parameter) {
            $name = strtolower(trim(explode('=', $parameter, 2)[0]));

            if ($name !== '' && ! in_array($name, ['ext', 'profile'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reject a write whose `data.type` or `data.id` contradicts the endpoint.
     *
     * The document is read raw rather than validated, because this is a
     * consistency check on the document itself rather than on any one member
     * (§4.5), and the form requests declare neither member.
     */
    private function ensureDocumentMatchesRoute(Request $request, Model $model): void
    {
        $type = $request->input('data.type');

        if (is_string($type) && $type !== $this->type()) {
            throw new JsonApiException(JsonApiError::conflict(
                "The document type \"{$type}\" does not match the endpoint type \"{$this->type()}\".",
                '/data/type',
            ));
        }

        $documentId = $request->input('data.id');

        if ($documentId !== null && (string) $documentId !== (string) $model->getKey()) {
            throw new JsonApiException(JsonApiError::conflict(
                'The document id does not match the id in the request path.',
                '/data/id',
            ));
        }
    }

    /**
     * Reject any attribute member the resource does not accept on a write (§4.5).
     *
     * The form requests prohibit the non-writable members they know about; this
     * closes the remaining gap, so an unknown key is a 422 naming it rather than
     * a member the model's fillable list silently drops.
     */
    private function ensureAttributesAreWritable(Request $request): void
    {
        $supplied = $request->input('data.attributes');

        if (! is_array($supplied)) {
            return;
        }

        $writable = $this->writableAttributes();

        foreach (array_keys($supplied) as $attribute) {
            if (! in_array($attribute, $writable, true)) {
                throw new JsonApiException(JsonApiError::validationFailed(
                    'The '.$attribute.' attribute is not writable.',
                    '/data/attributes/'.$attribute,
                ));
            }
        }
    }

    /**
     * Reject a query member the endpoint does not declare (§4.6, §4.9).
     *
     * Undeclared filters, sorts and includes are Spatie's to reject — it holds
     * the allow-lists and `bootstrap/app.php` renders its `InvalidQuery` family
     * as the 400 §4.6 defines. The include half is driven from here through
     * {@see self::ensureIncludesAreDeclared()} so the single-resource path runs
     * it too; what Spatie never sees is an unknown top-level member, a malformed
     * `page[…]`, or a `fields[…]` member, and those are checked here.
     *
     * @param  list<string>  $members
     */
    private function ensureQueryIsDeclared(Request $request, array $members): void
    {
        foreach (array_keys($request->query()) as $member) {
            if (! in_array($member, $members, true)) {
                throw new JsonApiException(JsonApiError::invalidQueryParameter(
                    'Unknown query parameter "'.$member.'". Allowed: '.implode(', ', $members).'.',
                    (string) $member,
                ));
            }
        }

        $this->ensureIncludesAreDeclared($request);
        $this->ensurePageIsDeclared($request);
        $this->ensureFieldsAreDeclared($request);
    }

    /**
     * Reject an `include` the resource does not declare (§4.9).
     *
     * The allow-list lives in {@see self::includes()} and Spatie is its one
     * validator: `allowedIncludes()` throws `InvalidIncludeQuery` eagerly when a
     * requested name is not on the list, which `bootstrap/app.php` renders as
     * the §4.6 400 with `source.parameter = include`.
     *
     * The collection path gets that check for free from
     * {@see self::listQuery()}, but the single-resource path never builds a
     * collection query — which is what let `GET /api/v1/writings/1?include=nonsense`
     * answer `200` where §4.9 requires `400`. Both paths therefore run the
     * *same* Spatie check against the *same* list here, before either resolves
     * its record; the builder the check returns is discarded, because a single
     * document has no collection query to narrow.
     */
    private function ensureIncludesAreDeclared(Request $request): void
    {
        QueryBuilder::for($this->readQuery(), $request)
            ->allowedIncludes(...$this->includes());
    }

    /**
     * Reject an unknown `fields[…]` type or an unknown field name (§4.9).
     */
    private function ensureFieldsAreDeclared(Request $request): void
    {
        $fields = $this->queryMember($request, 'fields');

        if ($fields === null) {
            return;
        }

        if (! is_array($fields)) {
            throw new JsonApiException(JsonApiError::invalidQueryParameter(
                'fields must be a set of named members, e.g. fields[writings]=title.',
                'fields',
            ));
        }

        foreach ($fields as $type => $names) {
            if (! is_string($type) || ! $this->isKnownType($type)) {
                throw new JsonApiException(JsonApiError::invalidQueryParameter(
                    'Unknown sparse fieldset type "'.(is_string($type) ? $type : '').'".',
                    'fields['.$type.']',
                ));
            }

            // Only this resource's own type and the embedded type can appear in
            // the document, so only those fieldsets need checking.
            if ($type !== $this->type() && $type !== 'tags') {
                continue;
            }

            if (! is_string($names)) {
                throw new JsonApiException(JsonApiError::invalidQueryParameter(
                    'fields['.$type.'] must be a comma-separated list of fields.',
                    'fields['.$type.']',
                ));
            }

            $allowed = $type === 'tags' ? $this->tagFields() : $this->allowedFields();

            foreach (explode(',', $names) as $name) {
                $name = trim($name);

                // `id` and `type` are members of every resource object whatever
                // the fieldset says (§4.9), so naming them is always valid.
                if ($name !== '' && ! in_array($name, ['id', 'type'], true) && ! in_array($name, $allowed, true)) {
                    throw new JsonApiException(JsonApiError::invalidQueryParameter(
                        'Unknown field "'.$name.'" for type '.$type.'.',
                        'fields['.$type.']',
                    ));
                }
            }
        }
    }

    /**
     * Reject an undeclared `page[…]` member (§4.7).
     */
    private function ensurePageIsDeclared(Request $request): void
    {
        $page = $this->queryMember($request, 'page');

        if ($page === null) {
            return;
        }

        if (! is_array($page)) {
            throw new JsonApiException(JsonApiError::invalidQueryParameter(
                'page must be a set of named members, e.g. page[number]=2.',
                'page',
            ));
        }

        foreach (array_keys($page) as $member) {
            if (! in_array($member, ['number', 'size'], true)) {
                throw new JsonApiException(JsonApiError::invalidQueryParameter(
                    'Unknown pagination parameter "'.$member.'". Allowed: number, size.',
                    'page['.$member.']',
                ));
            }
        }
    }

    /**
     * The requested page size, which §4.7 never clamps: outside 1–100 it is a 400.
     */
    private function pageSize(Request $request): int
    {
        $requested = $this->queryMember($request, 'page.size');

        if ($requested === null) {
            return self::DEFAULT_PAGE_SIZE;
        }

        $size = is_int($requested)
            ? $requested
            : (is_string($requested) ? filter_var($requested, FILTER_VALIDATE_INT) : false);

        if (! is_int($size) || $size < self::MIN_PAGE_SIZE || $size > self::MAX_PAGE_SIZE) {
            throw new JsonApiException(JsonApiError::invalidQueryParameter(
                'page[size] must be an integer between '.self::MIN_PAGE_SIZE.' and '.self::MAX_PAGE_SIZE.'.',
                'page[size]',
            ));
        }

        return $size;
    }

    /**
     * The requested page number.
     *
     * An unreadable or below-range number reads as page 1; a number past the end
     * is a 200 with an empty `data` array, never a 404 (§4.7).
     */
    private function pageNumber(Request $request): int
    {
        $requested = $this->queryMember($request, 'page.number');

        if ($requested === null) {
            return 1;
        }

        $number = is_int($requested)
            ? $requested
            : (is_string($requested) ? filter_var($requested, FILTER_VALIDATE_INT) : false);

        return is_int($number) ? max(1, $number) : 1;
    }

    /**
     * Whether a `fields[…]` key names one of the eight JSON:API types.
     *
     * The types come from {@see ContentType::routeSegment()}, which is already
     * the one place the eight API spellings are pinned: the JSON:API type is
     * that segment's snake_case spelling, and Table A's two columns are the
     * same word in the two cases. Deriving them from the singular case values
     * with `Str::plural()` cannot work — `Str::plural('education')` is
     * `education`, so `fields[educations]` would be refused as an unknown type.
     */
    private function isKnownType(string $type): bool
    {
        if ($type === 'tags') {
            return true;
        }

        foreach (ContentType::cases() as $case) {
            if (str_replace('-', '_', $case->routeSegment()) === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read a query member by its JSON:API path — `page.size`, `fields[writings]`.
     *
     * `Request::query('page.size')` cannot be used: the parameter bag is an
     * `InputBag`, whose keys are taken literally, so a dotted name would never
     * match. This walks the array instead.
     */
    private function queryMember(Request $request, string $key): mixed
    {
        return Arr::get($request->query(), $key);
    }
}
