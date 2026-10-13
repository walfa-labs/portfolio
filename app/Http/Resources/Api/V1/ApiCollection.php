<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * The paginated collection document §4.4.1 documents.
 *
 * Laravel's pagination information is not the JSON:API shape: it reports
 * `current_page`/`per_page`/`first_page_url` and its links are built from the
 * paginator's own `page` parameter, which would drop the `page[number]` and
 * `page[size]` the contract uses (§4.7). `PaginatedResourceResponse` calls
 * `paginationInformation()` on the collection object when it exists, so
 * overriding it here is the supported extension point and every link keeps the
 * caller's other query parameters, as JSON:API requires.
 *
 * It also collapses the `included` member to one resource object per type and
 * id: a compound document must not carry the same resource twice, and the
 * framework keeps one copy per *relationship instance* for a `morphToMany`
 * pivot, so a page whose items share a tag would otherwise repeat it.
 */
class ApiCollection extends AnonymousResourceCollection
{
    /**
     * Get the additional members a collection document carries.
     *
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function with($request): array
    {
        /** @var array<string, mixed> $members */
        $members = parent::with($request);

        if (isset($members['included'])) {
            $members['included'] = (new Collection($members['included']))
                ->unique(fn (array $included): string => $included['type'].':'.$included['id'])
                ->values()
                ->all();
        }

        return $members;
    }

    /**
     * Build the `links` and `meta.page` members of the collection document.
     *
     * @param  array<string, mixed>  $paginated
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        $paginator = $this->resource;

        if (! $paginator instanceof LengthAwarePaginator) {
            return $default;
        }

        $size = $paginator->perPage();
        $number = $paginator->currentPage();

        $page = Arr::except($request->query(), ['page', 'page.number', 'page.size']);

        $link = fn (int $pageNumber): string => $request->url().'?'.Arr::query([
            ...$page,
            'page' => ['number' => $pageNumber, 'size' => $size],
        ]);

        return [
            'links' => [
                'self' => $request->fullUrl(),
                'first' => $link(1),
                'prev' => $number > 1 ? $link($number - 1) : null,
                'next' => $paginator->hasMorePages() ? $link($number + 1) : null,
                'last' => $link($paginator->lastPage()),
            ],
            'meta' => [
                'page' => [
                    'number' => $number,
                    'size' => $size,
                    'total' => $paginator->total(),
                    'pages' => $paginator->lastPage(),
                ],
            ],
        ];
    }
}
