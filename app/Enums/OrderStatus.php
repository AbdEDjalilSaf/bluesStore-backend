<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    /**
     * Whether moving from this status to the target status is allowed.
     *
     * Orders advance one step at a time (pending → confirmed → shipped →
     * delivered) and can only be cancelled while they are still pending
     * or confirmed.
     */
    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Pending => $target === self::Confirmed || $target === self::Cancelled,
            self::Confirmed => $target === self::Shipped || $target === self::Cancelled,
            self::Shipped => $target === self::Delivered,
            self::Delivered, self::Cancelled => false,
        };
    }
}
