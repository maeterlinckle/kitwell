<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Api\Gate;
use App\Api\OpenApi;
use App\Api\Problem;
use App\Api\ResourceRegistry;
use App\Core\Auth;
use App\Core\View;

/**
 * The parts of the API that are not a resource: the index, the specification,
 * and the page a person reads.
 */
final class MetaController extends ApiController
{
    /**
     * GET /api/v1 — what this key can reach.
     *
     * Deliberately answers with the resources *this caller* may list rather
     * than the full set: an index promising eleven endpoints of which four
     * answer 403 is a worse starting point than an honest six.
     */
    public function index(): void
    {
        $this->handle(function (): void {
            $base      = rtrim((string) config('app.url'), '/') . '/api/v1';
            $resources = [];

            foreach (ResourceRegistry::visible() as $resource) {
                $methods = [];

                foreach (['list' => 'GET', 'create' => 'POST', 'update' => 'PATCH', 'delete' => 'DELETE'] as $action => $method) {
                    if ($resource->supports($action === 'list' ? 'list' : $action)
                        && Auth::can((string) ($resource->permission($action) ?? $resource->permission('list')))) {
                        $methods[] = $method;
                    }
                }

                $resources[$resource->name] = [
                    'href'        => $base . '/' . $resource->name,
                    'description' => $resource->description,
                    'methods'     => $methods,
                    'filters'     => array_keys($resource->filters),
                ];
            }

            $key = Gate::key();

            $this->respond([
                'data' => [
                    'name'    => (string) config('app.name'),
                    'version' => OpenApi::VERSION,
                    'documentation' => rtrim((string) config('app.url'), '/') . '/api/docs',
                    'openapi'       => $base . '/openapi.json',
                    'authenticated_as' => [
                        'user'  => Auth::name(),
                        'role'  => Auth::user()['role_name'] ?? null,
                        // Says how, because "why can I not write?" is answered
                        // differently for a read-only key and a browser session.
                        'via'   => $key === null ? 'session' : 'api_key',
                        'key'   => $key === null ? null : ['name' => $key['name'], 'scope' => $key['scope']],
                    ],
                    'resources' => $resources,
                ],
            ]);
        });
    }

    /**
     * GET /api/v1/openapi.json
     *
     * Behind the same authentication as everything else. The document lists
     * every field and filter of every resource, which is a map of the system —
     * not secret, but not something to hand to an unauthenticated caller
     * either.
     */
    public function openapi(): void
    {
        $this->handle(function (): void {
            $this->respond(OpenApi::document());
        });
    }

    /**
     * GET /api/docs — the readable, runnable version.
     *
     * Served from the application rather than loaded from a CDN, because the
     * Content-Security-Policy is `default-src 'self'` and allows no off-origin
     * scripts. Rather than weaken that for a documentation page — or vendor a
     * megabyte of somebody else's JavaScript into the repository — the viewer
     * is a small script of our own that reads the generated document. It shows
     * whatever the spec says, so it cannot drift from the API either.
     *
     * A normal HTML page behind the ordinary session, not an API endpoint: the
     * person reading it is signed in with a browser.
     */
    public function docs(): void
    {
        if (!Gate::isEnabled()) {
            View::renderError(
                503,
                'The API is switched off',
                'An administrator can enable it under Settings → API keys. The documentation describes '
                . 'endpoints that will not answer until they do.'
            );

            return;
        }

        View::render('api/docs', [
            'pageTitle' => 'API documentation',
            'specUrl'   => url('/api/v1/openapi.json'),
            'baseUrl'   => rtrim((string) config('app.url'), '/') . '/api/v1',
            'canManage' => Auth::can('api.manage'),
        ], 'layouts/app');
    }

    /** Anything under /api that matched no route. */
    public function notFound(): void
    {
        $this->handle(function (): void {
            throw Problem::notFound('No such endpoint. GET /api/v1 for the index, or /api/docs to read about it.');
        }, false);
    }
}
