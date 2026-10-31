<?php

namespace App\Http\Requests;

use App\Rules\UniqueProductSlug;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ProductRequest',
    required: ['name', 'slug', 'team', 'year', 'description', 'price', 'stock'],
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
        new OA\Property(property: 'size', type: 'string', maxLength: 255, nullable: true, description: 'Comma-separated list of available sizes.', example: 'S,M,L,XL'),
        new OA\Property(property: 'category_id', type: 'integer', nullable: true, description: 'Existing category id.', example: 1),
        new OA\Property(property: 'is_active', type: 'boolean', default: true),
        new OA\Property(
            property: 'images',
            type: 'array',
            description: 'Image uploads or image URLs. Requires a multipart/form-data request for uploads.',
            items: new OA\Items(type: 'string', format: 'binary')
        ),
    ]
)]
class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->offsetUnset('images');

        $images = $this->collectImagesFromFiles();

        if ($images !== null && ! empty($images)) {
            $this->merge(['images' => array_values($images)]);
        }
    }

    /**
     * @return array<int, UploadedFile>|null
     */
    private function collectImagesFromFiles(): ?array
    {
        $allFiles = $this->allFiles();
        $images = [];

        foreach ($allFiles as $key => $file) {
            if (str_starts_with($key, 'images')) {
                if ($file instanceof UploadedFile) {
                    $images[] = $file;
                } elseif (is_array($file)) {
                    foreach ($file as $nestedFile) {
                        if ($nestedFile instanceof UploadedFile) {
                            $images[] = $nestedFile;
                        }
                    }
                }
            }
        }

        return empty($images) ? null : $images;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $images = $this->input('images');
        $hasFileImages = false;

        if (is_array($images)) {
            foreach ($images as $image) {
                if ($image instanceof UploadedFile) {
                    $hasFileImages = true;
                    break;
                }
            }
        } elseif ($images instanceof UploadedFile) {
            $hasFileImages = true;
        }

        $baseRules = [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', new UniqueProductSlug],
            'team' => ['required', 'string', 'max:255'],
            'year' => ['required', 'integer', 'min:1900', 'max:2100'],
            'description' => ['required', 'string'],
            'price' => ['required', 'integer', 'min:0'],
            'old_price' => ['nullable', 'integer', 'min:0'],
            'stock' => ['required', 'integer', 'min:0'],
            'size' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'is_active' => ['sometimes', 'boolean'],
        ];

        if (! $this->has('images') || ! is_array($images)) {
            return $baseRules;
        }

        $imagesRules = ['sometimes', 'array', 'max:8'];

        if ($hasFileImages) {
            $imagesRules[] = function ($attribute, $value, $fail) {
                if (! is_array($value)) {
                    $fail('The images field must be an array.');

                    return;
                }

                foreach ($value as $image) {
                    if (! $image instanceof UploadedFile) {
                        $fail('The images must be valid image uploads.');

                        return;
                    }
                }
            };
        } elseif (! empty($images)) {
            $imagesRules[] = 'min:1';
        }

        $imageItemRules = $hasFileImages
            ? ['image', 'mimes:jpeg,jpg,png,webp', 'max:2048']
            : ['required', 'string', 'url'];

        return array_merge($baseRules, [
            'images' => $imagesRules,
            'images.*' => $imageItemRules,
        ]);
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
