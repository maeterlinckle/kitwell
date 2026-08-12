<?php

declare(strict_types=1);

namespace App\Api;

use App\Core\Config;
use App\Models\Setting;

/**
 * The OpenAPI 3.1 document, generated from the resource declarations.
 *
 * Built from the same `Resource` objects the endpoints are served from, so a
 * field that appears here appears in the response and a filter documented here
 * is a filter the router accepts — they are the same array.
 */
final class OpenApi
{
    public const VERSION = '1.0.0';

    /** @return array<string,mixed> */
    public static function document(): array
    {
        $paths = [];
        $tags  = [];

        foreach (ResourceRegistry::all() as $resource) {
            $tags[] = [
                'name'        => $resource->name,
                'description' => $resource->description,
            ];

            $paths['/' . $resource->name]          = self::collectionPath($resource);
            $paths['/' . $resource->name . '/{id}'] = self::itemPath($resource);
        }

        return [
            'openapi' => '3.1.0',
            'info'    => [
                'title'   => (string) Config::get('app.name', 'Kitwell') . ' API',
                'version' => self::VERSION,
                'summary' => 'Read and write the asset register over HTTP.',
                'description' => self::overview(),
            ],
            'servers' => [[
                'url'         => rtrim((string) Config::get('app.url', ''), '/') . '/api/v1',
                'description' => 'This installation',
            ]],
            'tags'       => $tags,
            'paths'      => $paths,
            'components' => [
                'securitySchemes' => [
                    'ApiKey' => [
                        'type'        => 'http',
                        'scheme'      => 'bearer',
                        'description' => 'An API key issued under Settings → API keys, sent as '
                            . '`Authorization: Bearer ark_…`. The key acts as the user it was issued '
                            . 'for and can do no more than that user could in the interface.',
                    ],
                ],
                'schemas' => [
                    'Error' => [
                        'type'       => 'object',
                        'required'   => ['error'],
                        'properties' => [
                            'error' => [
                                'type'       => 'object',
                                'required'   => ['status', 'code', 'message'],
                                'properties' => [
                                    'status'  => ['type' => 'integer', 'example' => 422],
                                    'code'    => ['type' => 'string', 'example' => 'validation_failed',
                                        'description' => 'Stable and machine-readable. Branch on this, not on the message.'],
                                    'message' => ['type' => 'string'],
                                    'details' => ['type' => 'object', 'additionalProperties' => true],
                                ],
                            ],
                        ],
                    ],
                    'Pagination' => [
                        'type'       => 'object',
                        'properties' => [
                            'page'     => ['type' => 'integer'],
                            'per_page' => ['type' => 'integer'],
                            'total'    => ['type' => 'integer'],
                            'pages'    => ['type' => 'integer'],
                        ],
                    ],
                ],
            ],
            'security' => [['ApiKey' => []]],
        ];
    }

    private static function overview(): string
    {
        $limit = Gate::rateLimit();

        return <<<TEXT
        Every response is JSON. Lists are `{"data": [...], "meta": {...}, "links": {...}}`;
        a single record is `{"data": {...}}`; an error is `{"error": {"status", "code", "message", "details"}}`.

        **Authentication.** Send an API key as `Authorization: Bearer ark_…`. A key is tied to one
        user and inherits exactly that user's role — it can never do more than they could through the
        interface, and a read-only key can do less. Keys are issued under Settings → API keys.

        **Pagination.** `?page=` and `?per_page=` on every list. The response carries `meta.total`
        and `links.next`.

        **Filtering.** Each resource declares its own filters, listed on its endpoint below. A filter
        this API does not know is a 400 rather than a silent no-op, because a misspelled filter that
        quietly returns everything is worse than one that fails.

        **Sorting.** `?sort=field` ascending, `?sort=-field` descending.

        **Rate limiting.** {$limit} requests per minute per key, reported in `X-RateLimit-Limit`,
        `X-RateLimit-Remaining` and `X-RateLimit-Reset`. Over it, 429 with `Retry-After`.

        **Updating.** `PATCH` changes the fields you send. `PUT` replaces the record: writable fields
        you leave out are cleared.
        TEXT;
    }

    /** @return array<string,mixed> */
    private static function collectionPath(Resource $resource): array
    {
        $path = [
            'get' => [
                'tags'        => [$resource->name],
                'summary'     => 'List ' . $resource->name,
                'description' => $resource->description,
                'parameters'  => array_merge(
                    self::paginationParameters(),
                    self::sortParameter($resource),
                    self::filterParameters($resource)
                ),
                'responses' => [
                    '200' => [
                        'description' => 'A page of results.',
                        'content'     => ['application/json' => ['schema' => [
                            'type'       => 'object',
                            'properties' => [
                                'data'  => ['type' => 'array', 'items' => self::schemaFor($resource)],
                                'meta'  => ['$ref' => '#/components/schemas/Pagination'],
                                'links' => ['type' => 'object', 'properties' => [
                                    'self' => ['type' => 'string'],
                                    'next' => ['type' => ['string', 'null']],
                                    'prev' => ['type' => ['string', 'null']],
                                ]],
                            ],
                        ]]],
                    ],
                    '400' => self::errorResponse('An unknown filter or an unsortable field.'),
                    '401' => self::errorResponse('Missing, unrecognised, revoked or expired key.'),
                    '403' => self::errorResponse('The key\'s user lacks ' . $resource->permission('list') . '.'),
                    '429' => self::errorResponse('Rate limit exceeded.'),
                ],
            ],
        ];

        if ($resource->supports('create')) {
            $path['post'] = [
                'tags'        => [$resource->name],
                'summary'     => 'Create a ' . $resource->singular,
                'requestBody' => [
                    'required' => true,
                    'content'  => ['application/json' => ['schema' => self::writableSchema($resource, true)]],
                ],
                'responses' => [
                    '201' => [
                        'description' => 'Created. `Location` carries the new record\'s URL.',
                        'content'     => ['application/json' => ['schema' => [
                            'type'       => 'object',
                            'properties' => ['data' => self::schemaFor($resource)],
                        ]]],
                    ],
                    '400' => self::errorResponse('Unknown or read-only fields in the body.'),
                    '403' => self::errorResponse('The key\'s user lacks ' . $resource->permission('create') . ', or the key is read-only.'),
                    '422' => self::errorResponse('Validation failed; `details` is keyed by field.'),
                ],
            ];
        }

        return $path;
    }

    /** @return array<string,mixed> */
    private static function itemPath(Resource $resource): array
    {
        $idParameter = [
            'name'     => 'id',
            'in'       => 'path',
            'required' => true,
            'schema'   => ['type' => 'integer'],
        ];

        $path = [
            'get' => [
                'tags'       => [$resource->name],
                'summary'    => 'Get one ' . $resource->singular,
                'parameters' => [$idParameter],
                'responses'  => [
                    '200' => [
                        'description' => 'The record.',
                        'content'     => ['application/json' => ['schema' => [
                            'type'       => 'object',
                            'properties' => ['data' => self::schemaFor($resource)],
                        ]]],
                    ],
                    '404' => self::errorResponse('No record with that id.'),
                ],
            ],
        ];

        if ($resource->supports('update')) {
            foreach (['patch' => 'Change the fields you send', 'put' => 'Replace: writable fields you omit are cleared'] as $method => $summary) {
                $path[$method] = [
                    'tags'        => [$resource->name],
                    'summary'     => $summary,
                    'parameters'  => [$idParameter],
                    'requestBody' => [
                        'required' => true,
                        'content'  => ['application/json' => ['schema' => self::writableSchema($resource, $method === 'put')]],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'The record as it now stands.',
                            'content'     => ['application/json' => ['schema' => [
                                'type'       => 'object',
                                'properties' => ['data' => self::schemaFor($resource)],
                            ]]],
                        ],
                        '404' => self::errorResponse('No record with that id.'),
                        '422' => self::errorResponse('Validation failed.'),
                    ],
                ];
            }
        }

        if ($resource->supports('delete')) {
            $path['delete'] = [
                'tags'       => [$resource->name],
                'summary'    => 'Delete a ' . $resource->singular,
                'parameters' => [$idParameter],
                'responses'  => [
                    '204' => ['description' => 'Deleted. No body.'],
                    '404' => self::errorResponse('No record with that id.'),
                    '409' => self::errorResponse('Refused because deleting it would destroy history. The message says what.'),
                ],
            ];
        }

        return $path;
    }

    /** @return array<string,mixed> */
    private static function schemaFor(Resource $resource): array
    {
        $properties = [];

        foreach ($resource->fields as $name => $field) {
            $property = ['type' => self::jsonType((string) ($field['type'] ?? 'string'))];

            if (isset($field['format'])) {
                $property['format'] = $field['format'];
            }

            if (isset($field['enum'])) {
                $property['enum'] = array_values((array) $field['enum']);
            }

            if (isset($field['description'])) {
                $property['description'] = $field['description'];
            }

            if (($field['writable'] ?? false) !== true) {
                $property['readOnly'] = true;
            }

            $properties[$name] = $property;
        }

        return ['type' => 'object', 'properties' => $properties];
    }

    /** @return array<string,mixed> */
    private static function writableSchema(Resource $resource, bool $requireAll): array
    {
        $properties = [];
        $required   = [];

        foreach ($resource->fields as $name => $field) {
            if (($field['writable'] ?? false) !== true) {
                continue;
            }

            $property = ['type' => self::jsonType((string) ($field['type'] ?? 'string'))];

            if (isset($field['format'])) {
                $property['format'] = $field['format'];
            }

            if (isset($field['enum'])) {
                $property['enum'] = array_values((array) $field['enum']);
            }

            if (isset($field['description'])) {
                $property['description'] = $field['description'];
            }

            // Documented because it is what a PUT resets the field to, which is
            // the one thing a client has to know before replacing a record.
            if (array_key_exists('default', $field)) {
                $property['default'] = $field['default'];
            }

            $properties[$name] = $property;

            if (($field['required'] ?? false) === true) {
                $required[] = $name;
            }
        }

        $schema = [
            'type'                 => 'object',
            'properties'           => $properties,
            // Unknown fields are a 400, not a silent drop, so the schema says so.
            'additionalProperties' => false,
        ];

        if ($requireAll && $required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /** @return array<int,array<string,mixed>> */
    private static function paginationParameters(): array
    {
        return [
            ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
            ['name' => 'per_page', 'in' => 'query', 'schema' => [
                'type'    => 'integer',
                'minimum' => 1,
                'maximum' => max(1, Setting::int('api_max_per_page', 100)),
                'default' => max(1, Setting::int('api_default_per_page', 25)),
            ]],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private static function sortParameter(Resource $resource): array
    {
        return [[
            'name'        => 'sort',
            'in'          => 'query',
            'description' => 'A field name, optionally prefixed with `-` for descending.',
            'schema'      => ['type' => 'string', 'enum' => array_merge(
                array_keys($resource->fields),
                array_map(static fn (string $f): string => '-' . $f, array_keys($resource->fields))
            )],
        ]];
    }

    /** @return array<int,array<string,mixed>> */
    private static function filterParameters(Resource $resource): array
    {
        $parameters = [];

        foreach ($resource->filters as $name => $filter) {
            $schema = ['type' => self::jsonType((string) ($filter['type'] ?? 'string'))];

            if (isset($filter['enum'])) {
                $schema['enum'] = array_values((array) $filter['enum']);
            }

            if (!empty($filter['repeatable'])) {
                $schema = ['type' => 'array', 'items' => $schema];
            }

            $parameters[] = [
                'name'        => !empty($filter['repeatable']) ? $name . '[]' : $name,
                'in'          => 'query',
                'description' => (string) ($filter['description'] ?? ''),
                'schema'      => $schema,
            ];
        }

        return $parameters;
    }

    /** @return array<string,mixed> */
    private static function errorResponse(string $description): array
    {
        return [
            'description' => $description,
            'content'     => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
        ];
    }

    private static function jsonType(string $type): string
    {
        return match ($type) {
            'integer' => 'integer',
            'number'  => 'number',
            'boolean' => 'boolean',
            'array'   => 'array',
            default   => 'string',
        };
    }
}
