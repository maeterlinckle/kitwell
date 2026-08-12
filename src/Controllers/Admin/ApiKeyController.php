<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Api\Gate;
use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\ActivityLog;
use App\Models\ApiKey;
use App\Models\Setting;
use App\Models\User;

/**
 * Issuing and revoking API keys.
 *
 * The one screen in the application that shows a secret, and it shows each one
 * exactly once — on the page immediately after it is created, carried in the
 * session so it survives the redirect and is gone the moment the page renders.
 * There is no "show key" button anywhere, because there is nothing to show: the
 * database holds a SHA-256. Somebody who has lost a key issues another and
 * revokes the old one, which is the right shape for a credential.
 */
final class ApiKeyController extends Controller
{
    private const FRESH_KEY = '__fresh_api_key';

    public function index(): void
    {
        $this->view('admin/api/index', [
            'pageTitle' => 'API keys',
            'keys'      => ApiKey::all(),
            'users'     => ApiKey::candidateUsers(),
            'scopes'    => ApiKey::SCOPES,
            'enabled'   => Gate::isEnabled(),
            'rateLimit' => Gate::rateLimit(),
            'settings'  => Setting::all(),
            // Shown once, straight after it is minted, and never again.
            'freshKey'  => Session::pull(self::FRESH_KEY, null),
        ]);
    }

    /** Turn the whole interface on or off, and set the rate limit. */
    public function updateSettings(): void
    {
        $data = $this->validate([
            'api_rate_limit'       => 'required|integer|min_value:1|max_value:10000',
            'api_default_per_page' => 'required|integer|min_value:1|max_value:1000',
            'api_max_per_page'     => 'required|integer|min_value:1|max_value:1000',
        ], [
            'api_rate_limit'       => 'Requests per minute',
            'api_default_per_page' => 'Default page size',
            'api_max_per_page'     => 'Maximum page size',
        ], '/admin/api');

        if ((int) $data['api_default_per_page'] > (int) $data['api_max_per_page']) {
            $this->failValidation(
                ['api_default_per_page' => 'The default page size cannot be larger than the maximum.'],
                '/admin/api'
            );
        }

        $wasEnabled = Gate::isEnabled();
        $enabled    = Request::boolean('api_enabled');

        Setting::put('api_enabled', $enabled ? '1' : '0');
        Setting::put('api_rate_limit', (string) (int) $data['api_rate_limit']);
        Setting::put('api_default_per_page', (string) (int) $data['api_default_per_page']);
        Setting::put('api_max_per_page', (string) (int) $data['api_max_per_page']);

        if ($wasEnabled !== $enabled) {
            ActivityLog::record(
                'updated',
                'settings',
                null,
                $enabled ? 'Switched the REST API on' : 'Switched the REST API off'
            );
        }

        Flash::success($enabled
            ? 'Saved. The API is answering at ' . rtrim((string) config('app.url'), '/') . '/api/v1.'
            : 'Saved. The API is switched off — every endpoint answers 503 until it is turned back on.');

        Response::redirect('/admin/api');
    }

    public function store(): void
    {
        $data = $this->validate([
            'name'    => 'required|max:120',
            'user_id' => 'required|integer',
            'scope'   => 'required|in:' . implode(',', array_keys(ApiKey::SCOPES)),
            'expires_on' => 'date',
        ], [
            'name'       => 'Key name',
            'user_id'    => 'Acts as',
            'scope'      => 'Access',
            'expires_on' => 'Expiry date',
        ], '/admin/api');

        $user = User::findActive((int) $data['user_id']);

        if ($user === null) {
            $this->failValidation(['user_id' => 'Choose an active user for the key to act as.'], '/admin/api');
        }

        $expiresOn = (string) $data['expires_on'];

        if ($expiresOn !== '' && $expiresOn <= date('Y-m-d')) {
            $this->failValidation(['expires_on' => 'An expiry date has to be in the future.'], '/admin/api');
        }

        $issued = ApiKey::issue(
            (string) $data['name'],
            (int) $user['id'],
            (string) $data['scope'],
            $expiresOn !== '' ? $expiresOn . ' 23:59:59' : null,
            Auth::id()
        );

        ActivityLog::record(
            'created',
            'api_key',
            $issued['id'],
            sprintf(
                'Issued the API key "%s" acting as %s (%s access)',
                $data['name'],
                $user['name'],
                $data['scope']
            )
        );

        // The one place the secret exists. Held in the session across the
        // redirect and pulled by the next render, so a refresh does not show it
        // again and a shared screenshot of the URL carries nothing.
        Session::put(self::FRESH_KEY, [
            'name'  => (string) $data['name'],
            'token' => $issued['token'],
            'user'  => (string) $user['name'],
        ]);

        Flash::success('Key created. Copy it now — it is not shown again.');
        Response::redirect('/admin/api');
    }

    public function revoke(string $id): void
    {
        $key = ApiKey::find((int) $id);

        if ($key === null) {
            $this->notFound('That API key no longer exists.');
        }

        ApiKey::revoke((int) $id);

        ActivityLog::record('revoked', 'api_key', (int) $id, sprintf('Revoked the API key "%s"', $key['name']));

        Flash::success('“' . $key['name'] . '” has been revoked. Any request using it is now refused.');
        Response::redirect('/admin/api');
    }

    /**
     * Delete a key outright.
     *
     * Revoking is the usual answer, because a revoked key keeps its name and
     * last-used time and the log of what it did still reads. Deleting is for
     * tidying up a key issued by mistake and never used.
     */
    public function destroy(string $id): void
    {
        $key = ApiKey::find((int) $id);

        if ($key === null) {
            $this->notFound('That API key no longer exists.');
        }

        ApiKey::delete((int) $id);

        ActivityLog::record('deleted', 'api_key', (int) $id, sprintf('Deleted the API key "%s"', $key['name']));

        Flash::success('“' . $key['name'] . '” has been deleted.');
        Response::redirect('/admin/api');
    }
}
