<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ProductUpdateRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Nigeria 1994 Home Shirt'),
        new OA\Property(property: 'slug', type: 'string', maxLength: 255, description: 'Unique url-safe slug.', example: 'nigeria-1994-home-shirt'),
        new OA\Property(property: 'team', type: 'string', maxLength: 255, example: 'Nigeria'),
        new OA\Property(property: 'year', type: 'integer', minimum: 1900, maximum: 2100, example: 1994),
        new OA\Property(property: 'description', type: 'string', example: 'Classic home shirt with gold trim.'),
        new OA\Property(property: 'price', type: 'integer', minimum: 0, description: 'Price in DZD.', example: 4500),
        new OA\Property(property: 'old_price', type: 'integer', minimum: 0, nullable: true, example: 6000),
        new OA\Property(property: 'stock', type: 'integer', minimum: 0, example: 10),
        new OA\Property(property: 'category_id', type: 'integer', nullable: true, description: 'Existing category id.', example: 1),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(
            property: 'images',
            type: 'array',
            description: 'Additional image uploads, appended to the existing ones. Requires a multipart/form-data request.',
            items: new OA\Items(type: 'string', format: 'binary')
        ),
    ]
)]
class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $productId = Product::findByKey((string) $this->route('key'))->id;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('products', 'slug')->ignore($productId)],
            'team' => ['sometimes', 'string', 'max:255'],
            'year' => ['sometimes', 'integer', 'min:1900', 'max:2100'],
            'description' => ['sometimes', 'string'],
            'price' => ['sometimes', 'integer', 'min:0'],
            'old_price' => ['nullable', 'integer', 'min:0'],
            'stock' => ['sometimes', 'integer', 'min:0'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'is_active' => ['sometimes', 'boolean'],
            'images' => ['sometimes', 'array', 'max:8'],
            'images.*' => ['image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'The slug must contain only lowercase letters, numbers and dashes.',
        ];
    }
}
