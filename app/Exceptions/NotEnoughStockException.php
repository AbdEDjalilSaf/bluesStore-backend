<?php

namespace App\Exceptions;

use RuntimeException;

class NotEnoughStockException extends RuntimeException
{
    public static function forProduct(string $productName): self
    {
        return new self("Not enough stock for {$productName}.");
    }
}
