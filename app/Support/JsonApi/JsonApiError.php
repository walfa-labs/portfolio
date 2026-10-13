<?php

namespace App\Support\JsonApi;

use App\Exceptions\JsonApiException;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonSerializable;

/**
 * The one JSON:API error document builder (§4.6).
 *
 * Every error the API returns — 400 through 500, including `429` and `500` — is
 * a JSON:API document: `Content-Type: application/vnd.api+json`, a top-level
 * `errors` array, and never `data` alongside it. One value object renders every
 * case, so the shape cannot drift between the twelve statuses §4.6 lists.
 *
 * ## The public API
 *
 * - `JsonApiError::invalidQueryParameter($detail, $parameter)` — 400, `source.parameter`
 * - `JsonApiError::malformedBody($detail, $pointer = '/data')` — 400, `source.pointer`
 * - `JsonApiError::unauthenticated($detail = …)` — 401
 * - `JsonApiError::forbidden($detail = …)` — 403
 * - `JsonApiError::notFound($detail)` — 404
 * - `JsonApiError::modelNotFound($modelClass, $ids)` — 404, derives the type from the model
 * - `JsonApiError::methodNotAllowed($method, $path, $allowed)` — 405 + `Allow`
 * - `JsonApiError::notAcceptable($acceptable = self::MEDIA_TYPE, $detail = null)` — 406 + `Accept`
 * - `JsonApiError::conflict($detail, $pointer = null, $parameter = null)` — 409
 * - `JsonApiError::unsupportedMediaType($detail = …, $header = 'Content-Type')` — 415
 * - `JsonApiError::validationFailed($detail, $pointer = null, $parameter = null)` — 422
 * - `JsonApiError::rateLimited($retryAfter = null)` — 429
 * - `JsonApiError::serverError($detail = …)` — 500
 * - `JsonApiError::forStatus($status, $detail = null)` — any of the above by status, or `null`
 * - `JsonApiError::make($status, $code, $detail, $source, $links, $headers)` — explicit members
 *
 * Every builder returns the value object. It is `Responsable`, so a controller
 * may `return JsonApiError::notFound(…)` directly; `->response()` builds the
 * `JsonResponse`, `->toDocument()` the array, and `->toArray()` one error
 * object. For more than one error (validation), use
 * `JsonApiError::document([…])` or `JsonApiError::fromValidationException($e)`.
 *
 * Errors raised deliberately by application code travel as
 * {@see JsonApiException}; the renderers in `bootstrap/app.php`
 * turn both into this document.
 *
 * ## Error-object members (§4.6)
 *
 * `status` (string), `code` (stable machine string), `title`, `detail`,
 * `source.pointer` / `source.parameter` / `source.header`, `links`, and
 * `meta.request_id` on every object.
 *
 * ## The two bodies §4.6 omits
 *
 * ```json
 * {"errors":[{"status":"405","code":"method_not_allowed","title":"Method not allowed","detail":"The QUERY method is not supported for /api/v1/writings. Allowed methods: GET, HEAD, OPTIONS, POST.","meta":{"request_id":"01J…"}}]}
 * {"errors":[{"status":"406","code":"not_acceptable","title":"Not acceptable","detail":"No acceptable media type. Acceptable media types: application/json, application/vnd.api+json.","source":{"header":"Accept"},"meta":{"request_id":"01J…"}}]}
 * ```
 *
 * The `405` carries the allowed methods in the `Allow` response header as well
 * as in `detail` — `meta` stays reserved for `request_id` so the member list
 * does not drift. The `406` carries `Accept` listing the media types the
 * endpoint can produce; `/api/v1/search` passes {@see self::SEARCH_MEDIA_TYPES}
 * so the header reads `application/json, application/vnd.api+json`.
 *
 * ## Search's media-type asymmetry (decision 15)
 *
 * The `200` from `/api/v1/search` is `application/json`; its errors are
 * `application/vnd.api+json`. `Vary: Accept` therefore rides on the JSON:API
 * responses only, which is what every builder here produces. Errors are also
 * `Cache-Control: no-store` (§4.12: "all errors").
 */
final class JsonApiError implements JsonSerializable, Responsable
{
    /**
     * The JSON:API media type — the only `Content-Type` an error may carry.
     */
    public const MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * The plain media type the search endpoint succeeds with (§4.4.3).
     */
    public const JSON_MEDIA_TYPE = 'application/json';

    /**
     * What `/api/v1/search` accepts, in the order §4.1 pins (decision 3).
     *
     * @var list<string>
     */
    public const SEARCH_MEDIA_TYPES = [self::JSON_MEDIA_TYPE, self::MEDIA_TYPE];

    /**
     * Status, code, title and default detail for every status §4.6 lists.
     *
     * `400` has two codes; `invalid_query_parameter` is the default and
     * {@see self::malformedBody()} supplies the other one.
     *
     * @var array<int, array{code: string, title: string, detail: string, source: array<string, string>, headers: array<string, string>}>
     */
    private const ERRORS = [
        400 => [
            'code' => 'invalid_query_parameter',
            'title' => 'Invalid query parameter',
            'detail' => 'The request could not be understood.',
            'source' => [],
            'headers' => [],
        ],
        401 => [
            'code' => 'unauthenticated',
            'title' => 'Unauthenticated',
            'detail' => 'This endpoint requires an authenticated admin session.',
            'source' => [],
            'headers' => [],
        ],
        403 => [
            'code' => 'forbidden',
            'title' => 'Forbidden',
            'detail' => 'This action is unauthorized.',
            'source' => [],
            'headers' => [],
        ],
        404 => [
            'code' => 'not_found',
            'title' => 'Not found',
            'detail' => 'The requested resource does not exist.',
            'source' => [],
            'headers' => [],
        ],
        405 => [
            'code' => 'method_not_allowed',
            'title' => 'Method not allowed',
            'detail' => 'The method is not supported for this endpoint.',
            'source' => [],
            'headers' => [],
        ],
        406 => [
            'code' => 'not_acceptable',
            'title' => 'Not acceptable',
            'detail' => 'No acceptable media type. Acceptable media types: '.self::MEDIA_TYPE.'.',
            'source' => ['header' => 'Accept'],
            'headers' => ['Accept' => self::MEDIA_TYPE],
        ],
        409 => [
            'code' => 'conflict',
            'title' => 'Conflict',
            'detail' => 'The request conflicts with the current state of the resource.',
            'source' => [],
            'headers' => [],
        ],
        415 => [
            'code' => 'unsupported_media_type',
            'title' => 'Unsupported media type',
            'detail' => 'Request content type must be '.self::MEDIA_TYPE.'.',
            'source' => ['header' => 'Content-Type'],
            'headers' => [],
        ],
        422 => [
            'code' => 'validation_failed',
            'title' => 'Validation failed',
            'detail' => 'The given data was invalid.',
            'source' => [],
            'headers' => [],
        ],
        429 => [
            'code' => 'rate_limited',
            'title' => 'Too many requests',
            'detail' => 'Rate limit exceeded.',
            'source' => [],
            'headers' => [],
        ],
        500 => [
            'code' => 'server_error',
            'title' => 'Server error',
            'detail' => 'An unexpected error occurred.',
            'source' => [],
            'headers' => [],
        ],
    ];

    /**
     * The order `Allow` is rendered in (decision 5, §4.5 Table B).
     *
     * Safe and read-only verbs first, then the writes: a collection therefore
     * reads `GET, HEAD, OPTIONS, POST` and a single resource
     * `GET, HEAD, OPTIONS, PUT, PATCH, DELETE`, whatever order the router
     * registered them in.
     *
     * @var list<string>
     */
    private const ALLOW_ORDER = ['GET', 'HEAD', 'OPTIONS', 'QUERY', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Query-parameter roots whose validation failures carry `source.parameter`.
     *
     * Anything else is treated as a member of the request document and gets a
     * `source.pointer` instead.
     *
     * @var list<string>
     */
    private const QUERY_PARAMETER_ROOTS = ['filter', 'page', 'fields', 'sort', 'include', 'q', 'types', 'tags'];

    /**
     * @param  array<string, string>  $source  One of `pointer`, `parameter`, `header`.
     * @param  array<string, string>  $links  JSON:API `links.about` / `links.type`.
     * @param  array<string, mixed>  $headers  Response headers this error adds (`Allow`, `Accept`).
     */
    private function __construct(
        public readonly int $status,
        public readonly string $code,
        public readonly string $title,
        public readonly string $detail,
        public readonly array $source = [],
        public readonly array $links = [],
        public readonly array $headers = [],
    ) {}

    /**
     * An unrecognised or undeclared query parameter — `filter[…]`, `sort`,
     * `include`, `fields[…]`, `page[…]` or an unknown name (§4.6, §4.8).
     */
    public static function invalidQueryParameter(string $detail, ?string $parameter = null): self
    {
        return new self(400, 'invalid_query_parameter', 'Invalid query parameter', $detail, self::source(parameter: $parameter));
    }

    /**
     * A request body that is not parseable JSON.
     */
    public static function malformedBody(string $detail, string $pointer = '/data'): self
    {
        return new self(400, 'malformed_body', 'Invalid query parameter', $detail, self::source(pointer: $pointer));
    }

    /**
     * No session on a write (or on a future authenticated read).
     */
    public static function unauthenticated(string $detail = 'This endpoint requires an authenticated admin session.'): self
    {
        return new self(401, 'unauthenticated', 'Unauthenticated', $detail);
    }

    /**
     * A session, but not the administrator — or a policy denial.
     */
    public static function forbidden(string $detail = 'This action is unauthorized.'): self
    {
        return new self(403, 'forbidden', 'Forbidden', $detail);
    }

    /**
     * An unknown id, an unknown collection, or a second `DELETE`.
     */
    public static function notFound(string $detail): self
    {
        return new self(404, 'not_found', 'Not found', $detail);
    }

    /**
     * A route-model binding miss, phrased with the resource type §4.2 derives.
     *
     * `Writing` → `No writings resource with id 999.`, which is the §4.6 example.
     *
     * @param  class-string  $modelClass
     * @param  mixed  $ids  One id or a list of them.
     */
    public static function modelNotFound(string $modelClass, mixed $ids): self
    {
        $type = Str::plural(Str::snake(class_basename($modelClass)));
        $ids = implode(', ', is_array($ids) ? $ids : [$ids]);

        return self::notFound("No {$type} resource with id {$ids}.");
    }

    /**
     * A verb that is not in §4.5 Table B for this URI shape.
     *
     * Sets the `Allow` response header as well as naming the methods in
     * `detail`; §4.6's body for this status is authored here.
     *
     * @param  string|list<string>  $allowed
     */
    public static function methodNotAllowed(string $method, string $path, string|array $allowed): self
    {
        $allow = self::allowedMethods($allowed);

        return new self(
            405,
            'method_not_allowed',
            'Method not allowed',
            "The {$method} method is not supported for {$path}. Allowed methods: {$allow}.",
            headers: ['Allow' => $allow],
        );
    }

    /**
     * An `Accept` the endpoint cannot satisfy.
     *
     * The `Accept` response header lists what it *can* produce; `/api/v1/search`
     * passes {@see self::SEARCH_MEDIA_TYPES}. §4.6's body for this status is
     * authored here.
     *
     * @param  string|list<string>  $acceptable
     */
    public static function notAcceptable(string|array $acceptable = self::MEDIA_TYPE, ?string $detail = null): self
    {
        $types = is_array($acceptable)
            ? $acceptable
            : array_values(array_filter(array_map('trim', explode(',', $acceptable))));

        $accept = implode(', ', $types);

        return new self(
            406,
            'not_acceptable',
            'Not acceptable',
            $detail ?? "No acceptable media type. Acceptable media types: {$accept}.",
            ['header' => 'Accept'],
            headers: ['Accept' => $accept],
        );
    }

    /**
     * A taken slug, or a `data.type` / `data.id` mismatch on `PUT`/`PATCH`.
     */
    public static function conflict(string $detail, ?string $pointer = null, ?string $parameter = null): self
    {
        return new self(409, 'conflict', 'Conflict', $detail, self::source(pointer: $pointer, parameter: $parameter));
    }

    /**
     * A write `Content-Type` that is not JSON:API, or a search `Content-Type`
     * that is not `application/json` (§4.6, decision 13).
     *
     * `$header` is the header the caller must fix; the JSON:API media type with
     * a parameter other than `ext`/`profile` is also a 415, and there the
     * offending header is still `Content-Type`.
     */
    public static function unsupportedMediaType(
        string $detail = 'Request content type must be application/vnd.api+json.',
        string $header = 'Content-Type',
    ): self {
        return new self(415, 'unsupported_media_type', 'Unsupported media type', $detail, ['header' => $header]);
    }

    /**
     * A validation failure, or a non-writable member supplied by the client.
     */
    public static function validationFailed(string $detail, ?string $pointer = null, ?string $parameter = null): self
    {
        return new self(422, 'validation_failed', 'Validation failed', $detail, self::source(pointer: $pointer, parameter: $parameter));
    }

    /**
     * A limiter rejection. The `Retry-After` header comes from the limiter.
     */
    public static function rateLimited(?int $retryAfter = null): self
    {
        return new self(
            429,
            'rate_limited',
            'Too many requests',
            $retryAfter === null ? 'Rate limit exceeded.' : "Rate limit exceeded. Retry after {$retryAfter} seconds.",
        );
    }

    /**
     * An unhandled server error — no internals, only `meta.request_id`.
     */
    public static function serverError(string $detail = 'An unexpected error occurred.'): self
    {
        return new self(500, 'server_error', 'Server error', $detail);
    }

    /**
     * Build an error from explicit members, filling any gap from the §4.6 table.
     *
     * This is the escape hatch for a caller that has a status, a machine code
     * and a detail in hand — {@see JsonApiException} uses it —
     * and the only way to supply a `code` the table does not name, such as the
     * `malformed_body` half of `400`.
     *
     * @param  array<string, string>  $source
     * @param  array<string, string>  $links
     * @param  array<string, mixed>  $headers
     */
    public static function make(
        int $status,
        ?string $code = null,
        ?string $detail = null,
        array $source = [],
        array $links = [],
        array $headers = [],
    ): self {
        $known = self::ERRORS[$status] ?? null;

        return new self(
            $status,
            $code ?? $known['code'] ?? 'error',
            $known['title'] ?? 'Error',
            $detail ?? $known['detail'] ?? 'An unexpected error occurred.',
            $source,
            $links,
            $headers,
        );
    }

    /**
     * Build an error for any status §4.6 lists, or `null` for one it does not.
     *
     * Used by the catch-all renderer in `bootstrap/app.php`, which lets anything
     * outside the table (`419`, `503`, …) keep Laravel's own rendering.
     */
    public static function forStatus(int $status, ?string $detail = null): ?self
    {
        $error = self::ERRORS[$status] ?? null;

        if ($error === null) {
            return null;
        }

        return new self($status, $error['code'], $error['title'], $detail ?? $error['detail'], $error['source'], [], $error['headers']);
    }

    /**
     * Turn a `ValidationException` into the whole `422` document.
     *
     * One error object per failed rule. Keys under `data.` become
     * `source.pointer` into the request document (`title` →
     * `/data/attributes/title`, `data.relationships.tags.data.0.id` →
     * `/data/relationships/tags/data/0/id`); query-parameter roots become
     * `source.parameter` (`page.size` → `page[size]`).
     */
    public static function fromValidationException(ValidationException $e): JsonResponse
    {
        $errors = [];

        foreach ($e->validator->errors()->messages() as $key => $messages) {
            foreach ((array) $messages as $message) {
                $errors[] = self::validationFailed((string) $message, ...self::validationSource((string) $key));
            }
        }

        if ($errors === []) {
            $errors[] = self::validationFailed($e->getMessage() ?: 'The given data was invalid.');
        }

        return self::document($errors);
    }

    /**
     * Render a document holding more than one error object.
     *
     * @param  list<self>  $errors
     * @param  array<string, mixed>  $headers
     */
    public static function document(array $errors, ?int $status = null, array $headers = []): JsonResponse
    {
        if ($errors === []) {
            $errors = [self::serverError()];
        }

        return self::toJsonResponse($errors, $status ?? $errors[0]->status, $headers);
    }

    /**
     * Render the `Allow` header value for a set of methods, in the pinned order.
     *
     * @param  string|list<string>  $methods  An array, or a comma/space separated string.
     */
    public static function allowedMethods(string|array $methods): string
    {
        $methods = is_array($methods) ? $methods : preg_split('/[\s,]+/', $methods, -1, PREG_SPLIT_NO_EMPTY);
        $methods = array_values(array_unique(array_map(
            static fn (mixed $method): string => strtoupper(trim((string) $method)),
            $methods === false ? [] : $methods,
        )));

        $ordered = array_values(array_filter(
            self::ALLOW_ORDER,
            static fn (string $method): bool => in_array($method, $methods, true),
        ));

        foreach ($methods as $method) {
            if (! in_array($method, $ordered, true)) {
                $ordered[] = $method;
            }
        }

        return implode(', ', $ordered);
    }

    /**
     * The request id every error object carries, stable for one request.
     *
     * An `X-Request-Id` from the caller or the proxy is honoured so the API's id
     * lines up with the edge's; otherwise a ULID is minted. The value is cached
     * on the request attributes, so a document with several error objects — or
     * two documents in one request — reports the same id.
     */
    public static function requestId(): string
    {
        $request = app()->bound('request') ? app('request') : null;

        if (! $request instanceof Request) {
            return (string) Str::ulid();
        }

        $id = $request->attributes->get('json_api.request_id');

        if (! is_string($id) || $id === '') {
            $header = $request->headers->get('X-Request-Id');
            $id = is_string($header) && $header !== '' ? $header : (string) Str::ulid();

            $request->attributes->set('json_api.request_id', $id);
        }

        return $id;
    }

    /**
     * A copy of this error with extra response headers.
     *
     * Used where an error must advertise more than its own headers — the search
     * `415`, for instance, also carries `Accept-Query: application/json` and
     * `Accept: application/json` (§4.1).
     *
     * @param  array<string, mixed>  $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self(
            $this->status,
            $this->code,
            $this->title,
            $this->detail,
            $this->source,
            $this->links,
            array_merge($this->headers, $headers),
        );
    }

    /**
     * One JSON:API error object.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'status' => (string) $this->status,
            'code' => $this->code,
            'title' => $this->title,
            'detail' => $this->detail,
            'source' => $this->source === [] ? null : $this->source,
            'links' => $this->links === [] ? null : $this->links,
            'meta' => ['request_id' => self::requestId()],
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * The whole JSON:API document for this one error.
     *
     * @return array{errors: list<array<string, mixed>>}
     */
    public function toDocument(): array
    {
        return ['errors' => [$this->toArray()]];
    }

    /**
     * The HTTP response for this error.
     *
     * @param  array<string, mixed>  $headers
     */
    public function response(array $headers = []): JsonResponse
    {
        return self::toJsonResponse([$this], $this->status, array_merge($this->headers, $headers));
    }

    /**
     * @return array{errors: list<array<string, mixed>>}
     */
    public function jsonSerialize(): array
    {
        return $this->toDocument();
    }

    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse
    {
        return $this->response();
    }

    /**
     * Build the response: JSON:API media type, `Vary: Accept`, `no-store`.
     *
     * Symfony normalises any `Cache-Control` that is neither `public` nor
     * `private` by appending `private` — "do not expose the session
     * information" — so the value on the wire is `no-store, private`. §4.12
     * asks for `no-store`; the extra directive is the framework's, not a
     * decision taken here.
     *
     * @param  list<self>  $errors
     * @param  array<string, mixed>  $headers
     */
    private static function toJsonResponse(array $errors, int $status, array $headers): JsonResponse
    {
        $response = response()->json(
            ['errors' => array_map(static fn (self $error): array => $error->toArray(), $errors)],
            $status,
            array_merge(['Cache-Control' => 'no-store', 'Vary' => 'Accept'], $headers),
        );

        $response->headers->set('Content-Type', self::MEDIA_TYPE);

        return $response;
    }

    /**
     * Assemble a `source` member from the members that were supplied.
     *
     * @return array<string, string>
     */
    private static function source(?string $pointer = null, ?string $parameter = null, ?string $header = null): array
    {
        $source = [];

        foreach (['pointer' => $pointer, 'parameter' => $parameter, 'header' => $header] as $member => $value) {
            if ($value !== null && $value !== '') {
                $source[$member] = $value;
            }
        }

        return $source;
    }

    /**
     * Decide whether a validation key is a document member or a query parameter.
     *
     * @return array{0: ?string, 1: ?string} `[$pointer, $parameter]`
     */
    private static function validationSource(string $key): array
    {
        if (in_array(explode('.', $key)[0], self::QUERY_PARAMETER_ROOTS, true)) {
            $segments = explode('.', $key);
            $parameter = (string) array_shift($segments);

            foreach ($segments as $segment) {
                $parameter .= "[{$segment}]";
            }

            return [null, $parameter];
        }

        if ($key === 'data' || str_starts_with($key, 'data.')) {
            return ['/'.str_replace('.', '/', $key), null];
        }

        return ['/data/attributes/'.str_replace('.', '/', $key), null];
    }
}
