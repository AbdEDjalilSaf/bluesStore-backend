<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $fillable = [
        'category_id', 'name', 'slug', 'team', 'year', 'description',
        'price', 'old_price', 'stock', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'year' => 'integer',
            'price' => 'integer',
            'old_price' => 'integer',
            'stock' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    /**
     * Find a product by its id or slug, or fail with a 404.
     */
    public static function findByKey(string $key): static
    {
        return static::query()
            ->where(function ($query) use ($key) {
                $query->where('slug', $key);

                if (ctype_digit($key)) {
                    $query->orWhere('id', $key);
                }
            })
            ->firstOrFail();
    }

    protected function discountPercent(): Attribute
    {
        return Attribute::get(function (): int {
            if (! $this->old_price || $this->old_price <= $this->price) {
                return 0;
            }

            return (int) round((($this->old_price - $this->price) / $this->old_price) * 100);
        });
    }
}
