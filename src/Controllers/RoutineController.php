<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\MaintenanceRoutine;
use App\Models\RoutineCompletion;

/**
 * Designing maintenance routines.
 *
 * Everything that writes here needs `routines.manage`; reading the list and
 * previewing a routine needs only `maintenance.view`. That split is the point
 * of the feature's permission: a technician can carry out a procedure and read
 * what it asks without being able to change what it asks.
 *
 * The editor is ordinary HTML with no JavaScript behind it. Each page of a
 * routine is one form: the fields of the page and of every step in it are
 * saved together, and the buttons for adding, deleting and reordering post to
 * the same place with a `do` value saying which. A round trip per action is a
 * fair price for an editor that cannot get out of step with itself.
 */
final class RoutineController extends Controller
{
    /** The routines a site has, whatever their state. */
    public function index(): void
    {
        $this->view('routines/index', [
            'pageTitle' => 'Maintenance routines',
            'routines'  => MaintenanceRoutine::all(),
        ]);
    }

    /** One routine: what it asks now, what it asked before, and where it is used. */
    public function show(string $id): void
    {
        $routine = MaintenanceRoutine::find((int) $id);

        if ($routine === null) {
            $this->notFound();
        }

        $routineId = (int) $routine['id'];
        $current   = MaintenanceRoutine::currentVersion($routineId);

        $this->view('routines/show', [
            'pageTitle'   => $routine['name'],
            'routine'     => $routine,
            'current'     => $current,
            'versions'    => MaintenanceRoutine::versions($routineId),
            'pages'       => $current === null ? [] : MaintenanceRoutine::structure((int) $current['id']),
            'completions' => RoutineCompletion::forRoutine($routineId, 25),
        ]);
    }

    public function create(): void
    {
        $this->view('routines/form', [
            'pageTitle'  => 'New maintenance routine',
            'routine'    => null,
            'categories' => Category::parentOptions(),
        ]);
    }

    public function store(): void
    {
        $data = $this->validateRoutine('/maintenance/routines/create');

        if (MaintenanceRoutine::nameTaken($data['name'])) {
            $this->failValidation(['name' => 'There is already a routine with that name.'], '/maintenance/routines/create');
        }

        $id = MaintenanceRoutine::create([
            'name'        => $data['name'],
            'description' => $data['description'] !== '' ? $data['description'] : null,
            'category_id' => $this->categoryFrom($data, '/maintenance/routines/create'),
            'status'      => 'active',
            'created_by'  => Auth::id(),
        ]);

        // A routine with nothing to ask is not a routine, so its first draft is
        // created with it and the editor is where you land.
        MaintenanceRoutine::createFirstVersion($id);

        ActivityLog::record('created', 'maintenance_routine', $id, 'Created maintenance routine "' . $data['name'] . '"');

        Flash::success('Routine created. Add the pages and steps it should ask for.');
        Response::redirect('/maintenance/routines/' . $id . '/edit');
    }

    /** The name and description, which belong to the routine rather than a version. */
    public function update(string $id): void
    {
        $routineId = (int) $id;
        $routine   = MaintenanceRoutine::find($routineId);

        if ($routine === null) {
            $this->notFound();
        }

        $redirect = '/maintenance/routines/' . $routineId . '/edit';
        $data     = $this->validateRoutine($redirect);

        if (MaintenanceRoutine::nameTaken($data['name'], $routineId)) {
            $this->failValidation(['name' => 'There is already a routine with that name.'], $redirect);
        }

        MaintenanceRoutine::update($routineId, [
            'name'        => $data['name'],
            'description' => $data['description'] !== '' ? $data['description'] : null,
            'category_id' => $this->categoryFrom($data, $redirect),
        ]);

        ActivityLog::record(
            'updated',
            'maintenance_routine',
            $routineId,
            'Renamed maintenance routine to "' . $data['name'] . '"',
            ActivityLog::diff($routine, $data)
        );

        Flash::success('Saved.');
        Response::redirect($redirect);
    }

    public function setStatus(string $id): void
    {
        $routineId = (int) $id;
        $routine   = MaintenanceRoutine::find($routineId);

        if ($routine === null) {
            $this->notFound();
        }

        $archive = Request::post('status') === 'archived';

        MaintenanceRoutine::setStatus($routineId, $archive ? 'archived' : 'active');

        ActivityLog::record(
            $archive ? 'archived' : 'restored',
            'maintenance_routine',
            $routineId,
            ($archive ? 'Archived' : 'Restored') . ' maintenance routine "' . $routine['name'] . '"'
        );

        Flash::success($archive
            ? 'Routine archived. Work already recorded against it is unaffected.'
            : 'Routine restored.');

        Response::redirect('/maintenance/routines/' . $routineId);
    }

    /**
     * The editor.
     *
     * Three things can be true, and the page says which. There is an open
     * draft; or the live version has never been used and can be changed in
     * place; or it has been used, in which case nothing is editable until
     * somebody explicitly starts the next version.
     */
    public function edit(string $id): void
    {
        $routine = MaintenanceRoutine::find((int) $id);

        if ($routine === null) {
            $this->notFound();
        }

        $routineId = (int) $routine['id'];
        $draft     = MaintenanceRoutine::draftVersion($routineId);
        $current   = MaintenanceRoutine::currentVersion($routineId);
        $version   = $draft;

        if ($version === null && $current !== null && (int) $current['completion_count'] === 0) {
            $version = $current;
        }

        $this->view('routines/edit', [
            'pageTitle'  => 'Edit ' . $routine['name'],
            'routine'    => $routine,
            'version'    => $version,
            'current'    => $current,
            'categories' => Category::parentOptions(),
            'pages'      => $version === null ? [] : MaintenanceRoutine::structure((int) $version['id']),
        ]);
    }

    /** Fork the live version into a draft at the next number. */
    public function newVersion(string $id): void
    {
        $routineId = (int) $id;
        $routine   = MaintenanceRoutine::find($routineId);

        if ($routine === null) {
            $this->notFound();
        }

        if (MaintenanceRoutine::draftVersion($routineId) !== null) {
            Flash::info('There is already a draft open for this routine.');
            Response::redirect('/maintenance/routines/' . $routineId . '/edit');
        }

        $version = MaintenanceRoutine::editableVersion($routineId);

        ActivityLog::record(
            'created',
            'maintenance_routine',
            $routineId,
            sprintf('Started version %d of "%s"', (int) $version['version_number'], $routine['name'])
        );

        Flash::success(sprintf(
            'Version %d started as a copy of what is live. Nothing changes for anyone until you publish it.',
            (int) $version['version_number']
        ));

        Response::redirect('/maintenance/routines/' . $routineId . '/edit');
    }

    public function publish(string $id): void
    {
        $routineId = (int) $id;
        $routine   = MaintenanceRoutine::find($routineId);

        if ($routine === null) {
            $this->notFound();
        }

        $redirect = '/maintenance/routines/' . $routineId . '/edit';
        $draft    = MaintenanceRoutine::draftVersion($routineId);

        if ($draft === null) {
            Flash::info('There is no draft to publish.');
            Response::redirect($redirect);
        }

        $versionId = (int) $draft['id'];

        if (MaintenanceRoutine::stepCount($versionId) === 0) {
            Flash::error('Add at least one step before publishing — an empty routine asks nothing.');
            Response::redirect($redirect);
        }

        MaintenanceRoutine::publish($versionId);

        ActivityLog::record(
            'published',
            'maintenance_routine',
            $routineId,
            sprintf('Published version %d of "%s"', (int) $draft['version_number'], $routine['name'])
        );

        Flash::success(sprintf(
            'Version %d is live. Routines already carried out keep the version they followed.',
            (int) $draft['version_number']
        ));

        Response::redirect('/maintenance/routines/' . $routineId);
    }

    public function discard(string $id): void
    {
        $routineId = (int) $id;
        $routine   = MaintenanceRoutine::find($routineId);

        if ($routine === null) {
            $this->notFound();
        }

        $draft = MaintenanceRoutine::draftVersion($routineId);

        if ($draft !== null) {
            MaintenanceRoutine::discardDraft((int) $draft['id']);

            ActivityLog::record(
                'deleted',
                'maintenance_routine',
                $routineId,
                sprintf('Discarded the draft of version %d of "%s"', (int) $draft['version_number'], $routine['name'])
            );

            Flash::success('Draft discarded. What is live is unchanged.');
        }

        Response::redirect('/maintenance/routines/' . $routineId);
    }

    /**
     * Whether a run of the version being edited is a checklist or a wizard.
     *
     * Its own action because it belongs to the version rather than to the
     * routine, and because it changes how a run behaves rather than what it
     * asks — a published version keeps the behaviour it was published with.
     */
    public function setOutOfOrder(string $id): void
    {
        [$routine, $version] = $this->editable((int) $id);

        $allow = Request::boolean('allow_out_of_order');

        MaintenanceRoutine::setOutOfOrder((int) $version['id'], $allow);

        ActivityLog::record(
            'updated',
            'maintenance_routine',
            (int) $routine['id'],
            sprintf(
                'Version %d of "%s" %s its steps to be answered out of order',
                (int) $version['version_number'],
                (string) $routine['name'],
                $allow ? 'now allows' : 'no longer allows'
            )
        );

        Flash::success($allow
            ? 'This version is now worked through as a checklist: any step, in any order, by anybody.'
            : 'This version is now worked through in order, as one form.');

        Response::redirect('/maintenance/routines/' . (int) $routine['id'] . '/edit');
    }

    /**
     * The category a routine is restricted to, checked against the tree.
     *
     * A category that has since been deleted comes back as an id nothing
     * answers to, and silently storing it would leave a routine restricted to
     * nothing at all.
     *
     * @param array<string,mixed> $data
     */
    private function categoryFrom(array $data, string $redirect): ?int
    {
        $categoryId = (int) $data['category_id'];

        if ($categoryId < 1) {
            return null;
        }

        if (Category::find($categoryId) === null) {
            $this->failValidation(['category_id' => 'That category no longer exists.'], $redirect);
        }

        return $categoryId;
    }

    /** The routine as somebody carrying it out would see it. */
    public function preview(string $id): void
    {
        $routine = MaintenanceRoutine::find((int) $id);

        if ($routine === null) {
            $this->notFound();
        }

        $requested = (int) Request::query('version', 0);
        $version   = $requested > 0
            ? MaintenanceRoutine::findVersion($requested)
            : (MaintenanceRoutine::draftVersion((int) $routine['id']) ?? MaintenanceRoutine::currentVersion((int) $routine['id']));

        if ($version === null || (int) $version['routine_id'] !== (int) $routine['id']) {
            $this->notFound('That version of the routine does not exist.');
        }

        $this->view('routines/preview', [
            'pageTitle' => 'Preview · ' . $routine['name'],
            'routine'   => $routine,
            'version'   => $version,
            'pages'     => MaintenanceRoutine::structure((int) $version['id']),
        ]);
    }

    // -- Pages and steps ----------------------------------------------------

    public function addPage(string $id): void
    {
        [$routine, $version] = $this->editable((int) $id);

        $title = trim((string) Request::post('title', ''));

        MaintenanceRoutine::addPage(
            (int) $version['id'],
            $title !== '' ? mb_substr($title, 0, 191) : 'Untitled page'
        );

        Flash::success('Page added.');
        Response::redirect('/maintenance/routines/' . (int) $routine['id'] . '/edit');
    }

    /**
     * One page's form: its own fields, every step in it, and whichever button
     * was pressed.
     *
     * Everything on the form is saved before the button is acted on, so
     * pressing "Add step" never loses an edit made higher up the page.
     */
    public function savePage(string $id, string $pageId): void
    {
        [$routine, $version] = $this->editable((int) $id);

        $page = MaintenanceRoutine::findPage((int) $pageId);

        if ($page === null || (int) $page['version_id'] !== (int) $version['id']) {
            $this->notFound('That page is no longer part of this routine.');
        }

        $redirect = '/maintenance/routines/' . (int) $routine['id'] . '/edit#page-' . (int) $page['id'];

        $this->applyPageForm($page);

        [$verb, $target, $direction] = self::action();

        switch ($verb) {
            case 'add-step':
                MaintenanceRoutine::addStep((int) $page['id'], [
                    'label'       => 'Untitled step',
                    'field_type'  => 'short_text',
                    'is_required' => 0,
                ]);
                Flash::success('Step added.');
                break;

            case 'delete-page':
                MaintenanceRoutine::deletePage((int) $page['id']);
                MaintenanceRoutine::reorderPages(
                    (int) $version['id'],
                    array_column(MaintenanceRoutine::pages((int) $version['id']), 'id')
                );
                Flash::success('Page removed.');
                Response::redirect('/maintenance/routines/' . (int) $routine['id'] . '/edit');
                break;

            case 'move-page':
                $this->movePage((int) $version['id'], (int) $page['id'], $target);
                break;

            case 'delete-step':
                $this->deleteStep((int) $page['id'], $target);
                break;

            case 'move-step':
                $this->moveStep((int) $page['id'], $target, $direction);
                break;

            default:
                Flash::success('Page saved.');
        }

        Response::redirect($redirect);
    }

    /**
     * Save the page's own fields and every step's configuration.
     *
     * @param array<string,mixed> $page
     */
    private function applyPageForm(array $page): void
    {
        $title       = trim((string) Request::post('title', ''));
        $description = trim((string) Request::post('description', ''));

        MaintenanceRoutine::updatePage((int) $page['id'], [
            'title'       => $title !== '' ? mb_substr($title, 0, 191) : 'Untitled page',
            'description' => $description !== '' ? mb_substr($description, 0, 1000) : null,
        ]);

        $submitted = Request::post('steps');

        if (!is_array($submitted)) {
            return;
        }

        $required = (array) (Request::post('step_required') ?? []);

        foreach ($submitted as $stepId => $fields) {
            $step = MaintenanceRoutine::findStep((int) $stepId);

            if ($step === null || (int) $step['page_id'] !== (int) $page['id'] || !is_array($fields)) {
                continue;
            }

            $label = trim((string) ($fields['label'] ?? ''));
            $help  = trim((string) ($fields['help_text'] ?? ''));
            $unit  = trim((string) ($fields['unit'] ?? ''));
            $type  = (string) ($fields['field_type'] ?? 'short_text');

            if (!array_key_exists($type, MaintenanceRoutine::FIELD_TYPES)) {
                $type = 'short_text';
            }

            MaintenanceRoutine::updateStep((int) $stepId, [
                'label'       => $label !== '' ? mb_substr($label, 0, 255) : 'Untitled step',
                'help_text'   => $help !== '' ? mb_substr($help, 0, 1000) : null,
                'field_type'  => $type,
                'is_required' => array_key_exists((string) $stepId, $required) ? 1 : 0,
                'unit'        => ($type === 'number' && $unit !== '') ? mb_substr($unit, 0, 30) : null,
                'options'     => in_array($type, MaintenanceRoutine::CHOICE_TYPES, true)
                    ? MaintenanceRoutine::encodeOptions((string) ($fields['options'] ?? ''))
                    : null,
            ]);
        }
    }

    private function movePage(int $versionId, int $pageId, int $direction): void
    {
        $ids = array_map('intval', array_column(MaintenanceRoutine::pages($versionId), 'id'));

        MaintenanceRoutine::reorderPages($versionId, self::shift($ids, $pageId, $direction));
        Flash::success('Page moved.');
    }

    private function deleteStep(int $pageId, int $stepId): void
    {
        $step = MaintenanceRoutine::findStep($stepId);

        if ($step === null || (int) $step['page_id'] !== $pageId) {
            return;
        }

        MaintenanceRoutine::deleteStep($stepId);
        MaintenanceRoutine::reorderSteps($pageId, array_column(MaintenanceRoutine::steps($pageId), 'id'));

        Flash::success('Step removed.');
    }

    /**
     * A step moves by its own id and the direction packed into the same button
     * value: "move-step:12:-1". One button, one value, and no hidden field that
     * a second button could contradict.
     */
    private function moveStep(int $pageId, int $stepId, int $direction): void
    {
        if ($direction === 0) {
            return;
        }

        $ids = array_map('intval', array_column(MaintenanceRoutine::steps($pageId), 'id'));

        MaintenanceRoutine::reorderSteps($pageId, self::shift($ids, $stepId, $direction));
        Flash::success('Step moved.');
    }

    /**
     * Move one id one place up or down a list.
     *
     * @param array<int,int> $ids
     * @return array<int,int>
     */
    private static function shift(array $ids, int $id, int $direction): array
    {
        $index = array_search($id, $ids, true);

        if ($index === false) {
            return $ids;
        }

        $target = $index + ($direction < 0 ? -1 : 1);

        if ($target < 0 || $target >= count($ids)) {
            return $ids;
        }

        [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];

        return $ids;
    }

    /**
     * The button that was pressed, as a verb, the id it applies to and a
     * direction — "move-step:12:-1" moves step 12 up one place.
     *
     * The verb is checked against a list before it reaches a switch, so an
     * invented value falls through to "save" rather than anywhere else.
     *
     * @return array{0:string,1:int,2:int}
     */
    private static function action(): array
    {
        $parts = explode(':', (string) Request::post('do', 'save'));
        $verb  = $parts[0];

        $known = ['save', 'add-step', 'delete-page', 'move-page', 'delete-step', 'move-step'];

        return [
            in_array($verb, $known, true) ? $verb : 'save',
            isset($parts[1]) ? (int) $parts[1] : 0,
            isset($parts[2]) ? (int) $parts[2] : 0,
        ];
    }

    /**
     * The routine and the version an edit may be written to, or a refusal.
     *
     * A published version that has been used is never editable. Reaching this
     * with one means the draft was published or discarded in another tab, and
     * the honest answer is to send the editor back rather than write into
     * history.
     *
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function editable(int $routineId): array
    {
        $routine = MaintenanceRoutine::find($routineId);

        if ($routine === null) {
            $this->notFound();
        }

        $draft   = MaintenanceRoutine::draftVersion($routineId);
        $current = MaintenanceRoutine::currentVersion($routineId);
        $version = $draft;

        if ($version === null && $current !== null && (int) $current['completion_count'] === 0) {
            $version = $current;
        }

        if ($version === null) {
            Flash::error('That version has been used and can no longer be changed. Start a new version instead.');
            Response::redirect('/maintenance/routines/' . $routineId . '/edit');
        }

        return [$routine, $version];
    }

    /** @return array<string,mixed> */
    private function validateRoutine(string $redirect): array
    {
        return $this->validate([
            'name'        => 'required|max:191',
            'description' => 'max:1000',
            'category_id' => 'integer',
        ], [
            'name'        => 'Name',
            'description' => 'Description',
            'category_id' => 'Applies to',
        ], $redirect);
    }
}
