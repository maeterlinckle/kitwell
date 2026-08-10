<?php

declare(strict_types=1);

/*
 * Demo/seed data so the application can be exercised immediately.
 *
 *   php bin/seed.php            add demo data (skips if assets already exist)
 *   php bin/seed.php --force    add it anyway
 *
 * Demo accounts all use the password below. Never run this on a live system.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Models\Role;

const DEMO_PASSWORD = 'Workshop!Demo2026';

$force = in_array('--force', $argv, true);

if (Config::get('app.env') === 'production' && !$force) {
    echo "APP_ENV is 'production'. Re-run with --force if you really want demo data.\n";
    exit(1);
}

$existing = (int) Database::scalar('SELECT COUNT(*) FROM assets');
if ($existing > 0 && !$force) {
    echo "Assets already exist ({$existing} rows). Re-run with --force to seed anyway.\n";
    exit(0);
}

echo "Seeding demo data...\n";

Database::beginTransaction();

try {
    // --- Users -------------------------------------------------------------
    $roleIds = [];
    foreach (Role::all() as $role) {
        $roleIds[$role['slug']] = (int) $role['id'];
    }

    if ($roleIds === []) {
        throw new RuntimeException('No roles found — run `php bin/migrate.php` first.');
    }

    $hash = password_hash(DEMO_PASSWORD, PASSWORD_DEFAULT);

    $demoUsers = [
        ['Alex Admin',      'admin@example.com',    'admin',    'Facilities Manager'],
        ['Sam Staff',       'manager@example.com',  'manager',  'Workshop Supervisor'],
        ['Val Viewer',      'viewer@example.com',   'viewer',   'Office Administrator'],
        ['Bailey Borrower', 'borrower@example.com', 'borrower',  null],
    ];

    $userIds = [];
    foreach ($demoUsers as [$name, $email, $roleSlug, $jobTitle]) {
        $found = Database::selectOne('SELECT id FROM users WHERE email = ?', [$email]);

        if ($found !== null) {
            $userIds[$roleSlug] = (int) $found['id'];
            continue;
        }

        $userIds[$roleSlug] = Database::insert('users', [
            'name'          => $name,
            'email'         => $email,
            'password_hash' => $hash,
            'role_id'       => $roleIds[$roleSlug],
            'is_active'     => 1,
            'job_title'     => $jobTitle,
        ]);
    }

    $admin   = $userIds['admin'];
    $manager = $userIds['manager'];

    // --- Categories --------------------------------------------------------
    $categoryIds = [];
    $categories  = [
        ['Power Tools',        'power-tools',        'Corded and cordless hand-held power tools.'],
        ['Hand Tools',         'hand-tools',         'Non-powered tools.'],
        ['Test Equipment',     'test-equipment',     'Meters, testers and calibration gear.'],
        ['IT Equipment',       'it-equipment',       'Computers, monitors and networking.'],
        ['Furniture',          'furniture',          'Desks, chairs, storage.'],
        ['Access Equipment',   'access-equipment',   'Ladders, steps, towers.'],
        ['Workshop Machinery', 'workshop-machinery', 'Fixed and bench-mounted machines.'],
    ];

    foreach ($categories as [$name, $slug, $description]) {
        $found = Database::selectOne('SELECT id FROM categories WHERE slug = ?', [$slug]);
        $categoryIds[$slug] = $found !== null
            ? (int) $found['id']
            : Database::insert('categories', ['name' => $name, 'slug' => $slug, 'description' => $description]);
    }

    // --- Locations ---------------------------------------------------------
    $locationIds = [];
    $mainWorkshop = Database::insert('locations', ['name' => 'Main Workshop', 'code' => 'WS', 'description' => 'Ground floor workshop.']);
    $locationIds['workshop']  = $mainWorkshop;
    $locationIds['bench3']    = Database::insert('locations', ['name' => 'Bench 3', 'code' => 'WS-B3', 'parent_id' => $mainWorkshop]);
    $locationIds['toolstore'] = Database::insert('locations', ['name' => 'Tool Store', 'code' => 'TS', 'description' => 'Locked tool cage.']);
    $locationIds['office']    = Database::insert('locations', ['name' => 'Office', 'code' => 'OF']);
    $locationIds['van']       = Database::insert('locations', ['name' => 'Van 1', 'code' => 'VAN1', 'description' => 'Mobile stock.']);

    // --- Assets ------------------------------------------------------------
    $today = new DateTimeImmutable('today');

    /** @var array<string,int> $assetIds */
    $assetIds = [];

    $assets = [
        [
            'key' => 'drill',
            'asset_tag' => 'AST-0001', 'name' => 'Makita DHP484 Combi Drill',
            'description' => '18V brushless combi drill, supplied with two batteries and a fast charger.',
            'category_id' => $categoryIds['power-tools'], 'location_id' => $locationIds['toolstore'],
            'condition_rating' => 'Good', 'status' => 'In Stock',
            'purchase_date' => '2023-04-18', 'purchase_cost' => 149.99, 'current_value' => 95.00,
            'supplier' => 'Toolstation', 'serial_number' => 'MK-884213-A',
            'manufacturer' => 'Makita', 'model' => 'DHP484Z',
            'manufacturer_url' => 'https://www.makita.co.uk/product/dhp484.html',
            'requires_pat' => 0, 'notes' => 'Battery tool — charger is PAT tested separately.',
        ],
        [
            'key' => 'charger',
            'parent' => 'drill', 'relationship_type' => 'accessory',
            'asset_tag' => 'AST-0002', 'name' => 'Makita DC18RC Fast Charger',
            'description' => 'Mains charger supplied with the DHP484 drill.',
            'category_id' => $categoryIds['power-tools'], 'location_id' => $locationIds['toolstore'],
            'condition_rating' => 'Good', 'status' => 'In Stock',
            'purchase_date' => '2023-04-18', 'purchase_cost' => 0.00,
            'manufacturer' => 'Makita', 'model' => 'DC18RC',
            'requires_pat' => 1, 'plug_fuse_rating_amps' => 3.00, 'appliance_class' => 'Class II', 'has_fuse' => 1, 'load_rating_va' =>  350, 'cable_csa_mm2' => 0.75,
        ],
        [
            'key' => 'battery',
            'parent' => 'drill', 'relationship_type' => 'sub-asset',
            'asset_tag' => 'AST-0003', 'name' => 'Makita BL1850B 5.0Ah Battery',
            'description' => 'Spare 18V lithium-ion battery pack.',
            'category_id' => $categoryIds['power-tools'], 'location_id' => $locationIds['toolstore'],
            'condition_rating' => 'Fair', 'status' => 'In Stock',
            'purchase_date' => '2023-04-18', 'purchase_cost' => 79.00,
            'manufacturer' => 'Makita', 'model' => 'BL1850B', 'requires_pat' => 0,
            'notes' => 'Runtime noticeably down — replace during the next tool review.',
        ],
        [
            'key' => 'grinder',
            'asset_tag' => 'AST-0004', 'name' => 'Bosch GWS 750 Angle Grinder',
            'description' => '115mm corded angle grinder.',
            'category_id' => $categoryIds['power-tools'], 'location_id' => $locationIds['workshop'],
            'condition_rating' => 'Good', 'status' => 'In Maintenance',
            'purchase_date' => '2022-09-02', 'purchase_cost' => 74.50, 'current_value' => 40.00,
            'serial_number' => 'BSH-2209-7741', 'manufacturer' => 'Bosch', 'model' => 'GWS 750-115',
            'manufacturer_url' => 'https://www.bosch-professional.com/gb/en/products/gws-750-115-0601394000',
            'requires_pat' => 1, 'plug_fuse_rating_amps' => 13.00, 'appliance_class' => 'Class I', 'has_fuse' => 1, 'load_rating_va' => 700, 'cable_csa_mm2' => 1.00,
            'notes' => 'Guard replaced March 2026. Brushes due for inspection.',
        ],
        [
            'key' => 'mft',
            'asset_tag' => 'AST-0005', 'name' => 'Fluke 1663 Multifunction Tester',
            'description' => 'Installation tester used for periodic inspection work.',
            'category_id' => $categoryIds['test-equipment'], 'location_id' => $locationIds['van'],
            'condition_rating' => 'Excellent', 'status' => 'On Loan',
            'purchase_date' => '2024-11-11', 'purchase_cost' => 1120.00, 'current_value' => 900.00,
            'serial_number' => 'FLK-1663-00921', 'manufacturer' => 'Fluke', 'model' => '1663 GB',
            'manufacturer_url' => 'https://www.fluke.com/en-gb/product/electrical-testing/installation-testers/fluke-1663',
            'requires_pat' => 1, 'plug_fuse_rating_amps' => 3.00, 'appliance_class' => 'Class II', 'has_fuse' => 1, 'load_rating_va' =>  350, 'cable_csa_mm2' => 0.75,
            'warranty_expires_on' => '2027-11-11',
            'notes' => 'Calibration certificate stored with the manuals.',
        ],
        [
            'key' => 'laptop',
            'asset_tag' => 'AST-0006', 'name' => 'Dell Latitude 5540 Laptop',
            'description' => 'Site laptop used for certification paperwork.',
            'category_id' => $categoryIds['it-equipment'], 'location_id' => $locationIds['office'],
            'condition_rating' => 'Good', 'status' => 'In Stock',
            'purchase_date' => '2024-02-20', 'purchase_cost' => 899.00, 'current_value' => 550.00,
            'serial_number' => 'DL-5540-JX82K', 'manufacturer' => 'Dell', 'model' => 'Latitude 5540',
            'requires_pat' => 1, 'plug_fuse_rating_amps' => 3.00, 'appliance_class' => 'Class II', 'has_fuse' => 1, 'load_rating_va' =>  350, 'cable_csa_mm2' => 0.75,
            // Office IT sits in a benign environment, so it carries a longer
            // retest interval than the site default.
            'pat_interval_months' => 24,
        ],
        [
            'key' => 'ladder',
            'asset_tag' => 'AST-0007', 'name' => 'Werner 3-Section Ladder 3.0m',
            'description' => 'Aluminium triple-extension ladder, EN 131 rated.',
            'category_id' => $categoryIds['access-equipment'], 'location_id' => $locationIds['workshop'],
            'condition_rating' => 'Fair', 'status' => 'In Stock',
            'purchase_date' => '2021-06-30', 'purchase_cost' => 210.00, 'current_value' => 90.00,
            'manufacturer' => 'Werner', 'requires_pat' => 0,
            'notes' => 'Foot rubbers worn — inspect at every LOLER check.',
        ],
        [
            'key' => 'pillardrill',
            'asset_tag' => 'AST-0008', 'name' => 'Axminster Pillar Drill',
            'description' => 'Bench-mounted pillar drill, 16-speed.',
            'category_id' => $categoryIds['workshop-machinery'], 'location_id' => $locationIds['bench3'],
            'condition_rating' => 'Good', 'status' => 'In Stock',
            'purchase_date' => '2020-03-15', 'purchase_cost' => 425.00, 'current_value' => 200.00,
            'manufacturer' => 'Axminster', 'model' => 'AT16B',
            'requires_pat' => 1, 'plug_fuse_rating_amps' => 13.00, 'appliance_class' => 'Class I', 'has_fuse' => 1, 'load_rating_va' => 700, 'cable_csa_mm2' => 1.50,
            'is_loanable' => 0, 'notes' => 'Fixed to the bench — not available for loan.',
        ],
        [
            'key' => 'chair',
            'asset_tag' => 'AST-0009', 'name' => 'Office Task Chair',
            'description' => 'Height-adjustable task chair, office desk 4.',
            'category_id' => $categoryIds['furniture'], 'location_id' => $locationIds['office'],
            'condition_rating' => 'Poor', 'status' => 'Retired',
            'purchase_date' => '2018-01-09', 'purchase_cost' => 145.00,
            'requires_pat' => 0, 'is_loanable' => 0, 'retired_on' => '2026-05-30',
            'notes' => 'Gas strut failed. Retired and awaiting disposal.',
        ],
        [
            'key' => 'sds',
            'asset_tag' => 'AST-0010', 'name' => 'DeWalt D25133K SDS Hammer Drill',
            'description' => '800W SDS-plus rotary hammer with carry case.',
            'category_id' => $categoryIds['power-tools'], 'location_id' => $locationIds['van'],
            'condition_rating' => 'Good', 'status' => 'On Loan',
            'purchase_date' => '2023-10-05', 'purchase_cost' => 189.00, 'current_value' => 130.00,
            'serial_number' => 'DW-25133-4471', 'manufacturer' => 'DeWalt', 'model' => 'D25133K',
            'manufacturer_url' => 'https://www.dewalt.co.uk/product/d25133k-gb/',
            'requires_pat' => 1, 'plug_fuse_rating_amps' => 13.00, 'appliance_class' => 'Class I', 'has_fuse' => 1, 'load_rating_va' => 700, 'cable_csa_mm2' => 1.50,
        ],
    ];

    foreach ($assets as $asset) {
        $key    = $asset['key'];
        $parent = $asset['parent'] ?? null;

        unset($asset['key'], $asset['parent']);

        $asset['parent_asset_id'] = $parent === null ? null : $assetIds[$parent];
        $asset['created_by']      = $manager;
        $asset['updated_by']      = $manager;

        $assetIds[$key] = Database::insert('assets', $asset);
    }

    // --- Maintenance -------------------------------------------------------
    $scheduleId = Database::insert('maintenance_schedules', [
        'asset_id'            => $assetIds['grinder'],
        'title'               => 'Brush and guard inspection',
        'maintenance_type'    => 'periodic',
        'frequency_interval'  => 6,
        'frequency_unit'      => 'months',
        'next_due_date'       => $today->modify('+18 days')->format('Y-m-d'),
        'last_completed_date' => $today->modify('-5 months')->format('Y-m-d'),
        'assigned_to_user_id' => $manager,
        'instructions'        => "Check carbon brushes for wear.\nCheck the guard rotates and locks.\nBlow out the motor housing.",
        'estimated_minutes'   => 30,
        'created_by'          => $manager,
    ]);

    Database::insert('maintenance_schedules', [
        'asset_id'            => $assetIds['ladder'],
        'title'               => 'Pre-use ladder inspection (formal)',
        'maintenance_type'    => 'routine',
        'frequency_interval'  => 3,
        'frequency_unit'      => 'months',
        'next_due_date'       => $today->modify('-9 days')->format('Y-m-d'), // deliberately overdue
        'last_completed_date' => $today->modify('-3 months')->format('Y-m-d'),
        'assigned_to_user_id' => $manager,
        'instructions'        => 'Inspect stiles, rungs, feet and locking hooks. Record on the ladder register.',
        'created_by'          => $manager,
    ]);

    Database::insert('maintenance_schedules', [
        'asset_id'           => $assetIds['pillardrill'],
        'title'              => 'Annual service and belt check',
        'maintenance_type'   => 'periodic',
        'frequency_interval' => 1,
        'frequency_unit'     => 'years',
        'next_due_date'      => $today->modify('+2 months')->format('Y-m-d'),
        'created_by'         => $manager,
    ]);

    // A one-off job, still open.
    Database::insert('maintenance_schedules', [
        'asset_id'          => $assetIds['mft'],
        'title'             => 'Send away for calibration',
        'maintenance_type'  => 'ad-hoc',
        'next_due_date'     => $today->modify('+45 days')->format('Y-m-d'),
        'assigned_to_user_id' => $admin,
        'instructions'      => "Book in with the calibration house before the certificate expires.\nKeep the certificate with the manuals.",
        'estimated_minutes' => 30,
        'created_by'        => $manager,
    ]);

    // A routine weekly check with no date set yet, to exercise "Unscheduled".
    Database::insert('maintenance_schedules', [
        'asset_id'           => $assetIds['laptop'],
        'title'              => 'Weekly backup check',
        'maintenance_type'   => 'routine',
        'frequency_interval' => 1,
        'frequency_unit'     => 'weeks',
        'created_by'         => $manager,
    ]);

    Database::insert('maintenance_logs', [
        'asset_id'             => $assetIds['grinder'],
        'schedule_id'          => $scheduleId,
        'maintenance_type'     => 'repair',
        'performed_on'         => $today->modify('-5 months')->format('Y-m-d'),
        'performed_by_user_id' => $manager,
        'work_done'            => 'Replaced cracked wheel guard and re-tested. Brushes measured within limits.',
        'parts_used'           => 'Bosch guard 1619P06 x1',
        'cost'                 => 18.40,
        'result'               => 'Completed',
        'condition_after'      => 'Good',
        'created_by'           => $manager,
    ]);

    Database::insert('maintenance_logs', [
        'asset_id'          => $assetIds['ladder'],
        'maintenance_type'  => 'inspection',
        'performed_on'      => $today->modify('-3 months')->format('Y-m-d'),
        'performed_by_name' => 'Sam Staff',
        'work_done'         => 'Quarterly formal inspection. Feet worn but serviceable.',
        'result'            => 'Completed',
        'condition_after'   => 'Fair',
        'created_by'        => $manager,
    ]);

    // --- PAT records -------------------------------------------------------
    $patRecords = [
        ['grinder', '-8 months', '+4 months', 'Class I',  'Pass', 0.08, 99.99, 0.21, 'PAT-2025-0141'],
        ['grinder', '-20 months','-8 months', 'Class I',  'Pass', 0.09, 99.99, 0.24, 'PAT-2024-0087'],
        ['laptop',  '-4 months', '+8 months', 'Class II', 'Pass', null, 99.99, 0.05, 'PAT-2026-0022'],
        ['charger', '-4 months', '+8 months', 'Class II', 'Pass', null, 99.99, 0.03, 'PAT-2026-0023'],
        ['mft',     '-2 months', '+10 months','Class II', 'Pass', null, 99.99, 0.04, 'PAT-2026-0031'],
        ['sds',     '-14 months','-2 months', 'Class I',  'Pass', 0.11, 50.00, 0.30, 'PAT-2025-0102'], // overdue
        ['pillardrill', '-11 months', '+1 months', 'Class I', 'Fail', 1.24, 12.50, 1.80, 'PAT-2025-0119'],
    ];

    foreach ($patRecords as [$assetKey, $testOffset, $dueOffset, $class, $result, $earth, $insulation, $leakage, $label]) {
        Database::insert('pat_records', [
            'asset_id'                    => $assetIds[$assetKey],
            'test_date'                   => $today->modify($testOffset)->format('Y-m-d'),
            'retest_due_date'             => $today->modify($dueOffset)->format('Y-m-d'),
            'tester_user_id'              => $manager,
            'tester_name'                 => 'Sam Staff',
            'tester_reference'            => 'C&G 2377-22 / 4471',
            'test_equipment'              => 'Seaward PrimeTest 250+ (S/N 0921884)',
            'appliance_class'             => $class,
            'visual_inspection_pass'      => $result === 'Pass' ? 1 : 0,
            'earth_continuity_ohms'       => $earth,
            'insulation_resistance_mohms' => $insulation,
            'leakage_current_ma'          => $leakage,
            'functional_check_pass'       => $result === 'Pass' ? 1 : 0,
            'overall_result'              => $result,
            'pat_label_serial'            => $label,
            'fuse_fitted_amps'            => $class === 'Class I' ? 13.00 : 3.00,
            'remedial_action'             => $result === 'Fail' ? 'Earth continuity out of tolerance. Removed from service pending flex replacement.' : null,
            'created_by'                  => $manager,
        ]);
    }

    // --- Condition photos --------------------------------------------------
    // Generated rather than shipped as binaries: it keeps the repository small
    // and gives the gallery something dated to show. Skipped without GD.
    if (App\Core\Image::isSupported()) {
        $samplePhotos = [
            ['grinder', '-8 months',  'As received from the supplier', [58, 110, 165]],
            ['grinder', '-5 months',  'Cracked wheel guard before repair', [168, 84, 60]],
            ['grinder', '-5 months',  'After fitting the replacement guard', [64, 132, 88]],
            ['ladder',  '-3 months',  'Quarterly inspection — feet worn but serviceable', [150, 120, 60]],
            ['ladder',  '-14 days',   'Rubber feet now perished on the left stile', [150, 70, 70]],
            ['sds',     '-24 days',   'Condition when checked out to Harding & Sons', [90, 90, 120]],
        ];

        foreach ($samplePhotos as $index => [$assetKey, $offset, $caption, $rgb]) {
            $assetId   = $assetIds[$assetKey];
            $directory = Config::get('storage.uploads') . '/assets/' . $assetId . '/photos';
            App\Core\Upload::ensureDirectory($directory);

            $filename = sprintf('demo-%02d-%s.jpg', $index + 1, bin2hex(random_bytes(4)));
            $absolute = $directory . '/' . $filename;

            $image = imagecreatetruecolor(1200, 900);
            imagefill($image, 0, 0, imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]));

            // A little structure so the thumbnails are distinguishable.
            $ink = imagecolorallocate($image, 255, 255, 255);
            for ($y = 0; $y < 900; $y += 90) {
                imageline($image, 0, $y, 1200, $y, imagecolorallocate($image, min(255, $rgb[0] + 25), min(255, $rgb[1] + 25), min(255, $rgb[2] + 25)));
            }
            imagestring($image, 5, 40, 40, 'DEMO CONDITION PHOTO', $ink);
            imagestring($image, 4, 40, 80, substr($caption, 0, 60), $ink);
            imagestring($image, 3, 40, 830, 'Sample image generated by bin/seed.php', $ink);

            imagejpeg($image, $absolute, 82);
            imagedestroy($image);

            $relative  = 'assets/' . $assetId . '/photos/' . $filename;
            $thumbnail = App\Core\Image::thumbnail($relative, 'image/jpeg');
            $takenAt   = $today->modify($offset)->format('Y-m-d H:i:s');

            Database::insert('asset_photos', [
                'asset_id'          => $assetId,
                'file_path'         => $relative,
                'thumbnail_path'    => $thumbnail,
                'original_filename' => 'condition-' . ($index + 1) . '.jpg',
                'mime_type'         => 'image/jpeg',
                'file_size_bytes'   => (int) filesize($absolute),
                'width_px'          => 1200,
                'height_px'         => 900,
                'caption'           => $caption,
                'taken_at'          => $takenAt,
                'is_primary'        => in_array($index, [1, 3, 5], true) ? 1 : 0,
                'uploaded_by'       => $manager,
            ]);
        }

        // Keep exactly one primary per asset.
        Database::run(
            'UPDATE asset_photos p
               JOIN (SELECT asset_id, MAX(id) AS keep_id FROM asset_photos WHERE is_primary = 1 GROUP BY asset_id) k
                 ON k.asset_id = p.asset_id
                SET p.is_primary = IF(p.id = k.keep_id, 1, 0)'
        );
    }

    // --- Borrowers ---------------------------------------------------------
    $borrowerPerson = Database::insert('borrowers', [
        'borrower_type' => 'Person',
        'name'          => 'Bailey Borrower',
        'company_name'  => 'Northfield Electrical Ltd',
        'reference'     => 'EMP-0042',
        'email'         => 'borrower@example.com',
        'phone'         => '07700 900042',
        'user_id'       => $userIds['borrower'],
        'created_by'    => $manager,
    ]);

    $borrowerCompany = Database::insert('borrowers', [
        'borrower_type' => 'Company',
        'name'          => 'Harding & Sons Contractors',
        'reference'     => 'ACC-1187',
        'email'         => 'plant@hardingandsons.example',
        'phone'         => '01865 496000',
        'address'       => "Unit 12, Eastway Industrial Estate\nOxford\nOX4 6JT",
        'created_by'    => $manager,
    ]);

    // --- Loans -------------------------------------------------------------
    Database::insert('loans', [
        'reference'              => 'LN-2026-0001',
        'asset_id'               => $assetIds['mft'],
        'borrower_id'            => $borrowerPerson,
        'checked_out_at'         => $today->modify('-6 days')->format('Y-m-d 08:15:00'),
        'due_back_date'          => $today->modify('+8 days')->format('Y-m-d'),
        'checked_out_by_user_id' => $manager,
        'condition_out'          => 'Excellent',
        'status'                 => 'Out',
        'purpose'                => 'Periodic inspection at Eastway site.',
    ]);

    Database::insert('loans', [
        'reference'              => 'LN-2026-0002',
        'asset_id'               => $assetIds['sds'],
        'borrower_id'            => $borrowerCompany,
        'checked_out_at'         => $today->modify('-24 days')->format('Y-m-d 14:40:00'),
        'due_back_date'          => $today->modify('-3 days')->format('Y-m-d'),
        'checked_out_by_user_id' => $manager,
        'condition_out'          => 'Good',
        'status'                 => 'Overdue',
        'hire_charge'            => 45.00,
        'purpose'                => 'Chased-in conduit work.',
    ]);

    Database::insert('loans', [
        'reference'              => 'LN-2025-0148',
        'asset_id'               => $assetIds['drill'],
        'borrower_id'            => $borrowerPerson,
        'checked_out_at'         => $today->modify('-70 days')->format('Y-m-d 09:00:00'),
        'due_back_date'          => $today->modify('-56 days')->format('Y-m-d'),
        'checked_out_by_user_id' => $manager,
        'condition_out'          => 'Good',
        'returned_at'            => $today->modify('-57 days')->format('Y-m-d 16:20:00'),
        'returned_to_user_id'    => $manager,
        'condition_in'           => 'Good',
        'returned_condition_notes' => 'Returned clean, keyless chuck slightly stiff.',
        'status'                 => 'Returned',
    ]);

    // --- Audit trail -------------------------------------------------------
    Database::insert('activity_log', [
        'user_id'     => $admin,
        'user_name'   => 'Alex Admin',
        'action'      => 'seeded',
        'entity_type' => 'system',
        'description' => 'Demo data loaded by bin/seed.php',
        'ip_address'  => '127.0.0.1',
    ]);

    Database::commit();
} catch (Throwable $e) {
    Database::rollBack();
    throw $e;
}

echo "\nDone. Demo accounts (password: " . DEMO_PASSWORD . "):\n";
echo "  admin@example.com     Administrator\n";
echo "  manager@example.com   Manager / Staff\n";
echo "  viewer@example.com    Read-only\n";
echo "  borrower@example.com  Borrower\n";
echo "\nChange or remove these before the system is used for real.\n";
