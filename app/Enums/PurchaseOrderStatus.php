<?php

namespace App\Enums;

enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Cancelled = 'cancelled';

    /**
     * Get the user-facing status label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Awaiting approval',
            self::Approved => 'Approved',
            self::PartiallyReceived => 'Partially received',
            self::Received => 'Received',
            self::Cancelled => 'Cancelled',
        };
    }
}
