<?php

namespace App\Rules;

use App\Contracts\ProductCatalog;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class UniqueProductSlug implements ValidationRule
{
    public function __construct(
        private readonly int|string|null $ignoreId = null
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (app(ProductCatalog::class)->slugExists($value, $this->ignoreId)) {
            $fail('The slug has already been taken.');
        }
    }
}
