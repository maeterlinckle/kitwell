<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Services\AssetTagger;
use App\Services\Branding;
use App\Services\TwoFactor;

final class SettingsController extends Controller
{
    public function edit(): void
    {
        $this->view('admin/settings/index', [
            'pageTitle' => 'Settings',
            'settings'  => Setting::all(),
            'nextTag'   => AssetTagger::next(),
            // Whether the site-wide requirement can be switched on at all —
            // the form says why not, rather than offering a control that fails.
            'canRequireTwoFactor' => TwoFactor::canEnforceSiteWide(),
            'logos'     => [
                // Setting::path(), not resolve(): this page must show what is
                // actually stored in each slot, not what stands in for it.
                'light' => Branding::path('light') === null ? null : Branding::url('light'),
                'dark'  => Branding::path('dark') === null ? null : Branding::url('dark'),
            ],
        ]);
    }

    /** Upload one or both logo variants. */
    public function updateLogo(): void
    {
        $saved  = [];
        $failed = [];

        foreach (Branding::VARIANTS as $variant) {
            $result = Branding::acceptUpload($variant);

            if (!$result['provided']) {
                continue;
            }

            if ($result['error'] === null) {
                $saved[] = $variant;
            } else {
                $failed[] = $result['error'];
            }
        }

        foreach ($failed as $problem) {
            Flash::error($problem);
        }

        if ($saved !== []) {
            ActivityLog::record(
                'branding_updated',
                'setting',
                null,
                'Uploaded the ' . implode(' and ', $saved) . ' mode logo'
            );

            Flash::success(count($saved) === 2
                ? 'Both logos updated.'
                : 'The ' . $saved[0] . ' mode logo was updated.');
        } elseif ($failed === []) {
            Flash::info('No file was chosen, so nothing changed.');
        }

        Response::redirect('/admin/settings');
    }

    public function removeLogo(string $variant): void
    {
        if (!in_array($variant, Branding::VARIANTS, true)) {
            $this->notFound();
        }

        Branding::remove($variant);

        ActivityLog::record('branding_updated', 'setting', null, 'Removed the ' . $variant . ' mode logo');

        Flash::success('The ' . $variant . ' mode logo was removed.');
        Response::redirect('/admin/settings');
    }

    public function update(): void
    {
        $data = $this->validate([
            'asset_tag_prefix'     => 'max:20',
            'asset_tag_pad'        => 'required|integer|min_value:1|max_value:12',
            'organisation_name'    => 'max:120',
            'organisation_address' => 'max:500',
            'maintenance_due_days' => 'required|integer|min_value:1|max_value:365',
            'pat_due_days'         => 'required|integer|min_value:1|max_value:365',
            'pat_default_interval_months' => 'required|integer|min_value:1|max_value:120',
            'pat_guide_insulation_mohm'   => 'required|numeric|min_value:0|max_value:1000',
            'pat_guide_earth_base_ohm'    => 'required|numeric|min_value:0|max_value:100',
            'pat_guide_earth_lead_ohm'    => 'required|numeric|min_value:0|max_value:100',
            'pat_guide_earth_lead_metres' => 'required|numeric|min_value:0.1|max_value:1000',
            'pat_guide_leakage_class1_ma' => 'required|numeric|min_value:0|max_value:1000',
            'pat_guide_leakage_class2_ma' => 'required|numeric|min_value:0|max_value:1000',
            'flash_auto_hide_seconds'     => 'required|integer|min_value:0|max_value:120',
            'trusted_device_days'         => 'required|integer|min_value:1|max_value:365',
            'trusted_device_idle_days'    => 'required|integer|min_value:1|max_value:365',
            'email_otp_minutes'           => 'required|integer|min_value:1|max_value:60',
            'two_factor_max_attempts'     => 'required|integer|min_value:3|max_value:10',
        ], [
            'asset_tag_prefix'     => 'Asset tag prefix',
            'asset_tag_pad'        => 'Number padding',
            'organisation_name'    => 'Organisation name',
            'organisation_address' => 'Organisation address',
            'maintenance_due_days' => 'Maintenance “due soon” window',
            'pat_due_days'         => 'PAT “due soon” window',
            'pat_default_interval_months' => 'Default PAT retest interval',
            'pat_guide_insulation_mohm'   => 'Insulation resistance guideline',
            'pat_guide_earth_base_ohm'    => 'Earth continuity guideline',
            'pat_guide_earth_lead_ohm'    => 'Earth continuity lead allowance',
            'pat_guide_earth_lead_metres' => 'Earth continuity lead length',
            'pat_guide_leakage_class1_ma' => 'Leakage guideline (Class I)',
            'pat_guide_leakage_class2_ma' => 'Leakage guideline (Class II)',
            'flash_auto_hide_seconds'     => 'Confirmation banner timeout',
            'trusted_device_days'         => 'Trusted device duration',
            'trusted_device_idle_days'    => 'Trusted device inactivity window',
            'email_otp_minutes'           => 'Emailed code lifetime',
            'two_factor_max_attempts'     => 'Code attempts allowed',
        ], '/admin/settings');

        $prefix = (string) $data['asset_tag_prefix'];

        // The tag ends up in a Code 128 barcode, so keep it to printable ASCII.
        if ($prefix !== '' && preg_match('/^[A-Za-z0-9\-_\/.]+$/', $prefix) !== 1) {
            $this->failValidation(
                ['asset_tag_prefix' => 'Use only letters, numbers, and - _ / . so the tag stays scannable.'],
                '/admin/settings'
            );
        }

        $before = Setting::all();

        Setting::put('asset_tag_prefix', $prefix);
        Setting::put('asset_tag_pad', (string) (int) $data['asset_tag_pad']);
        Setting::put('organisation_name', (string) $data['organisation_name']);
        Setting::put('organisation_address', (string) $data['organisation_address']);
        Setting::put('label_show_name', Request::boolean('label_show_name') ? '1' : '0');
        Setting::put('label_show_location', Request::boolean('label_show_location') ? '1' : '0');
        Setting::put('maintenance_due_days', (string) (int) $data['maintenance_due_days']);
        Setting::put('pat_due_days', (string) (int) $data['pat_due_days']);
        Setting::put('pat_default_interval_months', (string) (int) $data['pat_default_interval_months']);

        // Guideline pass ranges shown to the tester. Guidance only � nothing
        // compares a reading against these to decide a result.
        foreach (['pat_guide_insulation_mohm', 'pat_guide_earth_base_ohm', 'pat_guide_earth_lead_ohm',
                  'pat_guide_earth_lead_metres', 'pat_guide_leakage_class1_ma', 'pat_guide_leakage_class2_ma'] as $key) {
            Setting::put($key, (string) (float) $data[$key]);
        }

        Setting::put('flash_auto_hide_seconds', (string) (int) $data['flash_auto_hide_seconds']);

        // Two-factor policy. Requiring it site-wide is refused unless a code can
        // actually be delivered: with no authenticator enrolled and no SMTP,
        // turning it on would lock every account out of the application at once,
        // including the administrator doing the turning on.
        $requireTwoFactor = Request::boolean('two_factor_required');

        if ($requireTwoFactor && !TwoFactor::canEnforceSiteWide()) {
            $this->failValidation([
                'two_factor_required' => 'Email is not configured, so users without an authenticator app '
                    . 'would have no way to receive a code — and no way to sign in. Set up email first.',
            ], '/admin/settings');
        }

        Setting::put('two_factor_required',      $requireTwoFactor ? '1' : '0');
        Setting::put('trusted_device_days',      (string) (int) $data['trusted_device_days']);
        Setting::put('email_otp_minutes',        (string) (int) $data['email_otp_minutes']);
        Setting::put('two_factor_max_attempts',  (string) (int) $data['two_factor_max_attempts']);

        // An idle window longer than the outer limit would never be reached, so
        // it is capped rather than saved as written — the two are a pair.
        Setting::put(
            'trusted_device_idle_days',
            (string) min((int) $data['trusted_device_idle_days'], (int) $data['trusted_device_days'])
        );

        ActivityLog::record('updated', 'settings', null, 'Updated application settings', [
            'before' => array_intersect_key($before, array_flip(['asset_tag_prefix', 'asset_tag_pad', 'organisation_name'])),
            'after'  => ['asset_tag_prefix' => $prefix, 'asset_tag_pad' => (int) $data['asset_tag_pad'], 'organisation_name' => $data['organisation_name']],
        ]);

        Flash::success('Settings saved. The next asset tag will be ' . AssetTagger::next() . '.');
        Response::redirect('/admin/settings');
    }
}
