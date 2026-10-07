<?php

namespace App\Exceptions;

use App\Models\Product;
use RuntimeException;

class NotEnoughStockException extends RuntimeException
{
    public static function forProduct(Product $product): self
    {
        return new self("Not enough stock for {$product->name}.");
    }
}
