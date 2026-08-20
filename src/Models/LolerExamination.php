<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use DateTimeImmutable;

/**
 * In-house LOLER reports of thorough examination.
 *
 * LOLER 1998 regulation 9(3)(a) sets the examination interval:
 *
 *   (i)   lifting equipment for lifting persons, or an accessory for
 *         lifting — at least every 6 months;
 *   (ii)  other lifting equipment — at least every 12 months;
 *   (iii) either case, in accordance with an examination scheme;
 *   (iv)  each time exceptional circumstances liable to jeopardise the safety
 *         of the equipment have occurred.
 *
 * Regulation 2 defines an "accessory for lifting" as work equipment for
 * attaching loads to machinery for lifting — the slings, shackles, eyebolts
 * and beams below — which is why the type an asset is given decides the
 * interval it is offered rather than merely labelling it.
 *
 * Regulation 10 and Schedule 1 decide the shape of a report. Every Schedule 1
 * paragraph has a column, and this class holds the arithmetic the schedule
 * implies: which statutory basis an examination is on, and the latest date by
 * which the next one must be carried out.
 *
 * None of this is professional judgement. The application records what a
 * competent person examined and concluded; deciding whether equipment is safe
 * to operate — and discharging the regulation 10 duties that follow a
 * dangerous defect — remains theirs.
 */
final class LolerExamination
{
    /**
     * Categories of lifting equipment and accessories.
     *
     * `kind` is what regulation 9(3)(a) turns on:
     *   accessory — an accessory for lifting (reg 2), 6 months
     *   persons   — lifting equipment for lifting persons, 6 months
     *   equipment — other lifting equipment, 12 months
     *
     * Add to this list freely; the kind is the only part the regulations read.
     *
     * @var array<string,array{0:string,1:string}> key => [label, kind]
     */
    public const TYPES = [
        // Cranes and overhead handling.
        'overhead_crane'      => ['Overhead travelling crane', 'equipment'],
        'gantry_crane'        => ['Gantry or goliath crane', 'equipment'],
        'jib_crane'           => ['Jib or pillar crane', 'equipment'],
        'mobile_crane'        => ['Mobile crane', 'equipment'],
        'loader_crane'        => ['Vehicle-mounted loader crane', 'equipment'],
        'tower_crane'         => ['Tower crane', 'equipment'],
        'davit'               => ['Davit', 'equipment'],
        'runway_beam'         => ['Runway beam or monorail', 'equipment'],
        'a_frame'             => ['A-frame or portable gantry', 'equipment'],

        // Hoists and winches.
        'chain_hoist_manual'  => ['Chain hoist (manual)', 'equipment'],
        'chain_hoist_powered' => ['Chain hoist (powered)', 'equipment'],
        'wire_rope_hoist'     => ['Wire rope hoist', 'equipment'],
        'lever_hoist'         => ['Lever hoist', 'equipment'],
        'winch'               => ['Winch', 'equipment'],
        'engine_crane'        => ['Engine crane or folding shop crane', 'equipment'],

        // Vehicle and workshop lifting.
        'vehicle_lift'        => ['Vehicle lift (two- or four-post)', 'equipment'],
        'tail_lift'           => ['Vehicle tail lift', 'equipment'],
        'jacking_equipment'   => ['Jack or jacking equipment', 'equipment'],
        'axle_stand'          => ['Axle stand or support stand', 'equipment'],
        'scissor_lift_table'  => ['Scissor lift table', 'equipment'],
        'forklift'            => ['Forklift truck', 'equipment'],
        'telehandler'         => ['Telehandler', 'equipment'],
        'pallet_truck'        => ['Powered pallet truck or stacker', 'equipment'],
        'goods_lift'          => ['Goods lift', 'equipment'],

        // Equipment that lifts people — 6 months under reg 9(3)(a)(i).
        'mewp_scissor'        => ['MEWP — scissor lift', 'persons'],
        'mewp_boom'           => ['MEWP — boom or cherry picker', 'persons'],
        'mast_climber'        => ['Mast climbing work platform', 'persons'],
        'suspended_cradle'    => ['Suspended access cradle or BMU', 'persons'],
        'passenger_lift'      => ['Passenger lift', 'persons'],
        'platform_lift'       => ['Platform lift or stairlift', 'persons'],
        'patient_hoist'       => ['Patient or people hoist', 'persons'],
        'man_riding_basket'   => ['Man-riding basket or personnel cage', 'persons'],
        'rescue_winch'        => ['Man-riding or rescue winch', 'persons'],

        // Accessories for lifting — 6 months under reg 9(3)(a)(i).
        'chain_sling'         => ['Chain sling', 'accessory'],
        'wire_rope_sling'     => ['Wire rope sling', 'accessory'],
        'webbing_sling'       => ['Webbing or round sling', 'accessory'],
        'shackle'             => ['Shackle', 'accessory'],
        'eyebolt'             => ['Eyebolt or lifting eye', 'accessory'],
        'hook'                => ['Hook, including swivel hook', 'accessory'],
        'master_link'         => ['Master link or connecting link', 'accessory'],
        'lifting_beam'        => ['Lifting or spreader beam', 'accessory'],
        'lifting_clamp'       => ['Plate or lifting clamp', 'accessory'],
        'drum_lifter'         => ['Drum lifter or drum clamp', 'accessory'],
        'magnet_lifter'       => ['Lifting magnet', 'accessory'],
        'vacuum_lifter'       => ['Vacuum lifter', 'accessory'],
        'crane_forks'         => ['Crane forks or fork attachment', 'accessory'],
        'lifting_chain'       => ['Lifting chain', 'accessory'],
        'turnbuckle'          => ['Turnbuckle or rigging screw', 'accessory'],
        'swivel'              => ['Load ring or swivel', 'accessory'],
        'lifting_frame'       => ['Lifting frame or sling bar', 'accessory'],
    ];

    /** Units an SWL or WLL is expressed in. */
    public const SWL_UNITS = ['kg', 't', 'kN', 'lb', 'persons'];

    /**
     * Regulation 9(3)(a), as the report has to name it in Schedule 1(7)(a).
     *
     * @var array<string,string>
     */
    public const BASES = [
        '6-month'     => 'Within an interval of 6 months — regulation 9(3)(a)(i)',
        '12-month'    => 'Within an interval of 12 months — regulation 9(3)(a)(ii)',
        'scheme'      => 'In accordance with an examination scheme — regulation 9(3)(a)(iii)',
        'exceptional' => 'After the occurrence of exceptional circumstances — regulation 9(3)(a)(iv)',
    ];

    /**
     * Schedule 1(8), which splits a defect two ways and not three.
     *
     * @var array<string,array{label:string,short:string,help:string}>
     */
    public const DEFECT_CATEGORIES = [
        'danger' => [
            'label' => 'Is a danger to persons',
            'short' => 'Danger',
            'help'  => 'The equipment must not be used before the defect is rectified'
                . ' (regulation 10(3)(a)). The employer must be notified forthwith.',
        ],
        'becoming_danger' => [
            'label' => 'Not yet a danger, but could become one',
            'short' => 'Becoming a danger',
            'help'  => 'Give the date by which it could become a danger. The equipment'
                . ' must not be used after that date until the defect is rectified'
                . ' (regulation 10(3)(b)).',
        ],
    ];

    /** The statutory intervals, offered before anything an examination scheme sets. */
    public const STATUTORY_INTERVALS = [6, 12];

    private const SELECT = 'SELECT e.*,
                                   a.asset_tag, a.name AS asset_name,
                                   a.manufacturer, a.model,
                                   cat.name AS category_name,
                                   loc.name AS location_name,
                                   u.name AS examiner_account_name,
                                   au.name AS authenticated_account_name,
                                   (SELECT COUNT(*) FROM loler_defects d WHERE d.examination_id = e.id) AS defect_count,
                                   (SELECT COUNT(*) FROM loler_defects d WHERE d.examination_id = e.id AND d.category = \'danger\') AS danger_count,
                                   (SELECT COUNT(*) FROM loler_defects d WHERE d.examination_id = e.id AND d.serious_injury_risk = 1) AS serious_count
                              FROM loler_examinations e
                              INNER JOIN assets a ON a.id = e.asset_id
                              LEFT JOIN categories cat ON cat.id = a.category_id
                              LEFT JOIN locations loc ON loc.id = a.location_id
                              LEFT JOIN users u ON u.id = e.examiner_user_id
                              LEFT JOIN users au ON au.id = e.authenticated_by';

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne(self::SELECT . ' WHERE e.id = ?', [$id]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function forAsset(int $assetId, int $limit = 50): array
    {
        return Database::select(
            self::SELECT . ' WHERE e.asset_id = ? ORDER BY e.examined_on DESC, e.id DESC LIMIT ' . max(1, min(200, $limit)),
            [$assetId]
        );
    }

    /** The most recent examination of an asset, whatever it found. */
    public static function latestForAsset(int $assetId): ?array
    {
        return Database::selectOne(
            self::SELECT . ' WHERE e.asset_id = ? ORDER BY e.examined_on DESC, e.id DESC',
            [$assetId]
        );
    }

    /**
     * Every examination, newest first, with optional filters.
     *
     * Filters: q, asset_id, outcome, examiner, from, to, overdue.
     *
     * @param array<string,mixed> $filters
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int}
     */
    public static function search(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $where  = [];
        $params = [];

        $keywords = trim((string) ($filters['q'] ?? ''));

        if ($keywords !== '') {
            $like    = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keywords) . '%';
            $columns = ['a.asset_tag', 'a.name', 'e.serial_number', 'e.examiner_name', 'e.employer_name'];

            $clauses = [];

            foreach ($columns as $column) {
                $clauses[] = $column . " LIKE ? ESCAPE '!'";
                $params[]  = $like;
            }

            $where[] = '(' . implode(' OR ', $clauses) . ')';
        }

        if (!empty($filters['asset_id'])) {
            $where[]  = 'e.asset_id = ?';
            $params[] = (int) $filters['asset_id'];
        }

        if (!empty($filters['outcome']) && in_array($filters['outcome'], ['none', 'defects'], true)) {
            $where[]  = 'e.outcome = ?';
            $params[] = (string) $filters['outcome'];
        }

        if (!empty($filters['examiner'])) {
            $where[]  = 'e.examiner_user_id = ?';
            $params[] = (int) $filters['examiner'];
        }

        if (!empty($filters['from'])) {
            $where[]  = 'e.examined_on >= ?';
            $params[] = (string) $filters['from'];
        }

        if (!empty($filters['to'])) {
            $where[]  = 'e.examined_on <= ?';
            $params[] = (string) $filters['to'];
        }

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $total = (int) Database::scalar(
            'SELECT COUNT(*) FROM loler_examinations e INNER JOIN assets a ON a.id = e.asset_id' . $whereSql,
            $params
        );

        $perPage = max(5, min(200, $perPage));
        $pages   = max(1, (int) ceil($total / $perPage));
        $page    = max(1, min($page, $pages));
        $offset  = ($page - 1) * $perPage;

        $rows = Database::select(
            self::SELECT . $whereSql . ' ORDER BY e.examined_on DESC, e.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return [
            'rows'     => $rows,
            'total'    => $total,
            'pages'    => $pages,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        return Database::insert('loler_examinations', $data);
    }

    /** @param array<string,mixed> $data */
    public static function addDefect(array $data): int
    {
        return Database::insert('loler_defects', $data);
    }

    /** @return array<int,array<string,mixed>> */
    public static function defects(int $examinationId): array
    {
        return Database::select(
            'SELECT * FROM loler_defects WHERE examination_id = ? ORDER BY position, id',
            [$examinationId]
        );
    }

    // -- The regulation's own arithmetic ------------------------------------

    public static function typeLabel(?string $key): string
    {
        return self::TYPES[(string) $key][0] ?? (string) $key;
    }

    /** 'accessory', 'persons' or 'equipment' — what regulation 9(3)(a) turns on. */
    public static function typeKind(?string $key): string
    {
        return self::TYPES[(string) $key][1] ?? 'equipment';
    }

    /**
     * The interval regulation 9(3)(a) sets for a type before any examination
     * scheme changes it.
     */
    public static function statutoryInterval(?string $type): int
    {
        return self::typeKind($type) === 'equipment' ? 12 : 6;
    }

    /**
     * Which of regulation 9(3)(a)(i)-(iii) an examination at this interval,
     * on this type of equipment, is being carried out under.
     *
     * An interval that matches neither statutory figure can only be one set by
     * an examination scheme, which is what (iii) is for. Exceptional
     * circumstances — (iv) — are never inferred: only the examiner knows one
     * has occurred, so it is theirs to choose.
     */
    public static function basisFor(?string $type, ?int $intervalMonths): string
    {
        $statutory = self::statutoryInterval($type);
        $interval  = $intervalMonths ?? $statutory;

        if ($interval === 6 && $statutory === 6) {
            return '6-month';
        }

        if ($interval === 12 && $statutory === 12) {
            return '12-month';
        }

        return 'scheme';
    }

    /**
     * The latest date by which the next thorough examination must be carried
     * out — Schedule 1(8)(d).
     *
     * Counted from the date of this examination. An examination scheme or a
     * particular circumstance can shorten it, which is why the form lets the
     * examiner change what this suggests.
     */
    public static function nextExaminationDate(string $examinedOn, ?int $intervalMonths): ?string
    {
        $months = $intervalMonths ?? 0;

        if ($months < 1) {
            return null;
        }

        $from = DateTimeImmutable::createFromFormat('Y-m-d', $examinedOn);

        return $from === false ? null : $from->modify('+' . $months . ' months')->format('Y-m-d');
    }

    /**
     * How an examination reads at a glance.
     *
     * A dangerous defect is the headline whatever else was found, because
     * regulation 10(3)(a) stops the equipment being used until it is put
     * right.
     *
     * @param array<string,mixed> $examination
     */
    public static function verdict(array $examination): string
    {
        if ((int) $examination['danger_count'] > 0) {
            return 'Danger — do not use';
        }

        if ((int) $examination['defect_count'] > 0) {
            return 'Defects to remedy';
        }

        return (int) $examination['safe_to_operate'] === 1 ? 'No defects' : 'No defects recorded';
    }

    /** Is an asset's next examination overdue, and by when is it due? */
    public static function statusForAsset(int $assetId): ?array
    {
        $latest = self::latestForAsset($assetId);

        if ($latest === null) {
            return ['state' => 'Never examined', 'due' => null, 'latest' => null];
        }

        $due   = (string) $latest['next_examination_date'];
        $today = date('Y-m-d');

        return [
            'state'  => $due < $today ? 'Overdue' : 'Current',
            'due'    => $due,
            'latest' => $latest,
        ];
    }
}
