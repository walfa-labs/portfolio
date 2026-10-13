<?php

namespace App\Exceptions;

use App\Support\JsonApi\JsonApiError;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An API error raised deliberately by application code (§4.6).
 *
 * `JsonApiError` is the shape; this is the throwable. Form requests, controllers
 * and support classes raise it for the statuses the framework cannot produce on
 * its own — `400`, `409`, `415`, and any `406` the media-type negotiator
 * decides on:
 *
 * ```php
 * throw new JsonApiException(JsonApiError::conflict(
 *     "The slug \"{$slug}\" is already taken.",
 *     '/data/attributes/slug',
 * ));
 * ```
 *
 * §4.6 describes the exception as "carrying `status`/`code`/`detail`/`source`",
 * so those four may be passed directly instead, and the title and any member the
 * caller omits are filled in from the status table:
 *
 * ```php
 * throw new JsonApiException(
 *     status: 400,
 *     code: 'invalid_query_parameter',
 *     detail: 'Unknown filter "foo".',
 *     source: ['parameter' => 'filter[foo]'],
 * );
 * ```
 *
 * The renderer in `bootstrap/app.php` turns it back into the document, carrying
 * the status and any response headers the error declares (`Allow`, `Accept`).
 * Every other status is produced from the framework's own exceptions —
 * `ValidationException`, `AuthenticationException`, `AccessDeniedHttpException`,
 * `NotFoundHttpException`, `MethodNotAllowedHttpException` and Spatie's
 * `InvalidQuery` family — so a denial stays a real `403` and an absent session a
 * real `401` (§4.11).
 */
class JsonApiException extends HttpException
{
    /**
     * The error document this exception renders as.
     */
    public readonly JsonApiError $error;

    /**
     * @param  JsonApiError|int  $status  A prepared error, or the HTTP status code.
     * @param  array<string, string>  $source  `pointer`, `parameter` and/or `header`.
     * @param  array<string, string>  $links  JSON:API `links.about` / `links.type`.
     * @param  array<string, mixed>  $headers  Response headers this error adds.
     */
    public function __construct(
        JsonApiError|int $status,
        ?string $code = null,
        ?string $detail = null,
        array $source = [],
        array $links = [],
        array $headers = [],
    ) {
        $this->error = $status instanceof JsonApiError
            ? $status
            : JsonApiError::make($status, $code, $detail, $source, $links, $headers);

        parent::__construct($this->error->status, $this->error->detail, null, $this->error->headers);
    }

    /**
     * Raise the given error document.
     */
    public static function from(JsonApiError $error): self
    {
        return new self($error);
    }
}
