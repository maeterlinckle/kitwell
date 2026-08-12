<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\Flash;
use App\Core\QrCode;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Totp;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\TwoFactor;

/**
 * "My account → Security": a user managing their own second factor.
 *
 * Everything here is about the signed-in user and nobody else. There is no
 * route by which one person reaches another's enrolment, for the same reason
 * there is none for somebody else's calendar token — an administrator can
 * *remove* a second factor from the Users page when a phone is lost, and that
 * is a different act, logged as one.
 *
 * The secret being enrolled is held in the session between showing the QR code
 * and confirming it, not written to the user's row. Half-finished enrolment
 * must not leave a secret on an account whose owner never scanned it: that is
 * a credential nobody knows about, and it would make `totp_secret` present
 * while `totp_confirmed_at` is null mean two different things.
 */
final class SecurityController extends Controller
{
    private const PENDING_SECRET = '__totp_setup_secret';

    public function index(): void
    {
        $user = Auth::user();

        $this->view('profile/security', [
            'pageTitle'      => 'Security',
            'user'           => $user,
            'hasTotp'        => TwoFactor::hasTotp($user),
            'enabled'        => TwoFactor::isEnabled($user),
            'required'       => TwoFactor::requiredForEveryone(),
            'emailAvailable' => TwoFactor::emailAvailable(),
            'backupCodes'    => TwoFactor::backupCodesLeft((int) $user['id']),
            'devices'        => TrustedDevice::forUser((int) $user['id']),
            'trustDays'      => TrustedDevice::days(),
            'idleDays'       => TrustedDevice::idleDays(),
            // Shown once, straight after they are generated, and never again.
            'freshCodes'     => Session::pull('__fresh_backup_codes', []),
        ]);
    }

    /** Begin enrolment: a new secret, a QR code, and nothing written yet. */
    public function startTotp(): void
    {
        if (!Crypto::isAvailable() || !Crypto::hasKey()) {
            Flash::error('APP_KEY is not set, so a secret cannot be stored securely. Ask an administrator to run “php bin/console.php key:generate”.');
            Response::redirect('/profile/security');
        }

        $user   = Auth::user();
        $secret = Totp::generateSecret();

        Session::put(self::PENDING_SECRET, $secret);

        $issuer = (string) (Setting::get('organisation_name', '') ?: Config::get('app.name', 'Kitwell'));
        $uri    = Totp::uri($secret, (string) $user['email'], $issuer);

        $this->view('profile/two-factor-setup', [
            'pageTitle' => 'Set up an authenticator app',
            'secret'    => Totp::formatSecret($secret),
            'uri'       => $uri,
            'issuer'    => $issuer,
        ]);
    }

    /** Finish enrolment: prove a code from the app before anything is saved. */
    public function confirmTotp(): void
    {
        $user   = Auth::user();
        $secret = Session::get(self::PENDING_SECRET);

        if (!is_string($secret) || $secret === '') {
            Flash::error('That setup was not finished in time. Start again.');
            Response::redirect('/profile/security');
        }

        if (!Totp::verify($secret, (string) Request::post('code', ''))) {
            Flash::error('That code was not right. Check the time on your phone is correct, then try the next one.');
            Response::redirect('/profile/security/totp');
        }

        if (!TwoFactor::storeSecret((int) $user['id'], $secret)) {
            Flash::error('The secret could not be encrypted, so it has not been saved. Check APP_KEY.');
            Response::redirect('/profile/security');
        }

        Session::forget(self::PENDING_SECRET);
        TwoFactor::confirmTotp((int) $user['id']);

        // Issued at enrolment, not on request: the moment somebody needs a
        // backup code is the moment they cannot get in to generate one.
        Session::put('__fresh_backup_codes', TwoFactor::issueBackupCodes((int) $user['id']));

        ActivityLog::record(
            'two_factor_enabled',
            'user',
            (int) $user['id'],
            'Set up an authenticator app for two-factor authentication'
        );

        Flash::success('Two-factor authentication is on. Keep the backup codes below somewhere safe.');
        Response::redirect('/profile/security');
    }

    /** Turn on email codes without an authenticator app. */
    public function enableEmail(): void
    {
        $user = Auth::user();

        if (!TwoFactor::emailAvailable()) {
            Flash::error('Email is not configured on this server, so codes cannot be sent.');
            Response::redirect('/profile/security');
        }

        TwoFactor::enableEmailOnly((int) $user['id']);
        Session::put('__fresh_backup_codes', TwoFactor::issueBackupCodes((int) $user['id']));

        ActivityLog::record('two_factor_enabled', 'user', (int) $user['id'], 'Turned on two-factor authentication by email');

        Flash::success('Two-factor authentication is on. You will be emailed a code when you sign in.');
        Response::redirect('/profile/security');
    }

    public function disable(): void
    {
        $user = Auth::user();

        if (TwoFactor::requiredForEveryone()) {
            Flash::error('Two-factor authentication is required for everyone on this site, so it cannot be switched off.');
            Response::redirect('/profile/security');
        }

        TwoFactor::disable((int) $user['id']);
        TwoFactor::clearDeviceCookie();

        ActivityLog::record(
            'two_factor_disabled',
            'user',
            (int) $user['id'],
            'Turned off two-factor authentication; backup codes and trusted devices were cleared'
        );

        Flash::success('Two-factor authentication is off. Your backup codes and trusted devices have been cleared.');
        Response::redirect('/profile/security');
    }

    public function regenerateBackupCodes(): void
    {
        $user = Auth::user();

        if (!TwoFactor::isEnabled($user)) {
            Response::redirect('/profile/security');
        }

        Session::put('__fresh_backup_codes', TwoFactor::issueBackupCodes((int) $user['id']));

        ActivityLog::record('backup_codes_issued', 'user', (int) $user['id'], 'Generated a new set of backup codes');

        Flash::success('New backup codes. The previous set no longer works.');
        Response::redirect('/profile/security');
    }

    public function forgetDevice(string $id): void
    {
        $user   = Auth::user();
        $device = null;

        foreach (TrustedDevice::forUser((int) $user['id']) as $row) {
            if ((int) $row['id'] === (int) $id) {
                $device = $row;
                break;
            }
        }

        if ($device === null) {
            Flash::error('That device is no longer on the list.');
            Response::redirect('/profile/security');
        }

        TrustedDevice::forget((int) $id);

        ActivityLog::record('device_forgotten', 'user', (int) $user['id'], 'Stopped trusting ' . $device['label']);

        Flash::success('“' . $device['label'] . '” will be asked for a code next time.');
        Response::redirect('/profile/security');
    }

    public function forgetAllDevices(): void
    {
        $user = Auth::user();

        TrustedDevice::forgetAll((int) $user['id']);
        TwoFactor::clearDeviceCookie();

        ActivityLog::record('devices_forgotten', 'user', (int) $user['id'], 'Stopped trusting every device');

        Flash::success('Every device will be asked for a code next time, including this one.');
        Response::redirect('/profile/security');
    }

    /**
     * An administrator removing somebody's second factor.
     *
     * The lost-phone path. Deliberately a *removal* and not a reset — it leaves
     * the account able to sign in with a password, and if 2FA is required
     * site-wide the next sign-in walks them through enrolling again.
     */
    public function adminReset(string $id): void
    {
        Auth::authorize('users.manage');

        $target = User::find((int) $id);

        if ($target === null) {
            Flash::error('That user no longer exists.');
            Response::redirect('/admin/users');
        }

        TwoFactor::disable((int) $id);

        ActivityLog::record(
            'two_factor_reset',
            'user',
            (int) $id,
            sprintf('Removed two-factor authentication from %s (secret, backup codes and trusted devices)', $target['name'])
        );

        Flash::success($target['name'] . ' can sign in with just their password now. '
            . (TwoFactor::requiredForEveryone()
                ? 'They will be asked to set it up again at their next sign-in.'
                : 'Ask them to set it up again from their account page.'));

        Response::redirect('/admin/users/' . (int) $id . '/edit');
    }
}
