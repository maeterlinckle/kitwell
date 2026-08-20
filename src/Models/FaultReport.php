<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * "This is broken" — recorded, not just flagged.
 *
 * `assets.status` answers *is it faulty now?*. This table answers *what has
 * gone wrong with it, and how often?* — the same split PAT and maintenance
 * already make, and for the same reason: an asset that has been reported faulty
 * three times this year is telling you something a status column cannot say,
 * because the status column was overwritten each time.
 *
 * Nothing here closes a report. There is no "resolved" flag, because the
 * application already has the answer somewhere honest: the asset stops being
 * faulty when somebody changes its status — by editing it, or by recording the
 * maintenance that fixed it. A second, separate notion of open/closed would be
 * a thing to keep in step with the status, and the two would drift.
 */
final class FaultReport
{
    /**
     * How badly it needs fixing.
     *
     * Declared most-urgent-first, which is the order the report sorts in and
     * the order the filter offers. The database ENUM is declared the other way
     * round (Low → Critical), so every ORDER BY here is explicit rather than
     * relying on the ENUM's ordinal — see URGENCY_ORDER.
     */
    public const URGENCIES = ['Critical', 'High', 'Medium', 'Low'];

    /**
     * The filter value meaning "Critical or High".
     *
     * Not a level anybody chooses on the report form — a sentinel for the pair
     * the dashboard counts together, so the tile and the list it links to
     * always show the same rows.
     */
    public const URGENT = 'urgent';

    /**
     * Most urgent first. Used wherever a query has to sort by urgency.
     *
     * FIELD() returns **0** for a value it does not find, including NULL — so a
     * bare FIELD() sorts an unrated fault *above* a Critical one. An asset can
     * carry the Faulty status with no fault report behind it: somebody set the
     * status on the asset form, or a LOLER examination found a defect that is a
     * danger to persons and took the item out of service. Those rows have no
     * urgency, and they belong at the bottom of a list headed "most urgent
     * first", not the top.
     */
    private const URGENCY_ORDER = "COALESCE(NULLIF(FIELD(f.urgency,'Critical','High','Medium','Low'), 0), 99)";

    /** What each level is for, shown beside the choice on the form. */
    public const URGENCY_HINTS = [
        'Critical' => 'Dangerous or stopping work now. Somebody needs to see this today.',
        'High'     => 'Unusable, or usable only with care. Fix it this week.',
        'Medium'   => 'Works, but not properly. Fix it when the bench is free.',
        'Low'      => 'Cosmetic or minor. Worth recording, not worth dropping anything for.',
    ];

    private const SELECT = 'SELECT f.*,
                                   a.asset_tag,
                                   a.name AS asset_name,
                                   a.status AS asset_status,
                                   u.name AS reporter_account_name,
                                   (SELECT COUNT(*) FROM fault_report_photos p WHERE p.fault_report_id = f.id) AS photo_count
                              FROM fault_reports f
                              INNER JOIN assets a ON a.id = f.asset_id
                              LEFT JOIN users u ON u.id = f.reported_by';

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne(self::SELECT . ' WHERE f.id = ?', [$id]);
    }

    /**
     * Every report against one asset, most recent first.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forAsset(int $assetId, ?int $limit = null): array
    {
        $sql = self::SELECT . ' WHERE f.asset_id = ? ORDER BY f.faulty_on DESC, f.id DESC';

        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(1, min(200, $limit));
        }

        return Database::select($sql, [$assetId]);
    }

    /**
     * The report the asset is currently faulty *because of*.
     *
     * The most recent one. An asset can be reported faulty again while it is
     * already faulty — somebody finds a second thing wrong with it — and the
     * later report is the one that describes the state it is in now.
     *
     * @return array<string,mixed>|null
     */
    public static function latestForAsset(int $assetId): ?array
    {
        return Database::selectOne(
            self::SELECT . ' WHERE f.asset_id = ? ORDER BY f.faulty_on DESC, f.id DESC LIMIT 1',
            [$assetId]
        );
    }

    public static function countForAsset(int $assetId): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM fault_reports WHERE asset_id = ?', [$assetId]);
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        return Database::insert('fault_reports', $data);
    }

    // -- Photos -------------------------------------------------------------

    /** @param array<string,mixed> $data */
    public static function addPhoto(array $data): int
    {
        return Database::insert('fault_report_photos', $data);
    }

    /** @return array<int,array<string,mixed>> */
    public static function photos(int $faultReportId): array
    {
        return Database::select(
            'SELECT * FROM fault_report_photos WHERE fault_report_id = ? ORDER BY id',
            [$faultReportId]
        );
    }

    /** @return array<string,mixed>|null */
    public static function findPhoto(int $photoId): ?array
    {
        return Database::selectOne('SELECT * FROM fault_report_photos WHERE id = ?', [$photoId]);
    }

    /**
     * The photos belonging to several reports at once, keyed by report id.
     *
     * One query for a whole page rather than one per row — the same reason
     * AssetPhoto::primaryForMany() exists.
     *
     * @param array<int,int> $reportIds
     * @return array<int,array<int,array<string,mixed>>>
     */
    public static function photosForMany(array $reportIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $reportIds))));

        if ($ids === []) {
            return [];
        }

        $rows = Database::select(
            'SELECT * FROM fault_report_photos
              WHERE fault_report_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
              ORDER BY fault_report_id, id',
            $ids
        );

        $byReport = [];
        foreach ($rows as $row) {
            $byReport[(int) $row['fault_report_id']][] = $row;
        }

        return $byReport;
    }

    // -- Assets that are faulty right now ------------------------------------

    /**
     * Every asset currently at status 'Faulty', with the fault that describes it.
     *
     * Driven by the asset's status rather than by anything on the report,
     * because the status is what the rest of the application acts on: the
     * register filter, the dashboard tile and this list must never disagree
     * about what "faulty" means.
     *
     * The join finds the latest report per asset. An asset whose status was set
     * to Faulty by hand — editing the record rather than using the form — has
     * no report at all, and still appears here, with the fault columns null.
     * That is deliberate: leaving it out would make the dashboard count
     * disagree with the register, and a faulty asset nobody has described is
     * exactly the one worth chasing.
     *
     * @param array<string,mixed> $filters urgency, q, responsible ("user:7"/"team:2"/"none")
     * @return array<int,array<string,mixed>>
     */
    public static function currentFaults(array $filters = []): array
    {
        $where  = ["a.status = 'Faulty'"];
        $params = [];

        // 'urgent' is Critical *or* High — the pair the dashboard counts as one
        // figure. Without it the tile would offer a drill-down that showed
        // fewer rows than the number on the tile, which is the sort of small
        // inconsistency that teaches people not to trust the dashboard.
        $urgency = (string) ($filters['urgency'] ?? '');

        if ($urgency === self::URGENT) {
            $where[] = "f.urgency IN ('Critical','High')";
        } elseif ($urgency !== '' && in_array($urgency, self::URGENCIES, true)) {
            $where[]  = 'f.urgency = ?';
            $params[] = $urgency;
        }

        $keywords = trim((string) ($filters['q'] ?? ''));
        if ($keywords !== '') {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keywords) . '%';
            $where[] = "(a.asset_tag LIKE ? ESCAPE '!' OR a.name LIKE ? ESCAPE '!' OR f.description LIKE ? ESCAPE '!')";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $responsible = (string) ($filters['responsible'] ?? '');
        if ($responsible === 'none') {
            $where[] = 'a.responsible_user_id IS NULL AND a.responsible_team_id IS NULL';
        } elseif ($responsible !== '') {
            [$kind, $id] = Assignment::parse($responsible);

            if ($kind === 'team') {
                $where[]  = 'a.responsible_team_id = ?';
                $params[] = $id;
            } elseif ($kind === 'user') {
                $where[]  = 'a.responsible_user_id = ?';
                $params[] = $id;
            }
        }

        // Most urgent first, then longest-standing — a Critical fault from
        // three weeks ago outranks a Critical fault from this morning.
        $sort = (string) ($filters['sort'] ?? 'urgency');
        $order = match ($sort) {
            'reported' => 'f.faulty_on DESC, ' . self::URGENCY_ORDER . ' ASC',
            'oldest'   => 'f.faulty_on ASC, ' . self::URGENCY_ORDER . ' ASC',
            'asset'    => 'a.asset_tag ASC',
            default    => self::URGENCY_ORDER . ' ASC, f.faulty_on ASC, a.asset_tag ASC',
        };

        return Database::select(
            'SELECT a.id AS asset_id,
                    a.asset_tag,
                    a.name AS asset_name,
                    a.condition_rating,
                    a.responsible_user_id,
                    a.responsible_team_id,
                    COALESCE(rt.name, ru.name) AS responsible_name,
                    CASE
                        WHEN a.responsible_team_id IS NOT NULL THEN \'team\'
                        WHEN a.responsible_user_id IS NOT NULL THEN \'user\'
                    END AS responsible_kind,
                    l.name AS location_name,
                    c.name AS category_name,
                    f.id AS fault_report_id,
                    f.description,
                    f.faulty_on,
                    f.urgency,
                    f.reported_by_name,
                    f.created_at AS reported_at,
                    DATEDIFF(CURDATE(), f.faulty_on) AS days_faulty
               FROM assets a
               LEFT JOIN (
                   SELECT fr.*
                     FROM fault_reports fr
                     INNER JOIN (
                         SELECT asset_id, MAX(id) AS latest_id
                           FROM fault_reports
                          GROUP BY asset_id
                     ) newest ON newest.latest_id = fr.id
               ) f ON f.asset_id = a.id
               LEFT JOIN locations l ON l.id = a.location_id
               LEFT JOIN categories c ON c.id = a.category_id
               LEFT JOIN users ru ON ru.id = a.responsible_user_id
               LEFT JOIN teams rt ON rt.id = a.responsible_team_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY ' . $order,
            $params
        );
    }

    /**
     * How many assets are faulty, and how many of those are urgent.
     *
     * The same shape as MaintenanceSchedule::summary() and PatRecord::summary(),
     * so the dashboard tile reads its figure from the same query the drill-down
     * list uses and the two cannot disagree.
     *
     * @return array<string,int>
     */
    public static function summary(): array
    {
        $row = Database::selectOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN f.urgency IN ('Critical','High') THEN 1 ELSE 0 END) AS urgent,
                    SUM(CASE WHEN f.urgency = 'Critical' THEN 1 ELSE 0 END) AS critical,
                    SUM(CASE WHEN a.responsible_user_id IS NULL AND a.responsible_team_id IS NULL THEN 1 ELSE 0 END) AS unassigned
               FROM assets a
               LEFT JOIN (
                   SELECT fr.urgency, fr.asset_id
                     FROM fault_reports fr
                     INNER JOIN (
                         SELECT asset_id, MAX(id) AS latest_id
                           FROM fault_reports
                          GROUP BY asset_id
                     ) newest ON newest.latest_id = fr.id
               ) f ON f.asset_id = a.id
              WHERE a.status = 'Faulty'"
        );

        return [
            'total'      => (int) ($row['total'] ?? 0),
            'urgent'     => (int) ($row['urgent'] ?? 0),
            'critical'   => (int) ($row['critical'] ?? 0),
            'unassigned' => (int) ($row['unassigned'] ?? 0),
        ];
    }
}
