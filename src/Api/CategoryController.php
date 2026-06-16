<?php

namespace ReportingEngine\Api;

use ReportingEngine\Core\Request;
use ReportingEngine\Core\Response;
use ReportingEngine\Report\CategoryRepository;

class CategoryController
{
    private CategoryRepository $repository;

    public function __construct()
    {
        $this->repository = new CategoryRepository();
    }

    public function index(Request $request): Response
    {
        try {
            $categories = $this->repository->all();
            return Response::json($categories);
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function store(Request $request): Response
    {
        try {
            $name = trim($request->body['name'] ?? '');
            if (empty($name)) {
                return Response::error('Category name is required', 422);
            }
            if ($this->repository->findByName($name)) {
                return Response::error("Category '{$name}' already exists", 422);
            }
            $id = $this->repository->create($name);
            return Response::json(['id' => $id, 'name' => $name], 201, 'Category created');
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function update(Request $request): Response
    {
        try {
            $id = (int) $request->getParam('id');
            $category = $this->repository->find($id);
            if (!$category) {
                return Response::error('Category not found', 404);
            }
            $name = trim($request->body['name'] ?? '');
            if (empty($name)) {
                return Response::error('Category name is required', 422);
            }
            $existing = $this->repository->findByName($name);
            if ($existing && (int) $existing['id'] !== $id) {
                return Response::error("Category '{$name}' already exists", 422);
            }
            $this->repository->update($id, $name);
            return Response::json(null, 200, 'Category updated');
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }

    public function destroy(Request $request): Response
    {
        try {
            $id = (int) $request->getParam('id');
            $category = $this->repository->find($id);
            if (!$category) {
                return Response::error('Category not found', 404);
            }
            $this->repository->delete($id);
            return Response::json(null, 200, 'Category deleted');
        } catch (\Exception $e) {
            return Response::error($e->getMessage(), 500);
        }
    }
}
