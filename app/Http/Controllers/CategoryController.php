<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class CategoryController extends Controller
{
    /**
     * List all categories (public).
     */
    #[OA\Get(
        path: '/categories',
        summary: 'List all categories',
        description: 'Returns all categories (public).',
        tags: ['categories'],
        responses: [
            new OA\Response(response: 200, description: 'List of categories.', content: new OA\JsonContent(ref: '#/components/schemas/CategoryCollection')),
        ]
    )]
    public function index()
    {
        return CategoryResource::collection(Category::query()->orderBy('id')->get());
    }

    /**
     * Create a category (admin). Validates a unique name.
     */
    #[OA\Post(
        path: '/categories',
        summary: 'Create a category',
        description: 'Create a new category. Validates a unique name (admin).',
        tags: ['categories'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CategoryRequest')
        ),
        responses: [
            new OA\Response(response: 201, description: 'Category created.', content: new OA\JsonContent(ref: '#/components/schemas/Category')),
            new OA\Response(response: 422, description: 'Validation failed.'),
        ]
    )]
    public function store(StoreCategoryRequest $request)
    {
        $category = Category::create($request->validated());

        return (new CategoryResource($category))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Get one category (public).
     */
    #[OA\Get(
        path: '/categories/{id}',
        summary: 'Get a category',
        description: 'Returns a single category by ID (public).',
        tags: ['categories'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The category.', content: new OA\JsonContent(ref: '#/components/schemas/Category')),
            new OA\Response(response: 404, description: 'Category not found.'),
        ]
    )]
    public function show(int $id)
    {
        $category = Category::findOrFail($id);

        return new CategoryResource($category);
    }

    /**
     * Update a category's fields (admin).
     */
    #[OA\Patch(
        path: '/categories/{id}',
        summary: 'Update a category',
        description: 'Update category fields (admin).',
        tags: ['categories'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CategoryUpdateRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Category updated.', content: new OA\JsonContent(ref: '#/components/schemas/Category')),
            new OA\Response(response: 404, description: 'Category not found.'),
            new OA\Response(response: 422, description: 'Validation failed.'),
        ]
    )]
    public function update(UpdateCategoryRequest $request, int $id)
    {
        $category = Category::findOrFail($id);
        $category->update($request->validated());

        return new CategoryResource($category);
    }

    /**
     * Delete a category (admin). Blocks if any products exist.
     */
    #[OA\Delete(
        path: '/categories/{id}',
        summary: 'Delete a category',
        description: 'Delete a category (admin). Block the delete if any products exist.',
        tags: ['categories'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Category deleted.'),
            new OA\Response(response: 404, description: 'Category not found.'),
            new OA\Response(response: 422, description: 'Cannot delete category with existing products.'),
        ]
    )]
    public function destroy(int $id): JsonResponse
    {
        $category = Category::findOrFail($id);

        if ($category->products()->exists()) {
            return response()->json(['message' => 'Cannot delete category with existing products.'], 422);
        }

        $category->delete();

        return response()->json(null, 204);
    }
}
