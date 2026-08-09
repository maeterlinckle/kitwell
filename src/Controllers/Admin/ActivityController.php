<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Request;
use App\Models\ActivityLog;
use App\Models\User;

final class ActivityController extends Controller
{
    public function index(): void
    {
        $filters = [
            'entity_type' => (string) Request::query('type', ''),
            'user_id'     => (string) Request::query('user', ''),
        ];

        $this->view('admin/activity/index', [
            'pageTitle' => 'Activity log',
            'entries'   => ActivityLog::recent(100, array_filter($filters)),
            'users'     => User::all(),
            'filters'   => $filters,
            'total'     => ActivityLog::countAll(),
        ]);
    }
}
