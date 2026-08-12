<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\CalendarFeed;

/**
 * Personal calendar subscriptions.
 *
 * The feed route is deliberately outside the `auth` middleware group: a
 * calendar client cannot complete an interactive sign-in, so the 64-character
 * token in the URL is the credential. Everything that decides *what* the feed
 * contains still runs through the ordinary permission model — see
 * App\Services\CalendarFeed.
 *
 * The two management actions are the opposite: fully authenticated, and scoped
 * to the signed-in user only. There is no way here to see or regenerate
 * anybody else's token, including for an administrator, because a feed URL is
 * a credential and handing one person another's is not an administrative task.
 */
final class CalendarController extends Controller
{
    /** GET /calendar/{token}.ics — no session, authenticated by the token. */
    public function feed(string $token): void
    {
        $token = strtolower(trim($token));

        // Check the shape before touching the database: a token is always 64
        // hex characters, so anything else is a probe, not a typo.
        if (strlen($token) !== 64 || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            $this->feedNotFound();
        }

        $user = User::findByCalendarToken($token);

        if ($user === null) {
            $this->feedNotFound();
        }

        $body = CalendarFeed::build($user);

        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: inline; filename="kitwell.ics"');
        header('Content-Length: ' . strlen($body));
        // Private, and short-lived: a client that polls more often than this
        // gets a cached copy, and a revoked token stops working within minutes
        // rather than whenever the client decides to re-ask.
        header('Cache-Control: private, max-age=300');
        header('X-Robots-Tag: noindex, nofollow');

        echo $body;
    }

    /**
     * A plain 404 for a bad token, in plain text.
     *
     * Not the styled HTML error page: the only thing reading this is a
     * calendar client, and it says nothing about whether the token existed,
     * expired or was never valid.
     */
    private function feedNotFound(): never
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "No calendar feed found for that address.\n";
        exit;
    }

    /** GET /profile/calendar */
    public function show(): void
    {
        $user = Auth::user();

        $this->view('profile/calendar', [
            'pageTitle' => 'Calendar feed',
            'user'      => $user,
            'feedUrl'   => $user['calendar_token'] === null ? null : self::feedUrl((string) $user['calendar_token']),
            'contents'  => CalendarFeed::describe((int) $user['id']),
        ]);
    }

    /** POST /profile/calendar — create a token, or replace the current one. */
    public function regenerate(): void
    {
        $user   = Auth::user();
        $id     = (int) $user['id'];
        $isNew  = $user['calendar_token'] === null;

        User::regenerateCalendarToken($id);

        ActivityLog::record(
            $isNew ? 'created' : 'updated',
            'user',
            $id,
            $isNew ? 'Created their calendar feed link' : 'Regenerated their calendar feed link'
        );

        Flash::success($isNew
            ? 'Your calendar link is ready. Add it to your calendar app to see your dates.'
            : 'A new link has been created. The old one has stopped working — update any calendar already subscribed to it.');

        Response::redirect('/profile/calendar');
    }

    /** POST /profile/calendar/revoke */
    public function revoke(): void
    {
        $user = Auth::user();
        $id   = (int) $user['id'];

        User::update($id, ['calendar_token' => null, 'calendar_token_created_at' => null]);

        ActivityLog::record('deleted', 'user', $id, 'Revoked their calendar feed link');
        Flash::success('Your calendar link has been switched off. Any calendar subscribed to it will stop updating.');

        Response::redirect('/profile/calendar');
    }

    /**
     * The full, absolute feed URL.
     *
     * APP_URL is preferred, because the address a user copies into their phone
     * has to work from outside — the host header on the request they are
     * looking at might be an internal name or a proxy's.
     */
    public static function feedUrl(string $token): string
    {
        $base = rtrim((string) Config::get('app.url', ''), '/');

        if ($base === '') {
            $base = (Request::isSecure() ? 'https://' : 'http://') . Request::host() . Request::basePath();
        }

        return $base . '/calendar/' . $token . '.ics';
    }
}
