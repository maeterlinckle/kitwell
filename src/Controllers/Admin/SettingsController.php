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

final class SettingsController extends Controller
{
    public function edit(): void
    {
        $this->view('admin/settings/index', [
            'pageTitle' => 'Settings',
            'settings'  => Setting::all(),
            'nextTag'   => AssetTagger::next(),
        ]);
    }

    public function update(): void
    {
        $data = $this->validate([
            'asset_tag_prefix'     => 'max:20',
            'asset_tag_pad'        => 'required|integer|min_value:1|max_value:12',
            'organisation_name'    => 'max:120',
            'maintenance_due_days' => 'required|integer|min_value:1|max_value:365',
            'pat_due_days'         => 'required|integer|min_value:1|max_value:365',
            'pat_default_interval_months' => 'required|integer|min_value:1|max_value:120',
        ], [
            'asset_tag_prefix'     => 'Asset tag prefix',
            'asset_tag_pad'        => 'Number padding',
            'organisation_name'    => 'Organisation name',
            'maintenance_due_days' => 'Maintenance “due soon” window',
            'pat_due_days'         => 'PAT “due soon” window',
            'pat_default_interval_months' => 'Default PAT retest interval',
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
        Setting::put('label_show_name', Request::boolean('label_show_name') ? '1' : '0');
        Setting::put('label_show_location', Request::boolean('label_show_location') ? '1' : '0');
        Setting::put('maintenance_due_days', (string) (int) $data['maintenance_due_days']);
        Setting::put('pat_due_days', (string) (int) $data['pat_due_days']);
        Setting::put('pat_default_interval_months', (string) (int) $data['pat_default_interval_months']);

        ActivityLog::record('updated', 'settings', null, 'Updated application settings', [
            'before' => array_intersect_key($before, array_flip(['asset_tag_prefix', 'asset_tag_pad', 'organisation_name'])),
            'after'  => ['asset_tag_prefix' => $prefix, 'asset_tag_pad' => (int) $data['asset_tag_pad'], 'organisation_name' => $data['organisation_name']],
        ]);

        Flash::success('Settings saved. The next asset tag will be ' . AssetTagger::next() . '.');
        Response::redirect('/admin/settings');
    }
}
