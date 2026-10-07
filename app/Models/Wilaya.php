<?php

namespace App\Models;

use Database\Factories\WilayaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Wilaya extends Model
{
    /** @use HasFactory<WilayaFactory> */
    use HasFactory;

    protected $fillable = [
        'phone_number',
        'name',
        'shipping_fee',
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
            'shipping_fee' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
