<?php

declare(strict_types=1);

namespace App\Api;

use App\Core\Auth;
use App\Core\Request;
use App\Models\ApiKey;
use App\Models\Setting;
use App\Models\User;

/**
 * Turning an API key into a signed-in user.
 *
 * The single most important line in this file is `Auth::actAs($user)`. From
 * that point the request is, to every permission check in the application,
 * indistinguishable from the same person using the browser: `Auth::can()` reads
 * the same roles and grants, `Auth::id()` stamps the same `created_by`, and
 * `ActivityLog::record()` writes the same audit row. There is no second
 * permission model to keep in step, because there is no second permission
 * model — which is the only way to actually mean "a key never allows more than
 * that user could already do through the interface".
 *
 * A key may allow *less*: `scope = 'read'` refuses anything but GET.
 */
final class Gate
{
    /** @var array<string,mixed>|null The key this request authenticated with. */
    private static ?array $key = null;

    /** @var array<string,int>|null The rate-limit state for this request. */
    private static ?array $rate = null;

    public static function isEnabled(): bool
    {
        return Setting::bool('api_enabled', false);
    }

    public static function rateLimit(): int
    {
        return max(1, min(10000, Setting::int('api_rate_limit', 120)));
    }

    /** @return array<string,mixed>|null */
    public static function key(): ?array
    {
        return self::$key;
    }

    /** @return array<string,int>|null */
    public static function rateState(): ?array
    {
        return self::$rate;
    }

    /**
     * Authenticate the request, or throw.
     *
     * Two ways in, and they are not equals:
     *
     *  - **An API key**, which is the interface's purpose.
     *  - **An existing browser session**, accepted so the built-in
     *    documentation page can call the live endpoints from the same browser
     *    the reader is already signed into. Without it, "try it out" would ask
     *    somebody to mint a key before they could see whether the API was worth
     *    using. A session request is subject to CSRF nowhere here because it is
     *    read-only: session-authenticated calls are refused anything but GET,
     *    so a cross-site form post cannot reach a writing endpoint.
     */
    public static function authenticate(): void
    {
        if (!self::isEnabled()) {
            throw Problem::unavailable(
                'The API is switched off. An administrator can enable it under Settings → API keys.'
            );
        }

        $presented = self::presentedToken();

        if ($presented !== null) {
            self::authenticateKey($presented);

            return;
        }

        if (Auth::check()) {
            if (Request::method() !== 'GET') {
                throw Problem::forbidden(
                    'A browser session may only read from the API. Use an API key for anything that writes.'
                );
            }

            return;
        }

        throw Problem::unauthorised(
            'Send an API key as “Authorization: Bearer ark_…”, or open this from a signed-in browser to read.'
        );
    }

    private static function authenticateKey(string $presented): void
    {
        $key = ApiKey::findByToken($presented);

        if ($key === null) {
            throw Problem::unauthorised('That API key was not recognised.');
        }

        $reason = ApiKey::unusableReason($key);

        if ($reason !== null) {
            // A specific reason on purpose. The key is already proven genuine by
            // this point — saying "revoked" rather than "unrecognised" tells the
            // holder something they are entitled to know and reveals nothing to
            // anyone who does not hold it.
            throw Problem::unauthorised($reason);
        }

        if ((string) $key['scope'] === 'read' && Request::method() !== 'GET') {
            throw Problem::forbidden('This API key is read-only. Issue a full-access key to write.');
        }

        $rate = ApiKey::countRequest((int) $key['id'], self::rateLimit());
        self::$rate = [
            'limit'     => $rate['limit'],
            'remaining' => $rate['remaining'],
            'reset_in'  => $rate['reset_in'],
        ];

        if (!$rate['allowed']) {
            throw Problem::rateLimited($rate['reset_in'], $rate['limit']);
        }

        $user = User::findActive((int) $key['user_id']);

        if ($user === null) {
            throw Problem::unauthorised('The account this API key belongs to is no longer active.');
        }

        self::$key = $key;

        // From here the request *is* that user.
        Auth::actAs($user);
    }

    /** The key from the request, from either accepted header. */
    private static function presentedToken(): ?string
    {
        $authorization = trim((string) Request::header('Authorization'));

        if ($authorization !== '' && preg_match('/^Bearer\s+(\S+)$/i', $authorization, $m) === 1) {
            return $m[1];
        }

        $header = trim((string) Request::header('X-API-Key'));

        return $header !== '' ? $header : null;
    }

    /**
     * Refuse unless the acting user holds the permission.
     *
     * `Auth::can()`, never `Auth::authorize()` — the latter renders an HTML
     * error page, which is the wrong answer to a request that asked for JSON.
     */
    public static function require(string $permission): void
    {
        if (!Auth::can($permission)) {
            throw Problem::forbidden(sprintf(
                'Your role does not include “%s”, which this endpoint requires.',
                $permission
            ));
        }
    }
}
