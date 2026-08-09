<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Models\ActivityLog;
use App\Models\Category;

final class CategoryController extends Controller
{
    public function index(): void
    {
        $this->view('admin/categories/index', [
            'pageTitle'  => 'Categories',
            'categories' => Category::all(),
        ]);
    }

    public function store(): void
    {
        $data = $this->validate([
            'name'        => 'required|max:120',
            'parent_id'   => 'integer',
            'description' => 'max:255',
        ], ['name' => 'Category name'], '/admin/categories');

        $id = Category::create(
            $data['name'],
            (int) $data['parent_id'] > 0 ? (int) $data['parent_id'] : null,
            $data['description'] !== '' ? $data['description'] : null
        );

        ActivityLog::record('created', 'category', $id, 'Added category ' . $data['name']);
        Flash::success('Category “' . $data['name'] . '” added.');

        Response::redirect('/admin/categories');
    }

    public function update(string $id): void
    {
        $categoryId = (int) $id;
        $category   = Category::find($categoryId);

        if ($category === null) {
            $this->notFound();
        }

        $data = $this->validate([
            'name'        => 'required|max:120',
            'parent_id'   => 'integer',
            'description' => 'max:255',
        ], ['name' => 'Category name'], '/admin/categories');

        $parentId = (int) $data['parent_id'];
        if ($parentId === $categoryId) {
            $this->failValidation(['parent_id' => 'A category cannot be its own parent.'], '/admin/categories');
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
