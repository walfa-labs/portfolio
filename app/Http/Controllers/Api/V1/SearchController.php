<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ContentType;
use App\Exceptions\JsonApiException;
use App\Http\Controllers\Controller;
use App\Support\JsonApi\JsonApiError;
use App\Support\Search\ContentSearcher;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Site-wide search over the API (§4.1).
 *
 * Two entry points, one document: `QUERY /api/v1/search` carries a JSON body,
 * and `GET /api/v1/search` flattens the same fields into query parameters for
 * clients that cannot send QUERY. `OPTIONS` advertises both through `Allow` and
 * `Accept-Query`. There is no `POST` fallback — an unsafe verb would teach
 * clients the wrong thing about a safe, idempotent query.
 *
 * The success document is deliberately **not** JSON:API (§4.4.3): its primary
 * data mixes resource types, so it is plain `application/json` and the resource
 * types appear as the plural, kebab-cased API segments (`writings`, not
 * `writing`). Every error is a JSON:API error document under
 * `application/vnd.api+json` — the media-type asymmetry §4.1 pins — rendered
 * through the one {@see JsonApiError} value object so the shape cannot drift
 * from the rest of the API (§4.6).
 *
 * The endpoint is public and always `published()`-scoped through
 * {@see ContentSearcher}: drafts are never reachable from here, and no parameter
 * widens the scope (§4.8). It is never stored by a shared cache —
 * `private, max-age=0, must-revalidate` with a strong `ETag`. That `private`
 * directive is a policy choice, not the mechanism that satisfies RFC 10008's
 * requirement that a cache key incorporate the request content: it keeps shared
 * caches from storing the response at all, so no shared cache key is built. The
 * validator itself is made request-specific — a `QUERY`'s `ETag` hashes the
 * document together with the request body — so a private cache cannot serve one
 * query's document for another's.
 *
 * Errors split by source: a `400` always names the offending **parameter**
 * (`source.parameter`), spelled identically for both entry points, while a `422`
 * — which only a body can provoke — names a **pointer** into the request
 * document (`source.pointer`).
 */
class SearchController extends Controller
{
    /**
     * The verbs this URI answers. One `Allow` per URI shape, so the collection
     * and single-resource verbs of §4.5 never leak in here.
     */
    public const ALLOW = 'GET, HEAD, OPTIONS, QUERY';

    /**
     * The one request media type a QUERY body may declare (§4.1).
     *
     * `application/vnd.api+json` is acceptable in `Accept`, never as the request
     * `Content-Type`: the body is a plain JSON object, not a JSON:API document.
     */
    private const MEDIA_TYPE = JsonApiError::JSON_MEDIA_TYPE;

    /**
     * The default `page.size` (§4.7).
     */
    private const DEFAULT_PAGE_SIZE = ContentSearcher::DEFAULT_PER_PAGE;

    /**
     * The largest `page.size`; anything above it is a 400, never a clamp (§4.7).
     */
    private const MAX_PAGE_SIZE = 100;

    /**
     * The closed parameter set. Anything else answers 400 (§4.6).
     */
    private const PARAMETERS = ['q', 'types', 'tags', 'sort', 'page'];

    /**
     * Answer QUERY, GET, HEAD and OPTIONS on `/api/v1/search`.
     */
    public function __invoke(Request $request): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->discovery();
        }

        $isQuery = $request->isMethod('QUERY');

        if ($isQuery) {
            $this->ensureJsonBody($request);
        }

        $this->ensureAcceptable($request);

        $parameters = $isQuery
            ? $this->bodyParameters($request)
            : $this->queryParameters($request);

        return $this->document($request, $parameters);
    }

    /**
     * Advertise the verbs and the QUERY body media type (§4.1).
     *
     * `Accept-Query` is a registered Structured Field of type `List`, so a client
     * learns from this response alone that the URI takes a QUERY with an
     * `application/json` body.
     */
    private function discovery(): Response
    {
        return response('', 200, [
            'Allow' => self::ALLOW,
            'Accept-Query' => self::MEDIA_TYPE,
        ]);
    }

    /**
     * Build the §4.4.3 document and its validators.
     *
     * `meta.counts_by_type` counts the whole result set rather than the current
     * page: the document's own example pairs `total: 2` with one hit per type,
     * and a client uses the counts to render facet links. It is cast to an
     * object so an empty result set serialises as `{}` and not `[]` — the member
     * is a map of type to count, not a list.
     *
     * The document carries **no** method marker: `QUERY` and `GET` are two input
     * channels for one canonical search, and RFC 10008 defines the QUERY
     * response's selected representation as the equivalent GET resource's, so
     * the two entry points return byte-identical documents for the same query.
     * A caller already knows which method it sent.
     *
     * The `ETag` is a strong, request-specific validator. For a `QUERY` it
     * hashes the serialised document **plus the request body**, because the
     * body — not the query string — is the QUERY input: without it, two
     * different QUERY bodies could share a validator and a private cache could
     * reuse one document for the other. `Cache-Control: private` is a *policy
     * choice* that keeps shared caches from storing the response at all; it is
     * not the mechanism that satisfies RFC 10008's requirement that a cache key
     * incorporate the request content. The body-sensitive validator is the part
     * that makes the response correct for its request.
     *
     * @param  array{q: string, types: list<string>, tags: list<string>, sort: string|null, page: int, size: int}  $parameters
     */
    private function document(Request $request, array $parameters): Response
    {
        $hits = ContentSearcher::ranked(
            $parameters['q'],
            $parameters['types'],
            $parameters['tags'],
            $parameters['sort'],
        );

        $size = $parameters['size'];
        $number = $parameters['page'];
        $total = count($hits);

        $response = response()->json([
            'results' => array_map(
                fn (array $hit): array => $this->result($hit),
                array_slice($hits, ($number - 1) * $size, $size),
            ),
            'meta' => [
                'page' => [
                    'number' => $number,
                    'size' => $size,
                    'total' => $total,
                    'pages' => max(1, (int) ceil($total / $size)),
                ],
                'counts_by_type' => (object) $this->countsByType($hits),
            ],
        ]);

        $validator = (string) $response->getContent();

        if ($request->isMethod('QUERY')) {
            $validator .= '|'.(string) $request->getContent();
        }

        $response->setEtag(hash('sha256', $validator));
        $response->setCache(['private' => true, 'max_age' => 0, 'must_revalidate' => true]);

        // No `Accept-Query` here whatever the method: the route middleware
        // owns that header on `GET`/`HEAD` (§4.1, decision 16), and a QUERY
        // response does not carry it — a caller that has just sent one has
        // already discovered the method. Setting it here as well would be a
        // second source for one header, which is what let the two disagree.
        return $response->isNotModified($request) ? $response->setNotModified() : $response;
    }

    /**
     * Project one searcher hit onto the §4.4.3 result object.
     *
     * The searcher's hits also carry `published_at`, `is_placeholder` and `tags`
     * for the public cards; none of them belong in the API document, whose shape
     * is exactly these six members. `id` is a string because every id this API
     * emits is one (§4.4.1), and the searcher's singular `type` becomes the
     * plural resource type the rest of the API speaks.
     *
     * @param  array<string, mixed>  $hit
     * @return array{type: string, id: string, title: string, excerpt: string, path: string, score: float}
     */
    private function result(array $hit): array
    {
        return [
            'type' => $this->apiType((string) ($hit['type'] ?? '')),
            'id' => (string) ($hit['id'] ?? ''),
            'title' => (string) ($hit['title'] ?? ''),
            'excerpt' => (string) ($hit['excerpt'] ?? ''),
            'path' => (string) ($hit['path'] ?? '/'),
            'score' => (float) ($hit['score'] ?? 0.0),
        ];
    }

    /**
     * The hit count per resource type, keyed the way the document spells types.
     *
     * @param  list<array<string, mixed>>  $hits
     * @return array<string, int>
     */
    private function countsByType(array $hits): array
    {
        $counts = [];

        foreach (ContentSearcher::countsByType($hits) as $type => $count) {
            $counts[$this->apiType($type)] = $count;
        }

        return $counts;
    }

    /**
     * The API's name for a content type: the plural, kebab-cased segment.
     */
    private function apiType(string $type): string
    {
        return ContentType::tryFrom($type)?->routeSegment() ?? $type;
    }

    /**
     * Read the flattened parameter set from the query string (§4.1 fallback).
     *
     * The error set here is deliberately narrower than the body's: a query string
     * carries no types, so every shape violation is a 400 and Table B gives
     * `GET /api/v1/search` no 422 at all. A `q` outside the 2–100 range is not
     * rejected either — the GET form mirrors the public page, where a short query
     * finds nothing and a long one is truncated.
     *
     * @return array{q: string, types: list<string>, tags: list<string>, sort: string|null, page: int, size: int}
     */
    private function queryParameters(Request $request): array
    {
        $query = $request->query();

        foreach (array_keys($query) as $key) {
            if (! in_array($key, self::PARAMETERS, true)) {
                $this->unknown((string) $key);
            }
        }

        $q = $this->queryScalar($query, 'q') ?? '';

        if ($q !== '' && mb_strlen($q) < ContentSearcher::MIN_QUERY_LENGTH) {
            // The public page never runs a one-character search (§3.8), and this
            // flattened form inherits that rule: an empty needle finds nothing.
            // GET has no 422 to report the rejection with (Table B), so the
            // request succeeds with an empty result set.
            $q = '';
        }

        if (mb_strlen($q) > ContentSearcher::MAX_QUERY_LENGTH) {
            $q = Str::limit($q, ContentSearcher::MAX_QUERY_LENGTH, '');
        }

        $paging = $this->queryPaging($query['page'] ?? null);

        return [
            'q' => $q,
            'types' => $this->types($this->queryList($query, 'types')),
            'tags' => $this->queryList($query, 'tags'),
            'sort' => $this->sort($this->queryScalar($query, 'sort')),
            'page' => $paging['number'],
            'size' => $paging['size'],
        ];
    }

    /**
     * Read the parameter set from a QUERY request body.
     *
     * This is where the 422 lives (§4.1: unprocessable query content): the body
     * is JSON, so it can carry a `q` of the wrong type, a `types` that is not an
     * array, or a `page` that is not an object — none of which the query string
     * can express. A `q` outside the 2–100 characters §3.8 fixes is rejected
     * rather than truncated, because the API has no message channel to explain a
     * silently narrowed search.
     *
     * @return array{q: string, types: list<string>, tags: list<string>, sort: string|null, page: int, size: int}
     */
    private function bodyParameters(Request $request): array
    {
        $body = $this->decodeBody($request);

        foreach (array_keys($body) as $key) {
            if (! in_array($key, self::PARAMETERS, true)) {
                $this->unknown((string) $key);
            }
        }

        $q = $body['q'] ?? null;

        if ($q !== null && ! is_string($q)) {
            $this->unprocessable('q must be a string.', '/q');
        }

        $q = trim((string) $q);

        if ($q !== '' && mb_strlen($q) < ContentSearcher::MIN_QUERY_LENGTH) {
            $this->unprocessable(
                'q must be at least '.ContentSearcher::MIN_QUERY_LENGTH.' characters.',
                '/q',
            );
        }

        if (mb_strlen($q) > ContentSearcher::MAX_QUERY_LENGTH) {
            $this->unprocessable(
                'q must not be greater than '.ContentSearcher::MAX_QUERY_LENGTH.' characters.',
                '/q',
            );
        }

        $paging = $this->bodyPaging($body['page'] ?? null);

        return [
            'q' => $q,
            'types' => $this->types($this->bodyList($body, 'types')),
            'tags' => $this->bodyList($body, 'tags'),
            'sort' => $this->sort($this->bodyScalar($body, 'sort')),
            'page' => $paging['number'],
            'size' => $paging['size'],
        ];
    }

    /**
     * Decode the request body, rejecting anything that is not a JSON object.
     *
     * §4.1's "content inconsistent with the declared type → 400": a body that
     * cannot be decoded, or that decodes to a scalar or a list, is a malformed
     * query document rather than an unprocessable one.
     *
     * @return array<string, mixed>
     */
    private function decodeBody(Request $request): array
    {
        $content = (string) $request->getContent();

        if (trim($content) === '') {
            $this->malformed('The request body must be a JSON object.');
        }

        $decoded = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            $this->malformed('The request body must be a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Reject a request body that does not declare `Content-Type: application/json`.
     *
     * Parameters are ignored rather than rejected: `application/json; charset=utf-8`
     * is the media type with a parameter JSON does not define, and refusing it
     * would break clients for no gain.
     *
     * §4.1 asks the 415 to advertise both the media type it wants and QUERY
     * support, so the caller can retry with a correct body without a discovery
     * round trip.
     */
    private function ensureJsonBody(Request $request): void
    {
        $header = $request->header('Content-Type');

        $mediaType = is_string($header) ? mb_strtolower(trim(explode(';', $header)[0])) : '';

        if ($mediaType !== self::MEDIA_TYPE) {
            throw new JsonApiException(
                JsonApiError::unsupportedMediaType(
                    'Request content type must be '.self::MEDIA_TYPE.'.',
                )->withHeaders([
                    'Accept' => self::MEDIA_TYPE,
                    'Accept-Query' => self::MEDIA_TYPE,
                ]),
            );
        }
    }

    /**
     * Reject an `Accept` that cannot be satisfied by either response media type.
     *
     * The 406 names both media types in its own `Accept`, so the caller learns
     * what to send next. A wildcard, `application/*` and an absent `Accept` all
     * pass.
     */
    private function ensureAcceptable(Request $request): void
    {
        if ($request->accepts(JsonApiError::SEARCH_MEDIA_TYPES)) {
            return;
        }

        throw new JsonApiException(JsonApiError::notAcceptable(JsonApiError::SEARCH_MEDIA_TYPES));
    }

    /**
     * Resolve the `types` values against the closed resource set (§4.2).
     *
     * Both spellings the searcher understands are accepted — the API's
     * plural-kebab segment (`writings`) and the canonical singular (`writing`) —
     * so a caller who guesses the other one is not punished with a 400.
     * Anything else is undeclared, and §4.8 rejects undeclared values rather
     * than ignoring them.
     *
     * @param  list<string>  $values
     * @return list<string>
     */
    private function types(array $values): array
    {
        $resolved = [];

        foreach ($values as $value) {
            $type = $this->matchType($value);

            if ($type === null) {
                $this->invalid(
                    'Unknown type "'.$value.'". Allowed types: '.implode(', ', $this->typeSegments()).'.',
                    'types',
                );
            }

            if (! in_array($type->value, $resolved, true)) {
                $resolved[] = $type->value;
            }
        }

        return $resolved;
    }

    /**
     * Validate `sort` against the fields the searcher can order by (§4.8).
     *
     * Exactly one field is accepted: a result set that mixes content types has no
     * single secondary column, and the document's `meta` has nowhere to echo a
     * multi-sort.
     */
    private function sort(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $field = str_starts_with($value, '-') ? substr($value, 1) : $value;

        if (! in_array($field, ContentSearcher::SORTABLE, true)) {
            $this->invalid(
                'Unknown sort "'.$value.'". Allowed sorts: '.implode(', ', [
                    ...ContentSearcher::SORTABLE,
                    ...array_map(fn (string $field): string => '-'.$field, ContentSearcher::SORTABLE),
                ]).'.',
                'sort',
            );
        }

        return $value;
    }

    /**
     * Reject an undeclared parameter (§4.6: unknown query parameter → 400).
     *
     * The parameter is named in the query-string spelling — `page[size]`, not
     * `/page/size` — because the two entry points take the same fields, and a
     * client that gets this error from the body should be able to fix the same
     * field on the flattened URL.
     */
    private function unknown(string $key): never
    {
        $this->invalid(
            'Unknown parameter "'.$key.'". Allowed parameters for search: '.implode(', ', self::PARAMETERS).'.',
            $key,
        );
    }

    /**
     * The page size, rejected outside 1–100 rather than clamped (§4.7).
     */
    private function pageSize(int $size): int
    {
        if ($size < 1 || $size > self::MAX_PAGE_SIZE) {
            $this->invalid(
                'page.size must be between 1 and '.self::MAX_PAGE_SIZE.'; '.$size.' given.',
                'page[size]',
            );
        }

        return $size;
    }

    /**
     * Read `page` from the query string.
     *
     * An out-of-range page *number* is not an error: §4.7 answers `200` with an
     * empty result set, and a number below 1 is read as page 1 exactly as the
     * framework's own page resolver does.
     *
     * @return array{number: int, size: int}
     */
    private function queryPaging(mixed $page): array
    {
        if ($page === null) {
            return ['number' => 1, 'size' => self::DEFAULT_PAGE_SIZE];
        }

        if (! is_array($page)) {
            $this->invalid('page must be an array with number and size members.', 'page');
        }

        foreach (array_keys($page) as $key) {
            if (! in_array($key, ['number', 'size'], true)) {
                $this->invalid(
                    'Unknown page member "'.$key.'". Allowed members: number, size.',
                    'page['.$key.']',
                );
            }
        }

        $number = array_key_exists('number', $page) ? $this->integer($page['number'], 'page[number]') : 1;
        $size = array_key_exists('size', $page) ? $this->integer($page['size'], 'page[size]') : self::DEFAULT_PAGE_SIZE;

        return ['number' => max(1, $number), 'size' => $this->pageSize($size)];
    }

    /**
     * Read `page` from the request body.
     *
     * @return array{number: int, size: int}
     */
    private function bodyPaging(mixed $page): array
    {
        if ($page === null) {
            return ['number' => 1, 'size' => self::DEFAULT_PAGE_SIZE];
        }

        if (! is_array($page)) {
            $this->unprocessable('page must be an object with number and size.', '/page');
        }

        foreach (array_keys($page) as $key) {
            if (! in_array($key, ['number', 'size'], true)) {
                $this->invalid(
                    'Unknown page member "'.$key.'". Allowed members: number, size.',
                    'page['.$key.']',
                );
            }
        }

        $number = $page['number'] ?? 1;
        $size = $page['size'] ?? self::DEFAULT_PAGE_SIZE;

        if (! is_int($number)) {
            $this->unprocessable('page.number must be an integer.', '/page/number');
        }

        if (! is_int($size)) {
            $this->unprocessable('page.size must be an integer.', '/page/size');
        }

        return ['number' => max(1, $number), 'size' => $this->pageSize($size)];
    }

    /**
     * Read one scalar query-string parameter.
     *
     * @param  array<string, mixed>  $query
     */
    private function queryScalar(array $query, string $key): ?string
    {
        if (! array_key_exists($key, $query)) {
            return null;
        }

        $value = $query[$key];

        if (! is_scalar($value)) {
            $this->invalid(ucfirst($key).' must be a single value.', $key);
        }

        return trim((string) $value);
    }

    /**
     * Read a query-string list, written either comma-separated or repeated.
     *
     * @param  array<string, mixed>  $query
     * @return list<string>
     */
    private function queryList(array $query, string $key): array
    {
        if (! array_key_exists($key, $query)) {
            return [];
        }

        $values = $query[$key];
        $items = [];

        foreach (is_array($values) ? $values : explode(',', (string) $values) as $value) {
            if (! is_scalar($value)) {
                $this->invalid(ucfirst($key).' must be a list of values.', $key);
            }

            $value = trim((string) $value);

            if ($value !== '' && ! in_array($value, $items, true)) {
                $items[] = $value;
            }
        }

        return $items;
    }

    /**
     * Read one scalar member of the request body.
     *
     * @param  array<string, mixed>  $body
     */
    private function bodyScalar(array $body, string $key): ?string
    {
        if (! array_key_exists($key, $body)) {
            return null;
        }

        $value = $body[$key];

        if (! is_string($value)) {
            $this->unprocessable($key.' must be a string.', '/'.$key);
        }

        return trim($value);
    }

    /**
     * Read a list-of-strings member of the request body.
     *
     * @param  array<string, mixed>  $body
     * @return list<string>
     */
    private function bodyList(array $body, string $key): array
    {
        if (! array_key_exists($key, $body)) {
            return [];
        }

        $value = $body[$key];

        if (! is_array($value)) {
            $this->unprocessable($key.' must be an array of strings.', '/'.$key);
        }

        $items = [];

        foreach (array_values($value) as $index => $item) {
            if (! is_string($item) || trim($item) === '') {
                $this->unprocessable(
                    $key.' must be an array of strings.',
                    '/'.$key.'/'.$index,
                );
            }

            $items[] = trim($item);
        }

        return $items;
    }

    /**
     * Read an integer query-string value.
     */
    private function integer(mixed $value, string $parameter): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        $this->invalid($parameter.' must be an integer.', $parameter);
    }

    /**
     * Match a caller's type spelling against the canonical set.
     */
    private function matchType(string $value): ?ContentType
    {
        foreach (ContentType::cases() as $type) {
            if ($value === $type->value || $value === $type->routeSegment()) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Every API type segment, in the canonical order.
     *
     * @return list<string>
     */
    private function typeSegments(): array
    {
        return array_map(fn (ContentType $type): string => $type->routeSegment(), ContentType::cases());
    }

    /**
     * An undeclared parameter or an unparseable value: 400 (§4.6).
     */
    private function invalid(string $detail, string $parameter): never
    {
        throw new JsonApiException(JsonApiError::invalidQueryParameter($detail, $parameter));
    }

    /**
     * A body that decodes but cannot be processed as a query: 422 (§4.1).
     */
    private function unprocessable(string $detail, string $pointer): never
    {
        throw new JsonApiException(JsonApiError::validationFailed($detail, $pointer));
    }

    /**
     * A body that is not a JSON object at all: 400 (§4.1).
     *
     * No pointer: the search document has no `/data` member to point at, and
     * `source` is optional, so the detail carries the whole story.
     */
    private function malformed(string $detail): never
    {
        throw new JsonApiException(JsonApiError::malformedBody($detail, ''));
    }
}
