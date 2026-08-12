<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Api\Gate;
use App\Api\Problem;
use App\Api\Resource;
use App\Api\ResourceRegistry;
use App\Core\Request;
use App\Models\Setting;

/**
 * Every resource, served by one controller.
 *
 * List, read, create, update and delete are the same five shapes whatever the
 * resource is, so they are written once. Pagination, filtering, sorting, the
 * permission check, the writable allow-list and the response envelope are
 * therefore identical across the whole interface by construction rather than by
 * discipline — a resource cannot invent its own convention because it has
 * nowhere to put one.
 */
final class ResourceController extends ApiController
{
    /** GET /api/v1/{resource} */
    public function index(string $resource): void
    {
        $this->handle(function () use ($resource): void {
            $definition = $this->resolve($resource);
            $this->authorise($definition, 'list');

            $rows = $definition->fetchList($this->filters($definition));
            $rows = $this->applySort($definition, $rows);

            [$page, $perPage] = $this->pagination();

            $total = count($rows);
            $pages = max(1, (int) ceil($total / $perPage));
            $page  = min($page, $pages);

            $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

            $this->respond([
                'data' => array_map($definition->present(...), $slice),
                'meta' => [
                    'page'     => $page,
                    'per_page' => $perPage,
                    'total'    => $total,
                    'pages'    => $pages,
                ],
                'links' => $this->links($resource, $page, $pages, $perPage),
            ]);
        });
    }

    /** GET /api/v1/{resource}/{id} */
    public function show(string $resource, string $id): void
    {
        $this->handle(function () use ($resource, $id): void {
            $definition = $this->resolve($resource);
            $this->authorise($definition, 'read');

            $row = $this->require($definition, (int) $id);

            $this->respond(['data' => $definition->present($row)]);
        });
    }

    /** POST /api/v1/{resource} */
    public function store(string $resource): void
    {
        $this->handle(function () use ($resource): void {
            $definition = $this->resolve($resource);

            if (!$definition->supports('create')) {
                throw Problem::methodNotAllowed($definition->allowedMethods(false));
            }

            $this->authorise($definition, 'create');

            $input = $this->readBody($definition);
            $this->assertValid($definition, $input, null);

            $id  = $definition->insert($definition->writable($input));
            $row = $definition->fetchOne($id);

            $this->respond(
                ['data' => $row === null ? ['id' => $id] : $definition->present($row)],
                201,
                ['Location' => $this->base() . '/' . $resource . '/' . $id]
            );
        });
    }

    /**
     * PATCH /api/v1/{resource}/{id} — change the fields you send.
     * PUT   /api/v1/{resource}/{id} — replace: unsent writable fields are cleared.
     *
     * Both are offered because the difference is real and clients rely on it.
     * PATCH is what almost everything wants; PUT is honest about being a
     * replacement, and is the one to reach for when a script owns a record
     * outright and wants its absence of a field to mean "empty".
     */
    public function update(string $resource, string $id): void
    {
        $this->handle(function () use ($resource, $id): void {
            $definition = $this->resolve($resource);

            if (!$definition->supports('update')) {
                throw Problem::methodNotAllowed($definition->allowedMethods(true));
            }

            $this->authorise($definition, 'update');

            $existing = $this->require($definition, (int) $id);
            $input    = $this->readBody($definition);

            if (Request::method() === 'PUT') {
                // Replacement: every writable field the caller did not send goes
                // back to its starting value.
                //
                // "Starting value" is not always null. A field that declares a
                // `default` maps to a NOT NULL column — an asset's status, a
                // hirer's type — and emptying it is a database error rather than
                // a replacement. Sending them back to the default is what a
                // replacement actually means, and it is the difference between
                // a 200 and a 500: this exact case failed on assets and hirers
                // the first time the contract test ran.
                //
                // Required fields must still be present, so a PUT cannot quietly
                // blank a name.
                foreach ($definition->fields as $field => $meta) {
                    if (($meta['writable'] ?? false) !== true || array_key_exists($field, $input)) {
                        continue;
                    }

                    if (($meta['required'] ?? false) === true) {
                        throw Problem::validation([
                            $field => ($meta['label'] ?? $field) . ' is required. Use PATCH to change only some fields.',
                        ]);
                    }

                    $input[$field] = array_key_exists('default', $meta) ? $meta['default'] : null;
                }
            }

            $this->assertValid($definition, $input, $existing);

            $definition->modify((int) $id, $definition->writable($input), $existing);

            $row = $definition->fetchOne((int) $id);

            $this->respond(['data' => $row === null ? [] : $definition->present($row)]);
        });
    }

    /** DELETE /api/v1/{resource}/{id} */
    public function destroy(string $resource, string $id): void
    {
        $this->handle(function () use ($resource, $id): void {
            $definition = $this->resolve($resource);

            if (!$definition->supports('delete')) {
                throw Problem::methodNotAllowed($definition->allowedMethods(true));
            }

            $this->authorise($definition, 'delete');

            $existing = $this->require($definition, (int) $id);
            $definition->remove((int) $id, $existing);

            $this->respondNoContent();
        });
    }

    // -- Shared ----------------------------------------------------------------

    private function resolve(string $resource): Resource
    {
        $definition = ResourceRegistry::find($resource);

        if ($definition === null) {
            throw Problem::notFound(sprintf('There is no "%s" resource. GET %s for the list.', $resource, $this->base()));
        }

        return $definition;
    }

    private function authorise(Resource $definition, string $action): void
    {
        $permission = $definition->permission($action);

        if ($permission === null) {
            throw Problem::methodNotAllowed($definition->allowedMethods($action !== 'list' && $action !== 'create'));
        }

        Gate::require($permission);
    }

    /** @return array<string,mixed> */
    private function require(Resource $definition, int $id): array
    {
        $row = $definition->fetchOne($id);

        if ($row === null) {
            throw Problem::notFound(sprintf('No %s with id %d.', $definition->singular, $id));
        }

        return $row;
    }

    /**
     * The body, with unknown fields refused rather than dropped.
     *
     * @return array<string,mixed>
     */
    private function readBody(Resource $definition): array
    {
        $input   = $this->body();
        $unknown = $definition->unknownFields($input);

        if ($unknown !== []) {
            throw Problem::badRequest(
                'The request contains ' . (count($unknown) === 1 ? 'a field' : 'fields')
                . ' this resource does not have: ' . implode(', ', $unknown) . '.',
                ['unknown_fields' => $unknown]
            );
        }

        // A field that exists but is read-only is worth its own message: it is a
        // different mistake from a typo, and "id is not writable" is more use
        // than silence.
        $readOnly = [];

        foreach (array_keys($input) as $field) {
            if (($definition->fields[$field]['writable'] ?? false) !== true) {
                $readOnly[] = (string) $field;
            }
        }

        if ($readOnly !== []) {
            throw Problem::badRequest(
                'These fields are read-only: ' . implode(', ', $readOnly) . '.',
                ['read_only_fields' => $readOnly]
            );
        }

        return $input;
    }

    /** @param array<string,mixed> $input */
    private function assertValid(Resource $definition, array $input, ?array $existing): void
    {
        $errors = $definition->errorsFor($input, $existing);

        if ($errors !== []) {
            throw Problem::validation($errors);
        }
    }

    /**
     * Query parameters mapped onto the model's own filter keys.
     *
     * Anything the resource does not declare is refused, not ignored — a
     * misspelled filter that silently returns everything is how somebody
     * publishes a report containing rows they meant to exclude.
     *
     * @return array<string,mixed>
     */
    private function filters(Resource $definition): array
    {
        $reserved = ['page', 'per_page', 'sort'];
        $query    = $_GET;
        $filters  = [];

        foreach ($query as $key => $value) {
            $key = (string) $key;

            if (in_array($key, $reserved, true)) {
                continue;
            }

            if (!isset($definition->filters[$key])) {
                throw Problem::badRequest(
                    sprintf('"%s" is not a filter on this resource.', $key),
                    ['available_filters' => array_keys($definition->filters)]
                );
            }

            $spec     = $definition->filters[$key];
            $modelKey = (string) ($spec['model_key'] ?? $key);

            if (!empty($spec['repeatable'])) {
                $values = is_array($value) ? array_map('strval', $value) : [(string) $value];
                $values = array_values(array_filter($values, static fn (string $v): bool => trim($v) !== ''));

                // Rejected, not quietly dropped. Intersecting against the enum
                // and moving on turns `?status[]=Nonsens` into "no status
                // filter at all", so the caller gets every row back and a 200
                // that looks like agreement — the same trap the single-value
                // branch below avoids, and it was in here for one build.
                if (isset($spec['enum'])) {
                    $unknown = array_values(array_diff($values, (array) $spec['enum']));

                    if ($unknown !== []) {
                        throw Problem::badRequest(
                            sprintf(
                                '%s for "%s": %s. Allowed: %s.',
                                count($unknown) === 1 ? 'Unknown value' : 'Unknown values',
                                $key,
                                implode(', ', $unknown),
                                implode(', ', (array) $spec['enum'])
                            )
                        );
                    }
                }

                if ($values !== []) {
                    $filters[$modelKey] = $values;
                }

                continue;
            }

            if (is_array($value)) {
                throw Problem::badRequest(sprintf('"%s" takes a single value.', $key));
            }

            $value = trim((string) $value);

            if ($value === '') {
                continue;
            }

            if (isset($spec['enum']) && !in_array($value, (array) $spec['enum'], true)) {
                throw Problem::badRequest(
                    sprintf('"%s" must be one of: %s.', $key, implode(', ', (array) $spec['enum']))
                );
            }

            $filters[$modelKey] = ($spec['type'] ?? '') === 'boolean'
                ? in_array($value, ['1', 'true', 'yes'], true)
                : $value;
        }

        return $filters;
    }

    /**
     * `?sort=name` ascending, `?sort=-name` descending.
     *
     * Where the model has a matching named ordering it is used, so the API
     * returns rows in the same order the screen does. Where it has not, the
     * rows are ordered here — never by building a column name into SQL.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function applySort(Resource $definition, array $rows): array
    {
        $requested = trim((string) Request::query('sort', ''));

        if ($requested === '') {
            return $rows;
        }

        $descending = str_starts_with($requested, '-');
        $field      = ltrim($requested, '-+');

        if (!isset($definition->fields[$field])) {
            throw Problem::badRequest(
                sprintf('Cannot sort by "%s".', $field),
                ['sortable_fields' => array_keys($definition->fields)]
            );
        }

        $type = (string) ($definition->fields[$field]['type'] ?? 'string');

        usort($rows, static function (array $a, array $b) use ($field, $type, $descending): int {
            $left  = $a[$field] ?? null;
            $right = $b[$field] ?? null;

            $leftEmpty  = $left === null || $left === '';
            $rightEmpty = $right === null || $right === '';

            if ($leftEmpty || $rightEmpty) {
                return $leftEmpty === $rightEmpty ? 0 : ($leftEmpty ? 1 : -1);
            }

            $result = match ($type) {
                'integer', 'number' => (float) $left <=> (float) $right,
                'boolean'           => (int) (bool) $left <=> (int) (bool) $right,
                default             => str_contains((string) ($a[$field . '_format'] ?? ''), 'date')
                    ? strtotime((string) $left) <=> strtotime((string) $right)
                    : strnatcasecmp((string) $left, (string) $right),
            };

            return $descending ? -$result : $result;
        });

        return $rows;
    }

    /** @return array{0:int,1:int} */
    private function pagination(): array
    {
        $default = max(1, Setting::int('api_default_per_page', 25));
        $max     = max($default, Setting::int('api_max_per_page', 100));

        $page    = max(1, (int) Request::query('page', 1));
        $perPage = (int) Request::query('per_page', $default);
        $perPage = $perPage < 1 ? $default : min($perPage, $max);

        return [$page, $perPage];
    }

    /** @return array<string,string|null> */
    private function links(string $resource, int $page, int $pages, int $perPage): array
    {
        $build = function (int $target) use ($resource, $perPage): string {
            $query = $_GET;
            $query['page']     = $target;
            $query['per_page'] = $perPage;

            return $this->base() . '/' . $resource . '?' . http_build_query($query);
        };

        return [
            'self' => $build($page),
            'next' => $page < $pages ? $build($page + 1) : null,
            'prev' => $page > 1 ? $build($page - 1) : null,
        ];
    }

    private function base(): string
    {
        return rtrim((string) config('app.url'), '/') . '/api/v1';
    }
}
