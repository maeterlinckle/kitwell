<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;

/**
 * Maintenance routines: the procedures a technician fills in.
 *
 * A routine is a name and a description. What it actually asks lives in a
 * version, and a version is pages of steps. Only one version of a routine is
 * current at a time, and only a published version can be run.
 *
 * Versions exist because the answers outlive the questions. A completion
 * records the version it followed, so a record from two years ago still shows
 * what was asked then rather than what is asked now. That guarantee is
 * structural: `routine_completions` holds its version down with a foreign key
 * that refuses the delete, and editing a version that has been used forks a
 * new draft instead of touching it.
 */
final class MaintenanceRoutine
{
    /** Field types a step may use, in the order the editor offers them. */
    public const FIELD_TYPES = [
        'short_text'    => 'Short text',
        'long_text'     => 'Notes',
        'number'        => 'Number',
        'date'          => 'Date',
        'boolean'       => 'Yes / no',
        'single_choice' => 'Choose one',
        'multi_choice'  => 'Choose any',
        'photo'         => 'Photo',
        'document'      => 'Document (PDF)',
    ];

    /** The types that read `options`. */
    public const CHOICE_TYPES = ['single_choice', 'multi_choice'];

    /** The types whose answer is a file rather than a value. */
    public const FILE_TYPES = ['photo', 'document'];

    public const STATUSES = ['active', 'archived'];

    /** The most choices one step may offer, so a runaway paste cannot fill a page. */
    public const MAX_OPTIONS = 40;

    private const SELECT = 'SELECT r.*,
                                   cu.name AS created_by_name,
                                   cat.name AS category_name,
                                   cv.id AS current_version_id,
                                   cv.version_number AS current_version_number,
                                   cv.published_at AS current_published_at,
                                   cv.allow_out_of_order AS current_allow_out_of_order,
                                   dv.id AS draft_version_id,
                                   dv.version_number AS draft_version_number,
                                   (SELECT COUNT(*) FROM routine_completions rc WHERE rc.routine_id = r.id) AS completion_count
                              FROM maintenance_routines r
                              LEFT JOIN users cu ON cu.id = r.created_by
                              LEFT JOIN categories cat ON cat.id = r.category_id
                              LEFT JOIN routine_versions cv ON cv.routine_id = r.id AND cv.is_current = 1
                              LEFT JOIN routine_versions dv ON dv.routine_id = r.id AND dv.published_at IS NULL';

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne(self::SELECT . ' WHERE r.id = ?', [$id]);
    }

    /**
     * Every routine, newest activity first, for the management list.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function all(bool $activeOnly = false): array
    {
        $whereSql = $activeOnly ? " WHERE r.status = 'active'" : '';

        return Database::select(self::SELECT . $whereSql . ' ORDER BY r.status, r.name');
    }

    /**
     * The routines a technician may start: active, and with something published.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function runnable(): array
    {
        return Database::select(
            self::SELECT . " WHERE r.status = 'active' AND cv.id IS NOT NULL ORDER BY r.name"
        );
    }

    /**
     * The runnable routines that apply to an asset in this category.
     *
     * A routine naming a category covers that category and everything nested
     * beneath it, so the match is made by walking *up* from the asset's own
     * category: any routine restricted to one of its ancestors — or to itself,
     * or to nothing at all — applies.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function runnableFor(?int $categoryId): array
    {
        $covering = $categoryId === null ? [] : Category::ancestorIds($categoryId);

        if ($covering === []) {
            return Database::select(
                self::SELECT . " WHERE r.status = 'active' AND cv.id IS NOT NULL
                                   AND r.category_id IS NULL
                                 ORDER BY r.name"
            );
        }

        $placeholders = implode(', ', array_fill(0, count($covering), '?'));

        return Database::select(
            self::SELECT . " WHERE r.status = 'active' AND cv.id IS NOT NULL
                               AND (r.category_id IS NULL OR r.category_id IN (" . $placeholders . "))
                             ORDER BY r.name",
            $covering
        );
    }

    /**
     * Is this routine allowed to run against an asset in this category?
     *
     * The same rule as runnableFor(), asked of one routine — the picker uses
     * the query and the runner uses this, so a routine reached by typing a URL
     * is refused exactly where one hidden from the list would have been.
     *
     * @param array<string,mixed> $routine
     */
    public static function appliesTo(array $routine, ?int $categoryId): bool
    {
        $restriction = $routine['category_id'] === null ? 0 : (int) $routine['category_id'];

        if ($restriction === 0) {
            return true;
        }

        if ($categoryId === null) {
            return false;
        }

        return in_array($restriction, Category::ancestorIds($categoryId), true);
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        return Database::insert('maintenance_routines', $data);
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        Database::update('maintenance_routines', $data, $id);
    }

    public static function setStatus(int $id, string $status): void
    {
        Database::update('maintenance_routines', ['status' => $status], $id);
    }

    public static function nameTaken(string $name, int $exceptId = 0): bool
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM maintenance_routines WHERE name = ? AND id <> ?',
            [$name, $exceptId]
        ) > 0;
    }

    // -- Versions -----------------------------------------------------------

    private const VERSION_SELECT = 'SELECT v.*,
                                           r.name AS routine_name,
                                           r.description AS routine_description,
                                           r.status AS routine_status,
                                           pu.name AS published_by_name,
                                           (SELECT COUNT(*) FROM routine_completions rc WHERE rc.version_id = v.id) AS completion_count
                                      FROM routine_versions v
                                      INNER JOIN maintenance_routines r ON r.id = v.routine_id
                                      LEFT JOIN users pu ON pu.id = v.published_by';

    /** @return array<string,mixed>|null */
    public static function findVersion(int $id): ?array
    {
        return Database::selectOne(self::VERSION_SELECT . ' WHERE v.id = ?', [$id]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function versions(int $routineId): array
    {
        return Database::select(
            self::VERSION_SELECT . ' WHERE v.routine_id = ? ORDER BY v.version_number DESC',
            [$routineId]
        );
    }

    /** The published version in force, or null if nothing has been published yet. */
    public static function currentVersion(int $routineId): ?array
    {
        return Database::selectOne(
            self::VERSION_SELECT . ' WHERE v.routine_id = ? AND v.is_current = 1',
            [$routineId]
        );
    }

    /** The unpublished draft, if one is open. There is never more than one. */
    public static function draftVersion(int $routineId): ?array
    {
        return Database::selectOne(
            self::VERSION_SELECT . ' WHERE v.routine_id = ? AND v.published_at IS NULL',
            [$routineId]
        );
    }

    public static function completionCount(int $versionId): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM routine_completions WHERE version_id = ?',
            [$versionId]
        );
    }

    private static function nextVersionNumber(int $routineId): int
    {
        return 1 + (int) Database::scalar(
            'SELECT COALESCE(MAX(version_number), 0) FROM routine_versions WHERE routine_id = ?',
            [$routineId]
        );
    }

    /** Start a routine off with an empty first draft. */
    public static function createFirstVersion(int $routineId): int
    {
        return Database::insert('routine_versions', [
            'routine_id'     => $routineId,
            'version_number' => 1,
            'is_current'     => 0,
            'published_at'   => null,
        ]);
    }

    /**
     * The version an edit should be applied to.
     *
     * An open draft is the answer whenever there is one. Otherwise the current
     * version is edited in place if nobody has ever used it — there is no
     * history to protect and a version number nobody has seen is not worth
     * spending. Once it has been used, editing forks a copy at the next number
     * and the live version carries on serving until that draft is published.
     */
    public static function editableVersion(int $routineId): array
    {
        $draft = self::draftVersion($routineId);

        if ($draft !== null) {
            return $draft;
        }

        $current = self::currentVersion($routineId);

        if ($current === null) {
            $id = self::createFirstVersion($routineId);

            return self::findVersion($id) ?? [];
        }

        if ((int) $current['completion_count'] === 0) {
            return $current;
        }

        return self::findVersion(self::forkDraft($current)) ?? [];
    }

    /** Copy a version's pages and steps into a fresh draft at the next number. */
    public static function forkDraft(array $source): int
    {
        $routineId = (int) $source['routine_id'];

        $draftId = Database::insert('routine_versions', [
            'routine_id'         => $routineId,
            'version_number'     => self::nextVersionNumber($routineId),
            'is_current'         => 0,
            'published_at'       => null,
            'allow_out_of_order' => (int) ($source['allow_out_of_order'] ?? 0),
        ]);

        foreach (self::pages((int) $source['id']) as $page) {
            $pageId = Database::insert('routine_pages', [
                'version_id'  => $draftId,
                'position'    => (int) $page['position'],
                'title'       => $page['title'],
                'description' => $page['description'],
            ]);

            foreach (self::steps((int) $page['id']) as $step) {
                Database::insert('routine_steps', [
                    'page_id'     => $pageId,
                    'position'    => (int) $step['position'],
                    'label'       => $step['label'],
                    'help_text'   => $step['help_text'],
                    'field_type'  => $step['field_type'],
                    'is_required' => (int) $step['is_required'],
                    'unit'        => $step['unit'],
                    'options'     => $step['options'],
                ]);
            }
        }

        return $draftId;
    }

    /**
     * Publish a draft: it becomes the version new work follows, and whatever
     * was current stops being so. Completions already recorded keep pointing at
     * the version they were carried out against.
     */
    public static function publish(int $versionId): void
    {
        $version = self::findVersion($versionId);

        if ($version === null || $version['published_at'] !== null) {
            return;
        }

        Database::run(
            'UPDATE routine_versions SET is_current = 0 WHERE routine_id = ?',
            [(int) $version['routine_id']]
        );

        Database::update('routine_versions', [
            'is_current'   => 1,
            'published_at' => date('Y-m-d H:i:s'),
            'published_by' => Auth::id(),
        ], $versionId);
    }

    /**
     * Whether a run of this version is worked through as a checklist.
     *
     * Held on the version rather than on the routine, because it is part of
     * what was published: a completion knows how it was meant to be carried
     * out from the version it followed.
     */
    public static function setOutOfOrder(int $versionId, bool $allow): void
    {
        Database::update('routine_versions', ['allow_out_of_order' => $allow ? 1 : 0], $versionId);
    }

    /** Throw a draft away. Only ever a draft: a published version is history. */
    public static function discardDraft(int $versionId): void
    {
        Database::run(
            'DELETE FROM routine_versions WHERE id = ? AND published_at IS NULL',
            [$versionId]
        );
    }

    // -- Pages and steps ----------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    public static function pages(int $versionId): array
    {
        return Database::select(
            'SELECT * FROM routine_pages WHERE version_id = ? ORDER BY position, id',
            [$versionId]
        );
    }

    /** @return array<string,mixed>|null */
    public static function findPage(int $pageId): ?array
    {
        return Database::selectOne('SELECT * FROM routine_pages WHERE id = ?', [$pageId]);
    }

    /** @return array<int,array<string,mixed>> */
    public static function steps(int $pageId): array
    {
        return Database::select(
            'SELECT * FROM routine_steps WHERE page_id = ? ORDER BY position, id',
            [$pageId]
        );
    }

    /** @return array<string,mixed>|null */
    public static function findStep(int $stepId): ?array
    {
        return Database::selectOne('SELECT * FROM routine_steps WHERE id = ?', [$stepId]);
    }

    /**
     * A whole version as the runner, the preview and the completion view all
     * want it: pages in order, each carrying its own steps in order.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function structure(int $versionId): array
    {
        $pages = self::pages($versionId);

        foreach ($pages as $index => $page) {
            $pages[$index]['steps'] = self::steps((int) $page['id']);
        }

        return $pages;
    }

    /** Every step of a version, flattened — for validating a submission. */
    public static function allSteps(int $versionId): array
    {
        return Database::select(
            'SELECT s.*, p.title AS page_title, p.position AS page_position
               FROM routine_steps s
               INNER JOIN routine_pages p ON p.id = s.page_id
              WHERE p.version_id = ?
              ORDER BY p.position, p.id, s.position, s.id',
            [$versionId]
        );
    }

    public static function stepCount(int $versionId): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM routine_steps s
               INNER JOIN routine_pages p ON p.id = s.page_id
              WHERE p.version_id = ?',
            [$versionId]
        );
    }

    public static function addPage(int $versionId, string $title, ?string $description = null): int
    {
        $position = 1 + (int) Database::scalar(
            'SELECT COALESCE(MAX(position), 0) FROM routine_pages WHERE version_id = ?',
            [$versionId]
        );

        return Database::insert('routine_pages', [
            'version_id'  => $versionId,
            'position'    => $position,
            'title'       => $title,
            'description' => $description,
        ]);
    }

    /** @param array<string,mixed> $data */
    public static function updatePage(int $pageId, array $data): void
    {
        Database::update('routine_pages', $data, $pageId);
    }

    public static function deletePage(int $pageId): void
    {
        Database::run('DELETE FROM routine_pages WHERE id = ?', [$pageId]);
    }

    public static function addStep(int $pageId, array $data): int
    {
        $data['page_id']  = $pageId;
        $data['position'] = 1 + (int) Database::scalar(
            'SELECT COALESCE(MAX(position), 0) FROM routine_steps WHERE page_id = ?',
            [$pageId]
        );

        return Database::insert('routine_steps', $data);
    }

    /** @param array<string,mixed> $data */
    public static function updateStep(int $stepId, array $data): void
    {
        Database::update('routine_steps', $data, $stepId);
    }

    public static function deleteStep(int $stepId): void
    {
        Database::run('DELETE FROM routine_steps WHERE id = ?', [$stepId]);
    }

    /**
     * Renumber pages from an ordered list of ids.
     *
     * Positions are rewritten from 1 whatever came in, so a list that skipped a
     * number or repeated one still lands as a clean sequence.
     *
     * @param array<int,int> $orderedIds
     */
    public static function reorderPages(int $versionId, array $orderedIds): void
    {
        $position = 0;

        foreach ($orderedIds as $pageId) {
            $page = self::findPage((int) $pageId);

            if ($page === null || (int) $page['version_id'] !== $versionId) {
                continue;
            }

            Database::update('routine_pages', ['position' => ++$position], (int) $pageId);
        }
    }

    /** @param array<int,int> $orderedIds */
    public static function reorderSteps(int $pageId, array $orderedIds): void
    {
        $position = 0;

        foreach ($orderedIds as $stepId) {
            $step = self::findStep((int) $stepId);

            if ($step === null || (int) $step['page_id'] !== $pageId) {
                continue;
            }

            Database::update('routine_steps', ['position' => ++$position], (int) $stepId);
        }
    }

    // -- Choices ------------------------------------------------------------

    /**
     * The labels a choice step offers.
     *
     * Stored as a JSON array. A step of any other type has nothing here, and
     * asking for its choices returns none rather than failing.
     *
     * @return array<int,string>
     */
    public static function options(array $step): array
    {
        if (!in_array((string) $step['field_type'], self::CHOICE_TYPES, true)) {
            return [];
        }

        $decoded = json_decode((string) ($step['options'] ?? ''), true);

        if (!is_array($decoded)) {
            return [];
        }

        $labels = [];

        foreach ($decoded as $label) {
            if (is_string($label) && trim($label) !== '') {
                $labels[] = trim($label);
            }
        }

        return $labels;
    }

    /**
     * Turn a textarea of one choice per line into the stored JSON.
     *
     * Line breaks are the separator, which is also why a multi-choice answer
     * can be stored one label per line without ambiguity.
     */
    public static function encodeOptions(string $text): ?string
    {
        $labels = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '' && !in_array($line, $labels, true)) {
                $labels[] = mb_substr($line, 0, 191);
            }

            if (count($labels) >= self::MAX_OPTIONS) {
                break;
            }
        }

        return $labels === [] ? null : (string) json_encode($labels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** The stored choices back as one per line, for the editor's textarea. */
    public static function optionsText(array $step): string
    {
        return implode("\n", self::options($step));
    }

    public static function typeLabel(string $type): string
    {
        return self::FIELD_TYPES[$type] ?? $type;
    }

    /** "v3", or "v3 (draft)" — used wherever a version is named. */
    public static function versionLabel(array $version): string
    {
        return 'v' . (int) $version['version_number']
            . ($version['published_at'] === null ? ' (draft)' : '');
    }
}
