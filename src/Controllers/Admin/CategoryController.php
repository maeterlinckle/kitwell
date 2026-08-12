<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Models\ActivityLog;
use App\Models\Category;

/**
 * Categories: a self-nesting reference table, shown as a tree.
 *
 * The list and the form are separate pages. Editing used to happen inline, one
 * card of inputs per category, which meant the page grew with the data and the
 * hierarchy was only visible as a dropdown on each row. A tree you can read is
 * worth a second page.
 */
final class CategoryController extends Controller
{
    public function index(): void
    {
        $this->view('admin/categories/index', [
            'pageTitle'  => 'Categories',
            'categories' => Category::all(),
        ]);
    }

    public function create(): void
    {
        $this->view('admin/categories/form', [
            'pageTitle' => 'Add category',
            'category'  => null,
            'parents'   => Category::parentOptions(),
            // "Add inside" on a tree row lands here with the parent chosen.
            'parentId'  => max(0, (int) Request::query('parent', 0)),
        ]);
    }

    public function store(): void
    {
        $data = $this->validate([
            'name'        => 'required|max:120',
            'parent_id'   => 'integer',
            'description' => 'max:255',
        ], ['name' => 'Category name'], '/admin/categories/create');

        $id = Category::create(
            $data['name'],
            (int) $data['parent_id'] > 0 ? (int) $data['parent_id'] : null,
            $data['description'] !== '' ? $data['description'] : null
        );

        ActivityLog::record('created', 'category', $id, 'Added category ' . $data['name']);
        Flash::success('Category “' . $data['name'] . '” added.');

        Response::redirect('/admin/categories');
    }

    public function edit(string $id): void
    {
        $category = Category::find((int) $id);

        if ($category === null) {
            $this->notFound();
        }

        $this->view('admin/categories/form', [
            'pageTitle' => 'Edit ' . $category['name'],
            'category'  => $category,
            // Its own subtree is not offered: see Tree::options().
            'parents'   => Category::parentOptions((int) $id),
            'parentId'  => 0,
        ]);
    }

    public function update(string $id): void
    {
        $categoryId = (int) $id;
        $category   = Category::find($categoryId);

        if ($category === null) {
            $this->notFound();
        }

        $redirect = '/admin/categories/' . $categoryId . '/edit';

        $data = $this->validate([
            'name'        => 'required|max:120',
            'parent_id'   => 'integer',
            'description' => 'max:255',
        ], ['name' => 'Category name'], $redirect);

        $parentId = (int) $data['parent_id'];

        if ($parentId === $categoryId) {
            $this->failValidation(['parent_id' => 'A category cannot be its own parent.'], $redirect);
        }

        // The form does not offer a descendant, but the form is not the
        // control: a posted id has to be checked, or one crafted request turns
        // the tree into a cycle and every page that draws it stops responding.
        if ($parentId > 0 && in_array($parentId, Category::descendantIds($categoryId), true)) {
            $this->failValidation(
                ['parent_id' => 'A category cannot be moved inside one of its own sub-categories.'],
                $redirect
            );
        }

        Category::update($categoryId, [
            'name'        => $data['name'],
            'slug'        => Category::uniqueSlug($data['name'], $categoryId),
            'parent_id'   => $parentId > 0 ? $parentId : null,
            'description' => $data['description'] !== '' ? $data['description'] : null,
            'is_active'   => Request::boolean('is_active') ? 1 : 0,
        ]);

        ActivityLog::record('updated', 'category', $categoryId, 'Updated category ' . $data['name']);
        Flash::success('Category updated.');

        Response::redirect('/admin/categories');
    }

    public function destroy(string $id): void
    {
        $categoryId = (int) $id;
        $category   = Category::find($categoryId);

        if ($category === null) {
            $this->notFound();
        }

        if (Category::inUse($categoryId)) {
            Flash::error('“' . $category['name'] . '” is still assigned to assets. Move those assets first, or deactivate the category instead.');
            Response::redirect('/admin/categories');
        }

        Category::delete($categoryId);
        ActivityLog::record('deleted', 'category', $categoryId, 'Deleted category ' . $category['name']);
        Flash::success('Category deleted.');

        Response::redirect('/admin/categories');
    }
}
