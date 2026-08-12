<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Api\Gate;
use App\Api\Problem;
use App\Core\Request;
use JsonException;
use Throwable;

/**
 * What every API endpoint has in common.
 *
 * Chiefly: one place that turns anything thrown into a correctly shaped JSON
 * error. Without it each handler would have to remember to catch, and the one
 * that forgot would answer a malformed request with an HTML stack trace.
 */
abstract class ApiController
{
    /**
     * Run a handler with the API's error handling and headers around it.
     *
     * @param callable():void $handler
     */
    protected function handle(callable $handler, bool $authenticate = true): void
    {
        try {
            if ($authenticate) {
                Gate::authenticate();
            }

            $handler();
        } catch (Problem $problem) {
            $this->fail($problem);
        } catch (JsonException) {
            $this->fail(Problem::badRequest('The request body is not valid JSON.'));
        } catch (Throwable $error) {
            // Never the message: it can carry a query, a path or a value from
            // the row that failed. It goes to the log, where a person with
            // access to the server can read it; the client gets an identifier
            // to quote and nothing else.
            $reference = bin2hex(random_bytes(4));

            error_log(sprintf('[api %s] %s in %s:%d', $reference, $error->getMessage(), $error->getFile(), $error->getLine()));

            $this->fail(new Problem(
                500,
                'server_error',
                'Something went wrong handling that request. Quote reference ' . $reference . ' to an administrator.'
            ));
        }
    }

    /** @param array<string,mixed> $payload */
    protected function respond(array $payload, int $status = 200, array $headers = []): never
    {
        $this->sendHeaders($status, $headers);

        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    protected function respondNoContent(): never
    {
        $this->sendHeaders(204, []);
        exit;
    }

    protected function fail(Problem $problem): never
    {
        $this->respond($problem->toArray(), $problem->status, $problem->headers);
    }

    /** @param array<string,string> $extra */
    private function sendHeaders(int $status, array $extra): void
    {
        http_response_code($status);

        header('Content-Type: application/json; charset=utf-8');

        // An API response is never a page, and a cached 200 from a shared proxy
        // would be one client seeing another's data.
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');

        $rate = Gate::rateState();

        if ($rate !== null) {
            // The de-facto standard trio. A client that reads them can slow
            // down before it is refused, which is the point of publishing them.
            header('X-RateLimit-Limit: ' . $rate['limit']);
            header('X-RateLimit-Remaining: ' . $rate['remaining']);
            header('X-RateLimit-Reset: ' . (time() + $rate['reset_in']));
        }

        foreach ($extra as $name => $value) {
            header($name . ': ' . $value);
        }
    }

    /**
     * The request body as an array.
     *
     * JSON is the documented content type. A form-encoded body is accepted too,
     * because `curl -d` is how most people try an API for the first time and
     * refusing them at that point teaches nothing useful.
     *
     * @return array<string,mixed>
     */
    protected function body(): array
    {
        if (Request::isJson()) {
            return Request::json();
        }

        $post = $_POST;

        if ($post !== []) {
            unset($post['_token'], $post['_method']);

            return $post;
        }

        // No content type, but a body: try JSON before giving up, since that is
        // what it almost always is.
        return Request::json();
    }
}
