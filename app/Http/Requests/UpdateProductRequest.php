<?php

namespace App\Http\Requests;

use App\Contracts\ProductCatalog;
use App\Rules\UniqueProductSlug;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
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
        new OA\Property(property: 'size', type: 'string', maxLength: 255, nullable: true, description: 'Comma-separated list of available sizes.', example: 'S,M,L,XL'),
        new OA\Property(property: 'category_id', type: 'integer', nullable: true, description: 'Existing category id.', example: 1),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'replace_images', type: 'boolean', description: 'Replace the existing image gallery with the uploaded images.'),
        new OA\Property(
            property: 'images',
            type: 'array',
            description: 'Image uploads or image URLs appended by default, or used to replace the gallery when replace_images is true.',
            items: new OA\Items(oneOf: [
                new OA\Schema(type: 'string', format: 'binary'),
                new OA\Schema(type: 'string', format: 'uri'),
            ])
        ),
    ]
)]
class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('images')) {
            return;
        }

        $images = $this->input('images');

        if (is_string($images)) {
            $decoded = json_decode($images, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $this->merge(['images' => $decoded]);
            } elseif (str_contains($images, ',')) {
                $this->merge([
                    'images' => array_values(array_filter(array_map('trim', explode(',', $images)), fn ($value) => $value !== '')),
                ]);
            } else {
                $trimmed = trim($images);
                if ($trimmed !== '') {
                    $this->merge(['images' => [$trimmed]]);
                } else {
                    $this->merge(['images' => []]);
                }
            }

            return;
        }

        if (is_array($images)) {
            $normalized = [];

            foreach ($images as $image) {
                if ($image instanceof UploadedFile) {
                    $normalized[] = $image;

                    continue;
                }

                if (is_string($image)) {
                    $trimmed = trim($image);

                    if ($trimmed === '') {
                        continue;
                    }

                    $decoded = json_decode($trimmed, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        foreach ($decoded as $nested) {
                            if ($nested instanceof UploadedFile) {
                                $normalized[] = $nested;
                                continue;
                            }
                            if (is_string($nested)) {
                                $nestedTrimmed = trim($nested);
                                if ($nestedTrimmed !== '') {
                                    $normalized[] = $nestedTrimmed;
                                }
                            }
                        }
                        continue;
                    }

                    $normalized[] = $trimmed;
                    continue;
                }
            }

            $this->merge(['images' => $normalized]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $product = app(ProductCatalog::class)->find((string) $this->route('key'));

        abort_if($product === null, 404);

        $images = $this->input('images');
        $replaceImages = filter_var($this->input('replace_images'), FILTER_VALIDATE_BOOLEAN);
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

        $imagesRules = [];

        if ($replaceImages) {
            $imagesRules[] = 'required';
        } else {
            $imagesRules[] = 'sometimes';
        }

        $imagesRules[] = 'array';
        $imagesRules[] = 'max:8';

        if ($replaceImages && ! $hasFileImages) {
            $imagesRules[] = 'min:1';
        }

        $imageItemRules = $hasFileImages
            ? ['image', 'mimes:jpeg,jpg,png,webp', 'max:2048']
            : ['required', 'string', 'url'];

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', new UniqueProductSlug($product['id'])],
            'team' => ['sometimes', 'string', 'max:255'],
            'year' => ['sometimes', 'integer', 'min:1900', 'max:2100'],
            'description' => ['sometimes', 'string'],
            'price' => ['sometimes', 'integer', 'min:0'],
            'old_price' => ['nullable', 'integer', 'min:0'],
            'stock' => ['sometimes', 'integer', 'min:0'],
            'size' => ['sometimes', 'nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'is_active' => ['sometimes', 'boolean'],
            'replace_images' => ['sometimes', 'boolean'],
            'images' => $imagesRules,
            'images.*' => $imageItemRules,
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
