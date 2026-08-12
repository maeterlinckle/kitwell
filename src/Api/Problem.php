<?php

declare(strict_types=1);

namespace App\Api;

use RuntimeException;

/**
 * One error shape, for every endpoint.
 *
 * Thrown rather than returned, so a resource handler can refuse at the point it
 * discovers the problem instead of threading a failure back through three
 * layers. `App\Controllers\Api\ApiController` catches it and writes the
 * response.
 *
 * The body is always:
 *
 *     {
 *       "error": {
 *         "status": 422,
 *         "code": "validation_failed",
 *         "message": "Two fields need attention.",
 *         "details": { "asset_tag": "Asset tag is required." }
 *       }
 *     }
 *
 * `code` is the stable machine-readable part — a client may branch on it and it
 * will not change wording underneath them. `message` is for a person reading a
 * log. `details` is present only when there is something field-shaped to say.
 */
final class Problem extends RuntimeException
{
    /** @param array<string,mixed> $details */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
        /** Extra headers this problem requires, e.g. Retry-After. */
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public static function badRequest(string $message, array $details = []): self
    {
        return new self(400, 'bad_request', $message, $details);
    }

    public static function unauthorised(string $message): self
    {
        // 401 must carry a WWW-Authenticate header to be a correct 401 rather
        // than a 403 wearing the wrong number.
        return new self(401, 'unauthorised', $message, [], ['WWW-Authenticate' => 'Bearer realm="api"']);
    }

    public static function forbidden(string $message): self
    {
        return new self(403, 'forbidden', $message);
    }

    public static function notFound(string $message = 'No such resource.'): self
    {
        return new self(404, 'not_found', $message);
    }

    public static function methodNotAllowed(array $allowed): self
    {
        return new self(
            405,
            'method_not_allowed',
            'That method is not available on this resource.',
            ['allowed' => array_values($allowed)],
            ['Allow' => implode(', ', $allowed)]
        );
    }

    public static function conflict(string $message, array $details = []): self
    {
        return new self(409, 'conflict', $message, $details);
    }

    /** @param array<string,string> $errors */
    public static function validation(array $errors): self
    {
        $count = count($errors);

        return new self(
            422,
            'validation_failed',
            $count === 1 ? reset($errors) : $count . ' fields need attention.',
            $errors
        );
    }

    public static function rateLimited(int $retryAfter, int $limit): self
    {
        return new self(
            429,
            'rate_limited',
            sprintf('Too many requests. This key is limited to %d per minute.', $limit),
            ['retry_after' => $retryAfter],
            ['Retry-After' => (string) $retryAfter]
        );
    }

    public static function unavailable(string $message): self
    {
        return new self(503, 'unavailable', $message);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $error = [
            'status'  => $this->status,
            'code'    => $this->errorCode,
            'message' => $this->getMessage(),
        ];

        if ($this->details !== []) {
            $error['details'] = $this->details;
        }

        return ['error' => $error];
    }
}
