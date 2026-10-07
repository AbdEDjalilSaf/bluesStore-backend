<?php

namespace App\Models;

use App\Enums\Condition;
use App\Enums\Rarity;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'team',
        'year',
        'condition',
        'rarity',
        'description',
        'price',
        'old_price',
        'stock',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'condition' => Condition::class,
            'rarity' => Rarity::class,
            'year' => 'integer',
            'price' => 'integer',
            'old_price' => 'integer',
            'stock' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
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
