<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Yaml\Yaml;

/*
|--------------------------------------------------------------------------
| docs/api/openapi.yaml is the contract of record (§4.15, §11.4)
|--------------------------------------------------------------------------
|
| §4.15 makes the hand-written OpenAPI 3.1 document the contract of record and
| pins it to reality with a Pest contract test that compares
| `php artisan route:list --json` (vendor routes excluded) against the
| document's `paths` × methods, failing on drift in either direction. This file
| is that test.
|
| Drift is **reported, never repaired here**: the document is another writer's
| artefact, so a failing test that names the undeclared operation is worth more
| than a silently edited spec. The same rule covers the four content
| requirements §4.15 attaches to the document — `application/vnd.api+json` as
| the response media type, the `Accept-Query` response header, the `QUERY`
| operation on `/search` and every §4.6 error example — which the last three
| tests below pin.
|
| **Parser.** `symfony/yaml` 8.1.8: the framework's own YAML component, already
| a dependency (`composer show symfony/yaml`), so the document is read by the
| same parser the rest of the toolchain uses and no second YAML implementation
| is introduced. Two of its behaviours shape the code below, and both are
| called out in the document's own `info.description`:
|
|   1. A path item carries `summary`, `description` and — on the
|      single-resource paths — `parameters` beside its method keys, all of
|      which OpenAPI 3.1 permits. Methods therefore come from intersecting the
|      item's keys with the eight verbs (`openApiMethodKeys()`), never from
|      `array_keys()` of the whole path item, which would read `summary` as an
|      operation.
|   2. The parser casts the quoted `'200'` status keys to integers, so statuses
|      are used as integers and never assumed to be strings. Every comparison
|      below interpolates the key, so it holds either way.
|
| **Scope.** The document describes only the API; `route:list` reports the
| whole application. The comparison is scoped to `/api/v1`, which excludes the
| public pages, `/login`, `/settings`, Fortify, Passkeys, `spatie/laravel-feed`,
| `storage`, `boost` and `.well-known` by prefix rather than by an allow-list
| that would have to be maintained. Because the router side is narrowed, the
| document side carries its own guard that nothing outside the prefix is
| declared (test 2).
|
| **`HEAD`.** Laravel reports `GET|HEAD` and `PUT|PATCH` as one route entry
| each, so the router side is expanded per verb before comparison; the document
| declares `head` explicitly, which is why it appears on both sides.
|
*/

/**
 * The eight verbs a path item's keys are intersected with, in the order the
 * document documents them.
 *
 * @return list<string>
 */
function openApiMethodKeys(): array
{
    return ['get', 'head', 'options', 'query', 'post', 'put', 'patch', 'delete'];
}

/**
 * `docs/api/openapi.yaml`, parsed once per process.
 *
 * @return array<string, mixed>
 */
function openApiDocument(): array
{
    static $document = null;

    if ($document === null) {
        $document = Yaml::parseFile(base_path('docs/api/openapi.yaml'));
    }

    return $document;
}

/**
 * The document's operations, keyed by path, methods upper-cased and sorted:
 * `['/api/v1/awards' => ['GET', 'HEAD', 'OPTIONS', 'POST']]`.
 *
 * @return array<string, list<string>>
 */
function openApiSpecOperations(): array
{
    $document = openApiDocument();

    $operations = [];

    foreach ($document['paths'] as $path => $item) {
        $methods = [];

        // The intersection the document's own note asks for: `summary`,
        // `description` and `parameters` sit beside the method keys and must
        // not be mistaken for operations.
        foreach (array_keys($item) as $key) {
            if (in_array($key, openApiMethodKeys(), true)) {
                $methods[] = strtoupper($key);
            }
        }

        sort($methods);

        $operations[$path] = $methods;
    }

    ksort($operations);

    return $operations;
}

/**
 * The router's operations under `/api/v1`, keyed the same way, read from the
 * `route:list --json` output §4.15 names.
 *
 * @return array<string, list<string>>
 */
function openApiRouteOperations(): array
{
    $output = new BufferedOutput;

    $status = Artisan::call('route:list', ['--json' => true, '--except-vendor' => true], $output);

    if ($status !== 0) {
        throw new RuntimeException("`php artisan route:list --json --except-vendor` exited with {$status}.");
    }

    $routes = json_decode($output->fetch(), true, 512, JSON_THROW_ON_ERROR);

    $operations = [];

    foreach ($routes as $route) {
        $path = '/'.ltrim($route['uri'], '/');

        if (! str_starts_with($path, '/api/v1')) {
            continue;
        }

        // `GET|HEAD` and `PUT|PATCH` arrive as one entry each.
        foreach (explode('|', $route['method']) as $method) {
            $operations[$path][] = strtoupper($method);
        }
    }

    foreach ($operations as $path => $methods) {
        $operations[$path] = array_values(array_unique($methods));

        sort($operations[$path]);
    }

    ksort($operations);

    return $operations;
}

/**
 * The operations present in `$left` and absent from `$right`, as `METHOD path`
 * strings — the shape both drift directions are asserted in.
 *
 * A path missing from `$right` entirely contributes every one of its methods,
 * so path-level and method-level drift are reported by the same call.
 *
 * @param  array<string, list<string>>  $left
 * @param  array<string, list<string>>  $right
 * @return list<string>
 */
function openApiDifference(array $left, array $right): array
{
    $difference = [];

    foreach ($left as $path => $methods) {
        foreach ($methods as $method) {
            if (! in_array($method, $right[$path] ?? [], true)) {
                $difference[] = "{$method} {$path}";
            }
        }
    }

    sort($difference);

    return $difference;
}

/**
 * Resolve a local `$ref` (`#/components/…`) to the node it points at.
 *
 * Every reference in this document is local. An unresolvable one throws rather
 * than comparing against an empty node, which would turn a broken pointer into
 * a passing test.
 *
 * @param  array<string, mixed>  $document
 * @param  array<string, mixed>  $node
 * @return array<string, mixed>
 */
function openApiResolve(array $document, array $node): array
{
    $ref = $node['$ref'] ?? null;

    if (! is_string($ref)) {
        return $node;
    }

    if (! str_starts_with($ref, '#/')) {
        throw new RuntimeException("docs/api/openapi.yaml references [{$ref}], which is not a local pointer.");
    }

    $resolved = Arr::get($document, str_replace('/', '.', substr($ref, 2)));

    if (! is_array($resolved)) {
        throw new RuntimeException("docs/api/openapi.yaml references [{$ref}], which does not resolve.");
    }

    return $resolved;
}

/**
 * A response's declared header names. The key is the header name; its `$ref`
 * only points at the shared definition, so the key is the contract.
 *
 * @param  array<string, mixed>  $response
 * @return list<string>
 */
function openApiHeaderNames(array $response): array
{
    $names = array_map(strval(...), array_keys($response['headers'] ?? []));

    sort($names);

    return $names;
}

test('every /api/v1 path the router serves is declared in the spec', function () {
    $undeclared = array_values(array_diff(
        array_keys(openApiRouteOperations()),
        array_keys(openApiSpecOperations()),
    ));

    expect($undeclared)->toBe([]);
});

test('the spec declares no /api/v1 path the router does not serve', function () {
    $spec = openApiSpecOperations();
    $routes = openApiRouteOperations();

    $phantom = array_values(array_diff(array_keys($spec), array_keys($routes)));

    // The router side is narrowed to the `/api/v1` prefix, so a path outside it
    // would only be caught above when it also happened to be unserved — `/`
    // is served, and would pass silently. Hence the second guard.
    $outOfScope = array_values(array_filter(
        array_keys($spec),
        fn (string $path): bool => ! str_starts_with($path, '/api/v1'),
    ));

    expect($phantom)->toBe([]);
    expect($outOfScope)->toBe([]);
});

test('every method the router serves on an /api/v1 path is declared in the spec', function () {
    expect(openApiDifference(openApiRouteOperations(), openApiSpecOperations()))->toBe([]);
});

test('the spec declares no /api/v1 method the router does not serve', function () {
    expect(openApiDifference(openApiSpecOperations(), openApiRouteOperations()))->toBe([]);
});

test('the spec declares application/vnd.api+json for JSON:API and application/json for search', function () {
    $document = openApiDocument();

    // §4.1's asymmetry: the search document mixes resource types and is
    // deliberately not JSON:API, so its three successes answer
    // `application/json` while every other success and *every* error — on every
    // endpoint, search included — answers `application/vnd.api+json`.
    $jsonSuccesses = ['GET /api/v1/search 200', 'HEAD /api/v1/search 200', 'QUERY /api/v1/search 200'];

    $unexpected = [];

    foreach (openApiSpecOperations() as $path => $methods) {
        foreach ($methods as $method) {
            $operation = $document['paths'][$path][strtolower($method)];

            foreach ($operation['responses'] as $status => $response) {
                foreach (array_keys(openApiResolve($document, $response)['content'] ?? []) as $mediaType) {
                    $declaration = strtoupper($method)." {$path} {$status}";

                    if ($mediaType === 'application/vnd.api+json') {
                        continue;
                    }

                    if ($mediaType === 'application/json' && in_array($declaration, $jsonSuccesses, true)) {
                        continue;
                    }

                    $unexpected[] = "{$declaration} -> {$mediaType}";
                }
            }
        }
    }

    sort($unexpected);

    expect($unexpected)->toBe([]);
});

test('the spec declares the QUERY operation and Accept-Query on /api/v1/search', function () {
    $document = openApiDocument();

    $search = $document['paths']['/api/v1/search'];

    // OpenAPI 3.1 has no `query` fixed field — its method set predates RFC
    // 10008 — so the API's only non-GET read is declared as a method key anyway.
    expect(array_keys($search))->toContain('query');

    // §4.1: `Accept-Query` on the GET, HEAD and OPTIONS responses is how a
    // client discovers that the URI takes a QUERY and what body media type it
    // must declare.
    foreach (['get', 'head', 'options'] as $method) {
        $response = openApiResolve($document, $search[$method]['responses'][200]);

        expect(openApiHeaderNames($response))->toContain('Accept-Query');
    }

    // §4.5: the `415` a QUERY with the wrong body media type produces
    // advertises both the media type it wants (`Accept`) and QUERY support
    // (`Accept-Query`).
    $unsupported = openApiResolve($document, $search['query']['responses'][415]);

    expect(openApiHeaderNames($unsupported))->toContain('Accept-Query')->toContain('Accept');
});

test('every error response the spec declares carries an example', function () {
    $document = openApiDocument();

    $withoutExample = [];

    foreach (openApiSpecOperations() as $path => $methods) {
        foreach ($methods as $method) {
            $operation = $document['paths'][$path][strtolower($method)];

            foreach ($operation['responses'] as $status => $response) {
                if ($status < 400) {
                    continue;
                }

                $examples = [];

                foreach (openApiResolve($document, $response)['content'] ?? [] as $media) {
                    $examples = array_merge($examples, array_keys($media['examples'] ?? []));
                }

                if ($examples === []) {
                    $withoutExample[] = strtoupper($method)." {$path} {$status}";
                }
            }
        }
    }

    sort($withoutExample);

    expect($withoutExample)->toBe([]);
});
